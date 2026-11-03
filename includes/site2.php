<?php
/** Site extras: more than one watch button, Channels, page on/off switches, forced app update. */

/* ---------- extra watch buttons ---------- */
function extra_col(string $table): bool
{
    static $ok = [];
    if (!isset($ok[$table])) {
        $col = $table === 'streams' ? 'extra' : 'stream_extra';
        if (!in_array($table, ['matches', 'streams', 'streak_days'], true)) { return $ok[$table] = false; }
        $flag = 'extra_col_' . $table;
        if (setting($flag, '0') === '1') { return $ok[$table] = true; }
        try { $ok[$table] = sk_ensure_column($table, $col, 'TEXT NULL'); } catch (Throwable $e) { $ok[$table] = false; }
        if ($ok[$table]) { set_setting($flag, '1'); }
    }
    return $ok[$table];
}

/** [{l: label, u: address}] from stored JSON. */
function sources_decode($json): array
{
    $a = is_string($json) && $json !== '' ? json_decode($json, true) : [];
    $out = [];
    foreach (is_array($a) ? $a : [] as $r) {
        $u = stream_clean((string)($r['u'] ?? ''));
        if ($u !== '') { $out[] = ['l' => mb_substr(trim((string)($r['l'] ?? '')), 0, 30) ?: 'Watch', 'u' => $u]; }
    }
    return array_slice($out, 0, 8);
}

/** Rows for "add another watch button" (label + link or widget code). */
function sources_form($json): string
{
    $rows = sources_decode($json);
    $h = '<div class="field full srcs"><label>More watch buttons (optional): each one gets its own button with the name you give it</label>';
    $h .= '<div class="src-rows">';
    foreach ($rows ?: [['l' => '', 'u' => '']] as $r) { $h .= sources_row($r['l'], $r['u']); }
    $h .= '</div><button type="button" class="btn dark tiny src-add">+ Add another button</button></div>';
    $h .= '<script>(function(){var f=document.currentScript.previousElementSibling;if(!f)return;var rows=f.querySelector(".src-rows");f.querySelector(".src-add").onclick=function(){var d=document.createElement("div");d.innerHTML=' . json_encode(sources_row('', '')) . ';rows.appendChild(d.firstChild);};})();</script>';
    return $h;
}
function sources_row(string $l, string $u): string
{
    return '<div class="src-row" style="display:flex;gap:8px;margin:6px 0;flex-wrap:wrap"><input type="text" name="src_l[]" value="' . e($l) . '" maxlength="30" placeholder="Button name, e.g. Server 2" style="flex:1;min-width:140px">'
         . '<textarea name="src_c[]" rows="2" maxlength="20000" placeholder="Link or widget / embed code" style="flex:3;min-width:220px;font-family:monospace">' . e($u) . '</textarea></div>';
}

/** Reads the rows from the form. Returns [json, error]. */
function sources_from_post(): array
{
    $ls = (array)($_POST['src_l'] ?? []); $cs = (array)($_POST['src_c'] ?? []);
    $out = [];
    foreach ($cs as $i => $code) {
        $code = trim((string)$code);
        if ($code === '') { continue; }
        if (preg_match('~^https?://\S+$~i', $code)) { $u = stream_clean($code); $err = $u === '' ? 'One of the extra links is not a valid http(s) link.' : ''; }
        else { [$u, $err] = stream_widget_save($code); }
        if ($err !== '') { return ['', $err]; }
        $out[] = ['l' => mb_substr(trim((string)($ls[$i] ?? '')), 0, 30) ?: 'Watch ' . (count($out) + 2), 'u' => $u];
        if (count($out) >= 8) { break; }
    }
    return [$out ? json_encode($out, JSON_UNESCAPED_SLASHES) : '', ''];
}

function sources_save(string $table, string $where, $whereVal): void
{
    if (!extra_col($table)) { return; }
    [$json, $err] = sources_from_post();
    if ($err !== '') { return; }
    $col = $table === 'streams' ? 'extra' : 'stream_extra';
    db()->prepare("UPDATE $table SET $col = ? WHERE $where = ?")->execute([$json === '' ? null : $json, $whereVal]);
}

/** Extra buttons for a game/stream row: [{label, watch (app), play (modal), url}]. */
function stream_extra_list(array $t): array
{
    $json = $t['stream_extra'] ?? $t['extra'] ?? '';
    $title = function_exists('stream_title') ? stream_title($t) : '';
    $o = function_exists('stream_opts') ? stream_opts($t, '') : [];
    $out = [];
    foreach (sources_decode($json) as $r) {
        if (stream_dead($r['u'])) { continue; }
        $out[] = ['label' => $r['l'], 'url' => $r['u'], 'watch' => stream_watch_url($r['u'], $title, '', $o), 'play' => stream_watch_url($r['u'], $title, 'embed', $o), 'app' => stream_watch_url($r['u'], $title, 'app', $o)];
    }
    return $out;
}

/** Extra "Watch" buttons next to the main one (same live / not started / ended rules). */
function stream_extra_buttons(array $t): string
{
    $state = function_exists('stream_state_of') ? stream_state_of($t) : 'open';
    if (!in_array($state, ['live', 'open'], true)) { return ''; }
    $h = '';
    foreach (stream_extra_list($t) as $x) {
        $h .= ' <a class="watch-live alt" href="' . e($x['watch']) . '" data-play="' . e($x['play']) . '" data-title="' . e(stream_title($t)) . '">' . e($x['label']) . '</a>';
    }
    return $h;
}

/* ---------- Channels ---------- */
function channels_ready(): bool { return sk_ensure_table('channels', 'database-update-45.sql'); }

function channels_fetch(string $q = '', string $cat = '', int $limit = 120): array
{
    if (!channels_ready()) { return []; }
    $sql = 'SELECT * FROM channels WHERE is_active = 1'; $args = [];
    if ($q !== '') { $sql .= ' AND (name LIKE ? OR category LIKE ?)'; $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'; $args[] = $like; $args[] = $like; }
    if ($cat !== '') { $sql .= ' AND category = ?'; $args[] = $cat; }
    $sql .= ' ORDER BY sort_order, name LIMIT ' . (int)$limit;
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}
function channel_categories(): array
{
    if (!channels_ready()) { return []; }
    return db()->query("SELECT DISTINCT category FROM channels WHERE is_active = 1 AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
}
/** Buttons of a channel: main one plus extras. [{label, play, watch, app}] */
function channel_buttons(array $c): array
{
    $out = [];
    $all = [];
    if (stream_clean((string)$c['url']) !== '') { $all[] = ['l' => 'Watch', 'u' => stream_clean((string)$c['url'])]; }
    foreach (sources_decode($c['extra'] ?? '') as $r) { $all[] = $r; }
    foreach ($all as $r) {
        $out[] = ['label' => $r['l'], 'play' => stream_watch_url($r['u'], (string)$c['name'], 'embed'), 'watch' => stream_watch_url($r['u'], (string)$c['name']), 'app' => stream_watch_url($r['u'], (string)$c['name'], 'app')];
    }
    return $out;
}

/* ---------- page on/off switches ---------- */
function site_pages(): array
{
    // key => [label, regex on the script path, API actions that also switch off]
    return [
        'vip'        => ['VIP, plans and payments', '#/(vip|checkout|access)\.php$|/account/(subscription|payments|group-plan|trial)\.php$|/payments/(checkout|failed|success|mpesa-stkpush|paypal-create|paystack-init|razorpay-create|stripe-init|wallet-init)\.php$#', ['plans', 'pay', 'checkout']],
        'jackpot'    => ['Jackpot', '#/jackpot(-results)?\.php$#', ['jackpot']],
        'game'       => ['Prediction game', '#/game\.php$#', ['game']],
        'livestream' => ['Livestream, replays and channels', '#/(livestream|replays|watch|channels|go-stream|highlight)\.php$#', ['streams', 'replays', 'channels']],
        'live'       => ['Live scores', '#/live\.php$#', ['live']],
        'chat'       => ['Global chat and match chat', '#/globalchat\.php$#', []],
        'groups'     => ['Groups and discussion', '#/(discuss|room|post|m|community-search)\.php$|/community/#', ['community']],
        'tipsters'   => ['Tipsters and tipster plans', '#/(tipsters|tipster|tipster-record|leaderboard)\.php$#', ['tipsters']],
        'accas'      => ['Accumulators', '#/(accumulators|acca|vip-accumulators)\.php$#', ['accas']],
        'blog'       => ['Blog', '#/blog\.php$#', ['blog']],
        'results'    => ['Results and performance', '#/(results|performance|audit)\.php$#', []],
        'football'   => ['Stats, tables and leagues', '#/(football|league-table|league|team|club|clubs|fixture)\.php$#', []],
        'arena'      => ['Arena quiz', '#/arena\.php$#', ['arena']],
        'slips'      => ['Betting slips and tracker', '#/(slips|slip|slip-share|slip-pdf|calculator)\.php$|/account/tracker\.php$#', ['slips', 'tracker']],
    ];
}
function page_on(string $key): bool { return setting('page_off_' . $key, '0') !== '1'; }

/** Blocks a switched-off page for visitors (staff can still open it to check). */
function page_gate(): void
{
    if (PHP_SAPI === 'cli') { return; }
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (preg_match('#/(admin|cron|payments/[a-z0-9\-]*(callback|webhook|result|capture|verify|status|return))#', $path)) { return; }
    foreach (site_pages() as $key => $p) {
        if (!page_on($key) && preg_match($p[1], $path)) {
            if (function_exists('is_staff') && is_staff()) { return; }
            http_response_code(404);
            if (strpos($path, '/api/') !== false) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => 'This section is not available right now.']); exit; }
            $page_title = 'Not available - ' . SITE_NAME;
            $active = '';
            include dirname(__DIR__) . '/includes/header.php';
            echo '<div class="wrap page-top"><div class="note"><strong>This section is not available right now.</strong> Please check back later.</div><p><a class="btn gold" href="' . htmlspecialchars(url(), ENT_QUOTES) . '">Back to the home page</a></p></div>';
            include dirname(__DIR__) . '/includes/footer.php';
            exit;
        }
    }
}
/** Switched-off sections for the app (it hides the matching menu items, tabs and buttons). */
function pages_off_list(): array
{
    $o = [];
    foreach (array_keys(site_pages()) as $k) { if (!page_on($k)) { $o[] = $k; } }
    return $o;
}
/** API action -> section key, for switched-off sections. */
function page_off_for_action(string $a): ?string
{
    foreach (site_pages() as $key => $p) { if (!page_on($key) && in_array($a, $p[2], true)) { return $key; } }
    return null;
}

/* ---------- forced app update ---------- */
function app_update_info(): array
{
    $cur = function_exists('app_current') ? app_current() : null;
    return ['latest' => $cur ? (string)$cur['version'] : '', 'force' => $cur && setting('app_force_update', '1') === '1', 'url' => url('app-download.php'), 'notes' => $cur ? (string)($cur['notes'] ?? '') : ''];
}
/** The app's own version from its user agent (SlipKingApp/1.2.3), or ''. */
function app_client_version(): string
{
    return preg_match('~SlipKingApp/([0-9][0-9A-Za-z.\-+]*)~', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''), $m) ? $m[1] : '';
}
function app_update_required(): bool
{
    $v = app_client_version();
    if ($v === '') { return false; }
    $i = app_update_info();
    return $i['force'] && $i['latest'] !== '' && version_compare($v, $i['latest'], '<');
}

/** True when the page at this site path (e.g. 'vip.php') belongs to a switched-off section. */
function page_file_off(string $file): bool
{
    $path = '/' . ltrim(parse_url($file, PHP_URL_PATH) ?: $file, '/');
    foreach (site_pages() as $key => $p) { if (!page_on($key) && preg_match($p[1], $path)) { return true; } }
    return false;
}
