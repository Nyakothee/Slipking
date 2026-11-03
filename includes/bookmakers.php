<?php
/**
 * Bookmaker affiliate links and odds comparison.
 * Every public link goes through go.php so clicks can be counted. Nothing here ever breaks a page:
 * if the tables are missing (SQL not run yet) the functions simply return an empty string.
 */

/** Placeholder link used by the seeded example rows. It can never go live. */
function bookmaker_is_placeholder(string $url): bool
{
    return stripos($url, 'https://example.com/replace-me') === 0;
}

/** True for a plain http(s) address (max 500 chars, no spaces or control characters). */
function bookmaker_valid_url(string $url): bool
{
    if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7f]/', $url)) {
        return false;
    }
    if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $host = (string)parse_url($url, PHP_URL_HOST);
    return strpos($host, '.') !== false;
}

/** Active bookmakers that really have an affiliate link, in display order. Cached per request. */
function bookmakers_active(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }
    $rows = [];
    try {
        $all = db()->query('SELECT id, name, slug, affiliate_url, logo_text, bonus_text, countries
                            FROM bookmakers WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
        foreach ($all as $b) {
            if (!bookmaker_is_placeholder($b['affiliate_url']) && bookmaker_valid_url($b['affiliate_url'])) {
                $rows[] = $b;
            }
        }
    } catch (Throwable $e) {
        $rows = [];
    }
    return $rows;
}

function bookmaker_go_url(string $slug, ?int $tipId, string $page): string
{
    $q = ['b' => $slug];
    if ($tipId) {
        $q['t'] = $tipId;
    }
    $q['p'] = $page;
    return url('go.php?' . http_build_query($q));
}

/** Plain responsible-gambling line shown under every group of affiliate links. */
function bookmaker_18_line(): string
{
    return '<p class="bm-18">18+ only. Gamble responsibly. <a href="' . e(url('responsible-gambling.php')) . '">Responsible gambling</a></p>';
}

/**
 * "Bet on <name>" buttons for every active bookmaker. Returns '' when there are none.
 * Only call this for a tip the visitor is allowed to see (never for a locked VIP card).
 * The optional countries column is stored but not used yet: all active bookmakers are shown.
 */
function bookmaker_buttons(?array $tip = null, string $page = 'tip'): string
{
    // The Android app never shows "Bet on ..." buttons (store rules for gambling apps); tips there are information only.
    if (function_exists('is_app') && is_app()) {
        return '';
    }
    $list = bookmakers_active();
    if (!$list) {
        return '';
    }
    $page  = substr((string)preg_replace('/[^a-z0-9_\-]/', '', strtolower($page)), 0, 60);
    $tipId = $tip && !empty($tip['id']) ? (int)$tip['id'] : null;
    $h = '<div class="bm-buttons">';
    foreach ($list as $b) {
        $h .= '<a class="bm-btn" href="' . e(bookmaker_go_url($b['slug'], $tipId, $page)) . '" target="_blank" rel="sponsored noopener nofollow">Bet on ' . e($b['name']) . '</a>';
    }
    $h .= '</div>';
    return $h . bookmaker_18_line();
}

/**
 * Pure helper: given rows of ['odds'=>..] returns the highest odds as a float (0 if none).
 */
function odds_best(array $rows): float
{
    $best = 0.0;
    foreach ($rows as $r) {
        if ((float)$r['odds'] > $best) {
            $best = (float)$r['odds'];
        }
    }
    return $best;
}

/** Saved odds for a tip: rows with bookmaker name/slug, only for active bookmakers with a real link. */
function tip_odds_rows(int $tipId): array
{
    try {
        $st = db()->prepare('SELECT o.odds, b.id AS bookmaker_id, b.name, b.slug, b.affiliate_url
                             FROM tip_odds o JOIN bookmakers b ON b.id = o.bookmaker_id
                             WHERE o.tip_id = ? AND b.is_active = 1 ORDER BY o.odds DESC, b.sort_order, b.id');
        $st->execute([$tipId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            if (!bookmaker_is_placeholder($r['affiliate_url']) && bookmaker_valid_url($r['affiliate_url'])) {
                $out[] = $r;
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Small odds comparison table for one tip. Returns '' when no odds were entered.
 * Caller must only use it for tips the visitor may see and that can still be bet on.
 */
function tip_odds_table(array $tip, string $page = 'odds'): string
{
    $rows = tip_odds_rows((int)$tip['id']);
    if (!$rows) {
        return '';
    }
    $best = odds_best($rows);
    $h = '<div class="bm-odds"><h3>Compare odds</h3><table class="bm-table"><thead><tr><th>Bookmaker</th><th>Odds</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $isBest = (float)$r['odds'] >= $best;
        $h .= '<tr' . ($isBest ? ' class="best"' : '') . '><td>' . e($r['name']) . ($isBest ? ' <span class="bm-bestflag">Best odds</span>' : '') . '</td>'
            . '<td><strong>' . e(number_format((float)$r['odds'], 2)) . '</strong></td>'
            . '<td><a class="bm-btn small" href="' . e(bookmaker_go_url($r['slug'], (int)$tip['id'], $page)) . '" target="_blank" rel="sponsored noopener nofollow">Bet on ' . e($r['name']) . '</a></td></tr>';
    }
    $h .= '</tbody></table>' . bookmaker_18_line() . '</div>';
    return $h;
}
