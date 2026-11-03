<?php
/**
 * Alerts: in-app notification (always) plus browser push, email and SMS if the member turned them on.
 * Everything goes through alert_enqueue(); alerts_process() (cron/send-alerts.php, and a small batch right after a tip is posted) delivers.
 * Each alert has a dedupe key so the same alert is never sent twice to the same person.
 */

function alert_prefs(int $userId): array
{
    $st = db()->prepare('SELECT push, email, sms, kickoff, results, vip FROM alert_prefs WHERE user_id = ?');
    $st->execute([$userId]);
    $r = $st->fetch();
    return $r ?: ['push' => 1, 'email' => 0, 'sms' => 0, 'kickoff' => 1, 'results' => 1, 'vip' => 1];
}

/**
 * Queues one alert for one member. $kind: 'tip' (a followed tipster posted), 'kickoff', 'result', 'vip'.
 * Returns false if it was already queued before (same $dedupe).
 */
function alert_enqueue(int $userId, string $kind, string $title, string $body, string $path, string $dedupe): bool
{
    $pdo = db();
    $p = alert_prefs($userId);
    $prefKey = ['kickoff' => 'kickoff', 'goal' => 'kickoff', 'lineup' => 'kickoff', 'result' => 'results', 'vip' => 'vip'][$kind] ?? null;
    if ($prefKey !== null && empty($p[$prefKey])) {
        return false;
    }
    if (function_exists('alert_is_muted') && alert_is_muted($userId, $title . ' ' . $body)) { return false; }
    $dedupe = substr($dedupe, 0, 60);
    $title = mb_substr($title, 0, 110);
    $body  = mb_substr($body, 0, 280);
    // The "push" row is always written first: it is also the marker that stops a second in-app notification.
    $marker = $pdo->prepare('INSERT IGNORE INTO alert_queue (user_id, channel, title, body, url, dedupe_key, sent_at, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $pushOn = !empty($p['push']);
    $marker->execute([$userId, 'push', $title, $body, $path, $dedupe, $pushOn ? null : date('Y-m-d H:i:s'), $pushOn ? null : 'off']);
    if ($marker->rowCount() !== 1) {
        return false;
    }
    notify_user($userId, $title, $body, $path, $pushOn);
    if (!empty($p['email'])) {
        $pdo->prepare('INSERT IGNORE INTO alert_queue (user_id, channel, title, body, url, dedupe_key) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, 'email', $title, $body, $path, $dedupe]);
    }
    if (!empty($p['sms']) && sms_enabled()) {
        $pdo->prepare('INSERT IGNORE INTO alert_queue (user_id, channel, title, body, url, dedupe_key) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, 'sms', $title, $body, $path, $dedupe]);
    }
    return true;
}

/** Delivers queued alerts. Returns [sent, failed]. */
function alerts_process(int $limit = 40): array
{
    $pdo = db();
    $rows = $pdo->query("SELECT q.*, u.email AS u_email, u.name AS u_name, u.email_optout, u.mobile, u.mobile_verified
                         FROM alert_queue q JOIN users u ON u.id = q.user_id
                         WHERE q.sent_at IS NULL AND q.tries < 3 AND q.created_at > NOW() - INTERVAL 2 DAY
                         ORDER BY q.id ASC LIMIT " . (int)$limit)->fetchAll();
    $sent = 0; $failed = 0;
    foreach ($rows as $r) {
        $claim = $pdo->prepare('UPDATE alert_queue SET tries = tries + 1 WHERE id = ? AND sent_at IS NULL AND tries = ?');
        $claim->execute([$r['id'], $r['tries']]);
        if ($claim->rowCount() !== 1) {
            continue;       // another run has it
        }
        $ok = false; $err = '';
        if ($r['channel'] === 'push') {
            $subs = $pdo->prepare('SELECT * FROM push_subs WHERE user_id = ?');
            $subs->execute([$r['user_id']]);
            $list = $subs->fetchAll();
            if (!$list) {
                $ok = true; $err = 'no device';
            } elseif (!webpush_ready()) {
                $ok = true; $err = 'push not set up';
            } else {
                $json = json_encode(['title' => $r['title'], 'body' => $r['body'], 'url' => $r['url']]);
                foreach ($list as $s) {
                    $code = webpush_send($s, $json);
                    if ($code >= 200 && $code < 300) {
                        $ok = true;
                    } elseif ($code === 404 || $code === 410) {
                        $pdo->prepare('DELETE FROM push_subs WHERE id = ?')->execute([$s['id']]);
                    } else {
                        $err = 'push ' . $code;
                    }
                }
                if (!$ok && $err === '') { $ok = true; $err = 'device gone'; }
            }
        } elseif ($r['channel'] === 'email') {
            if ((int)$r['email_optout'] === 1) {
                $ok = true; $err = 'opted out';
            } else {
                $link = rtrim(SITE_URL, '/') . '/' . ltrim((string)$r['url'], '/');
                $ok = send_mail($r['u_email'], $r['title'], "Hi {$r['u_name']},\n\n{$r['body']}\n\n$link\n\nChange which alerts you get: " . rtrim(SITE_URL, '/') . "/account/alerts.php\n\n" . SITE_NAME);
                $err = $ok ? '' : 'mail failed';
            }
        } else {            // sms
            $cap = $pdo->prepare("SELECT COUNT(*) FROM alert_queue WHERE user_id = ? AND channel = 'sms' AND sent_at > NOW() - INTERVAL 1 DAY AND error IS NULL");
            $cap->execute([$r['user_id']]);
            if (!sms_enabled() || empty($r['mobile']) || empty($r['mobile_verified'])) {
                $ok = true; $err = 'no confirmed mobile';
            } elseif ((int)$cap->fetchColumn() >= 3) {
                $ok = true; $err = 'daily sms limit';
            } else {
                $e2 = '';
                $ok = sms_send((string)$r['mobile'], SITE_NAME . ': ' . $r['body'] . ' ' . rtrim(SITE_URL, '/') . '/' . ltrim((string)$r['url'], '/'), $e2);
                $err = $ok ? '' : mb_substr($e2, 0, 100);
            }
        }
        if ($ok) {
            $pdo->prepare('UPDATE alert_queue SET sent_at = NOW(), error = ? WHERE id = ?')->execute([$err !== '' ? $err : null, $r['id']]);
            $sent++;
        } else {
            $pdo->prepare('UPDATE alert_queue SET error = ? WHERE id = ?')->execute([mb_substr($err, 0, 100), $r['id']]);
            $failed++;
        }
    }
    if (function_exists('fcm_process')) { fcm_process($limit); }
    return [$sent, $failed];
}

/** Followers of a tipster plus members who currently have a subscription. */
function alert_tipster_audience(int $tipsterId, int $max = 1500): array
{
    $st = db()->prepare("SELECT user_id FROM tipster_follows WHERE tipster_id = ?
                         UNION SELECT user_id FROM tipster_subscriptions WHERE tipster_id = ? AND ends_at > NOW() LIMIT " . (int)$max);
    $st->execute([$tipsterId, $tipsterId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** A tipster posted a tip: followers and subscribers hear about it. The message never shows a premium pick. */
function alerts_new_tip(int $tipId): int
{
    $pdo = db();
    $st = $pdo->prepare('SELECT t.id, t.tipster_id, t.is_vip, m.home_team, m.away_team, m.kickoff_at, ti.display_name, ti.slug, ti.user_id AS owner
                         FROM tips t JOIN matches m ON m.id = t.match_id JOIN tipsters ti ON ti.id = t.tipster_id
                         WHERE t.id = ? AND t.deleted_at IS NULL');
    $st->execute([$tipId]);
    $t = $st->fetch();
    if (!$t) {
        return 0;
    }
    $n = 0;
    $title = $t['display_name'] . ' posted a new tip';
    $body  = $t['home_team'] . ' vs ' . $t['away_team'] . ', kickoff ' . date('D H:i', strtotime($t['kickoff_at'])) . ($t['is_vip'] ? ' (premium)' : ' (free)') . '. Open the site to see it.';
    foreach (alert_tipster_audience((int)$t['tipster_id']) as $uid) {
        if ($uid === (int)$t['owner']) {
            continue;
        }
        if (alert_enqueue($uid, 'tip', $title, $body, 'tipster.php?slug=' . rawurlencode($t['slug']), 'tip-' . $tipId)) {
            $n++;
        }
    }
    alerts_process(30);
    return $n;
}

/** A tip was settled: members who tracked it in their bet tracker, and followers of its tipster, are told. */
function alerts_tip_settled(int $tipId, string $result): int
{
    if (!in_array($result, ['won', 'lost', 'void'], true)) {
        return 0;
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT t.id, t.tipster_id, t.pick, m.home_team, m.away_team FROM tips t JOIN matches m ON m.id = t.match_id WHERE t.id = ?');
    $st->execute([$tipId]);
    $t = $st->fetch();
    if (!$t) {
        return 0;
    }
    $ids = $pdo->prepare('SELECT DISTINCT user_id FROM user_bets WHERE tip_id = ? AND deleted_at IS NULL LIMIT 1500');
    $ids->execute([$tipId]);
    $users = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
    $path = 'tip.php?id=' . $tipId;
    if (function_exists('tip_watchers')) { $users = array_merge($users, tip_watchers($tipId)); }
    if (!empty($t['tipster_id'])) {
        foreach (alert_tipster_audience((int)$t['tipster_id'], 500) as $u) {
            $users[] = $u;
        }
    }
    $label = ['won' => 'Your tip won', 'lost' => 'Your tip lost', 'void' => 'Your tip was void (stake back)'][$result];
    $n = 0;
    foreach (array_unique($users) as $uid) {
        if (alert_enqueue($uid, 'result', $label, $t['home_team'] . ' vs ' . $t['away_team'] . ': ' . $t['pick'] . ' - ' . $result . '.', $path, 'res-' . $tipId)) {
            $n++;
        }
    }
    return $n;
}

/** Kickoff-in-about-an-hour reminders. Call every few minutes. */
function alerts_kickoff_reminders(): int
{
    $pdo = db();
    $ms = $pdo->query("SELECT id, home_team, away_team, kickoff_at FROM matches
                       WHERE kickoff_at > NOW() + INTERVAL 45 MINUTE AND kickoff_at <= NOW() + INTERVAL 75 MINUTE LIMIT 60")->fetchAll();
    $n = 0;
    foreach ($ms as $m) {
        $users = [];
        $q = $pdo->prepare("SELECT DISTINCT user_id FROM game_picks WHERE match_id = ? AND status = 'open'");
        $q->execute([$m['id']]);
        $users = array_merge($users, $q->fetchAll(PDO::FETCH_COLUMN));
        $q = $pdo->prepare("SELECT DISTINCT b.user_id FROM user_bets b JOIN tips t ON t.id = b.tip_id WHERE t.match_id = ? AND b.status = 'pending' AND b.deleted_at IS NULL");
        $q->execute([$m['id']]);
        $users = array_merge($users, $q->fetchAll(PDO::FETCH_COLUMN));
        $q = $pdo->prepare("SELECT DISTINCT f.user_id FROM tipster_follows f JOIN tips t ON t.tipster_id = f.tipster_id
                            WHERE t.match_id = ? AND t.status = 'pending' AND t.deleted_at IS NULL");
        $q->execute([$m['id']]);
        $users = array_merge($users, $q->fetchAll(PDO::FETCH_COLUMN));
        foreach (array_unique(array_map('intval', $users)) as $uid) {
            if (alert_enqueue($uid, 'kickoff', 'Kickoff in about an hour',
                              $m['home_team'] . ' vs ' . $m['away_team'] . ' starts at ' . date('H:i', strtotime($m['kickoff_at'])) . '.', 'match.php?id=' . $m['id'], 'ko-' . $m['id'])) {
                $n++;
            }
        }
    }
    return $n;
}

/** VIP plan ends within 24 hours. One alert per plan end date. */
function alerts_vip_expiring(): int
{
    $rows = db()->query("SELECT user_id, MAX(ends_at) AS ends_at FROM subscriptions WHERE status = 'active' AND ends_at > NOW()
                         GROUP BY user_id HAVING MAX(ends_at) < NOW() + INTERVAL 24 HOUR LIMIT 500")->fetchAll();
    $n = 0;
    foreach ($rows as $r) {
        if (alert_enqueue((int)$r['user_id'], 'vip', 'Your VIP ends within 24 hours',
                          'Your VIP plan ends ' . date('j M, H:i', strtotime($r['ends_at'])) . '. Renew to keep every pick.', 'vip.php', 'vip-' . date('ymdH', strtotime($r['ends_at'])))) {
            $n++;
        }
    }
    return $n;
}

/* ======================================================================
 * Firebase Cloud Messaging for the Android app (HTTP v1, no libraries).
 * Admin > Android app > "Firebase push" holds the service-account key.
 * Every in-app notification is also sent to the member's phone(s) when the key is set.
 * ====================================================================== */

function app_push_ready(): bool { return sk_ensure_table('app_devices', 'database-update-27.sql'); }

function fcm_file(): string { return dirname(__DIR__) . '/private-uploads/app/fcm.json'; }

/** Service-account details, or null when push is not set up. */
function fcm_config(): ?array
{
    static $c = false;
    if ($c !== false) { return $c; }
    $c = null;
    $f = fcm_file();
    if (is_file($f)) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j) && !empty($j['client_email']) && !empty($j['private_key']) && !empty($j['project_id'])) { $c = $j; }
    }
    return $c;
}

function fcm_ready(): bool { return fcm_config() !== null && function_exists('curl_init') && function_exists('openssl_sign'); }

function fcm_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function fcm_http(string $url, string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5]);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($out) ? $out : ''];
}

/** OAuth token for Firebase, cached for about 50 minutes. */
function fcm_access_token(): ?string
{
    $cfg = fcm_config();
    if (!$cfg) { return null; }
    $cache = dirname(__DIR__) . '/cache/fcm-token.json';
    $c = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    if (is_array($c) && !empty($c['t']) && ($c['e'] ?? 0) > time() + 120 && ($c['p'] ?? '') === $cfg['project_id']) { return (string)$c['t']; }
    $uri = (string)($cfg['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    $now = time();
    $head = fcm_b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = fcm_b64(json_encode(['iss' => $cfg['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => $uri, 'iat' => $now, 'exp' => $now + 3600]));
    $sig = '';
    if (!openssl_sign($head . '.' . $claim, $sig, $cfg['private_key'], OPENSSL_ALGO_SHA256)) { return null; }
    [$code, $out] = fcm_http($uri, http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $head . '.' . $claim . '.' . fcm_b64($sig)]), ['Content-Type: application/x-www-form-urlencoded']);
    $j = json_decode($out, true);
    if ($code !== 200 || empty($j['access_token'])) { error_log('fcm token: ' . $code . ' ' . substr($out, 0, 200)); return null; }
    @file_put_contents($cache, json_encode(['t' => $j['access_token'], 'e' => $now + (int)($j['expires_in'] ?? 3000), 'p' => $cfg['project_id']]), LOCK_EX);
    return (string)$j['access_token'];
}

/** Sends one data message. $target = ['token' => ...] or ['topic' => ...]. Returns [httpCode, responseBody]. */
function fcm_send(array $target, string $title, string $body, string $path, string $id = ''): array
{
    $cfg = fcm_config(); $tok = fcm_access_token();
    if (!$cfg || !$tok) { return [0, 'not ready']; }
    $msg = ['data' => ['title' => mb_substr($title, 0, 110), 'body' => mb_substr($body, 0, 240), 'path' => $path, 'id' => $id], 'android' => ['priority' => 'HIGH', 'ttl' => '86400s']] + $target;
    return fcm_http('https://fcm.googleapis.com/v1/projects/' . rawurlencode($cfg['project_id']) . '/messages:send', json_encode(['message' => $msg]), ['Authorization: Bearer ' . $tok, 'Content-Type: application/json']);
}

function fcm_queue(int $userId, string $title, string $body, string $path): void
{
    try {
        if (!fcm_ready() || !app_push_ready()) { return; }
        if ($userId > 0) {
            $h = db()->prepare('SELECT 1 FROM app_devices WHERE user_id = ? LIMIT 1'); $h->execute([$userId]);
            if (!$h->fetchColumn()) { return; }
        }
        db()->prepare('INSERT INTO app_push_queue (user_id, title, body, path) VALUES (?, ?, ?, ?)')
            ->execute([$userId, mb_substr($title, 0, 150), mb_substr(trim(preg_replace('/\s+/', ' ', $body)), 0, 300), mb_substr($path !== '' ? $path : 'account/notifications.php', 0, 250)]);
        static $hooked = false;
        if (!$hooked) {      // deliver right after the page is sent, so phones buzz within seconds
            $hooked = true;
            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
                try { fcm_process(25); } catch (Throwable $e) { }
            });
        }
    } catch (Throwable $e) { error_log('fcm_queue: ' . $e->getMessage()); }
}

/** Delivers waiting phone notifications. Returns [sent, failed]. */
function fcm_process(int $limit = 40): array
{
    $sent = 0; $failed = 0;
    try {
        if (!fcm_ready() || !app_push_ready()) { return [0, 0]; }
        $pdo = db();
        $rows = $pdo->query("SELECT * FROM app_push_queue WHERE sent_at IS NULL AND tries < 3 AND created_at > NOW() - INTERVAL 1 DAY ORDER BY id ASC LIMIT " . (int)$limit)->fetchAll();
        foreach ($rows as $r) {
            $claim = $pdo->prepare('UPDATE app_push_queue SET tries = tries + 1 WHERE id = ? AND sent_at IS NULL AND tries = ?');
            $claim->execute([$r['id'], $r['tries']]);
            if ($claim->rowCount() !== 1) { continue; }
            $ok = false; $err = '';
            if ((int)$r['user_id'] === 0) {
                [$code, $out] = fcm_send(['topic' => 'all'], $r['title'], $r['body'], $r['path'], 'q' . $r['id']);
                $ok = $code >= 200 && $code < 300; $err = $ok ? '' : 'fcm ' . $code;
            } else {
                $d = $pdo->prepare('SELECT id, token FROM app_devices WHERE user_id = ?'); $d->execute([$r['user_id']]);
                $devs = $d->fetchAll();
                if (!$devs) { $ok = true; $err = 'no device'; }
                foreach ($devs as $dv) {
                    [$code, $out] = fcm_send(['token' => $dv['token']], $r['title'], $r['body'], $r['path'], 'q' . $r['id']);
                    if ($code >= 200 && $code < 300) { $ok = true; }
                    elseif ($code === 404 || ($code === 400 && stripos($out, 'INVALID_ARGUMENT') !== false) || stripos($out, 'UNREGISTERED') !== false) { $pdo->prepare('DELETE FROM app_devices WHERE id = ?')->execute([$dv['id']]); $ok = $ok ?: false; $err = 'token gone'; }
                    else { $err = 'fcm ' . $code; }
                }
                if (!$ok && $err === 'token gone') { $ok = true; }
            }
            if ($ok) { $pdo->prepare('UPDATE app_push_queue SET sent_at = NOW(), error = ? WHERE id = ?')->execute([$err !== '' ? $err : null, $r['id']]); $sent++; }
            else { $pdo->prepare('UPDATE app_push_queue SET error = ? WHERE id = ?')->execute([mb_substr($err, 0, 100), $r['id']]); $failed++; }
        }
    } catch (Throwable $e) { error_log('fcm_process: ' . $e->getMessage()); }
    return [$sent, $failed];
}

/* ---------- "game started" and "live now" alerts ---------- */

/** Everyone who should hear about a site-wide game: members with the kickoff alert on (the alert itself respects each member's setting). */
function alerts_all_members(int $max = 5000): array
{
    return array_map('intval', db()->query("SELECT id FROM users WHERE role = 'user' ORDER BY id DESC LIMIT " . (int)$max)->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * When a game that has tips starts, members get "the game has started". If the game has a live-stream link they get
 * "LIVE NOW: watch" instead (also when the link is added a little after kickoff). The 2 Odds Streak pick works the same way.
 * Each member gets each alert once (dedupe keys). Never shows a VIP pick. Call every few minutes.
 */
function alerts_match_started(): int
{
    $pdo = db();
    $n = 0;
    $hasStream = function_exists('stream_col') && stream_col();
    $sel = 'm.id, m.home_team, m.away_team, m.kickoff_at, ' . ($hasStream ? 'm.stream_url' : "'' AS stream_url");
    $ms = $pdo->query("SELECT $sel,
                              (SELECT t.id FROM tips t WHERE t.match_id = m.id AND t.deleted_at IS NULL AND t.status = 'pending' ORDER BY t.tipster_id IS NULL DESC, t.id LIMIT 1) AS tip_id,
                              (SELECT COUNT(*) FROM tips t WHERE t.match_id = m.id AND t.deleted_at IS NULL AND t.status = 'pending' AND t.tipster_id IS NULL) AS site_tips
                       FROM matches m
                       WHERE m.kickoff_at <= NOW() AND m.kickoff_at > NOW() - INTERVAL 3 HOUR
                         AND EXISTS (SELECT 1 FROM tips t WHERE t.match_id = m.id AND t.deleted_at IS NULL AND t.status = 'pending')
                       LIMIT 40")->fetchAll();
    $members = null;
    foreach ($ms as $m) {
        $live = $hasStream && function_exists('stream_clean') && stream_clean((string)$m['stream_url']) !== '';
        $age = time() - strtotime($m['kickoff_at']);
        if (!$live && $age > 1800) { continue; }          // plain "started" alerts only within 30 minutes of kickoff
        if ((int)$m['site_tips'] > 0) {
            if ($members === null) { $members = alerts_all_members(); }
            $aud = $members;
        } else {
            $q = $pdo->prepare("SELECT DISTINCT f.user_id FROM tipster_follows f JOIN tips t ON t.tipster_id = f.tipster_id WHERE t.match_id = ? AND t.status = 'pending' AND t.deleted_at IS NULL");
            $q->execute([$m['id']]);
            $aud = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        }
        $name = $m['home_team'] . ' vs ' . $m['away_team'];
        $path = 'tip.php?id=' . (int)$m['tip_id'];
        foreach ($aud as $uid) {
            $ok = $live
                ? alert_enqueue($uid, 'kickoff', 'LIVE NOW: ' . $name, 'The live stream has started. Open the tip and tap Watch live.', $path, 'live-' . $m['id'])
                : alert_enqueue($uid, 'kickoff', 'Game started: ' . $name, 'The game has kicked off. Follow your tips live.', $path, 'st-' . $m['id']);
            if ($ok) { $n++; }
        }
    }
    // the 2 Odds Streak pick
    if (function_exists('streak_ready') && streak_ready()) {
        $ds = $pdo->query("SELECT id, home, away, stream_url, kickoff_at FROM streak_days
                           WHERE status = 'pending' AND kickoff_at <= NOW() AND kickoff_at > NOW() - INTERVAL 3 HOUR LIMIT 3")->fetchAll();
        foreach ($ds as $d) {
            $live = function_exists('stream_clean') && stream_clean((string)$d['stream_url']) !== '';
            if (!$live && time() - strtotime($d['kickoff_at']) > 1800) { continue; }
            if ($members === null) { $members = alerts_all_members(); }
            $name = $d['home'] . ' vs ' . $d['away'];
            foreach ($members as $uid) {
                $ok = $live
                    ? alert_enqueue($uid, 'kickoff', 'LIVE NOW: ' . $name, 'Today\'s 2 Odds Streak game is live. Tap to watch.', 'streak.php', 'sk-live-' . $d['id'])
                    : alert_enqueue($uid, 'kickoff', 'Streak game started: ' . $name, 'Today\'s 2 Odds Streak game has kicked off.', 'streak.php', 'sk-st-' . $d['id']);
                if ($ok) { $n++; }
            }
        }
    }
    return $n;
}

/** Runs from any page load at most every 5 minutes (after the page is sent), so alerts go out even without a cron job. */
function alerts_maybe_run(): void
{
    try {
        $last = (int)setting('alerts_started_ts', '0');
        if (time() - $last < 300) { return; }
        set_setting('alerts_started_ts', (string)time());
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            @ignore_user_abort(true); @set_time_limit(60);
            try { alerts_match_started(); if (function_exists('alerts2_run')) { alerts2_run(); } alerts_process(60); } catch (Throwable $e) { error_log('alerts_maybe_run: ' . $e->getMessage()); }
        });
    } catch (Throwable $e) { }
}
