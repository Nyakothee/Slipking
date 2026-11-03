<?php
/**
 * "Bad day" guarantee: if EVERY settled VIP tip of a day lost (void tips ignored) and there were at least
 * guarantee_min_tips of them, every member with an active paid VIP plan that day gets free VIP days.
 * Settings (Admin > Guarantee): guarantee_enabled, guarantee_days, guarantee_min_tips.
 * Uses includes/offers.php setting().
 */

/**
 * PURE rule check (no database). $statuses = settled-or-not statuses of one day's VIP tips,
 * e.g. ['lost','lost','void','pending']. Returns counts and whether the guarantee applies.
 * Applies when: no tip won, lost >= $minTips. Void and pending tips are ignored.
 */
function guarantee_evaluate(array $statuses, int $minTips): array
{
    $won = $lost = $void = $pending = 0;
    foreach ($statuses as $s) {
        if ($s === 'won') $won++;
        elseif ($s === 'lost') $lost++;
        elseif ($s === 'void') $void++;
        else $pending++;
    }
    $minTips = max(1, $minTips);
    return [
        'won' => $won, 'lost' => $lost, 'void' => $void, 'pending' => $pending,
        'settled' => $won + $lost,
        'applies' => ($won === 0 && $lost >= $minTips),
    ];
}

function guarantee_enabled(): bool
{
    return setting('guarantee_enabled', '0') === '1';
}

function guarantee_days(): int
{
    return max(1, min(30, (int)setting('guarantee_days', '1')));
}

function guarantee_min_tips(): int
{
    return max(1, min(50, (int)setting('guarantee_min_tips', '3')));
}

/** One-line HTML for public pages. Returns '' unless the guarantee is switched on. */
function guarantee_badge(): string
{
    if (!guarantee_enabled()) {
        return '';
    }
    $d = guarantee_days();
    $n = guarantee_min_tips();
    return '<p class="gt-badge"><strong>Bad-day guarantee:</strong> if all of our VIP tips lose on a day (at least ' . $n . ' settled tips), every VIP member gets '
        . $d . ' free VIP day' . ($d === 1 ? '' : 's') . '.</p>';
}

/** Statuses of the VIP tips whose match was on $day (Y-m-d). Deleted pending tips are ignored. */
function guarantee_day_statuses(PDO $pdo, string $day): array
{
    $st = $pdo->prepare("SELECT t.status FROM tips t JOIN matches m ON m.id = t.match_id
                         WHERE t.is_vip = 1 AND t.tipster_id IS NULL AND DATE(m.kickoff_at) = ? AND (t.deleted_at IS NULL OR t.status IN ('won','lost'))");
    $st->execute([$day]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** User ids with an active PAID plan (not trial or bonus, not cancelled) at any point of $day. */
function guarantee_member_ids(PDO $pdo, string $day): array
{
    $st = $pdo->prepare("SELECT DISTINCT s.user_id FROM subscriptions s JOIN plans p ON p.id = s.plan_id
                         WHERE s.status IN ('active','expired') AND p.slug NOT IN ('trial','bonus')
                           AND s.starts_at < DATE_ADD(?, INTERVAL 1 DAY) AND s.ends_at > ?");
    $st->execute([$day, $day]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Settles the guarantee for one calendar day. Safe to run twice (guarantee_log.day is UNIQUE).
 * Returns ['status'=>'disabled|already|waiting|logged|granted', 'tips'=>n, 'members'=>n, 'days'=>n].
 * 'waiting' = a VIP tip of that day is still unsettled; nothing is written, so the next run tries again
 * (after 3 days the unsettled tip is ignored so one forgotten tip cannot block the rule forever).
 */
function guarantee_run(PDO $pdo, string $day): array
{
    if (!guarantee_enabled()) {
        return ['status' => 'disabled', 'tips' => 0, 'members' => 0, 'days' => 0];
    }
    $st = $pdo->prepare('SELECT 1 FROM guarantee_log WHERE day = ?');
    $st->execute([$day]);
    if ($st->fetchColumn()) {
        return ['status' => 'already', 'tips' => 0, 'members' => 0, 'days' => 0];
    }
    $ev = guarantee_evaluate(guarantee_day_statuses($pdo, $day), guarantee_min_tips());
    $ageDays = (int)floor((time() - strtotime($day)) / 86400);
    if ($ev['pending'] > 0 && $ageDays < 3) {
        return ['status' => 'waiting', 'tips' => $ev['settled'], 'members' => 0, 'days' => 0];
    }

    $days = guarantee_days();
    $members = $ev['applies'] ? guarantee_member_ids($pdo, $day) : [];
    $bonusId = 0;
    if ($members) {
        $st = $pdo->prepare("SELECT id FROM plans WHERE slug = 'bonus' LIMIT 1");
        $st->execute();
        $bonusId = (int)$st->fetchColumn();
        if (!$bonusId) {
            throw new RuntimeException("The hidden 'bonus' plan is missing; run database-update-5.sql.");
        }
    }
    try {
        $pdo->beginTransaction();
        // The UNIQUE day column is the lock: a second run fails here and grants nothing.
        $pdo->prepare('INSERT INTO guarantee_log (day, tips_count, days_granted, members_count) VALUES (?, ?, ?, ?)')
            ->execute([$day, $ev['settled'], $ev['applies'] ? $days : 0, count($members)]);
        $done = 0;
        foreach ($members as $uid) {
            $ends = grant_vip($uid, $bonusId, $days);
            if ($ends) {
                $done++;
                notify_user($uid, 'Guarantee: free VIP days added',
                    'All ' . $ev['lost'] . ' of our VIP tips on ' . date('j F Y', strtotime($day)) . ' lost, so as promised we added ' . $days
                    . ' VIP day' . ($days === 1 ? '' : 's') . ' to your account. Your VIP now runs until ' . date('j F Y, H:i', strtotime($ends)) . '.');
            }
        }
        if ($done !== count($members)) {
            $pdo->prepare('UPDATE guarantee_log SET members_count = ? WHERE day = ?')->execute([$done, $day]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
            return ['status' => 'already', 'tips' => 0, 'members' => 0, 'days' => 0];
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['status' => $ev['applies'] ? 'granted' : 'logged', 'tips' => $ev['settled'], 'members' => $ev['applies'] ? count($members) : 0, 'days' => $ev['applies'] ? $days : 0];
}
