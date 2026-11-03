<?php
/**
 * SlipKing JSON API v1 for the standalone Android app.  Usage: api/v1/?a=<action>
 * Read actions use GET, changing actions use POST (with the X-SlipKing-App: 1 header and the app user agent).
 * Sign-in uses the normal PHP session cookie, so the same login also works in the app's web screens (checkout).
 */
define('NO_TRANSLATE', true);
@ini_set('display_errors', '0');   // a PHP warning must never break the JSON the app reads
// Fatal errors (memory, parse problems) also answer in the normal error shape.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
        error_log('api v1 fatal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'The server had a problem. Please try again in a minute.', 'code' => 'server_error']);
    }
});
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once dirname(__DIR__, 2) . '/includes/api.php';
require_once dirname(__DIR__, 2) . '/includes/subscription.php';
require_once dirname(__DIR__, 2) . '/includes/limits.php';

try {
    $a = preg_replace('/[^a-z_]/', '', (string)($_GET['a'] ?? ''));
    // Weekly access fee (only when the admin switched it on): members whose time is up get a 402 and the app shows the pay screen.
    if (access_lock() && !is_logged_in() && !in_array($a, ['config', 'login', 'logout', 'me', 'register', 'forgot', 'access', 'crash_report'], true)) {
        api_fail('Please log in to use SlipKing.', 401, ['login' => true]);
    }
    if (access_on() && is_logged_in() && !access_ok() && !in_array($a, ['config', 'login', 'logout', 'me', 'access', 'register', 'forgot', 'push_token', 'crash_report'], true)) {
        api_fail('Your free week has ended. Pay for 7 more days to keep using SlipKing.', 402, ['access_due' => true, 'access' => access_api_info()]);
    }
    // Outdated app: the member must install the latest version (admin can switch this off on the app page).
    if (app_update_required() && !in_array($a, ['config', 'app_notice', 'crash_report'], true)) {
        api_fail('A new version of SlipKing is available. Please update to keep using the app.', 426, ['update' => app_update_info()]);
    }
    if (($offKey = page_off_for_action($a)) !== null) { api_fail('This section is not available right now.', 404, ['page_off' => $offKey]); }
    switch ($a) {

    /* ---------- app start ---------- */
    case 'config':
        $cs = [];
        foreach (countries() as $iso => $c) { $cs[] = ['iso' => $iso, 'name' => $c['name'], 'dial' => $c['dial']]; }
        api_out([
            'site' => SITE_NAME, 'base' => rtrim(SITE_URL, '/') . '/',
            'sports' => api_sports(), 'countries' => $cs, 'country' => country_default(),
            'push' => function_exists('fcm_ready') && fcm_ready(),
            'trial_days' => max(1, (int)setting('trial_days', '1')),
            'sms' => sms_enabled(),
            'access' => access_api_info(null),
            'tipster_fee' => ['on' => tipster_fee_on(), 'ke' => tipster_fee_for_country('KE')['label'], 'other' => tipster_fee_for_country('US')['label']],
            'api' => 1,
            'update' => app_update_info(), 'pages_off' => pages_off_list(),
        ]);

    case 'channels':
        $out = [];
        foreach (channels_fetch(trim((string)($_GET['q'] ?? '')), trim((string)($_GET['cat'] ?? ''))) as $c) {
            $bs = [];
            foreach (channel_buttons($c) as $b) { $bs[] = ['label' => $b['label'], 'watch' => $b['app']]; }
            if ($bs) { $out[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'category' => $c['category'], 'thumb' => thumb_src((string)$c['thumb']), 'buttons' => $bs]; }
        }
        api_out(['items' => $out, 'categories' => channel_categories()]);

    /* ---------- account ---------- */
    case 'login':
        api_require_post();
        $email = strtolower(api_input('email', 150));
        $pw = (string)($_POST['password'] ?? '');
        if (too_many_attempts(8)) { api_fail('Too many attempts. Please wait 15 minutes and try again.', 429); }
        $st = db()->prepare('SELECT id, password_hash FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();
        $hash = $u ? $u['password_hash'] : password_hash('not-a-real-password', PASSWORD_DEFAULT);
        if (!(password_verify($pw, $hash) && $u)) {
            record_attempt($email);
            api_fail('The email or password is not correct.', 401);
        }
        clear_attempts();
        if (($banWhy = acct_ban((int)$u['id'])) !== null) { api_fail('Your account is suspended: ' . $banWhy . ' To appeal, open ' . url('auth/appeal.php?e=' . rawurlencode($email)), 403); }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
        }
        log_in_user((int)$u['id']);
        $_SESSION['user_id'] = (int)$u['id'];
        api_out(['logged_in' => true]);      // the app now calls ?a=me for the full profile

    case 'register':
        api_require_post();
        $name = api_input('name', 100);
        $email = strtolower(api_input('email', 150));
        $country = strtoupper(api_input('country', 4));
        $mobileIn = api_input('mobile', 30);
        $pw = (string)($_POST['password'] ?? '');
        $ref = preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['ref'] ?? ''));
        $errors = [];
        if (too_many_attempts(10)) { api_fail('Too many attempts. Please wait 15 minutes and try again.', 429); }
        record_attempt($email);
        if (mb_strlen($name) < 2) $errors[] = 'Enter your name (2 to 100 characters).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) $errors[] = 'Enter a valid email address.';
        if (!isset(countries()[$country])) $country = '';
        $phone = null; $mobile = '';
        $pp = phone_parse($country, $mobileIn);
        if (!$pp['ok']) { $errors[] = $pp['error']; } else { $mobile = $pp['mobile']; if ($country === 'KE') { $phone = $mobile; } }
        if (strlen($pw) < 8) $errors[] = 'Your password must be at least 8 characters.';
        if (isset($_POST['password2']) && $pw !== (string)$_POST['password2']) $errors[] = 'The two passwords do not match.';
        if (empty($_POST['age'])) $errors[] = 'You must confirm that you are 18 or older.';
        if (empty($_POST['terms'])) $errors[] = 'Please accept the terms.';
        if (!$errors) {
            $st = db()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
            $st->execute([$email]);
            if ($st->fetchColumn()) $errors[] = 'An account with this email already exists. Try logging in.';
        }
        if (!$errors && sms_enabled()) {
            $st = db()->prepare('SELECT 1 FROM users WHERE mobile = ? AND mobile_verified = 1 LIMIT 1');
            $st->execute([$mobile]);
            if ($st->fetchColumn()) $errors[] = 'This mobile number is already confirmed on another account. Try logging in.';
        }
        if ($errors) { api_fail($errors[0], 422, ['errors' => $errors]); }
        $referrerId = null;
        if ($ref !== '') {
            $st = db()->prepare('SELECT id FROM users WHERE referral_code = ? LIMIT 1');
            $st->execute([strtoupper($ref)]);
            $referrerId = $st->fetchColumn() ?: null;
        }
        $userId = 0;
        for ($try = 0; $try < 3 && !$userId; $try++) {
            try {
                db()->prepare('INSERT INTO users (name, email, phone, country, mobile, password_hash, referral_code, referred_by, is_tipster_account) VALUES (?,?,?,?,?,?,?,?,0)')
                    ->execute([$name, $email, $phone, $country, $mobile, password_hash($pw, PASSWORD_DEFAULT), strtoupper(bin2hex(random_bytes(4))), $referrerId]);
                $userId = (int)db()->lastInsertId();
            } catch (PDOException $ex) {
                if ($ex->getCode() !== '23000') throw $ex;
                $st = db()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
                $st->execute([$email]);
                if ($st->fetchColumn()) { api_fail('An account with this email already exists. Try logging in.', 422); }
            }
        }
        if (!$userId) { api_fail('We could not create your account. Please try again.', 500); }
        if ($referrerId) { db()->prepare('INSERT INTO referrals (referrer_id, referred_id) VALUES (?, ?)')->execute([$referrerId, $userId]); }
        limits_confirm_age($userId);
        send_verification_email($userId, $email, $name);
        clear_attempts();
        log_in_user($userId);
        $_SESSION['user_id'] = $userId;
        $needPhone = false;
        if (sms_enabled()) { phone_code_send($userId, $mobile); $needPhone = true; }
        api_out(['logged_in' => true,
                 'verify_phone_url' => $needPhone ? url('auth/verify-phone.php') : null]);

    case 'logout':
        api_require_post();
        if ($u = current_user()) {
            if (!empty($_POST['token']) && function_exists('app_push_ready') && app_push_ready()) {
                db()->prepare('DELETE FROM app_devices WHERE token = ? AND user_id = ?')->execute([(string)$_POST['token'], (int)$u['id']]);
            }
        }
        $_SESSION = [];
        session_regenerate_id(true);
        api_out([]);

    case 'crash_report':
        api_require_post();
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS app_crashes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        app_version VARCHAR(20) NOT NULL DEFAULT '', os VARCHAR(40) NOT NULL DEFAULT '', device VARCHAR(80) NOT NULL DEFAULT '', screen VARCHAR(60) NOT NULL DEFAULT '',
                        message TEXT, user_id INT UNSIGNED NULL DEFAULT NULL, KEY idx_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $n = (int)db()->query('SELECT COUNT(*) FROM app_crashes WHERE created_at > NOW() - INTERVAL 1 HOUR')->fetchColumn();
            if ($n < 200) {   // a flood of reports is ignored
                $cu = current_user();
                db()->prepare('INSERT INTO app_crashes (app_version, os, device, screen, message, user_id) VALUES (?,?,?,?,?,?)')
                    ->execute([api_input('v', 20), api_input('os', 40), api_input('device', 80), api_input('screen', 60), mb_substr((string)($_POST['msg'] ?? ''), 0, 4000), $cu ? (int)$cu['id'] : null]);
            }
        } catch (Throwable $e) { error_log('crash_report: ' . $e->getMessage()); }
        api_out([]);

    case 'delete_account':
        api_require_post();
        $u = api_require_login();
        $why = account_delete_member(db(), (int)$u['id'], (string)($_POST['password'] ?? ''));
        if ($why !== '') { api_fail($why, 422); }
        $_SESSION = [];
        session_regenerate_id(true);
        api_out([]);

    case 'me':
        $u = current_user();
        if (!$u) { api_out(['user' => null]); }
        [$trialOk, $trialWhy] = trial_eligibility($u);
        api_out(['access' => access_api_info($u), 'dm_unread' => dm_unread((int)$u['id']), 'user' => api_user($u), 'trial' => ['available' => (bool)$trialOk, 'why' => $trialOk ? '' : (string)$trialWhy]]);

    case 'replays':
        $out = [];
        foreach (replays_list() as $r) { $out[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'date' => $r['played_on'] ? date('D, j M Y', strtotime($r['played_on'])) : '', 'link1' => $r['link1'], 'link2' => $r['link2'], 'watch1' => stream_watch_url($r['link1'], $r['title'] . ' - 1st half', 'app'), 'watch2' => stream_watch_url($r['link2'], $r['title'] . ' - 2nd half', 'app'), 'thumb' => thumb_src((string)($r['thumb'] ?? ''))]; }
        api_out(['items' => $out]);

    case 'streak':
        if (!streak_ready()) { api_out(['streak' => null]); }
        $u = current_user();
        $v = streak_view($u);
        $v['start_label'] = streak_money($v['start']);
        $v['logged_in'] = (bool)$u;
        if ($v['today']) { $v['today']['thumb'] = thumb_src((string)($v['today']['thumb'] ?? '')); $v['today']['kickoff'] = api_when($v['today']['kickoff_at']); unset($v['today']['kickoff_at']); }
        api_out(['streak' => $v]);

    case 'streak_join':
        $u = api_require_login(); api_require_post();
        if (!streak_ready()) { api_fail('Not available yet.', 503); }
        $st = db()->prepare('SELECT * FROM streak_days WHERE id = ?'); $st->execute([(int)($_POST['day'] ?? 0)]);
        $d = $st->fetch();
        if (!$d || !streak_join((int)$u['id'], $d)) { api_fail('Today\'s streak pick is closed.', 422); }
        api_out(['ok' => true]);

    case 'access':
        $u = api_require_login();
        api_out(['access' => access_api_info($u)]);

    /* ---------- tips ---------- */
    case 'home':
        app_log_open(function_exists('current_user') ? current_user() : null, (preg_match('~SlipKingApp/([0-9A-Za-z.\-]+)~', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''), $avm) ? $avm[1] : ''));
        $pub = cache_remember('api-home', 60, function () {
            $today = fetch_tips(['today' => true, 'limit' => 40]);
            $free = []; $vipTips = [];
            foreach ($today as $t) { $x = api_tip($t); if ($t['is_vip']) { $vipTips[] = $x; } else { $free[] = $x; } }
            $up = [];
            foreach (upcoming_matches(5) as $m) { $up[] = ['match' => $m['home_team'] . ' vs ' . $m['away_team'], 'when' => kickoff_label($m['kickoff_at'])]; }
            return [
                'stats' => site_stats(), 'record30' => record_stats(30),
                'form' => recent_form(8), 'free' => array_slice($free, 0, 6), 'vip' => array_slice($vipTips, 0, 6),
                'vip_count' => count($vipTips), 'upcoming' => $up, 'sports' => api_sports(),
            ];
        });
        $u = current_user();
        api_out($pub + ['user' => $u ? api_user($u) : null, 'live_streams' => live_stream_items()]);

    case 'tips':
        $o = ['limit' => 80];
        $date = (string)($_GET['date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $o['date'] = $date; } else { $o['today'] = true; $date = date('Y-m-d'); }
        $sport = preg_replace('/[^a-z0-9\-]/', '', (string)($_GET['sport'] ?? ''));
        if ($sport !== '') { $o['sport'] = $sport; }
        $v = (string)($_GET['vip'] ?? '');
        if ($v === '0' || $v === '1') { $o['vip'] = (int)$v; }
        $rows = fetch_tips($o);
        $items = array_map('api_tip', $rows);
        // number of tips on each of the next 7 days (tab badges)
        $days = [];
        $cnt = db()->query("SELECT DATE(m.kickoff_at) d, COUNT(*) c FROM tips t JOIN matches m ON m.id = t.match_id
                            WHERE t.deleted_at IS NULL AND t.tipster_id IS NULL AND m.kickoff_at >= CURDATE() AND m.kickoff_at < CURDATE() + INTERVAL 7 DAY GROUP BY DATE(m.kickoff_at)")->fetchAll(PDO::FETCH_KEY_PAIR);
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime("+$i day"));
            $days[] = ['date' => $d, 'label' => $i === 0 ? 'Today' : ($i === 1 ? 'Tomorrow' : date('D j', strtotime($d))), 'count' => (int)($cnt[$d] ?? 0)];
        }
        api_out(['date' => $date, 'days' => $days, 'tips' => $items, 'sports' => api_sports()]);

    case 'tip':
        $t = fetch_tip((int)($_GET['id'] ?? 0));
        if (!$t) { api_fail('Tip not found', 404); }
        $others = [];
        $st = db()->prepare('SELECT id FROM tips WHERE match_id = ? AND id <> ? AND deleted_at IS NULL AND pending_review = 0 ORDER BY id LIMIT 6');
        $st->execute([$t['match_id'], $t['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $oid) { if ($ot = fetch_tip((int)$oid)) { $others[] = api_tip($ot); } }
        $m = db()->prepare('SELECT live_status, live_minute, home_score, away_score FROM matches WHERE id = ?');
        $m->execute([$t['match_id']]);
        $mm = $m->fetch() ?: [];
        api_out(['tip' => api_tip($t, true), 'others' => $others,
                 'score' => ['home' => isset($mm['home_score']) && $mm['home_score'] !== null ? (int)$mm['home_score'] : null,
                             'away' => isset($mm['away_score']) && $mm['away_score'] !== null ? (int)$mm['away_score'] : null,
                             'label' => live_status_label($mm['live_status'] ?? null, isset($mm['live_minute']) && $mm['live_minute'] !== null ? (int)$mm['live_minute'] : null)],
                 'share' => url('tip.php?id=' . (int)$t['id'])]);

    case 'results':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = 25;
        $sport = preg_replace('/[^a-z0-9\-]/', '', (string)($_GET['sport'] ?? ''));
        $status = (string)($_GET['status'] ?? '');
        if (!in_array($status, ['won', 'lost', 'void'], true)) { $status = ''; }
        $from = "FROM tips t JOIN matches m ON m.id = t.match_id JOIN leagues l ON l.id = m.league_id JOIN sports s ON s.id = l.sport_id
                 WHERE (t.status <> 'pending' OR m.kickoff_at < NOW()) AND t.tipster_id IS NULL AND (t.deleted_at IS NULL OR t.status IN ('won','lost'))";
        $args = [];
        if ($sport !== '') { $from .= ' AND s.slug = ?'; $args[] = $sport; }
        if ($status !== '') { $from .= ' AND t.status = ?'; $args[] = $status; }
        $st = db()->prepare('SELECT COUNT(*) ' . $from); $st->execute($args);
        $total = (int)$st->fetchColumn();
        $st = db()->prepare('SELECT t.id ' . $from . ' ORDER BY m.kickoff_at DESC, t.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
        $st->execute($args);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { if ($t = fetch_tip((int)$id)) { $items[] = api_tip($t); } }
        api_out(['items' => $items, 'page' => $page, 'more' => $total > $page * $per, 'total' => $total,
                 'stats30' => record_stats(30), 'stats_all' => record_stats(null), 'sports' => api_sports()]);

    case 'live':
        $out = [];
        foreach (live_matches() as $r) {
            $out[] = ['id' => (int)$r['id'], 'home' => $r['home_team'], 'away' => $r['away_team'], 'league' => $r['league'],
                      'home_logo' => team_logo($r['home_team']), 'away_logo' => team_logo($r['away_team']),
                      'kickoff' => api_when($r['kickoff_at']),
                      'label' => live_status_label($r['live_status'], $r['live_minute'] !== null ? (int)$r['live_minute'] : null),
                      'live' => live_is_live($r['live_status']), 'finished' => live_is_finished($r['live_status']),
                      'home_score' => $r['home_score'] !== null ? (int)$r['home_score'] : null,
                      'away_score' => $r['away_score'] !== null ? (int)$r['away_score'] : null];
        }
        api_out(['matches' => $out]);

    /* ---------- VIP ---------- */
    case 'plans':
        $plans = access_on() ? [] : db()->query('SELECT * FROM plans WHERE is_active = 1 AND tipster_id IS NULL ORDER BY sort_order, id')->fetchAll();
        $out = [];
        foreach ($plans as $p) {
            $feat = array_values(array_filter(array_map('trim', preg_split('/\r?\n|\|/', (string)$p['features']))));
            $out[] = ['slug' => $p['slug'], 'name' => $p['name'], 'price' => plan_price_display($p), 'price_kes' => (int)$p['price_kes'],
                      'period' => plan_period((int)$p['duration_days']), 'days' => (int)$p['duration_days'],
                      'featured' => (bool)$p['is_featured'], 'features' => $feat,
                      'checkout' => url('payments/checkout.php?plan=' . rawurlencode($p['slug']))];
        }
        $u = current_user();
        [$trialOk, $trialWhy] = trial_eligibility($u);
        api_out(['plans' => $out, 'free_vip' => access_on(), 'user' => $u ? api_user($u) : null,
                 'trial' => ['available' => (bool)$trialOk, 'why' => $trialOk ? '' : (string)$trialWhy, 'days' => max(1, (int)setting('trial_days', '1'))]]);

    case 'trial':
        api_require_post();
        $u = api_require_login();
        [$ok, $why] = trial_eligibility($u);
        if (!$ok) {
            $msgs = ['verify' => 'Please confirm your email address first.', 'phone' => 'Please add your mobile number in your profile first.', 'phone-verify' => 'Please confirm your mobile number with the SMS code first.',
                     'used' => 'The free trial is for new members who have not had a plan yet.', 'phone-used' => 'This phone number has already been used for a free trial.', 'off' => 'The free trial is not available right now.'];
            api_fail($msgs[$why] ?? 'The free trial is not available.', 409);
        }
        $st = db()->prepare("SELECT id FROM plans WHERE slug = 'trial' LIMIT 1");
        $st->execute();
        $planId = (int)$st->fetchColumn();
        $days = max(1, (int)setting('trial_days', '1'));
        if (!$planId || !($ends = grant_vip((int)$u['id'], $planId, $days))) { api_fail('The free trial is not set up yet. Please contact us.', 409); }
        notify_user((int)$u['id'], 'Your free VIP trial has started', 'Enjoy every VIP pick until ' . date('j F Y, H:i', strtotime($ends)) . '.');
        if (function_exists('payments_log')) { payments_log('trial', ['user' => $u['id'], 'days' => $days, 'via' => 'app']); }
        api_out(['ends' => api_when($ends)]);

    /* ---------- messages ---------- */
    case 'notifications':
        $u = api_require_login();
        $st = db()->prepare('SELECT id, user_id, title, message, is_read, created_at' . (notif_path_col() ? ', path' : ", '' AS path") . ' FROM notifications WHERE user_id = ? OR user_id IS NULL ORDER BY created_at DESC LIMIT 60');
        $st->execute([(int)$u['id']]);
        $items = [];
        foreach ($st->fetchAll() as $n) {
            $items[] = ['id' => (int)$n['id'], 'title' => $n['title'], 'message' => $n['message'], 'unread' => $n['user_id'] !== null && !$n['is_read'],
                        'when' => api_when($n['created_at']), 'ago' => (time() - strtotime($n['created_at'])), 'path' => api_notif_target($n),
                        'reply' => (bool)preg_match('/new message|replied|new reply|reply to|new comment|mentioned|first comment|chat|join request/i', $n['title'] . ' ' . $n['message'])];
        }
        api_out(['items' => $items, 'unread' => count(array_filter($items, function ($i) { return $i['unread']; })), 'banner' => function_exists('urgent_banner') ? urgent_banner() : '']);

    /* ---------- account tools: alerts, group plan, support tickets ---------- */
    case 'alerts_get':
        $u = api_require_login();
        $pr = alert_prefs((int)$u['id']);
        $ax = alert_extra_get((int)$u['id']);
        $mt = [];
        if (alerts2_ready()) { $q = db()->prepare('SELECT kind, ref FROM alert_mutes WHERE user_id = ? ORDER BY ref'); $q->execute([(int)$u['id']]); foreach ($q->fetchAll() as $r) { $mt[] = ['kind' => $r['kind'], 'ref' => $r['ref']]; } }
        api_out(['push' => (int)$pr['push'], 'email' => (int)$pr['email'], 'kickoff' => (int)$pr['kickoff'], 'results' => (int)$pr['results'], 'vip' => (int)$pr['vip'], 'digest_hour' => (int)$ax['digest_hour'], 'weekly' => (int)$ax['weekly'], 'mutes' => $mt]);

    case 'alerts_save':
        api_require_post();
        $u = api_require_login();
        $f = function ($k) { return !empty($_POST[$k]) && $_POST[$k] !== '0' ? 1 : 0; };
        db()->prepare('INSERT INTO alert_prefs (user_id, push, email, sms, kickoff, results, vip) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE push = VALUES(push), email = VALUES(email), kickoff = VALUES(kickoff), results = VALUES(results), vip = VALUES(vip)')
            ->execute([(int)$u['id'], $f('push'), $f('email'), 0, $f('kickoff'), $f('results'), $f('vip')]);
        if (alerts2_ready()) {
            $hr = max(-1, min(23, (int)($_POST['digest_hour'] ?? 8)));
            db()->prepare('INSERT INTO alert_extra (user_id, digest_hour, weekly) VALUES (?,?,?) ON DUPLICATE KEY UPDATE digest_hour = VALUES(digest_hour), weekly = VALUES(weekly)')->execute([(int)$u['id'], $hr, $f('weekly')]);
            if (digest_ready()) { db()->prepare('INSERT INTO digest_prefs (user_id, email_daily) VALUES (?,?) ON DUPLICATE KEY UPDATE email_daily = VALUES(email_daily)')->execute([(int)$u['id'], $hr >= 0 ? 1 : 0]); }
        }
        api_out([]);

    case 'alerts_mute':
        api_require_post();
        $u = api_require_login();
        if (!alerts2_ready()) { api_fail('Not available yet.', 503); }
        $ref = trim(mb_substr((string)($_POST['ref'] ?? ''), 0, 80));
        $kind = ($_POST['kind'] ?? '') === 'tipster' ? 'tipster' : 'team';
        if ($ref === '') { api_fail('Type a team name.', 422); }
        if (($_POST['on'] ?? '1') === '1') { db()->prepare('INSERT IGNORE INTO alert_mutes (user_id, kind, ref) VALUES (?,?,?)')->execute([(int)$u['id'], $kind, $ref]); }
        else { db()->prepare('DELETE FROM alert_mutes WHERE user_id = ? AND kind = ? AND ref = ?')->execute([(int)$u['id'], $kind, $ref]); }
        api_out([]);

    case 'group_codes':
        $u = api_require_login();
        $out = [];
        if (paytools_ready()) { $q = db()->prepare('SELECT g.code, g.used_by, p.name AS plan FROM group_codes g JOIN plans p ON p.id = g.plan_id WHERE g.owner_id = ? ORDER BY g.id DESC LIMIT 60'); $q->execute([(int)$u['id']]); foreach ($q->fetchAll() as $r) { $out[] = ['code' => $r['code'], 'plan' => $r['plan'], 'used' => (bool)$r['used_by']]; } }
        api_out(['items' => $out]);

    case 'group_redeem':
        api_require_post();
        $u = api_require_login();
        [$okG, $mG] = group_redeem((int)$u['id'], (string)($_POST['code'] ?? ''));
        if (!$okG) { api_fail($mG, 422); }
        api_out(['message' => $mG]);

    case 'tickets':
        $u = api_require_login();
        if (!tickets_ready()) { api_out(['items' => []]); }
        $q = db()->prepare('SELECT id, subject, status, rating FROM support_tickets WHERE user_id = ? ORDER BY id DESC LIMIT 50'); $q->execute([(int)$u['id']]);
        api_out(['items' => array_map(function ($r) { return ['id' => (int)$r['id'], 'subject' => $r['subject'], 'status' => $r['status'], 'label' => ticket_status_label($r['status']), 'rated' => $r['rating'] !== null]; }, $q->fetchAll())]);

    case 'ticket':
        $u = api_require_login();
        $tid = (int)($_GET['id'] ?? 0);
        $q = db()->prepare('SELECT * FROM support_tickets WHERE id = ? AND user_id = ?'); $q->execute([$tid, (int)$u['id']]); $tk = $q->fetch();
        if (!$tk) { api_fail('Ticket not found.', 404); }
        $m = db()->prepare('SELECT from_staff, body, created_at FROM support_msgs WHERE ticket_id = ? ORDER BY id'); $m->execute([$tid]);
        api_out(['id' => $tid, 'subject' => $tk['subject'], 'status' => $tk['status'], 'label' => ticket_status_label($tk['status']), 'rated' => $tk['rating'] !== null,
                 'messages' => array_map(function ($r) { return ['staff' => (bool)$r['from_staff'], 'body' => $r['body'], 'when' => date('j M H:i', strtotime($r['created_at']))]; }, $m->fetchAll())]);

    case 'ticket_new':
    case 'ticket_reply':
    case 'ticket_rate':
        api_require_post();
        $u = api_require_login();
        if (!tickets_ready()) { api_fail('Not available yet.', 503); }
        if ($a === 'ticket_new') {
            $sj = trim((string)($_POST['subject'] ?? '')); $bd = trim((string)($_POST['body'] ?? ''));
            if (mb_strlen($sj) < 3 || mb_strlen($bd) < 10) { api_fail('Add a subject and describe the problem.', 422); }
            $nid = ticket_open((int)$u['id'], $sj, $bd);
            try { send_mail(SITE_EMAIL, '[Ticket #' . $nid . '] ' . $sj, "Member #" . $u['id'] . " ({$u['email']})\n\n$bd"); } catch (Throwable $e) { }
            api_out(['id' => $nid]);
        }
        $tid = (int)($_POST['id'] ?? 0);
        $q = db()->prepare('SELECT * FROM support_tickets WHERE id = ? AND user_id = ?'); $q->execute([$tid, (int)$u['id']]); $tk = $q->fetch();
        if (!$tk) { api_fail('Ticket not found.', 404); }
        if ($a === 'ticket_reply') {
            if ($tk['status'] === 'closed') { api_fail('This ticket is closed. Open a new one.', 422); }
            ticket_reply($tid, false, (string)($_POST['body'] ?? ''));
        } elseif ($tk['status'] === 'closed' && $tk['rating'] === null) {
            db()->prepare('UPDATE support_tickets SET rating = ?, rating_note = ? WHERE id = ?')->execute([max(1, min(5, (int)($_POST['rating'] ?? 5))), mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300), $tid]);
        }
        api_out([]);

    case 'app_notice':
        api_out(['banner' => urgent_banner(), 'whats_new' => whats_new(), 'rules' => chat_rules_text(), 'unread' => (($uu = current_user()) ? notif_unread_count((int)$uu['id']) : 0)]);

    case 'tip_react':
        api_require_post();
        $u = api_require_login();
        $rr = tip_react((int)$u['id'], (int)($_POST['id'] ?? 0), (string)($_POST['emoji'] ?? ''));
        if ($rr === null) { api_fail('Could not react.', 422); }
        api_out($rr);

    case 'notification_delete':
        api_require_post();
        $u = api_require_login();
        db()->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')->execute([(int)($_POST['id'] ?? 0), (int)$u['id']]);
        api_out([]);

    case 'notification_delete':
        api_require_post();
        $u = api_require_login();
        db()->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')->execute([(int)($_POST['id'] ?? 0), (int)$u['id']]);
        api_out([]);

    case 'notifications_read':
        api_require_post();
        $u = api_require_login();
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([(int)$u['id']]);
        api_out([]);

    /* ---------- private messages ---------- */
    case 'dm_list':
        $u = api_require_login();
        if (!dm_ready()) { api_out(['items' => [], 'unread' => 0]); }
        $out = [];
        foreach (dm_conversations((int)$u['id']) as $cv) {
            $out[] = ['with' => (int)$cv['oid'], 'name' => mask_name((string)$cv['name']), 'last' => mb_strimwidth((string)$cv['body'], 0, 90, '...'),
                      'mine' => (int)$cv['from_id'] === (int)$u['id'], 'unread' => (int)$cv['unread'], 'when' => api_when($cv['created_at'])];
        }
        api_out(['items' => $out, 'unread' => dm_unread((int)$u['id'])]);

    case 'dm_thread':
        $u = api_require_login();
        $uid = (int)$u['id'];
        $with = (int)($_GET['with'] ?? 0);
        if (!dm_ready() || !$with || $with === $uid) { api_fail('Choose someone to message.', 404); }
        $oq = db()->prepare('SELECT id, name FROM users WHERE id = ?'); $oq->execute([$with]); $other = $oq->fetch();
        if (!$other) { api_fail('Member not found.', 404); }
        $msgs = [];
        if (dm2_ready()) {
            $msgs = dm2_thread($uid, $with, (int)($_GET['after'] ?? 0), trim((string)($_GET['q'] ?? '')));
        } else {
            $mq = db()->prepare('SELECT id, from_id, body, created_at FROM dm_messages WHERE hidden = 0 AND ((from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)) ORDER BY id DESC LIMIT 100');
            $mq->execute([$uid, $with, $with, $uid]);
            foreach (array_reverse($mq->fetchAll()) as $m) {
                $msgs[] = ['id' => (int)$m['id'], 'mine' => (int)$m['from_id'] === $uid, 'body' => (string)$m['body'], 'time' => date('H:i', strtotime($m['created_at'])), 'day' => date('Y-m-d', strtotime($m['created_at']))];
            }
        }
        if (trim((string)($_GET['q'] ?? '')) === '') { db()->prepare('UPDATE dm_messages SET read_at = NOW() WHERE to_id = ? AND from_id = ? AND read_at IS NULL')->execute([$uid, $with]); }
        $stx = dm2_ready() ? dm2_status($uid, $with) : ['online' => false, 'typing' => false, 'muted' => false];
        api_out(['name' => mask_name((string)$other['name']), 'messages' => $msgs, 'online' => $stx['online'], 'typing' => $stx['typing'], 'muted' => $stx['muted'], 'rules' => chat_rules_text(), 'blocked' => dm_has_blocked($uid, $with), 'blocked_me' => dm_has_blocked($with, $uid)]);

    case 'dm_typing':
    case 'dm_react':
    case 'dm_hide':
    case 'dm_mute':
    case 'dm_send':
    case 'dm_delete':
    case 'dm_report':
    case 'dm_block':
        api_require_post();
        $u = api_require_login();
        $uid = (int)$u['id'];
        $pdo = db();
        if (!dm_ready()) { api_fail('Messages are not available yet.', 503); }
        $to = (int)($_POST['to'] ?? 0);
        if ($a === 'dm_typing') { if ($to && $to !== $uid && dm2_ready()) { dm2_typing_set($uid, $to); } api_out([]); }
        if ($a === 'dm_mute') { api_out(['muted' => dm2_ready() ? dm2_mute_toggle($uid, $to) : false]); }
        if ($a === 'dm_react') { api_out(['ok' => dm2_ready() && dm2_react($uid, (int)($_POST['id'] ?? 0), (string)($_POST['emoji'] ?? ''))]); }
        if ($a === 'dm_hide') { if (dm2_ready()) { dm2_hide_for_me($uid, (int)($_POST['id'] ?? 0)); } api_out([]); }
        if ($a === 'dm_send' && dm2_ready()) {
            [$okS, $rS] = dm2_send($u, $to, (string)($_POST['body'] ?? ''), (int)($_POST['reply_to'] ?? 0), $_FILES);
            if (!$okS) { api_fail((string)$rS, 422); }
            api_out(['id' => (int)$rS]);
        }
        if ($a === 'dm_send') {
            $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)($_POST['body'] ?? '')));
            $body = preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $body));
            $why = dm_block_reason($u, $to);
            $tq = $pdo->prepare('SELECT id FROM users WHERE id = ?'); $tq->execute([$to]);
            if ($why === '' && (!$tq->fetchColumn() || $to === $uid)) { $why = 'Choose someone else to message.'; }
            if ($why === '' && dm_has_blocked($to, $uid)) { $why = 'This member is not accepting messages from you.'; }
            if ($why === '' && dm_has_blocked($uid, $to)) { $why = 'You blocked this member. Unblock them first.'; }
            if ($why === '') { $why = comment_text_error($body); }
            if ($why === '') { $why = spam_block_reason($u, $body, 'dm'); }
            if ($why !== '') { api_fail($why, 422); }
            $had = $pdo->prepare('SELECT COUNT(*) FROM dm_messages WHERE from_id = ? AND to_id = ? AND read_at IS NULL AND created_at > NOW() - INTERVAL 2 MINUTE');
            $had->execute([$uid, $to]); $recent = (int)$had->fetchColumn();
            $pdo->prepare('INSERT INTO dm_messages (from_id, to_id, body) VALUES (?, ?, ?)')->execute([$uid, $to, $body]);
            if ($recent === 0) { cmty_alert($to, 'New message from ' . mask_name((string)$u['name']), mb_substr($body, 0, 100), 'account/messages.php?with=' . $uid); }
            api_out(['id' => (int)$pdo->lastInsertId()]);
        }
        if ($a === 'dm_block') {
            if (!$to || $to === $uid) { api_fail('Choose someone else.', 422); }
            if (($_POST['on'] ?? '1') === '1') { $pdo->prepare('INSERT IGNORE INTO dm_blocks (user_id, blocked_id) VALUES (?, ?)')->execute([$uid, $to]); }
            else { $pdo->prepare('DELETE FROM dm_blocks WHERE user_id = ? AND blocked_id = ?')->execute([$uid, $to]); }
            api_out([]);
        }
        $mid = (int)($_POST['id'] ?? 0);
        if ($a === 'dm_report') {
            $mq = $pdo->prepare('SELECT 1 FROM dm_messages WHERE id = ? AND to_id = ?'); $mq->execute([$mid, $uid]);
            if (!$mq->fetchColumn()) { api_fail('You cannot report that message.', 422); }
            $pdo->prepare('INSERT IGNORE INTO dm_reports (msg_id, user_id) VALUES (?, ?)')->execute([$mid, $uid]);
            api_out([]);
        }
        $pdo->prepare('UPDATE dm_messages SET hidden = 1 WHERE id = ? AND from_id = ?')->execute([$mid, $uid]);
        api_out([]);

    /* ---------- community groups ---------- */
    case 'groups':
        $u = current_user();
        if (!cmty_ready()) { api_out(['items' => []]); }
        $out = [];
        foreach (cmty_hub($u ? (int)$u['id'] : 0) as $c) { $out[] = api_group($c); }
        api_out(['items' => $out, 'chat' => gchat_ready()]);

    case 'group':
        $u = current_user();
        $slug = preg_replace('/[^a-z0-9\-]/', '', (string)($_GET['s'] ?? ''));
        $c = $slug !== '' ? cmty_by_slug($slug) : null;
        if (!$c) { api_fail('Group not found.', 404); }
        $sort = in_array($_GET['sort'] ?? '', ['hot', 'new', 'top'], true) ? $_GET['sort'] : 'hot';
        $uid = $u ? (int)$u['id'] : 0;
        $me = $c + ['joined' => 0, 'joined_n' => 0, 'posts' => 0];
        foreach (cmty_hub($uid) as $row) { if ((int)$row['id'] === (int)$c['id']) { $me = $row; } }
        $posts = [];
        foreach (cmty_posts((int)$c['id'], $sort, $u) as $p) { $posts[] = api_post($p, $uid); }
        $block = comment_block_reason($u);
        $take = $u ? cmty_can_take_part($c, $u) : false;
        api_out(['group' => api_group($me), 'posts' => $posts, 'sort' => $sort, 'can_post' => $block === '' && $take,
                 'post_block' => $block !== '' ? $block : ($take || !$u ? '' : 'Join this private group first.'),
                 'rules' => array_values(array_filter(array_map('trim', preg_split('/\r?\n|\\\\n/', (string)($c['rules'] ?? '')))))]);

    case 'post':
        $u = current_user();
        $uid = $u ? (int)$u['id'] : 0;
        $id = (int)($_GET['id'] ?? 0);
        sk_ensure_table('comment_votes', 'database-update-19.sql');
        $mine = $uid ? '(SELECT v2.value FROM comment_votes v2 WHERE v2.comment_id = c.id AND v2.user_id = ' . $uid . ')' : 'NULL';
        $cols = "c.id, c.user_id, c.parent_id, c.title, c.body, c.image, c.created_at, u.name, u.role,
                 (c.likes + COALESCE((SELECT SUM(v.value) FROM comment_votes v WHERE v.comment_id = c.id), 0)) AS score, $mine AS myvote,
                 (SELECT COUNT(*) FROM comments r WHERE r.parent_id = c.id AND r.status = 'visible') AS replies";
        $st = db()->prepare("SELECT $cols, c.target_id FROM comments c JOIN users u ON u.id = c.user_id WHERE c.id = ? AND c.target_type = 'community' AND c.status = 'visible'");
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) { api_fail('Post not found.', 404); }
        $g = cmty_by_id((int)$p['target_id']);
        $replies = []; $level = [$id];
        for ($d = 0; $d < 5 && $level; $d++) {
            $in = implode(',', array_map('intval', $level));
            $rs = db()->query("SELECT $cols, c.target_id FROM comments c JOIN users u ON u.id = c.user_id WHERE c.parent_id IN ($in) AND c.status = 'visible' ORDER BY c.created_at ASC LIMIT 200")->fetchAll();
            $level = [];
            foreach ($rs as $r) { $replies[] = api_post($r, $uid) + ['parent' => (int)$r['parent_id'], 'depth' => $d]; $level[] = (int)$r['id']; }
        }
        $block = comment_block_reason($u);
        api_out(['post' => api_post($p, $uid), 'replies' => $replies, 'group' => $g ? ['id' => (int)$g['id'], 'slug' => $g['slug'], 'name' => $g['name']] : null,
                 'can_reply' => $block === '', 'reply_block' => $block]);

    /* ---------- accumulators ---------- */
    case 'accas':
        $kind = (($_GET['kind'] ?? '') === 'vip') ? 'vip' : 'million';
        $access = $kind === 'vip' ? has_vip_acca() : true;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = 12;
        $rec = db()->prepare("SELECT SUM(status = 'won') AS w, SUM(status = 'lost') AS l FROM accumulators WHERE kind = ? AND status IN ('won','lost')");
        $rec->execute([$kind]); $rec = $rec->fetch();
        $items = [];
        if ($access) {
            $cnt = db()->prepare("SELECT COUNT(*) FROM accumulators WHERE kind = ? AND (deleted_at IS NULL OR status IN ('won','lost'))");
            $cnt->execute([$kind]); $total = (int)$cnt->fetchColumn();
            $st = db()->prepare("SELECT a.*, (SELECT COUNT(*) FROM accumulator_legs l WHERE l.accumulator_id = a.id) AS legs FROM accumulators a
                                 WHERE a.kind = ? AND (a.deleted_at IS NULL OR a.status IN ('won','lost')) ORDER BY a.posted_at DESC LIMIT $per OFFSET " . (($page - 1) * $per));
            $st->execute([$kind]);
            foreach ($st->fetchAll() as $a) {
                $items[] = ['id' => (int)$a['id'], 'title' => $a['title'], 'status' => $a['status'], 'vip' => $a['kind'] === 'vip', 'legs' => (int)$a['legs'],
                            'odds' => round((float)$a['total_odds'], 2), 'min_stake' => acca_min_stake_display((int)$a['target_kes'], (float)$a['total_odds']),
                            'target' => acca_target_display((int)$a['target_kes']), 'posted' => api_when($a['posted_at']),
                            'first' => api_when($a['first_kickoff'])];
            }
        } else { $total = 0; }
        api_out(['kind' => $kind, 'access' => $access, 'items' => $items, 'page' => $page, 'more' => $total > $page * $per,
                 'won' => (int)$rec['w'], 'lost' => (int)$rec['l']]);

    case 'acca':
        $st = db()->prepare("SELECT * FROM accumulators WHERE id = ? AND (deleted_at IS NULL OR status IN ('won','lost')) LIMIT 1");
        $st->execute([(int)($_GET['id'] ?? 0)]);
        $a = $st->fetch();
        if (!$a) { api_fail('Not found', 404); }
        $locked = $a['kind'] === 'vip' && !has_vip_acca() && $a['status'] === 'pending';
        $legs = []; $odds = (float)$a['total_odds'];
        if (!$locked) {
            $ls = db()->prepare('SELECT * FROM accumulator_legs WHERE accumulator_id = ? ORDER BY position');
            $ls->execute([$a['id']]);
            $rows = $ls->fetchAll();
            if ($rows) { $odds = acca_effective_odds($rows); }
            foreach ($rows as $l) {
                $legs[] = ['home' => $l['home_team'], 'away' => $l['away_team'], 'league' => $l['league'], 'kickoff' => api_when($l['kickoff_at']),
                           'pick' => $l['pick'], 'odds' => round((float)$l['odds'], 2), 'status' => $l['status']];
            }
        }
        $n = (int)db()->query('SELECT COUNT(*) FROM accumulator_legs WHERE accumulator_id = ' . (int)$a['id'])->fetchColumn();
        api_out(['id' => (int)$a['id'], 'title' => $a['title'], 'status' => $a['status'], 'vip' => $a['kind'] === 'vip', 'locked' => $locked, 'count' => $n,
                 'posted' => api_when($a['posted_at']), 'odds' => $locked ? null : round($odds, 2),
                 'min_stake' => $locked ? '' : acca_min_stake_display((int)$a['target_kes'], $odds), 'target' => $locked ? '' : acca_target_display((int)$a['target_kes']),
                 'chance' => $locked ? '' : '1 in ' . number_format($odds), 'legs' => $legs]);

    /* ---------- jackpot ---------- */
    case 'jackpot':
        $u = current_user();
        $ready = jackpot_ready();
        $round = $ready ? jackpot_open_round() : null;
        $past = [];
        if ($ready) {
            foreach (db()->query("SELECT title, amount_kes, winners_n, prize_each, settled_at FROM jackpot_rounds WHERE status = 'settled' ORDER BY settled_at DESC LIMIT 8")->fetchAll() as $p) {
                $past[] = ['title' => $p['title'], 'amount' => jackpot_kes($p['amount_kes']), 'winners' => (int)$p['winners_n'], 'each' => (int)$p['winners_n'] ? jackpot_kes($p['prize_each']) : '-', 'date' => date('j M Y', strtotime($p['settled_at']))];
            }
        }
        if (!$round) { api_out(['round' => null, 'past' => $past]); }
        $games = jackpot_games((int)$round['id']);
        $closed = jackpot_is_closed($round, $games);
        $entry = $u ? jackpot_entry((int)$round['id'], (int)$u['id']) : null;
        $q = db()->prepare('SELECT COUNT(*) FROM jackpot_entries WHERE round_id = ?'); $q->execute([$round['id']]); $count = (int)$q->fetchColumn();
        $dist = [];
        if ($closed) {
            jackpot_rescore((int)$round['id']);
            foreach (jackpot_distribution((int)$round['id']) as $n => $c) { $dist[] = ['correct' => (int)$n, 'members' => (int)$c]; }
            $entry = $u ? jackpot_entry((int)$round['id'], (int)$u['id']) : null;
        }
        $out = [];
        foreach ($games as $g) {
            $out[] = ['id' => (int)$g['id'], 'home' => $g['home'], 'away' => $g['away'], 'league' => $g['league'],
                      'kickoff' => $g['kickoff_at'] ? api_when($g['kickoff_at']) : null, 'result' => $g['result'],
                      'pick' => $entry['picks'][(int)$g['id']] ?? ''];
        }
        $cl = jackpot_closes_at($round, $games);
        api_out(['round' => ['id' => (int)$round['id'], 'title' => $round['title'], 'amount' => jackpot_kes($round['amount_kes']), 'note' => (string)$round['note'],
                             'consolation' => (float)$round['consolation_kes'] > 0 ? jackpot_kes($round['consolation_kes']) : '', 'entries' => $count,
                             'closed' => $closed, 'closes_at' => $cl ? date('c', $cl) : null, 'games' => $out,
                             'entered' => (bool)$entry, 'correct' => $entry ? (int)$entry['correct_n'] : 0, 'dist' => $dist], 'past' => $past]);

    case 'jackpot_pick':
        api_require_post();
        $u = api_require_login();
        $round = jackpot_ready() ? jackpot_open_round() : null;
        if (!$round) { api_fail('No jackpot round is open.', 404); }
        $games = jackpot_games((int)$round['id']);
        if (($why = comment_block_reason($u)) !== '' && strpos($why, 'Confirm your email') === 0) { api_fail('Confirm your email address to enter the jackpot.', 403); }
        $err = jackpot_save_picks($round, $games, (int)$u['id'], (array)($_POST['pick'] ?? []));
        if ($err !== '') { api_fail($err, 422); }
        api_out(['saved' => count($games)]);

    /* ---------- account: subscription, payments, referrals ---------- */
    case 'subscription':
        $u = api_require_login();
        $st = db()->prepare("SELECT s.starts_at, s.ends_at, s.status, p.name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.user_id = ? ORDER BY s.starts_at DESC LIMIT 50");
        $st->execute([$u['id']]);
        $subs = $st->fetchAll();
        $cur = null; $lastEnd = 0;
        foreach ($subs as $r) {
            if ($r['status'] === 'active' && strtotime($r['ends_at']) > time()) {
                $lastEnd = max($lastEnd, strtotime($r['ends_at']));
                if (strtotime($r['starts_at']) <= time()) $cur = $r;
            }
        }
        $items = [];
        foreach ($subs as $r) {
            $live = $r['status'] === 'active' && strtotime($r['ends_at']) > time();
            $items[] = ['title' => $r['name'], 'sub' => date('j M Y', strtotime($r['starts_at'])) . ' - ' . date('j M Y', strtotime($r['ends_at'])), 'right' => $live ? 'Active' : 'Ended', 'path' => ''];
        }
        api_out(['active' => (bool)$cur, 'plan' => $cur ? $cur['name'] : '', 'ends' => $lastEnd ? date('j F Y, H:i', $lastEnd) : '',
                 'days_left' => $lastEnd ? max(0, (int)ceil(($lastEnd - time()) / 86400)) : 0, 'items' => $items]);

    case 'payments':
        $u = api_require_login();
        $st = db()->prepare("SELECT py.*, p.name AS plan_name FROM payments py JOIN plans p ON p.id = py.plan_id WHERE py.user_id = ? ORDER BY py.created_at DESC LIMIT 100");
        $st->execute([$u['id']]);
        $items = [];
        foreach ($st->fetchAll() as $r) {
            $items[] = ['title' => $r['plan_name'] . ' · ' . $r['currency'] . ' ' . number_format((float)$r['amount'], 2),
                        'sub' => date('j M Y, H:i', strtotime($r['created_at'])) . ' · ' . strip_tags((string)method_label($r['method'])) . "\n" . ($r['mpesa_receipt'] ?: $r['reference']),
                        'right' => ucfirst($r['status']), 'path' => ''];
        }
        api_out(['items' => $items, 'unread' => count(array_filter($items, function ($i) { return $i['unread']; })), 'banner' => function_exists('urgent_banner') ? urgent_banner() : '']);

    case 'referrals':
        $u = api_require_login();
        $pdo = db();
        $st = $pdo->prepare('SELECT referral_code FROM users WHERE id = ?');
        $st->execute([$u['id']]);
        $code = $st->fetchColumn();
        if (!$code) {
            $code = strtoupper(bin2hex(random_bytes(4)));
            $pdo->prepare('UPDATE users SET referral_code = ? WHERE id = ? AND referral_code IS NULL')->execute([$code, $u['id']]);
        }
        $r = referral_stats((int)$u['id']);
        $st = $pdo->prepare("SELECT u.name, u.created_at, EXISTS(SELECT 1 FROM payments py WHERE py.user_id = u.id AND py.status = 'paid') AS paid FROM users u WHERE u.referred_by = ? ORDER BY u.id DESC LIMIT 50");
        $st->execute([$u['id']]);
        $items = [];
        foreach ($st->fetchAll() as $f) {
            $items[] = ['title' => mask_name($f['name']), 'sub' => date('j M Y', strtotime($f['created_at'])), 'right' => $f['paid'] ? 'VIP' : '', 'path' => ''];
        }
        api_out(['link' => url('auth/register.php?ref=' . $code), 'reward_days' => (int)setting('referral_reward_days', '3'),
                 'joined' => (int)$r['joined'], 'paid' => (int)$r['paid'], 'days' => (int)$r['days'], 'rank' => $r['rank'] ? (int)$r['rank'] : null, 'items' => $items]);

    case 'alerts':
        $u = api_require_login();
        $uid = (int)$u['id'];
        $canSms = sms_enabled() && !empty($u['mobile']) && !empty($u['mobile_verified']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            api_require_post();
            $f = function ($k) { return !empty($_POST[$k]) && $_POST[$k] !== '0' ? 1 : 0; };
            db()->prepare('INSERT INTO alert_prefs (user_id, push, email, sms, kickoff, results, vip) VALUES (?,?,?,?,?,?,?)
                           ON DUPLICATE KEY UPDATE push = VALUES(push), email = VALUES(email), sms = VALUES(sms), kickoff = VALUES(kickoff), results = VALUES(results), vip = VALUES(vip)')
                ->execute([$uid, $f('push'), $f('email'), $canSms ? $f('sms') : 0, $f('kickoff'), $f('results'), $f('vip')]);
        }
        $p = alert_prefs($uid);
        api_out(['push' => (bool)$p['push'], 'email' => (bool)$p['email'], 'sms' => (bool)($p['sms'] && $canSms), 'sms_available' => $canSms,
                 'kickoff' => (bool)$p['kickoff'], 'results' => (bool)$p['results'], 'vip' => (bool)$p['vip'], 'email_to' => (string)$u['email']]);

    case 'limits':
        $u = api_require_login();
        $uid = (int)$u['id'];
        $msg = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            api_require_post();
            if (($_POST['do'] ?? '') === 'limit') {
                $raw = trim((string)($_POST['limit'] ?? ''));
                $kes = $raw === '' ? null : max(0, min(10000000, (int)$raw));
                limits_set_monthly($uid, $kes === 0 ? null : $kes);
                $msg = 'Saved. A lower limit starts now. Raising or removing a limit takes 24 hours.';
            } elseif (($_POST['do'] ?? '') === 'exclude') {
                $m = (int)($_POST['months'] ?? -1);
                if (!in_array($m, [1, 3, 6, 0], true) || ($_POST['sure'] ?? '') !== 'yes') { api_fail('Choose a period and confirm.', 422); }
                limits_exclude($uid, $m);
                admin_log('limits.exclude', 'user#' . $uid, $m === 0 ? 'forever' : $m . ' months');
                $msg = 'Your break has started. Payments are switched off for your account. It cannot be shortened.';
            }
        }
        limits_apply_pending($uid);
        $r = limits_row($uid);
        $ex = limits_exclusion($uid);
        $pending = json_decode((string)setting('limit_raise_' . $uid, ''), true);
        api_out(['message' => $msg, 'limit' => $r['monthly_limit_kes'] !== null ? (int)$r['monthly_limit_kes'] : null, 'spent' => (int)limits_spent_month($uid),
                 'pending' => is_array($pending) ? date('j M, H:i', (int)$pending['at']) : '',
                 'excluded' => $ex === '' ? '' : ($ex === 'forever' ? 'forever' : date('j M Y', strtotime($ex)))]);

    case 'profile':
        $u = api_require_login();
        $uid = (int)$u['id'];
        $msg = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            api_require_post();
            $do = (string)($_POST['do'] ?? '');
            if ($do === 'profile') {
                $name = trim((string)($_POST['name'] ?? ''));
                $raw = trim((string)($_POST['phone'] ?? ''));
                $phone = null;
                if (mb_strlen($name) < 2 || mb_strlen($name) > 100) api_fail('Enter your name (2 to 100 characters).', 422);
                if ($raw !== '') {
                    $phone = normalize_phone($raw);
                    if ($phone === null) api_fail('Enter a valid Kenyan phone number, like 0712 345 678.', 422);
                }
                db()->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?')->execute([$name, $phone, $uid]);
                $msg = 'Your details have been saved.';
            } elseif ($do === 'emails') {
                db()->prepare('UPDATE users SET email_optout = ? WHERE id = ?')->execute([!empty($_POST['optout']) && $_POST['optout'] !== '0' ? 1 : 0, $uid]);
                $msg = 'Email choice saved.';
            } elseif ($do === 'password') {
                $cur = (string)($_POST['current'] ?? '');
                $pw = (string)($_POST['password'] ?? '');
                $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
                $st->execute([$uid]);
                if (too_many_attempts(8)) api_fail('Too many attempts. Please wait 15 minutes.', 429);
                if (!password_verify($cur, (string)$st->fetchColumn())) { record_attempt($u['email']); api_fail('Your current password is not correct.', 422); }
                if (strlen($pw) < 8) api_fail('Your new password must be at least 8 characters.', 422);
                if ($pw !== (string)($_POST['password2'] ?? '')) api_fail('The two new passwords do not match.', 422);
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]);
                $msg = 'Your password has been changed.';
            }
        }
        $st = db()->prepare('SELECT name, email, phone, email_optout FROM users WHERE id = ?');
        $st->execute([$uid]);
        $me = $st->fetch();
        api_out(['message' => $msg, 'name' => (string)$me['name'], 'email' => (string)$me['email'], 'phone' => (string)$me['phone'], 'optout' => (bool)$me['email_optout']]);

    case 'tracker':
        $u = api_require_login();
        require_once dirname(__DIR__, 2) . '/includes/tracker-lib.php';
        $uid = (int)$u['id'];
        $pdo = db();
        $today = date('Y-m-d');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            api_require_post();
            $do = (string)($_POST['do'] ?? '');
            if ($do === 'add') {
                $c = $pdo->prepare('SELECT COUNT(*) FROM user_bets WHERE user_id = ? AND deleted_at IS NULL');
                $c->execute([$uid]);
                if ((int)$c->fetchColumn() >= TRK_MAX_BETS) api_fail('You have reached the limit of 2000 bets. Delete some old bets to add more.', 422);
                [$clean, $errs] = trk_validate($_POST, $today);
                if ($errs) api_fail(implode(' ', $errs), 422);
                $status = (string)($_POST['status'] ?? 'pending');
                if (!in_array($status, TRK_STATUSES, true)) $status = 'pending';
                $placed = $clean['placed_on'] . ' ' . ($clean['placed_on'] === $today ? date('H:i:s') : '12:00:00');
                $pdo->prepare('INSERT INTO user_bets (user_id, tip_id, description, bookmaker, stake, odds, status, placed_at, settled_at, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$uid, null, $clean['description'], $clean['bookmaker'], $clean['stake'], $clean['odds'], $status, $placed, $status === 'pending' ? null : date('Y-m-d H:i:s'), $clean['notes']]);
            } elseif ($do === 'status') {
                $status = (string)($_POST['status'] ?? '');
                if (!in_array($status, TRK_STATUSES, true)) api_fail('Choose a valid result.', 422);
                $pdo->prepare('UPDATE user_bets SET status = ?, settled_at = ? WHERE id = ? AND user_id = ? AND deleted_at IS NULL')
                    ->execute([$status, $status === 'pending' ? null : date('Y-m-d H:i:s'), (int)($_POST['id'] ?? 0), $uid]);
            } elseif ($do === 'delete') {
                $pdo->prepare('UPDATE user_bets SET deleted_at = NOW() WHERE id = ? AND user_id = ? AND deleted_at IS NULL')->execute([(int)($_POST['id'] ?? 0), $uid]);
            }
        }
        $flt = in_array((string)($_GET['st'] ?? ''), TRK_STATUSES, true) ? (string)$_GET['st'] : '';
        $sql = 'SELECT * FROM user_bets WHERE user_id = ? AND deleted_at IS NULL' . ($flt !== '' ? ' AND status = ?' : '') . ' ORDER BY placed_at DESC, id DESC LIMIT ' . TRK_MAX_BETS;
        $st = $pdo->prepare($sql);
        $st->execute($flt !== '' ? [$uid, $flt] : [$uid]);
        $bets = $st->fetchAll();
        $sum = trk_summary($bets);
        $items = [];
        foreach (array_slice($bets, 0, 200) as $b) {
            $items[] = ['id' => (int)$b['id'], 'title' => (string)$b['description'], 'status' => (string)$b['status'],
                        'sub' => date('j M Y', strtotime($b['placed_at'])) . ' · ' . ($b['bookmaker'] ?: '-') . ' · stake ' . number_format((float)$b['stake'], 2) . ' @ ' . number_format((float)$b['odds'], 2),
                        'profit' => round(trk_profit((string)$b['status'], (float)$b['stake'], (float)$b['odds']), 2)];
        }
        api_out(['summary' => $sum, 'items' => $items, 'filter' => $flt, 'today' => $today]);

    case 'streams':
        if (!streams_ready()) { api_out(['live' => [], 'upcoming' => [], 'highlights' => [], 'more' => false]); }
        $per = 12;
        $page = max(1, min(20, (int)($_GET['page'] ?? 1)));
        $live = []; $up = [];
        foreach (streams_fetch('stream', 60) as $s) {
            if (stream_state($s) === 'live') { $live[] = stream_item_json($s); } else { $up[] = stream_item_json($s); }
        }
        $hl = streams_fetch('highlight', $per + 1, ($page - 1) * $per);
        $more = count($hl) > $per;
        api_out(['live' => $live, 'upcoming' => $up, 'highlights' => array_map('stream_item_json', array_slice($hl, 0, $per)), 'more' => $more, 'page' => $page]);

    case 'widgets':
        // Embedded widgets for the app: ?slot=live|home|results|tips|livestream|ticker, or ?p=<page slug> for a custom page.
        $wslug = widget_slug((string)($_GET['p'] ?? ''));
        if ($wslug !== '') { api_out(['widgets' => widgets_json('page', $wslug)]); }
        $wslot = (string)($_GET['slot'] ?? 'live');
        if (!array_key_exists($wslot, widget_slots()) || $wslot === 'page') { api_out(['widgets' => []]); }
        api_out(['widgets' => widgets_json($wslot)]);

    case 'widget_pages':
        api_out(['pages' => array_map(function ($pg) { return ['slug' => $pg['page_slug'], 'title' => $pg['page_title'], 'count' => (int)$pg['n']]; }, widget_pages())]);

    case 'search':
        $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $out = [];
        if (mb_strlen($q) >= 2) {
            $terms = search_terms($q);
            $sa = [];
            $st = db()->prepare("SELECT m.id, m.home_team, m.away_team, m.kickoff_at, l.name AS league FROM matches m JOIN leagues l ON l.id = m.league_id
                                 WHERE " . search_like_sql(['m.home_team', 'm.away_team', 'l.name'], $terms, $sa) . " ORDER BY ABS(TIMESTAMPDIFF(HOUR, m.kickoff_at, NOW())) ASC LIMIT 20");
            $st->execute($sa);
            foreach ($st->fetchAll() as $m) {
                $out[] = ['kind' => 'match', 'title' => $m['home_team'] . ' vs ' . $m['away_team'], 'sub' => $m['league'] . ' · ' . date('D j M Y, H:i', strtotime($m['kickoff_at'])), 'url' => url('match.php?id=' . (int)$m['id'])];
            }
            $st = db()->prepare('SELECT l.id, l.name, s.name AS sport FROM leagues l JOIN sports s ON s.id = l.sport_id WHERE l.name LIKE ? ORDER BY l.name LIMIT 10');
            $st->execute([$like]);
            foreach ($st->fetchAll() as $l) {
                $out[] = ['kind' => 'league', 'title' => $l['name'], 'sub' => $l['sport'], 'url' => url('league.php?id=' . (int)$l['id'])];
            }
            $st = db()->prepare("SELECT * FROM tipsters WHERE status = 'active'" . tipster_live_sql('tipsters') . " AND display_name LIKE ? ORDER BY display_name LIMIT 10");
            $st->execute([$like]);
            foreach ($st->fetchAll() as $t) {
                $out[] = ['kind' => 'tipster', 'title' => $t['display_name'], 'sub' => '', 'url' => tipster_url($t)];
            }
        }
        api_out(['items' => $out, 'q' => $q]);

    /* ---------- generic read-only pages: football, predictions, odds, audit, performance, members, ... ---------- */
    case 'page':
        $pg = (string)($_GET['p'] ?? '');
        $items = []; $stats = []; $note = ''; $title = '';
        $row = function ($t, $sub = '', $right = '', $url = '', $head = false) { return ['title' => (string)$t, 'sub' => (string)$sub, 'right' => (string)$right, 'url' => (string)$url, 'head' => $head]; };
        switch ($pg) {
        case 'football':
            $title = 'Football leagues';
            if (!stats_on()) { $note = 'League data is not available yet.'; break; }
            foreach (stats_competitions() as $c) {
                $top = array_slice(stats_table($c['code']), 0, 3);
                $sub = [];
                foreach ($top as $r) { $sub[] = (int)$r['position'] . '. ' . $r['team_name'] . ' (' . (int)$r['points'] . ')'; }
                $items[] = $row($c['name'], ($c['area'] ?: '') . ($sub ? "\n" . implode(' · ', $sub) : ''), 'Table', 'n:page:table&c=' . rawurlencode($c['code']));
            }
            break;
        case 'table':
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['c'] ?? '')));
            $comp = stats_on() ? stats_competition($code) : null;
            if (!$comp) { $note = 'League not found.'; $title = 'League table'; break; }
            $title = $comp['name'];
            foreach (stats_table($comp['code']) as $r) {
                $items[] = $row((int)$r['position'] . '. ' . $r['team_name'], 'P ' . (int)$r['played'] . ' · W ' . (int)$r['won'] . ' · D ' . (int)$r['draw'] . ' · L ' . (int)$r['lost'] . ' · GD ' . (int)$r['gd'], (int)$r['points']);
            }
            $items[] = $row('Fixtures', '', '', '', true);
            foreach (stats_matches($comp['code'], 'upcoming', 10) as $m) {
                $items[] = $row($m['home_name'] . ' vs ' . $m['away_name'], stats_local($m['kick_utc'], 'D j M, H:i'), '', '');
            }
            $items[] = $row('Latest results', '', '', '', true);
            foreach (stats_matches($comp['code'], 'results', 10) as $m) {
                $items[] = $row($m['home_name'] . ' vs ' . $m['away_name'], stats_local($m['kick_utc'], 'j M'), (int)$m['home_goals'] . ' - ' . (int)$m['away_goals']);
            }
            $note = 'Statistical estimates are not betting advice.';
            break;
        case 'predictions':
            $title = 'Match predictions';
            if (!stats_on()) { $note = 'Predictions are not available yet.'; break; }
            $sel = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['c'] ?? '')));
            $days = in_array((int)($_GET['d'] ?? 3), [1, 3, 7], true) ? (int)($_GET['d'] ?? 3) : 3;
            $sql = "SELECT m.*, c.name AS comp_name FROM stat_matches m JOIN stat_competitions c ON c.code = m.comp_code
                    WHERE m.status IN ('SCHEDULED','TIMED') AND m.kick_utc > UTC_TIMESTAMP() AND m.kick_utc < UTC_TIMESTAMP() + INTERVAL $days DAY" . ($sel !== '' ? ' AND m.comp_code = ?' : '') . ' ORDER BY m.kick_utc ASC LIMIT 80';
            $st = db()->prepare($sql);
            $st->execute($sel !== '' ? [$sel] : []);
            foreach ($st->fetchAll() as $m) {
                $pr = stats_predict($m);
                $sub = stats_local($m['kick_utc'], 'D j M, H:i') . ' · ' . $m['comp_name'];
                if ($pr) {
                    $sub .= "\n1 " . round($pr['home'] ?? 0) . '% · X ' . round($pr['draw'] ?? 0) . '% · 2 ' . round($pr['away'] ?? 0) . '% · O2.5 ' . round($pr['over25']) . '% · BTTS ' . round($pr['btts']) . '%';
                }
                $items[] = $row($m['home_name'] . ' vs ' . $m['away_name'], $sub);
            }
            $note = 'Automatic statistical estimates, not betting advice. 18+ only.';
            break;
        case 'odds':
            $title = 'Odds comparison';
            if (!odds_on()) { $note = 'Odds comparison is not available.'; break; }
            $all = odds_sports_all();
            $selS = (string)($_GET['s'] ?? '');
            if (!isset($all[$selS])) $selS = '';
            foreach (odds_upcoming($selS) as $ev) {
                $sm = odds_summary(odds_quotes((int)$ev['id']));
                $f = function ($v) { return $v !== null ? number_format($v, 2) : '-'; };
                $items[] = $row($ev['home_team'] . ' vs ' . $ev['away_team'], stats_local($ev['commence_at'], 'D j M, H:i') . ' · ' . ($all[$ev['sport_key']] ?? '') . "\nBest: 1 " . $f($sm['best_home']) . ' · X ' . $f($sm['best_draw']) . ' · 2 ' . $f($sm['best_away']), (int)$sm['books'] . ' books');
            }
            $note = 'Odds can be a few hours old. Always check the price at the bookmaker. We do not take bets. 18+ only.';
            break;
        case 'audit':
            $title = 'Tip audit log';
            $v = audit_verify();
            $note = $v['ok'] ? 'Chain intact. ' . (int)$v['rows'] . ' entries checked.' : 'Chain broken at entry #' . (int)$v['broken_at'] . '.';
            foreach (db()->query('SELECT id, tip_id, event, payload, hash, created_at FROM audit_chain ORDER BY id DESC LIMIT 50')->fetchAll() as $r) {
                $d = $r['event'] === 'post' ? (strpos($r['payload'], '| vip') !== false ? explode(' | ', $r['payload'])[0] . ' (premium tip, pick hidden until settled)' : $r['payload']) : 'Tip #' . (int)$r['tip_id'] . ': ' . $r['payload'];
                $items[] = $row('#' . (int)$r['id'] . ' ' . ($r['event'] === 'post' ? 'Posted' : 'Settled'), date('j M Y H:i', strtotime($r['created_at'])) . "\n" . $d, substr($r['hash'], 0, 8));
            }
            break;
        case 'performance':
            $title = 'Performance';
            $per = (string)($_GET['per'] ?? '90');
            if (!in_array($per, ['30', '90', '365', 'all'], true)) $per = '90';
            $where = $per === 'all' ? '' : ' AND t.posted_at >= NOW() - INTERVAL ' . (int)$per . ' DAY';
            $tot = record_stats($per === 'all' ? null : (int)$per);
            $stats = [['Win rate', $tot['win_rate'] === null ? '-' : $tot['win_rate'] . '%'], ['Won', (string)$tot['won']], ['Lost', (string)$tot['lost']], ['Units', $tot['units']], ['Avg odds', $tot['avg_odds']]];
            $groups = ['By sport' => 's.name', 'By league' => 'l.name', 'By market' => 't.market', 'Free vs VIP' => "CASE WHEN t.is_vip = 1 THEN 'VIP' ELSE 'Free' END"];
            foreach ($groups as $label => $expr) {
                $items[] = $row($label, '', '', '', true);
                $rs = db()->query("SELECT $expr AS label, SUM(t.status = 'won') AS w, SUM(t.status = 'lost') AS l, AVG(t.odds) AS ao,
                                          SUM(CASE WHEN t.status = 'won' THEN t.odds - 1 WHEN t.status = 'lost' THEN -1 ELSE 0 END) AS units, COUNT(*) AS n
                                   FROM tips t JOIN matches m ON m.id = t.match_id JOIN leagues l ON l.id = m.league_id JOIN sports s ON s.id = l.sport_id
                                   WHERE t.status IN ('won','lost') AND t.tipster_id IS NULL $where GROUP BY label ORDER BY n DESC LIMIT 15")->fetchAll();
                foreach ($rs as $r) {
                    $n = (int)$r['w'] + (int)$r['l'];
                    $u = round((float)$r['units'], 1);
                    $items[] = $row($r['label'], (int)$r['w'] . '-' . (int)$r['l'] . ' · ' . ($n ? round($r['w'] / $n * 100) : 0) . '% · odds ' . number_format((float)$r['ao'], 2), ($u > 0 ? '+' : '') . $u);
                }
            }
            $note = 'Every settled tip is counted. Units assume a flat 1-unit stake.';
            break;
        case 'members':
            $title = 'Top members';
            $sortM = in_array($_GET['sort'] ?? '', ['units', 'winrate', 'followers'], true) ? $_GET['sort'] : 'units';
            $qm = strtolower(trim((string)($_GET['q'] ?? '')));
            $qm = preg_match('/^[a-z0-9_]{1,20}$/', $qm) ? $qm : '';
            foreach (members_top($sortM, 5, 50, $qm) as $i => $r) {
                $u = round((float)$r['units'], 2);
                $items[] = $row(($i + 1) . '. @' . $r['handle'], ($r['win_rate'] !== null ? $r['win_rate'] . '% win' : '-') . ' · ' . (int)$r['settled'] . ' settled · ' . (int)$r['followers'] . ' followers', ($u > 0 ? '+' : '') . $u, url('member.php?u=' . $r['handle']));
            }
            $note = 'Self-reported records, not verified and not betting advice.';
            break;
        case 'refboard':
            $title = 'Top referrers';
            $perR = ($_GET['per'] ?? '') === 'month' ? 'month' : 'all';
            foreach (referral_leaderboard($perR, 30) as $i => $r) {
                $items[] = $row(($i + 1) . '. ' . mask_name((string)$r['name']), (int)$r['joined'] . ' friends joined', (int)$r['paid'] . ' VIP');
            }
            $note = 'Ranked by friends who became paying VIPs. Names are shortened for privacy.';
            break;
        case 'badges':
            $uB = api_require_login();
            $title = 'Rewards';
            $uid = (int)$uB['id'];
            $have = user_badges($uid);
            $sk = user_streak($uid);
            $stats = [['Day streak', (string)(int)$sk['current_streak']], ['Best streak', (string)(int)$sk['best_streak']], ['Points (week)', (string)game_user_points($uid, 'week')], ['Points (all time)', (string)game_user_points($uid)]];
            foreach (badge_catalog() as $code => $b) {
                $got = isset($have[$code]);
                $items[] = $row($b[0], $b[1], $got ? 'Earned ' . date('j M Y', strtotime($have[$code])) : 'Not yet');
            }
            $note = 'Visit on consecutive days to grow your streak. Badges are earned automatically.';
            break;
        case 'oddswatch':
            $uW = api_require_login();
            $title = 'Odds alerts';
            if (odds_watch_ready()) {
                $q = db()->prepare('SELECT e.id, e.home_team, e.away_team, e.commence_at FROM odds_watch w JOIN odds_events e ON e.id = w.event_id WHERE w.user_id = ? ORDER BY e.commence_at ASC');
                $q->execute([(int)$uW['id']]);
                foreach ($q->fetchAll() as $r) $items[] = $row($r['home_team'] . ' vs ' . $r['away_team'], stats_local($r['commence_at'], 'D j M, H:i'));
            }
            $note = 'You are told when the best price for a match you watch moves by 5% or more. Add matches from the odds page on the website.';
            break;
        case 'dashboard':
            $uD = api_require_login();
            $title = 'Dashboard';
            $st = db()->prepare("SELECT s.ends_at, p.name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.user_id = ? AND s.status = 'active' AND s.ends_at > NOW() ORDER BY s.ends_at DESC LIMIT 1");
            $st->execute([(int)$uD['id']]);
            $pl = $st->fetch();
            $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
            $st->execute([(int)$uD['id']]);
            $stats = [['Plan', $pl ? $pl['name'] : 'Free'], ['VIP ends', $pl ? date('j M Y', strtotime($pl['ends_at'])) : '-'], ['Unread', (string)(int)$st->fetchColumn()]];
            $items[] = $row('My subscription', '', '', 'n:subscription');
            $items[] = $row('Payment history', '', '', 'n:payments');
            $items[] = $row('Invite friends', '', '', 'n:referrals');
            $items[] = $row('Slip tracker', '', '', 'n:tracker');
            $items[] = $row('Rewards', '', '', 'n:page:badges');
            $items[] = $row('Profile and settings', '', '', 'n:profile');
            break;
        default:
            api_fail('Unknown page', 404);
        }
        api_out(['title' => $title, 'note' => $note, 'stats' => array_map(function ($x) { return ['label' => $x[0], 'value' => $x[1]]; }, $stats), 'items' => $items]);

    /* ---------- slips ---------- */
    case 'slips':
        $u = api_require_login();
        if (!slips_ready()) api_fail('Slip Builder is not available yet.', 503);
        $uid = (int)$u['id'];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            api_require_post();
            $sl = slip_get((int)($_POST['id'] ?? 0), $uid);
            if (!$sl) api_fail('Slip not found.', 404);
            if (($_POST['do'] ?? '') === 'duplicate') {
                $c = db()->prepare('SELECT COUNT(*) FROM slips WHERE user_id = ?'); $c->execute([$uid]);
                if ((int)$c->fetchColumn() >= 100) api_fail('You can keep up to 100 slips. Delete some first.', 422);
                slip_save($uid, null, mb_substr($sl['title'], 0, 70) . ' (copy)', (float)$sl['stake'], $sl['items']);
            } elseif (($_POST['do'] ?? '') === 'delete') {
                db()->prepare('DELETE FROM slip_items WHERE slip_id = ?')->execute([$sl['id']]);
                db()->prepare('DELETE FROM slips WHERE id = ? AND user_id = ?')->execute([$sl['id'], $uid]);
            }
        }
        $st = db()->prepare('SELECT s.id, s.title, s.stake, s.updated_at, COUNT(i.id) AS n, EXP(SUM(LN(i.odds))) AS total FROM slips s LEFT JOIN slip_items i ON i.slip_id = s.id WHERE s.user_id = ? GROUP BY s.id ORDER BY s.updated_at DESC');
        $st->execute([$uid]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'n' => (int)$r['n'], 'total' => $r['total'] ? round((float)$r['total'], 2) : null,
                      'stake' => (float)$r['stake'], 'updated' => date('j M Y, H:i', strtotime($r['updated_at'])),
                      'share_url' => slip_share_url((int)$r['id']), 'pdf_url' => url('slip-pdf.php?id=' . (int)$r['id'])];
        }
        api_out(['items' => $out]);

    case 'slip':
        $u = api_require_login();
        if (!slips_ready()) api_fail('Slip Builder is not available yet.', 503);
        $uid = (int)$u['id'];
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            api_require_post();
            $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 80) ?: 'My slip';
            $stake = trim((string)($_POST['stake'] ?? ''));
            [$posted, $errs] = slip_items_from_post();
            if ($stake !== '' && (!is_numeric($stake) || (float)$stake < 0 || (float)$stake > 100000000)) $errs[] = 'Enter a valid stake or leave it empty.';
            if ($id && !slip_get($id, $uid)) api_fail('Slip not found.', 404);
            if (!$id) { $c = db()->prepare('SELECT COUNT(*) FROM slips WHERE user_id = ?'); $c->execute([$uid]); if ((int)$c->fetchColumn() >= 100) $errs[] = 'You can keep up to 100 slips.'; }
            if ($errs) api_fail(implode(' ', $errs), 422);
            $id = slip_save($uid, $id ?: null, $title, $stake === '' ? 0.0 : (float)$stake, $posted);
        }
        $sl = $id ? slip_get($id, $uid) : null;
        if ($id && !$sl) api_fail('Slip not found.', 404);
        api_out(['id' => $sl ? (int)$sl['id'] : 0, 'title' => $sl ? $sl['title'] : 'My slip', 'stake' => $sl ? (float)$sl['stake'] : 0,
                 'items' => $sl ? array_map(function ($i) { return ['home' => $i['home'], 'away' => $i['away'], 'league' => (string)($i['league'] ?? ''), 'market' => (string)($i['market'] ?? ''), 'kickoff' => (string)($i['kickoff'] ?? ''), 'outcome' => $i['outcome'], 'odds' => (float)$i['odds']]; }, $sl['items']) : [],
                 'markets' => slip_markets(), 'leagues' => slip_leagues_flat(),
                 'ref' => $sl ? slip_ref($sl) : '',
                 'total' => $sl ? $sl['total'] : 0, 'return' => $sl ? $sl['return'] : 0,
                 'share_url' => $sl ? slip_share_url((int)$sl['id']) : '', 'pdf_url' => $sl ? url('slip-pdf.php?id=' . (int)$sl['id']) : '']);

    /* ---------- clubs ---------- */
    case 'clubs':
        $u = api_require_login();
        if (!clubs_ready()) api_fail('Clubs are not available yet.', 503);
        $uid = (int)$u['id'];
        $msg = ''; $newId = 0;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            api_require_post();
            $do = (string)($_POST['do'] ?? '');
            if ($do === 'create') {
                $name = trim((string)($_POST['name'] ?? '')); $about = mb_substr(trim((string)($_POST['about'] ?? '')), 0, 300);
                $own = db()->prepare('SELECT COUNT(*) FROM clubs WHERE owner_id = ?'); $own->execute([$uid]);
                if (mb_strlen($name) < 3 || mb_strlen($name) > 60) api_fail('Club name must be 3 to 60 characters.', 422);
                if ((int)$own->fetchColumn() >= 5) api_fail('You can own up to 5 clubs.', 422);
                db()->prepare('INSERT INTO clubs (owner_id, name, about, invite_code, public_record) VALUES (?,?,?,?,?)')
                    ->execute([$uid, $name, $about, club_new_code(), !empty($_POST['public_record']) && $_POST['public_record'] !== '0' ? 1 : 0]);
                $newId = (int)db()->lastInsertId();
                db()->prepare('INSERT INTO club_members (club_id, user_id) VALUES (?,?)')->execute([$newId, $uid]);
                $msg = 'Club created. Invite your friends from the club page.';
            } elseif ($do === 'join') {
                $cc = club_by_code(trim((string)($_POST['code'] ?? '')));
                if (!$cc) api_fail('That invite code was not found.', 404);
                if (!club_is_member((int)$cc['id'], $uid)) {
                    if ((int)$cc['members'] >= 50) api_fail('This club is full (50 members).', 422);
                    db()->prepare('INSERT IGNORE INTO club_members (club_id, user_id) VALUES (?,?)')->execute([$cc['id'], $uid]);
                }
                $newId = (int)$cc['id'];
                $msg = 'Welcome to ' . $cc['name'] . '!';
            }
        }
        $mine = array_map(function ($c) { return ['id' => (int)$c['id'], 'name' => $c['name'], 'members' => (int)$c['members'], 'public' => (bool)$c['public_record']]; }, club_my($uid));
        $pub = [];
        foreach (db()->query('SELECT c.id, c.name, c.about, (SELECT COUNT(*) FROM club_members x WHERE x.club_id = c.id) AS members FROM clubs c WHERE c.public_record = 1 ORDER BY c.created_at DESC LIMIT 12')->fetchAll() as $c) {
            $pub[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'about' => (string)$c['about'], 'members' => (int)$c['members']];
        }
        api_out(['message' => $msg, 'new_id' => $newId, 'mine' => $mine, 'public' => $pub]);

    case 'club':
        $u = api_require_login();
        if (!clubs_ready()) api_fail('Clubs are not available yet.', 503);
        $uid = (int)$u['id'];
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $c = $id ? club_get($id) : null;
        if (!$c) api_fail('Club not found.', 404);
        $member = club_is_member($id, $uid);
        $owner = (int)$c['owner_id'] === $uid;
        if (!$member && !$c['public_record']) api_fail('This club is private. Ask a member for an invite code.', 403);
        $msg = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            api_require_post();
            if (!$member) api_fail('Join the club first.', 403);
            $do = (string)($_POST['do'] ?? '');
            if ($do === 'pick') {
                $mid = (int)($_POST['match_id'] ?? 0); $pk = (string)($_POST['pick'] ?? ''); $odds = (float)($_POST['odds'] ?? 0);
                $q = db()->prepare('SELECT kickoff_at FROM matches WHERE id = ?'); $q->execute([$mid]); $ko = $q->fetchColumn();
                if (!$ko || strtotime($ko) <= time()) api_fail('That match is not open for selections.', 422);
                if (!in_array($pk, ['1', 'X', '2'], true)) api_fail('Choose home, draw or away.', 422);
                if ($odds < 1.01 || $odds > 100) api_fail('Odds must be between 1.01 and 100.', 422);
                db()->prepare('INSERT INTO club_picks (club_id, user_id, match_id, pick, odds, note) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE pick = VALUES(pick), odds = VALUES(odds), note = VALUES(note)')
                    ->execute([$id, $uid, $mid, $pk, number_format($odds, 2, '.', ''), mb_substr(trim((string)($_POST['note'] ?? '')), 0, 160)]);
                $msg = 'Selection shared with the club.';
            } elseif ($do === 'leave' && !$owner) {
                db()->prepare('DELETE FROM club_members WHERE club_id = ? AND user_id = ?')->execute([$id, $uid]);
                api_out(['left' => true, 'message' => 'You left the club.']);
            } elseif ($owner && $do === 'settings') {
                db()->prepare('UPDATE clubs SET name = ?, about = ?, public_record = ? WHERE id = ?')
                    ->execute([mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60) ?: $c['name'], mb_substr(trim((string)($_POST['about'] ?? '')), 0, 300), !empty($_POST['public_record']) && $_POST['public_record'] !== '0' ? 1 : 0, $id]);
                $msg = 'Club settings saved.';
            } elseif ($owner && $do === 'kick') {
                $ku = (int)($_POST['user_id'] ?? 0);
                if ($ku !== $uid) { db()->prepare('DELETE FROM club_members WHERE club_id = ? AND user_id = ?')->execute([$id, $ku]); db()->prepare('DELETE FROM club_picks WHERE club_id = ? AND user_id = ?')->execute([$id, $ku]); }
                $msg = 'Member removed.';
            } elseif ($owner && $do === 'delete') {
                foreach (['club_picks', 'club_members', 'clubs'] as $t) { db()->prepare("DELETE FROM $t WHERE " . ($t === 'clubs' ? 'id' : 'club_id') . ' = ?')->execute([$id]); }
                api_out(['left' => true, 'message' => 'Club deleted.']);
            }
            $c = club_get($id);
        }
        $all = club_picks($id);
        $picks = $member ? $all : array_values(array_filter($all, function ($p) { return $p['result'] !== 'pending'; }));
        $lab = ['1' => 'Home win', 'X' => 'Draw', '2' => 'Away win'];
        $pj = [];
        foreach (array_slice($picks, 0, 100) as $p) {
            $pj[] = ['match' => $p['home_team'] . ' vs ' . $p['away_team'], 'kickoff' => date('j M, H:i', strtotime($p['kickoff_at'])), 'by' => $p['name'], 'pick' => $lab[$p['pick']] ?? $p['pick'],
                     'odds' => round((float)$p['odds'], 2), 'note' => (string)$p['note'], 'result' => $p['result']];
        }
        $board = array_map(function ($b) { return ['name' => $b['name'], 'n' => (int)$b['n'], 'won' => (int)$b['won'], 'units' => round($b['units'], 2)]; }, club_leaderboard($all));
        $open = []; $mem = [];
        if ($member) {
            foreach (db()->query("SELECT m.id, m.home_team, m.away_team, m.kickoff_at, l.name AS league FROM matches m JOIN leagues l ON l.id = m.league_id WHERE m.kickoff_at > NOW() + INTERVAL 5 MINUTE AND m.kickoff_at < NOW() + INTERVAL 7 DAY ORDER BY m.kickoff_at LIMIT 100")->fetchAll() as $m) {
                $open[] = ['id' => (int)$m['id'], 'title' => $m['home_team'] . ' vs ' . $m['away_team'] . ' (' . date('D j M H:i', strtotime($m['kickoff_at'])) . ')'];
            }
            $q = db()->prepare('SELECT u.id, u.name FROM club_members cm JOIN users u ON u.id = cm.user_id WHERE cm.club_id = ? ORDER BY cm.joined_at');
            $q->execute([$id]);
            foreach ($q->fetchAll() as $mm) $mem[] = ['id' => (int)$mm['id'], 'name' => $mm['name'], 'owner' => (int)$mm['id'] === (int)$c['owner_id']];
        }
        api_out(['message' => $msg, 'id' => (int)$c['id'], 'name' => $c['name'], 'about' => (string)$c['about'], 'public' => (bool)$c['public_record'], 'member' => $member, 'owner' => $owner,
                 'code' => $member ? $c['invite_code'] : '', 'link' => $member ? url('club.php?join=' . $c['invite_code']) : '',
                 'board' => $board, 'picks' => $pj, 'open' => $open, 'members' => $mem]);

    /* ---------- arena (football quiz) ---------- */
    case 'arena':
        if (!arena_ready()) api_fail('The Arena is not available yet.', 503);
        $season = arena_season();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            api_require_post();
            $u = api_require_login();
            $do = (string)($_POST['do'] ?? '');
            if ($do === 'start') {
                $aid = arena_start((int)$u['id'], (string)($_POST['cat'] ?? 'all'));
                if (!$aid) api_fail('Not enough questions in that category yet.', 422);
                $att = arena_attempt($aid, (int)$u['id']);
                $qs = array_map(function ($q) { return ['id' => (int)$q['id'], 'question' => $q['question'], 'options' => [$q['opt1'], $q['opt2'], $q['opt3'], $q['opt4']]]; }, arena_questions($att, false));
                api_out(['attempt' => $aid, 'seconds' => max(0, (int)$att['seconds_allowed'] - (int)$att['elapsed']), 'questions' => $qs]);
            }
            if ($do === 'finish') {
                $att = arena_attempt((int)($_POST['attempt'] ?? 0), (int)$u['id']);
                if (!$att) api_fail('Round not found.', 404);
                $late = false;
                if ($att['finished_at'] === null) { $r = arena_finish($att, (array)($_POST['a'] ?? [])); $late = !empty($r['late']); }
                $att = arena_attempt((int)$att['id'], (int)$u['id']);
                $rev = array_map(function ($q) { return ['question' => $q['question'], 'options' => [$q['opt1'], $q['opt2'], $q['opt3'], $q['opt4']], 'correct' => (int)$q['correct']]; }, arena_questions($att, true));
                api_out(['score' => (int)$att['score'], 'total' => (int)$att['total'], 'late' => $late, 'seconds_used' => (int)$att['seconds_used'], 'review' => $rev]);
            }
            api_fail('Unknown action', 400);
        }
        $view = preg_match('#^\d{4}/\d{2}$#', (string)($_GET['season'] ?? '')) ? (string)$_GET['season'] : $season;
        $board = [];
        foreach (arena_board($view) as $i => $r) $board[] = ['rank' => $i + 1, 'name' => $r['name'], 'rounds' => (int)$r['rounds'], 'correct' => (int)$r['pts'], 'accuracy' => $r['tot'] ? (int)round($r['pts'] / $r['tot'] * 100) : 0];
        $cats = [];
        foreach (arena_categories() as $k => $l) $cats[] = ['key' => $k, 'label' => $l];
        api_out(['season' => $season, 'view' => $view, 'previous' => arena_season(strtotime('-1 year')), 'categories' => $cats, 'board' => $board,
                 'per_round' => ARENA_QUESTIONS, 'seconds_per_q' => ARENA_SECONDS_PER_Q]);

    /* ---------- free prediction game ---------- */
    case 'game':
        $u = current_user();
        $tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? 'pick');
        if (!in_array($tab, ['pick', 'mine', 'week', 'month', 'all'], true)) $tab = 'pick';
        $prev = !empty($_GET['prev']) ? -1 : 0;
        $msg = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            api_require_post();
            $u = api_require_login();
            $open = [];
            foreach (game_open_matches(72) as $m) $open[(int)$m['id']] = $m;
            $saved = 0; $late = 0;
            foreach ((array)($_POST['p'] ?? []) as $mid => $pk) {
                $mid = (int)$mid;
                if (!in_array($pk, ['1', 'X', '2'], true) || !isset($open[$mid])) continue;
                if (!game_can_pick($open[$mid])) { $late++; continue; }
                db()->prepare("INSERT INTO game_picks (user_id, match_id, pick) VALUES (?,?,?) ON DUPLICATE KEY UPDATE pick = IF(status = 'open', VALUES(pick), pick), updated_at = IF(status = 'open', NOW(), updated_at)")
                    ->execute([$u['id'], $mid, $pk]);
                $saved++;
            }
            if ($saved) badge_award((int)$u['id'], 'first_pick');
            if (!$saved) api_fail($late ? 'Those matches are about to start, picks are closed.' : 'Choose 1, X or 2 for at least one match.', 422);
            $msg = 'Saved ' . $saved . ' pick' . ($saved === 1 ? '' : 's') . '. Good luck!';
        }
        $out = ['tab' => $tab, 'message' => $msg, 'logged_in' => (bool)$u, 'points_per_pick' => (int)game_points_correct(), 'items' => [], 'stats' => [], 'label' => ''];
        if ($tab === 'pick') {
            $ms = game_open_matches(72);
            $mine = [];
            if ($u && $ms) {
                $ids = implode(',', array_map('intval', array_column($ms, 'id')));
                $q = db()->prepare("SELECT match_id, pick FROM game_picks WHERE user_id = ? AND match_id IN ($ids)");
                $q->execute([$u['id']]);
                foreach ($q->fetchAll() as $r) $mine[(int)$r['match_id']] = $r['pick'];
            }
            foreach ($ms as $m) {
                $out['items'][] = ['id' => (int)$m['id'], 'home' => $m['home_team'], 'away' => $m['away_team'], 'league' => $m['league'], 'day' => date('l j F', strtotime($m['kickoff_at'])),
                                   'time' => date('H:i', strtotime($m['kickoff_at'])), 'can' => game_can_pick($m), 'pick' => $mine[(int)$m['id']] ?? ''];
            }
        } elseif ($tab === 'mine') {
            if (!$u) api_fail('Please log in.', 401, ['login' => true]);
            $st = db()->prepare("SELECT p.pick, p.status, m.home_team, m.away_team, m.kickoff_at, m.home_score, m.away_score FROM game_picks p JOIN matches m ON m.id = p.match_id WHERE p.user_id = ? ORDER BY m.kickoff_at DESC LIMIT 60");
            $st->execute([$u['id']]);
            foreach ($st->fetchAll() as $r) {
                $out['items'][] = ['home' => $r['home_team'], 'away' => $r['away_team'], 'time' => date('j M, H:i', strtotime($r['kickoff_at'])), 'pick' => $r['pick'], 'status' => $r['status'],
                                   'score' => $r['home_score'] !== null ? (int)$r['home_score'] . ' - ' . (int)$r['away_score'] : ''];
            }
            $out['stats'] = [['Week', (string)game_user_points((int)$u['id'], 'week')], ['Month', (string)game_user_points((int)$u['id'], 'month')], ['All time', (string)game_user_points((int)$u['id'])], ['Streak', (string)(int)user_streak((int)$u['id'])['current_streak']]];
        } else {
            $per = $tab === 'all' ? null : game_period($tab, $prev);
            $out['label'] = $per ? $per['label'] : 'All time';
            foreach (game_leaderboard($tab, $prev, 50) as $i => $r) {
                $me = $u && (int)$r['user_id'] === (int)$u['id'];
                $out['items'][] = ['rank' => $i + 1, 'name' => mask_name((string)$r['name']) . ($me ? ' (you)' : ''), 'points' => (int)$r['points'], 'correct' => (int)$r['correct']];
            }
            if ($tab !== 'all') {
                $pz = array_values(array_filter(array_map('intval', explode(',', (string)setting($tab === 'month' ? 'game_monthly_prizes' : 'game_weekly_prizes', ''))), function ($d) { return $d > 0; }));
                $out['prizes'] = $pz;
            }
        }
        api_out($out);

    /* ---------- my public profile ---------- */
    case 'public_profile':
        $u = api_require_login();
        $uid = (int)$u['id'];
        if (!members_ready()) api_fail('Public profiles are not available yet.', 503);
        $msg = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            api_require_post();
            $do = (string)($_POST['do'] ?? '');
            if ($do === 'share_all' || $do === 'share_none') {
                db()->prepare('UPDATE user_bets SET is_public = ? WHERE user_id = ? AND deleted_at IS NULL')->execute([$do === 'share_all' ? 1 : 0, $uid]);
                $msg = $do === 'share_all' ? 'All your tracked bets are now shared.' : 'None of your bets are shared now.';
            } elseif ($do === 'save') {
                $h = strtolower(trim((string)($_POST['handle'] ?? '')));
                $bio = trim((string)($_POST['bio'] ?? ''));
                $on = !empty($_POST['is_public']) && $_POST['is_public'] !== '0';
                $stk = !empty($_POST['show_stakes']) && $_POST['show_stakes'] !== '0';
                if ($h === '') api_fail('Choose a username first.', 422);
                if ($m = member_handle_error($h, $uid)) api_fail($m, 422);
                if (mb_strlen($bio) > 160) api_fail('Your bio is too long (160 characters maximum).', 422);
                if (preg_match('~(https?:|www\.|\b[a-z0-9\-]+\.(com|net|org|co|io|ke|info|me|tv|bet|xyz)\b)~i', $bio)) api_fail('Links are not allowed in your bio.', 422);
                if (function_exists('looks_like_secret') && looks_like_secret($bio)) api_fail('Never share passwords or codes.', 422);
                db()->prepare('INSERT INTO member_public (user_id, handle, bio, is_public, show_stakes) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE handle = VALUES(handle), bio = VALUES(bio), is_public = VALUES(is_public), show_stakes = VALUES(show_stakes)')
                    ->execute([$uid, $h, $bio, $on ? 1 : 0, $stk ? 1 : 0]);
                $msg = $on ? 'Your public profile is live.' : 'Saved. Your profile is hidden from everyone.';
            }
        }
        $me = member_row_for_user($uid);
        $q = db()->prepare('SELECT COUNT(*), SUM(is_public = 1) FROM user_bets WHERE user_id = ? AND deleted_at IS NULL'); $q->execute([$uid]);
        [$allBets, $shared] = array_map('intval', $q->fetch(PDO::FETCH_NUM));
        $stats = $me ? member_stats($uid, !empty($me['show_stakes'])) : null;
        api_out(['message' => $msg, 'handle' => $me['handle'] ?? '', 'bio' => $me['bio'] ?? '', 'on' => !empty($me['is_public']), 'stakes' => !empty($me['show_stakes']),
                 'followers' => $me ? member_followers($uid) : 0, 'shared' => (int)$shared, 'bets' => (int)$allBets,
                 'units' => $stats && $stats['settled'] ? round((float)$stats['units'], 2) : null, 'win_rate' => $stats && $stats['settled'] ? $stats['win_rate'] : null, 'settled' => $stats ? (int)$stats['settled'] : 0,
                 'url' => $me && !empty($me['is_public']) ? url('member.php?u=' . $me['handle']) : '']);

    /* ---------- league page (tips for one league) ---------- */
    case 'league':
        require_once dirname(__DIR__, 2) . '/includes/seo.php';
        $lid = (int)($_GET['id'] ?? 0);
        $l = $lid ? seo_league($lid) : null;
        if (!$l) api_fail('League not found.', 404);
        $rec = seo_league_record($lid); $rec60 = seo_league_record($lid, 60);
        api_out(['id' => $lid, 'name' => $l['name'], 'sport' => $l['sport'],
                 'win_rate' => $rec['win_rate'], 'settled' => (int)$rec['settled'], 'won' => (int)$rec['won'], 'lost' => (int)$rec['lost'], 'win_rate_60' => $rec60['win_rate'], 'settled_60' => (int)$rec60['settled'],
                 'upcoming' => array_map('api_tip', seo_tips_query(['league_id' => $lid, 'when' => 'upcoming', 'limit' => 24])),
                 'recent' => array_map('api_tip', seo_tips_query(['league_id' => $lid, 'when' => 'recent', 'limit' => 12]))]);

    /* ---------- tipsters, leaderboard, blog ---------- */
    case 'tipsters':
        $sort = in_array($_GET['sort'] ?? '', ['rating', 'winrate', 'newest', 'subs'], true) ? $_GET['sort'] : 'rating';
        $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
        $out = [];
        foreach (tipsters_directory($sort, $q) as $t) {
            $out[] = api_tipster($t) + ['tagline' => (string)$t['tagline'], 'win_rate' => $t['win_rate'] !== null ? (int)$t['win_rate'] : null,
                     'settled' => (int)$t['settled'], 'subs' => (int)$t['subs'], 'specialties' => (string)$t['specialties'],
                     'from_kes' => $t['from_kes'] !== null ? (int)$t['from_kes'] : null];
        }
        api_out(['items' => $out]);

    case 'leaderboard':
        $periods = ['30' => 30, '90' => 90, 'all' => 0];
        $per = isset($periods[$_GET['period'] ?? '']) ? $_GET['period'] : '30';
        $sort = in_array($_GET['sort'] ?? '', ['units', 'winrate', 'rating'], true) ? $_GET['sort'] : 'units';
        $out = [];
        foreach (leaderboard_rows($periods[$per], $sort) as $i => $r) {
            $out[] = api_tipster($r) + ['rank' => $i + 1, 'units' => round((float)$r['units'], 1), 'win_rate' => (int)$r['win_rate'],
                     'won' => (int)$r['w'], 'lost' => (int)$r['l'], 'avg_odds' => round((float)$r['avg_odds'], 2), 'subs' => (int)$r['subs']];
        }
        api_out(['items' => $out, 'period' => $per, 'sort' => $sort]);

    case 'blog':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = 12;
        $hasCat = blog_has_category();
        $bq = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
        $bc = mb_substr(trim((string)($_GET['cat'] ?? '')), 0, 40);
        $where = "WHERE status = 'published'"; $bargs = [];
        if ($bc !== '' && $hasCat) { $where .= ' AND category = ?'; $bargs[] = $bc; }
        if ($bq !== '') { $bl = '%' . addcslashes($bq, '%_\\') . '%'; $where .= ' AND (title LIKE ? OR excerpt LIKE ?)'; array_push($bargs, $bl, $bl); }
        $st = db()->prepare("SELECT COUNT(*) FROM posts $where"); $st->execute($bargs);
        $total = (int)$st->fetchColumn();
        $st = db()->prepare("SELECT title, slug, excerpt, image_url, created_at" . ($hasCat ? ', category' : '') . " FROM posts $where ORDER BY created_at DESC LIMIT $per OFFSET " . (($page - 1) * $per));
        $st->execute($bargs);
        $out = [];
        foreach ($st->fetchAll() as $p) {
            $out[] = ['title' => $p['title'], 'excerpt' => (string)$p['excerpt'], 'image' => blog_cover($p['image_url']), 'category' => (string)($p['category'] ?? ''),
                      'date' => date('j M Y', strtotime($p['created_at'])), 'url' => url('post.php?slug=' . urlencode($p['slug']))];
        }
        $catsOut = [];
        if ($page === 1) { foreach (blog_categories() as $c) { $catsOut[] = ['name' => $c['name'], 'n' => (int)$c['n']]; } }
        api_out(['items' => $out, 'page' => $page, 'more' => $total > $page * $per, 'categories' => $catsOut]);

    default:
        api_fail('Unknown action', 404);
    }
} catch (Throwable $e) {
    $ref = strtoupper(substr(md5(microtime() . mt_rand()), 0, 6));
    error_log('api v1 [' . $ref . '] ' . ($a ?? '') . ': ' . $e->getMessage());
    api_fail('The server had a problem. Please try again in a minute. If it keeps happening, tell support code ' . $ref . '.', 500);
}
