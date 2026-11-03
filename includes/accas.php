<?php
/** Accumulator helpers (1 Million Accumulator = free, VIP Accumulator = paid). */

function acca_kind_label(string $kind): string
{
    return $kind === 'vip' ? 'VIP Accumulator' : '1 Million Accumulator';
}

/**
 * Turns pasted text into legs. One leg per line:
 *   Home vs Away | League | 2026-10-04 17:30 | Pick | Odds
 * (a tab between the columns works too, so you can paste straight from a spreadsheet)
 * Returns [legs, errors].
 */
function parse_acca_legs(string $text, bool $requireFuture): array
{
    $legs = [];
    $errors = [];
    $lineNo = 0;
    foreach (preg_split('/\R/', $text) as $line) {
        $lineNo++;
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $f = preg_split('/\s*\|\s*|\t+/', $line);
        if (count($f) !== 5) {
            $errors[] = "Line $lineNo: expected 5 parts (match | league | kickoff | pick | odds) but found " . count($f) . '.';
            continue;
        }
        [$match, $league, $ko, $pick, $odds] = array_map('trim', $f);
        $teams = preg_split('/\s+vs\.?\s+/i', $match);
        if (count($teams) !== 2 || $teams[0] === '' || $teams[1] === '') {
            $errors[] = "Line $lineNo: write the match as \"Home vs Away\".";
            continue;
        }
        if (mb_strlen($teams[0]) > 80 || mb_strlen($teams[1]) > 80 || mb_strlen($league) > 80 || $league === '') {
            $errors[] = "Line $lineNo: team and league names must be 1 to 80 characters.";
            continue;
        }
        $dt = DateTime::createFromFormat('Y-m-d H:i', str_replace('T', ' ', $ko));
        $dtErr = DateTime::getLastErrors();
        if (!$dt || ($dtErr && ($dtErr['warning_count'] || $dtErr['error_count']))) {
            $errors[] = "Line $lineNo: kickoff must look like 2026-10-04 17:30.";
            continue;
        }
        if ($requireFuture && $dt->getTimestamp() <= time() + 60) {
            $errors[] = "Line $lineNo: kickoff is in the past. Accumulators are posted before the matches start.";
            continue;
        }
        if ($pick === '' || mb_strlen($pick) > 120) {
            $errors[] = "Line $lineNo: the pick must be 1 to 120 characters.";
            continue;
        }
        if (!is_numeric($odds) || (float)$odds < 1.01 || (float)$odds > 100) {
            $errors[] = "Line $lineNo: odds must be a number from 1.01 to 100.";
            continue;
        }
        $legs[] = ['league' => $league, 'home' => $teams[0], 'away' => $teams[1], 'kickoff' => $dt->format('Y-m-d H:i:s'),
                   'pick' => $pick, 'odds' => round((float)$odds, 2)];
    }
    if (!$errors) {
        if (count($legs) < 2) {
            $errors[] = 'An accumulator needs at least 2 selections.';
        } elseif (count($legs) > 100) {
            $errors[] = 'A maximum of 100 selections is allowed.';
        } elseif (acca_product(array_column($legs, 'odds')) > 1.0e12) {
            $errors[] = 'The total odds are above 1,000,000,000,000. Remove some selections.';
        }
    }
    return [$legs, $errors];
}

/** Multiplies a list of odds. */
function acca_product(array $odds): float
{
    $p = 1.0;
    foreach ($odds as $o) {
        $p *= (float)$o;
    }
    return $p;
}

/** Total odds counting only legs that are not void (a void leg counts as odds 1.00). */
function acca_effective_odds(array $legs): float
{
    $odds = [];
    foreach ($legs as $l) {
        if (($l['status'] ?? 'pending') !== 'void') {
            $odds[] = $l['odds'];
        }
    }
    return round(acca_product($odds), 2);
}

/** Smallest stake (in the visitor's currency, whole units) that returns at least the target. */
function acca_min_stake_display(int $targetKes, float $odds): string
{
    $cur    = current_currency();
    $target = convert_kes_to((float)$targetKes, $cur['code']);
    $stake  = max(1, (int)ceil($target / max(1.01, $odds)));
    return format_money((float)$stake, $cur, $cur['code'] !== 'KES');
}

function acca_target_display(int $targetKes): string
{
    $cur = current_currency();
    return format_money(convert_kes_to((float)$targetKes, $cur['code']), $cur, $cur['code'] !== 'KES');
}

/** Works out the accumulator's status from its legs and saves it. Returns the new status. */
function recompute_acca(int $id): string
{
    $pdo = db();
    $st = $pdo->prepare('SELECT odds, status FROM accumulator_legs WHERE accumulator_id = ?');
    $st->execute([$id]);
    $legs = $st->fetchAll();
    $count = ['won' => 0, 'lost' => 0, 'void' => 0, 'pending' => 0];
    foreach ($legs as $l) {
        $count[$l['status']]++;
    }
    if ($count['lost'] > 0)        $status = 'lost';       // one lost leg loses the whole accumulator
    elseif ($count['pending'] > 0) $status = 'pending';
    elseif ($count['won'] > 0)     $status = 'won';
    else                           $status = 'void';
    $pdo->prepare("UPDATE accumulators SET status = ?, settled_at = CASE WHEN ? = 'pending' THEN NULL ELSE COALESCE(settled_at, NOW()) END WHERE id = ?")
        ->execute([$status, $status, $id]);
    return $status;
}

/** An accumulator can only be changed or deleted before its first match starts. */
function acca_is_locked(array $a): bool
{
    return $a['status'] !== 'pending' || strtotime($a['first_kickoff']) <= time();
}

/** Latest accumulator of a kind (or null). Never breaks the page if the table is missing. */
function latest_acca(string $kind): ?array
{
    try {
        $st = db()->prepare('SELECT a.*, (SELECT COUNT(*) FROM accumulator_legs l WHERE l.accumulator_id = a.id) AS legs
                             FROM accumulators a WHERE a.kind = ? AND a.deleted_at IS NULL ORDER BY a.posted_at DESC LIMIT 1');
        $st->execute([$kind]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Who may see VIP accumulators: staff, and members with an active paid plan of at least 7 days (Weekly and above). Free trial and bonus days do not count. */
function has_vip_acca(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    $u = current_user();
    if (!$u) { return $ok = false; }
    if (in_array($u['role'], ['admin', 'editor', 'support', 'finance'], true)) { return $ok = true; }
    if (function_exists('access_vip_free') && access_vip_free()) { return $ok = true; }
    try {
        $min = max(1, (int)setting('vip_acca_min_days', '7'));
        $st = db()->prepare("SELECT 1 FROM subscriptions s JOIN plans p ON p.id = s.plan_id
                             WHERE s.user_id = ? AND s.status = 'active' AND s.ends_at > NOW() AND p.duration_days >= ? AND p.price_kes > 0 LIMIT 1");
        $st->execute([$u['id'], $min]);
        return $ok = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/** Small tab row shared by the VIP pages. */
function vip_tabs(string $on): string
{
    $h = '<div class="tabs" style="margin-bottom:18px">';
    foreach (['plans' => ['vip.php', 'VIP plans'], 'acca' => ['vip-accumulators.php', 'VIP Accumulator']] as $k => $it) {
        $h .= '<a href="' . e(url($it[0])) . '"' . ($k === $on ? ' class="on" aria-current="page"' : '') . '>' . e($it[1]) . '</a>';
    }
    return $h . '</div>';
}
