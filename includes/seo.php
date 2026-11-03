<?php
/**
 * SEO helpers: meta tags, JSON-LD, public match/league queries and extra sitemap URLs.
 * Pages that use it: match.php, league.php. Include with:
 *   require_once __DIR__ . '/includes/seo.php';   (after includes/init.php)
 * Nothing here ever reads a VIP pick: descriptions and JSON-LD only use tips that are public
 * (free, or VIP and already settled), the same rule tip_card() applies.
 */

/** Tip is visible to everybody, regardless of who is looking (free, or settled VIP). */
function seo_tip_is_public(array $t): bool
{
    return empty($t['is_vip']) || $t['status'] !== 'pending';
}

/** ISO 8601 date-time in the site time zone, e.g. 2026-10-12T20:00:00+03:00. */
function seo_iso(string $datetime): string
{
    return date('c', strtotime($datetime));
}

/** Cuts text to $max characters on a word boundary. Plain text in, plain text out (escape later). */
function seo_trim(string $s, int $max = 158): string
{
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));
    if (mb_strlen($s) <= $max) {
        return $s;
    }
    $cut = mb_substr($s, 0, $max - 1);
    $sp  = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > $max * 0.6) {
        $cut = mb_substr($cut, 0, $sp);
    }
    return rtrim($cut, " ,;:-") . "\xE2\x80\xA6";
}

/** Only http(s) addresses are allowed in meta tags. */
function seo_clean_url($u): string
{
    $u = trim((string)$u);
    return preg_match('#^https?://[^\s<>"\']+$#i', $u) ? $u : '';
}

/**
 * Canonical + Open Graph + Twitter tags (NOT <title> or meta description: header.php prints those).
 * Options: title, description, url (canonical), image, image_alt, type ('website'|'article'), robots ('noindex,follow'), locale.
 * Every value is escaped here; the returned string is safe to echo as it is.
 */
function seo_meta(array $o): string
{
    $title = (string)($o['title'] ?? SITE_NAME);
    $desc  = seo_trim((string)($o['description'] ?? ''), 200);
    $url   = seo_clean_url($o['url'] ?? '');
    $img   = seo_clean_url($o['image'] ?? '');
    if ($img === '' && is_file(dirname(__DIR__) . '/assets/img/share.png')) { $img = url('assets/img/share.png'); }   // default share picture for WhatsApp, Facebook, X
    $type  = in_array(($o['type'] ?? 'website'), ['website', 'article'], true) ? ($o['type'] ?? 'website') : 'website';
    $loc   = ['en' => 'en_US', 'fr' => 'fr_FR', 'ar' => 'ar_AR', 'sw' => 'sw_KE'];
    $lc    = function_exists('lang_code') ? lang_code() : 'en';
    $locale = (string)($o['locale'] ?? ($loc[$lc] ?? 'en_US'));

    $h = '';
    if ($url !== '') {
        $h .= '<link rel="canonical" href="' . e($url) . '">' . "\n";
    }
    if (!empty($o['robots']) && preg_match('/^[a-z, ]{3,40}$/', (string)$o['robots'])) {
        $h .= '<meta name="robots" content="' . e($o['robots']) . '">' . "\n";
    }
    $h .= '<meta property="og:site_name" content="' . e(SITE_NAME) . '">' . "\n";
    $h .= '<meta property="og:type" content="' . e($type) . '">' . "\n";
    $h .= '<meta property="og:title" content="' . e($title) . '">' . "\n";
    if ($desc !== '') {
        $h .= '<meta property="og:description" content="' . e($desc) . '">' . "\n";
    }
    if ($url !== '') {
        $h .= '<meta property="og:url" content="' . e($url) . '">' . "\n";
    }
    $h .= '<meta property="og:locale" content="' . e($locale) . '">' . "\n";
    if ($img !== '') {
        $h .= '<meta property="og:image" content="' . e($img) . '">' . "\n";
        if (!empty($o['image_alt'])) {
            $h .= '<meta property="og:image:alt" content="' . e($o['image_alt']) . '">' . "\n";
        }
    }
    $h .= '<meta name="twitter:card" content="' . ($img !== '' ? 'summary_large_image' : 'summary') . '">' . "\n";
    $h .= '<meta name="twitter:title" content="' . e($title) . '">' . "\n";
    if ($desc !== '') {
        $h .= '<meta name="twitter:description" content="' . e($desc) . '">' . "\n";
    }
    if ($img !== '') {
        $h .= '<meta name="twitter:image" content="' . e($img) . '">' . "\n";
    }
    return $h;
}

/** One JSON-LD <script> block. HEX flags stop "</script>" or quotes in names from breaking out of the tag. */
function seo_jsonld(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($json === false) {
        return '';
    }
    return '<script type="application/ld+json">' . $json . '</script>' . "\n";
}

/** Organization + WebSite JSON-LD for the home page. Echo it inside $head_extra. */
function seo_site_jsonld(): string
{
    $home = url();
    return seo_jsonld([
        '@context' => 'https://schema.org',
        '@graph'   => [
            ['@type' => 'Organization', '@id' => $home . '#org', 'name' => SITE_NAME, 'url' => $home],
            ['@type' => 'WebSite', '@id' => $home . '#site', 'name' => SITE_NAME, 'url' => $home,
             'inLanguage' => function_exists('lang_code') ? lang_code() : 'en', 'publisher' => ['@id' => $home . '#org']],
        ],
    ]);
}

/** BreadcrumbList JSON-LD. $items = [[name, absoluteUrl], ...] in order; the last one may omit the URL. */
function seo_breadcrumb_jsonld(array $items): string
{
    $list = [];
    $i = 1;
    foreach ($items as $it) {
        $row = ['@type' => 'ListItem', 'position' => $i++, 'name' => (string)$it[0]];
        if (!empty($it[1])) {
            $row['item'] = (string)$it[1];
        }
        $list[] = $row;
    }
    return seo_jsonld(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list]);
}

/* ---------- data ---------- */

/** A match with league and sport, or null. */
function seo_match(int $id): ?array
{
    $st = db()->prepare('SELECT m.*, l.name AS league, l.id AS league_id, s.name AS sport, s.slug AS sport_slug
                         FROM matches m JOIN leagues l ON l.id = m.league_id JOIN sports s ON s.id = l.sport_id
                         WHERE m.id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function seo_league(int $id): ?array
{
    $st = db()->prepare('SELECT l.id, l.name, s.name AS sport, s.slug AS sport_slug
                         FROM leagues l JOIN sports s ON s.id = l.sport_id WHERE l.id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function seo_sport(string $slug): ?array
{
    $st = db()->prepare('SELECT id, name, slug FROM sports WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

/**
 * Tips (same columns as fetch_tips) for a match, league or sport.
 * Options: match_id, league_id, sport_id, when ('upcoming' | 'recent' | 'all'), limit.
 * 'upcoming' = kickoff not yet passed; 'recent' = settled (won/lost/void). Deleted tips are hidden,
 * except settled won/lost tips, exactly like fetch_tip(), so the record cannot be erased.
 */
function seo_tips_query(array $o): array
{
    $sql = "SELECT t.*, m.home_team, m.away_team, m.kickoff_at, l.name AS league, s.name AS sport, s.slug AS sport_slug
            FROM tips t JOIN matches m ON m.id = t.match_id JOIN leagues l ON l.id = m.league_id JOIN sports s ON s.id = l.sport_id
            WHERE (t.deleted_at IS NULL OR t.status IN ('won','lost')) AND t.tipster_id IS NULL";
    $args = [];
    foreach (['match_id' => 'm.id', 'league_id' => 'l.id', 'sport_id' => 's.id'] as $k => $col) {
        if (!empty($o[$k])) {
            $sql .= " AND $col = ?";
            $args[] = (int)$o[$k];
        }
    }
    $when = $o['when'] ?? 'all';
    if ($when === 'upcoming') {
        $sql .= " AND m.kickoff_at >= NOW() AND t.status = 'pending'";
    } elseif ($when === 'recent') {
        $sql .= " AND t.status IN ('won','lost','void')";
    }
    $sql .= ($when === 'recent') ? ' ORDER BY m.kickoff_at DESC, t.id DESC' : ' ORDER BY m.kickoff_at ASC, t.id ASC';
    $sql .= ' LIMIT ' . max(1, min(200, (int)($o['limit'] ?? 50)));
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Last $n settled tips (won/lost) on matches involving a team, excluding one match. Returns ['won','lost',...] newest first. */
function seo_team_form(string $team, int $excludeMatchId, int $n = 5): array
{
    $st = db()->prepare("SELECT t.status FROM tips t JOIN matches m ON m.id = t.match_id
                         WHERE t.status IN ('won','lost') AND t.tipster_id IS NULL AND m.id <> ? AND (m.home_team = ? OR m.away_team = ?)
                         ORDER BY m.kickoff_at DESC, t.id DESC LIMIT " . max(1, min(10, $n)));
    $st->execute([$excludeMatchId, $team, $team]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** Win/loss record of settled tips for a league (optionally the last $days days; deleted tips count). */
function seo_league_record(int $leagueId, ?int $days = null): array
{
    $sql = "SELECT SUM(t.status = 'won') AS w, SUM(t.status = 'lost') AS l
            FROM tips t JOIN matches m ON m.id = t.match_id
            WHERE m.league_id = ? AND t.status IN ('won','lost') AND t.tipster_id IS NULL";
    if ($days) {
        $sql .= ' AND m.kickoff_at >= NOW() - INTERVAL ' . (int)$days . ' DAY';
    }
    $st = db()->prepare($sql);
    $st->execute([$leagueId]);
    $r = $st->fetch();
    $w = (int)$r['w'];
    $l = (int)$r['l'];
    return ['won' => $w, 'lost' => $l, 'settled' => $w + $l, 'win_rate' => ($w + $l) ? (int)round($w / ($w + $l) * 100) : null];
}

/**
 * Extra sitemap entries: [absolute URL, lastmod Y-m-d, priority], same shape sitemap.php uses.
 * Matches with a visible tip from 60 days ago to 14 days ahead, plus leagues that have such matches.
 */
function sitemap_extra_urls(): array
{
    $out = [];
    try {
        $rows = db()->query("SELECT m.id, m.kickoff_at, MAX(GREATEST(t.posted_at, COALESCE(t.settled_at, t.posted_at))) AS lm
                             FROM matches m JOIN tips t ON t.match_id = m.id AND t.tipster_id IS NULL AND (t.deleted_at IS NULL OR t.status IN ('won','lost'))
                             WHERE m.kickoff_at BETWEEN NOW() - INTERVAL 60 DAY AND NOW() + INTERVAL 14 DAY
                             GROUP BY m.id, m.kickoff_at ORDER BY m.kickoff_at DESC LIMIT 2000")->fetchAll();
        foreach ($rows as $r) {
            $out[] = [url('match.php?id=' . (int)$r['id']), date('Y-m-d', strtotime($r['lm'] ?: $r['kickoff_at'])), 0.5];
        }
        $rows = db()->query("SELECT l.id, MAX(m.kickoff_at) AS lm
                             FROM leagues l JOIN matches m ON m.league_id = l.id JOIN tips t ON t.match_id = m.id AND t.tipster_id IS NULL AND (t.deleted_at IS NULL OR t.status IN ('won','lost'))
                             WHERE m.kickoff_at >= NOW() - INTERVAL 60 DAY GROUP BY l.id ORDER BY lm DESC LIMIT 500")->fetchAll();
        foreach ($rows as $r) {
            $out[] = [url('league.php?id=' . (int)$r['id']), date('Y-m-d', strtotime(min(strtotime($r['lm']), time()))), 0.6];
        }
        $out[] = [url('leaderboard.php'), date('Y-m-d'), 0.6];
        $out[] = [url('jackpot.php'), date('Y-m-d'), 0.6];
        $out[] = [url('jackpot-results.php'), date('Y-m-d'), 0.5];
        $out[] = [url('replays.php'), date('Y-m-d'), 0.5];
        $out[] = [url('livestream.php'), date('Y-m-d'), 0.6];
        if (function_exists('stats_on') && stats_on()) {
            $out[] = [url('football.php'), date('Y-m-d'), 0.7];
            $out[] = [url('predictions.php'), date('Y-m-d'), 0.8];
            foreach (db()->query("SELECT code FROM stat_competitions WHERE enabled = 1 AND synced_at IS NOT NULL")->fetchAll() as $r) {
                $out[] = [url('league-table.php?c=' . rawurlencode($r['code'])), date('Y-m-d'), 0.7];
            }
            foreach (db()->query("SELECT id, kick_utc FROM stat_matches WHERE status IN ('SCHEDULED','TIMED') AND kick_utc BETWEEN UTC_TIMESTAMP() AND UTC_TIMESTAMP() + INTERVAL 7 DAY ORDER BY kick_utc LIMIT 400")->fetchAll() as $r) {
                $out[] = [url('fixture.php?id=' . (int)$r['id']), date('Y-m-d'), 0.5];
            }
            foreach (db()->query("SELECT DISTINCT comp_code, team_id FROM stat_standings WHERE type = 'TOTAL' LIMIT 400")->fetchAll() as $r) {
                $out[] = [url('team.php?c=' . rawurlencode($r['comp_code']) . '&id=' . (int)$r['team_id']), date('Y-m-d'), 0.4];
            }
        }
        $out[] = [url('audit.php'), date('Y-m-d'), 0.3];
        $rows = db()->query("SELECT slug FROM tipsters WHERE status = 'active'" . tipster_live_sql('tipsters') . " LIMIT 500")->fetchAll();
        foreach ($rows as $r) {
            $out[] = [url('tipster.php?slug=' . rawurlencode($r['slug'])), date('Y-m-d'), 0.5];
        }
    } catch (Throwable $e) {
        error_log('sitemap_extra_urls failed: ' . $e->getMessage());
    }
    return $out;
}

/** Renders a W/L form strip using the site's .form styles. */
function seo_form_strip(array $form): string
{
    if (!$form) {
        return '<span class="mp-none">No settled tips yet</span>';
    }
    $h = '<span class="form">';
    foreach ($form as $s) {
        $h .= '<i class="' . ($s === 'won' ? 'w' : 'l') . '" title="' . ($s === 'won' ? 'Won' : 'Lost') . '">' . ($s === 'won' ? 'W' : 'L') . '</i>';
    }
    return $h . '</span>';
}
