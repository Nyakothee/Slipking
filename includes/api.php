<?php
/**
 * Helpers for the JSON API used by the standalone SlipKing app (api/v1/index.php).
 * The app is "just another browser": it keeps the normal PHP session cookie, so every rule of the website
 * (VIP locking, limits, staff roles) is applied here by the same functions. Locked picks never leave the server.
 */

function api_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data + ['ok' => $code < 400], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/**
 * Every error has the same shape: {"ok": false, "error": "<message to show the member>", "code": "<short slug>"}
 * (plus optional extras such as "login": true). The app shows "error" as it is.
 */
function api_fail(string $message, int $code = 400, array $extra = []): void
{
    $slugs = [400 => 'bad_request', 401 => 'login_required', 402 => 'payment_required', 403 => 'not_allowed', 404 => 'not_found', 405 => 'wrong_method', 409 => 'conflict', 422 => 'invalid', 429 => 'rate_limited', 500 => 'server_error', 503 => 'unavailable'];
    api_out(['error' => $message, 'code' => $slugs[$code] ?? ($code >= 500 ? 'server_error' : 'error')] + $extra, $code);
}

/** POST requests must carry the app header and user agent (a web page on another site cannot add a custom header). */
function api_require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_fail('That action must be sent from the app. Please update the app and try again.', 405); }
    if (($_SERVER['HTTP_X_SLIPKING_APP'] ?? '') !== '1' || !is_app()) { api_fail('This request was not accepted. Please update the SlipKing app and try again.', 403); }
}

function api_require_login(): array
{
    $u = current_user();
    if (!$u) { api_fail('Please log in.', 401, ['login' => true]); }
    return $u;
}

function api_input(string $key, int $max = 500): string
{
    return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max);
}

function api_when(string $dt): array
{
    return ['iso' => date('c', strtotime($dt)), 'label' => kickoff_label($dt)];
}

/**
 * One tip as the app sees it. A pending VIP tip the member cannot access has NO pick, odds, analysis or market
 * (same rule as the website's tip card, enforced here on the server).
 */
function api_tip(array $t, bool $detail = false): array
{
    $vip = !empty($t['is_vip']);
    $locked = $vip && $t['status'] === 'pending' && !tip_access($t);
    $out = [
        'id' => (int)$t['id'],
        'home' => $t['home_team'], 'away' => $t['away_team'],
        'home_logo' => team_logo($t['home_team']), 'away_logo' => team_logo($t['away_team']),
        'league' => $t['league'], 'sport' => $t['sport'], 'sport_slug' => $t['sport_slug'] ?? '',
        'kickoff' => api_when($t['kickoff_at']),
        'status' => $t['status'], 'vip' => $vip, 'premium' => !empty($t['tipster_slug']),
        'locked' => $locked,
        'confidence' => (int)$t['confidence'],
        'market' => $locked ? '' : (string)$t['market'],
        'pick' => $locked ? '' : (string)$t['pick'],
        'odds' => $locked ? null : round((float)$t['odds'], 2),
        'by' => !empty($t['tipster_name']) ? $t['tipster_name'] : '',
        'tipster_slug' => (string)($t['tipster_slug'] ?? ''),
        'proof' => isset($t['posted_at']) ? tip_proof($t) : null,
        'stream_url' => stream_pick($t),
        'watch_extra' => ($locked || in_array(stream_state_of($t), ['soon', 'ended'], true)) ? [] : array_map(fn($x) => ['label' => $x['label'], 'watch' => $x['app']], stream_extra_list($t)),
        'watch_url' => $locked ? '' : stream_app_url($t),
        'thumb' => $locked ? '' : thumb_src((string)($t['thumb'] ?? '')),
        'phase' => match_phase($t),
        'stream_state' => stream_pick($t) === '' ? '' : stream_state_of($t),
        'home_score' => $t['home_score'] === null || !isset($t['home_score']) ? null : (int)$t['home_score'], 'away_score' => !isset($t['away_score']) || $t['away_score'] === null ? null : (int)$t['away_score'], 'minute' => isset($t['live_minute']) && $t['live_minute'] !== null ? (int)$t['live_minute'] : null,
        'form_home' => implode('', team_form((string)$t['home_team'])), 'form_away' => implode('', team_form((string)$t['away_team'])),
        'progress' => $locked ? null : tip_progress($t),
        'stats' => ($locked || match_phase($t) !== 'live') ? null : match_stats_get((int)$t['match_id']),
        'reactions' => (function_exists('tip_reactions_of') && !$locked) ? tip_reactions_of((int)$t['id'], (function_exists('current_user') && ($cu = current_user())) ? (int)$cu['id'] : 0) : ['counts' => (object)[], 'mine' => ''],
        'image_url' => url('tip-image.php?id=' . (int)$t['id']),
        'tipster_avatar' => !empty($t['tipster_avatar']) ? api_media((string)$t['tipster_avatar']) : '',
    ];
    if ($detail) {
        $out['analysis'] = $locked ? '' : (string)($t['analysis'] ?? '');
        $out['author'] = (string)($t['author_name'] ?? '');
        $out['settled_at'] = !empty($t['settled_at']) ? api_when($t['settled_at']) : null;
    }
    return $out;
}

function api_user(array $u): array
{
    $st = db()->prepare("SELECT s.ends_at, p.name FROM subscriptions s JOIN plans p ON p.id = s.plan_id
                         WHERE s.user_id = ? AND s.status = 'active' AND s.ends_at > NOW() ORDER BY s.ends_at DESC LIMIT 1");
    $st->execute([(int)$u['id']]);
    $plan = $st->fetch() ?: null;
    $n = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $n->execute([(int)$u['id']]);
    $tp = null;
    if (function_exists('tipster_for_user')) {
        $t = tipster_for_user((int)$u['id']);
        if ($t) {
            $owed = tipster_fee_owed($t);
            $tp = ['status' => $t['status'], 'fee_owed' => $owed, 'fee' => $owed ? tipster_fee_amount($u)['label'] : '',
                   'pay_url' => $owed ? tipster_fee_pay_url() : '', 'live' => tipster_is_live($t)];
        }
    }
    return [
        'tipster' => $tp,
        'id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'],
        'staff' => function_exists('is_staff') ? is_staff() : false,
        'vip' => is_vip(),
        'plan' => $plan ? ['name' => $plan['name'], 'ends' => api_when($plan['ends_at'])] : null,
        'email_verified' => !empty($u['email_verified']),
        'unread' => (int)$n->fetchColumn(),
    ];
}

function api_sports(): array
{
    return db()->query('SELECT name, slug FROM sports ORDER BY id')->fetchAll();
}

function api_tipster(array $t): array
{
    $av = !empty($t['avatar_url']) ? media_src((string)$t['avatar_url']) : '';
    if ($av !== '' && !preg_match('#^https?://#i', $av)) { $av = url(ltrim($av, '/')); }
    return ['id' => (int)$t['id'], 'name' => $t['display_name'], 'slug' => $t['slug'], 'avatar' => $av,
            'rating' => round((float)$t['rating_avg'], 1), 'ratings' => (int)$t['rating_count'],
            'url' => url('tipster.php?slug=' . rawurlencode((string)$t['slug']))];
}

/** Where a message should lead when the member taps it in the app (notifications keep no link of their own). */
/** Address for one notification: the one saved with it, else a guess from the title. */
function api_notif_target(array $n): string
{
    $p = ltrim((string)($n['path'] ?? ''), '/');
    if ($p !== '' && !preg_match('#^(https?:)?//#i', $p) && strpos($p, '..') === false) {
        return strpos($p, 'account/messages.php') === 0 ? 'n:inbox' : (strpos($p, 'account/badges.php') === 0 ? 'n:game' : $p);
    }
    return api_notif_path((string)$n['title']);
}

function api_notif_path(string $title): string
{
    $t = strtolower($title);
    if (strpos($t, 'new message from') === 0) { return 'n:inbox'; }
    if (preg_match('/reply|comment|mention|post/', $t)) { return 'discuss.php'; }
    if (preg_match('/vip|trial|payment|plan|subscri|receipt/', $t)) { return 'n:vip'; }
    if (preg_match('/won|lost|result/', $t)) { return 'n:results'; }
    if (preg_match('/tip|slip|odds/', $t)) { return 'n:tips'; }
    return '';
}

function api_media(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') { return ''; }
    $src = media_src($path);
    return preg_match('#^https?://#i', $src) ? $src : url(ltrim($src, '/'));
}

function api_group(array $c): array
{
    return ['id' => (int)$c['id'], 'slug' => $c['slug'], 'name' => $c['name'], 'icon' => (string)($c['icon'] ?? ''), 'color' => (string)($c['color'] ?? '#10c58a'),
            'cover' => api_media($c['cover'] ?? ''), 'desc' => (string)($c['description'] ?? ''),
            'members' => (int)($c['joined_n'] ?? $c['members'] ?? 0), 'posts' => (int)($c['posts'] ?? 0),
            'joined' => !empty($c['joined']), 'private' => !empty($c['is_private'])];
}

/** A group post or reply for the app. */
function api_post(array $p, int $uid): array
{
    $staff = isset($p['role']) && $p['role'] !== 'user';
    return ['id' => (int)$p['id'], 'title' => (string)($p['title'] ?? ''), 'body' => (string)$p['body'], 'image' => api_media($p['image'] ?? ''),
            'name' => $staff ? 'Team' : mask_name((string)$p['name']), 'staff' => $staff, 'mine' => $uid && (int)$p['user_id'] === $uid, 'uid' => $staff ? 0 : (int)$p['user_id'],
            'when' => api_when($p['created_at']), 'score' => (int)($p['score'] ?? 0), 'myvote' => isset($p['myvote']) ? (int)$p['myvote'] : 0,
            'replies' => (int)($p['replies'] ?? 0), 'pinned' => !empty($p['pinned'])];
}
