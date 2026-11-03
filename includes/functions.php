<?php
/** Small helpers used across the site. */

/** Escape text for safe output in HTML. Use on EVERYTHING that came from the database or a user. */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    if (!empty($GLOBALS['SK_API_JSON'])) {          // the native app asked: answer with JSON (the last flash message) instead of redirecting
        $err = ''; $msg = '';
        foreach (get_flash() as $x) { if ($x['type'] === 'error') { $err = $x['msg']; } else { $msg = $x['msg']; } }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => $err === '', 'error' => $err, 'message' => $msg]);
        exit;
    }
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function get_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function money_kes($amount): string
{
    return 'KES ' . number_format((float)$amount);
}

function stars(int $n): string
{
    $n = max(0, min(5, $n));
    return str_repeat('&#9733;', $n) . str_repeat('&#9734;', 5 - $n);
}

function team_logo(string $name): string
{
    return 'https://ui-avatars.com/api/?name=' . rawurlencode(mb_substr($name, 0, 3)) . '&background=155240&color=fff&bold=true&size=88';
}

/** "Today 20:00", "Tomorrow 15:00", or "Sat 12 Oct 15:00". */
/** "3 h", "45 min", "2 days": a short span for the "posted before kickoff" proof line. */
function span_label(int $minutes): string
{
    if ($minutes < 60) { return max(1, $minutes) . ' min'; }
    if ($minutes < 2880) { return round($minutes / 60) . ' h'; }
    return round($minutes / 1440) . ' days';
}

/** Proof that a tip was public before the match started: ['early' => bool, 'minutes' => minutes between posting and kickoff]. */
function tip_proof(array $t): array
{
    $m = (int)round((strtotime((string)$t['kickoff_at']) - strtotime((string)($t['posted_at'] ?? $t['kickoff_at']))) / 60);
    return ['early' => $m > 0, 'minutes' => abs($m)];
}

function kickoff_label(string $datetime): string
{
    $ts = strtotime($datetime);
    $day = date('Y-m-d', $ts);
    if ($day === date('Y-m-d')) {
        return 'Today ' . date('H:i', $ts);
    }
    if ($day === date('Y-m-d', strtotime('+1 day'))) {
        return 'Tomorrow ' . date('H:i', $ts);
    }
    if ($day === date('Y-m-d', strtotime('-1 day'))) {
        return 'Yesterday ' . date('H:i', $ts);
    }
    return date('D j M H:i', $ts);
}

function plan_period(int $days): string
{
    if ($days === 7) return 'week';
    if ($days === 30) return 'month';
    if ($days === 90) return '3 months';
    if ($days === 365) return 'year';
    return $days . ' days';
}

/**
 * Fetch tips with their match, league and sport.
 * Options: vip (0|1), today (bool), date (Y-m-d), sport (slug), market, limit (int), order ('recent' = newest first)
 * Deleted tips are never shown.
 */
function fetch_tips(array $o = []): array
{
    $sql = 'SELECT t.*, m.home_team, m.away_team, m.kickoff_at, m.live_status, m.live_minute, m.home_score, m.away_score, ' . (stream_col() ? 'm.stream_url, ' : '') . (stream2_col() ? 'm.stream_url2, ' : '') . (extra_col('matches') ? 'm.stream_extra, ' : '') . (thumb_col('matches') ? 'm.thumb, ' : '') . 'l.name AS league, s.name AS sport, s.slug AS sport_slug, ti.display_name AS tipster_name, ti.slug AS tipster_slug, ti.avatar_url AS tipster_avatar, au.display_name AS author_name, au.slug AS author_slug
            FROM tips t
            JOIN matches m ON m.id = t.match_id
            JOIN leagues l ON l.id = m.league_id
            JOIN sports s ON s.id = l.sport_id
            LEFT JOIN tipsters ti ON ti.id = t.tipster_id
            LEFT JOIN tipsters au ON au.id = t.author_id
            WHERE t.deleted_at IS NULL';
    $args = [];
    // Tipster tips never mix into the site's own tips. Pass 'tipster' => id to list one tipster's tips.
    if (!empty($o['tipster'])) {
        $sql .= ' AND t.tipster_id = ?';
        $args[] = (int)$o['tipster'];
    } else {
        $sql .= ' AND t.tipster_id IS NULL';
    }
    if (isset($o['vip'])) {
        $sql .= ' AND t.is_vip = ?';
        $args[] = (int)$o['vip'];
    }
    if (!empty($o['today'])) {
        $sql .= ' AND DATE(m.kickoff_at) = CURDATE()';
    }
    if (!empty($o['sport'])) {
        $sql .= ' AND s.slug = ?';
        $args[] = $o['sport'];
    }
    if (!empty($o['date'])) {
        $sql .= ' AND DATE(m.kickoff_at) = ?';
        $args[] = $o['date'];
    }
    if (!empty($o['market'])) {
        $sql .= ' AND t.market = ?';
        $args[] = $o['market'];
    }
    $sql .= (($o['order'] ?? '') === 'recent') ? ' ORDER BY m.kickoff_at DESC' : ' ORDER BY m.kickoff_at ASC';
    $sql .= ' LIMIT ' . max(1, (int)($o['limit'] ?? 50));

    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * Track-record numbers for the last 30 days.
 * Deleted tips still count here, so the record cannot be cleaned up after the fact.
 */
function site_stats(): array
{
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    $pdo = db();
    $r = $pdo->query("SELECT SUM(status = 'won') AS w, COUNT(*) AS n, AVG(odds) AS ao
                      FROM tips
                      WHERE status IN ('won','lost') AND tipster_id IS NULL AND posted_at >= NOW() - INTERVAL 30 DAY")->fetch();
    $n = (int)$r['n'];
    $w = (int)$r['w'];
    $s = [
        'settled'    => $n,
        'win_rate'   => $n ? (int)round($w / $n * 100) : null,
        'avg_odds'   => $r['ao'] ? number_format((float)$r['ao'], 2) : '-',
        'tips_today' => (int)$pdo->query("SELECT COUNT(*) FROM tips t JOIN matches m ON m.id = t.match_id
                                          WHERE t.deleted_at IS NULL AND t.tipster_id IS NULL AND DATE(m.kickoff_at) = CURDATE()")->fetchColumn(),
    ];
    return $s;
}

/** Last N settled results as ['won','lost',...], newest first. */
function recent_form(int $n = 8): array
{
    $st = db()->query("SELECT status FROM tips WHERE status IN ('won','lost') AND tipster_id IS NULL ORDER BY settled_at DESC, id DESC LIMIT " . (int)$n);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** Win rate per month for the last 6 months: [['label'=>'Sep','rate'=>74], ...] */
function monthly_rates(): array
{
    $rows = db()->query("SELECT DATE_FORMAT(posted_at, '%Y-%m') AS ym, DATE_FORMAT(MIN(posted_at), '%b') AS label,
                                ROUND(100 * SUM(status = 'won') / COUNT(*)) AS rate, COUNT(*) AS n, SUM(status = 'won') AS w
                         FROM tips
                         WHERE status IN ('won','lost') AND tipster_id IS NULL
                           AND posted_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
                         GROUP BY ym ORDER BY ym")->fetchAll();
    return $rows;
}

function upcoming_matches(int $n = 4): array
{
    $st = db()->query('SELECT home_team, away_team, kickoff_at FROM matches WHERE kickoff_at >= NOW() AND EXISTS (SELECT 1 FROM tips x WHERE x.match_id = matches.id AND x.tipster_id IS NULL AND x.deleted_at IS NULL) ORDER BY kickoff_at ASC LIMIT ' . (int)$n);
    return $st->fetchAll();
}

/** One tip by id. Settled tips stay reachable even if deleted, so the record cannot be erased. */
function fetch_tip(int $id): ?array
{
    $st = db()->prepare("SELECT t.*, m.home_team, m.away_team, m.kickoff_at, m.live_status, m.live_minute, m.home_score, m.away_score, " . (stream_col() ? 'm.stream_url, ' : '') . (stream2_col() ? 'm.stream_url2, ' : '') . (extra_col('matches') ? 'm.stream_extra, ' : '') . (thumb_col('matches') ? 'm.thumb, ' : '') . "l.name AS league, s.name AS sport, s.slug AS sport_slug, ti.display_name AS tipster_name, ti.slug AS tipster_slug, ti.avatar_url AS tipster_avatar, au.display_name AS author_name, au.slug AS author_slug
            FROM tips t
            JOIN matches m ON m.id = t.match_id
            JOIN leagues l ON l.id = m.league_id
            JOIN sports s ON s.id = l.sport_id
            LEFT JOIN tipsters ti ON ti.id = t.tipster_id
            LEFT JOIN tipsters au ON au.id = t.author_id
            WHERE t.id = ? AND (t.deleted_at IS NULL OR t.status IN ('won','lost'))
            LIMIT 1");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Win/loss record. $days = null for all time. Profit is in units: 1 unit staked per tip,
 * a win returns (odds - 1), a loss costs 1, void is 0. Deleted tips are included.
 */
function record_stats(?int $days = null): array
{
    $sql = "SELECT SUM(status = 'won') AS w, SUM(status = 'lost') AS l, SUM(status = 'void') AS v,
                   AVG(CASE WHEN status IN ('won','lost') THEN odds END) AS ao,
                   SUM(CASE WHEN status = 'won' THEN odds - 1 WHEN status = 'lost' THEN -1 ELSE 0 END) AS units
            FROM tips WHERE status IN ('won','lost','void') AND tipster_id IS NULL";
    if ($days) {
        $sql .= ' AND posted_at >= NOW() - INTERVAL ' . (int)$days . ' DAY';
    }
    $r = db()->query($sql)->fetch();
    $w = (int)$r['w'];
    $l = (int)$r['l'];
    $n = $w + $l;
    $units = round((float)$r['units'], 1);
    return [
        'won' => $w, 'lost' => $l, 'void' => (int)$r['v'], 'settled' => $n,
        'win_rate' => $n ? (int)round($w / $n * 100) : null,
        'avg_odds' => $r['ao'] ? number_format((float)$r['ao'], 2) : '-',
        'units' => ($units > 0 ? '+' : '') . number_format($units, 1),
    ];
}

/** Current page address with some query values changed (null removes one). Escape with e() when printing. */
function qs_url(string $file, array $override = []): string
{
    $q = array_merge($_GET, $override);
    $q = array_filter($q, function ($v) { return $v !== null && $v !== ''; });
    $s = http_build_query($q);
    return url($file) . ($s ? '?' . $s : '');
}

function pager(int $page, int $pages, string $file): string
{
    if ($pages < 2) {
        return '';
    }
    $h = '<nav class="pager" aria-label="Pages">';
    if ($page > 1) {
        $h .= '<a href="' . e(qs_url($file, ['page' => $page - 1])) . '">Previous</a>';
    }
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
        $h .= '<a href="' . e(qs_url($file, ['page' => $i])) . '"' . ($i === $page ? ' class="on" aria-current="page"' : '') . '>' . $i . '</a>';
    }
    if ($page < $pages) {
        $h .= '<a href="' . e(qs_url($file, ['page' => $page + 1])) . '">Next</a>';
    }
    return $h . '</nav>';
}

/* ---------- forms, input and security helpers ---------- */

function post_str(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

/** Only allow redirects to pages inside this site (blocks "next=http://evil.com"). */
function safe_next($next): string
{
    $next = (string)$next;
    return preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*\.php(\?[A-Za-z0-9_=&%\-]*)?$#', $next) ? $next : '';
}

/** Kenyan number -> 2547XXXXXXXX or 2541XXXXXXXX. Returns null if it is not valid. */
function normalize_phone(string $raw): ?string
{
    $p = preg_replace('/[^\d]/', '', $raw);
    if (preg_match('/^0([17]\d{8})$/', $p, $m)) {
        $p = '254' . $m[1];
    } elseif (preg_match('/^([17]\d{8})$/', $p, $m)) {
        $p = '254' . $m[1];
    }
    return preg_match('/^254[17]\d{8}$/', $p) ? $p : null;
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function too_many_attempts(int $max = 8): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > NOW() - INTERVAL 15 MINUTE');
    $st->execute([client_ip()]);
    return (int)$st->fetchColumn() >= $max;
}

function record_attempt(?string $email = null): void
{
    $st = db()->prepare('INSERT INTO login_attempts (ip, email) VALUES (?, ?)');
    $st->execute([client_ip(), $email ? substr($email, 0, 150) : null]);
    if (random_int(1, 50) === 1) {
        db()->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
    }
}

function clear_attempts(): void
{
    $st = db()->prepare('DELETE FROM login_attempts WHERE ip = ?');
    $st->execute([client_ip()]);
}

/** Secret used to sign email-verification links. Set APP_SECRET in config.php to your own long random text if you like. */
function app_secret(): string
{
    return defined('APP_SECRET') ? APP_SECRET : hash('sha256', DB_NAME . '|' . DB_USER . '|' . DB_PASS . '|slipking');
}

function verify_sig(int $userId, int $exp): string
{
    return hash_hmac('sha256', 'verify|' . $userId . '|' . $exp, app_secret());
}

function verify_link(int $userId): string
{
    $exp = time() + 3 * 86400;
    return url('auth/verify-email.php?u=' . $userId . '&e=' . $exp . '&s=' . verify_sig($userId, $exp));
}

function log_in_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    if (function_exists('fraud_record')) { fraud_record($userId); }
    if (function_exists('traffic_log_login')) { traffic_log_login($userId); }
    if (function_exists('acct_on_login')) { acct_on_login($userId); }
}

/* ---------- admin helpers ---------- */

/** Writes one line to the admin activity log. Never breaks the page if the table is missing. */
function admin_log(string $action, string $target = '', string $details = ''): void
{
    if (function_exists('cache_flush')) { cache_flush(); }   // any admin change shows on the public pages immediately
    try {
        $u = current_user();
        db()->prepare('INSERT INTO admin_log (admin_id, action, target, details, ip) VALUES (?, ?, ?, ?, ?)')
            ->execute([$u['id'] ?? null, substr($action, 0, 40), substr($target, 0, 60), substr($details, 0, 500), client_ip()]);
    } catch (Throwable $e) {
        error_log('admin_log failed: ' . $e->getMessage());
    }
}

/** A tip can only be changed (or deleted) while it is still pending AND its match has not started. */
function tip_is_locked(array $tip): bool
{
    return $tip['status'] !== 'pending' || strtotime($tip['kickoff_at']) <= time();
}

function slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    return $s !== '' ? substr($s, 0, 60) : 'item';
}

/** Returns $base, or $base-2, $base-3 ... whichever is not used yet. $table must be one of the fixed names below. */
function unique_slug(string $table, string $base, ?int $exceptId = null): string
{
    if (!in_array($table, ['posts', 'sports', 'plans'], true)) {
        throw new InvalidArgumentException('bad table');
    }
    $slug = $base;
    for ($i = 2; $i < 200; $i++) {
        $st = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE slug = ? AND id <> ?");
        $st->execute([$slug, (int)$exceptId]);
        if ((int)$st->fetchColumn() === 0) {
            return $slug;
        }
        $slug = substr($base, 0, 55) . '-' . $i;
    }
    return $base . '-' . bin2hex(random_bytes(2));
}

/** Each in-app notification can remember the page it points to (so the inbox can offer "Reply" / "Open"). */
function notif_path_col(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try {
        if (setting('notif_path_col', '0') === '1') { return $ok = true; }
        $ok = sk_ensure_column('notifications', 'path', "VARCHAR(250) NOT NULL DEFAULT ''");
        if ($ok) { set_setting('notif_path_col', '1'); }
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

function notify_user(int $userId, string $title, string $message, string $path = '', bool $phonePush = true): void
{
    if (notif_path_col()) {
        db()->prepare('INSERT INTO notifications (user_id, title, message, path) VALUES (?, ?, ?, ?)')
            ->execute([$userId, substr($title, 0, 150), substr($message, 0, 2000), substr(ltrim($path, '/'), 0, 250)]);
    } else {
        db()->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)')
            ->execute([$userId, substr($title, 0, 150), substr($message, 0, 2000)]);
    }
    if ($phonePush && function_exists('fcm_queue')) { fcm_queue($userId, $title, $message, $path); }   // Android app (Firebase)
}

/* ---------- safety: never let people hand us passwords ---------- */

/** True if a message looks like it contains a password, PIN or one-time code. */
function looks_like_secret(string $s): bool
{
    return (bool)preg_match('/\b(pass(word)?|passcode|pwd|pin|otp|login\s*details)\b\s*(is|are|was|:|=|-)\s*\S+/i', $s);
}

/** Counts shown as badges in the admin menu. Never breaks a page if a table is missing. */
function admin_counts(): array
{
    $out = ['managed' => 0, 'chat' => 0, 'tipsters' => 0, 'payouts' => 0];
    try {
        $out['managed'] = (int)db()->query("SELECT COUNT(*) FROM managed_requests WHERE status = 'new'")->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $out['chat'] = (int)db()->query("SELECT COUNT(*) FROM chat_threads WHERE status = 'open' AND unread_admin > 0")->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $out['tipsters'] = (int)db()->query("SELECT COUNT(*) FROM tipsters WHERE status = 'pending'")->fetchColumn();
        $out['payouts']  = (int)db()->query("SELECT COUNT(*) FROM tipster_payouts WHERE status = 'requested'")->fetchColumn();
    } catch (Throwable $e) {}
    return $out;
}
