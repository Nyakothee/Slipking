<?php
/**
 * Tipster marketplace helpers: profiles, access, record, ratings, commission, earnings and balance.
 *
 * How the money works (also in README.txt):
 *  - Members pay for a tipster's plan through the normal checkout (M-Pesa, PayPal, Paystack, Razorpay). ALL money lands in YOUR accounts.
 *  - complete_payment() calls tipster_record_sale(): it gives the member access to that tipster and writes an earnings row with the
 *    site's commission % copied at that moment. Changing the commission later only affects future sales.
 *  - Earnings are "held" for tipster_hold_days (refund window), then become available. Tipsters withdraw from their wallet (tipster/earnings.php)
 *    and the money is sent automatically (includes/payouts.php), one withdrawal every N days; Admin > Tipster payouts only shows exceptions.
 * The commission is the share the SITE keeps. The gross amount is the plan's KES price.
 */

function tipster_default_commission(): float
{
    $v = (float)setting('tipster_commission_percent', '30');
    return max(0.0, min(95.0, $v));
}

/** The site's commission % for this tipster (their own rate if you set one, otherwise the default). */
function tipster_commission(array $t): float
{
    if (isset($t['commission_percent']) && $t['commission_percent'] !== null && $t['commission_percent'] !== '') {
        return max(0.0, min(95.0, (float)$t['commission_percent']));
    }
    return tipster_default_commission();
}

function tipster_for_user(int $userId): ?array
{
    $st = db()->prepare('SELECT * FROM tipsters WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

function tipster_by_slug(string $slug): ?array
{
    $st = db()->prepare('SELECT * FROM tipsters WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function tipster_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM tipsters WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Logged-in member's tipster row, only if approved. */
function current_tipster(): ?array
{
    $u = current_user();
    if (!$u) {
        return null;
    }
    $t = tipster_for_user((int)$u['id']);
    return ($t && $t['status'] === 'active') ? $t : null;
}

/** For pages under /tipster/: needs a login and a tipster row. Pending/suspended tipsters can still open the dashboard. */
function require_tipster(bool $mustBeActive = false): array
{
    require_login();
    $u = current_user();
    $t = tipster_for_user((int)$u['id']);
    if (!$t) {
        set_flash('info', 'Apply to become a tipster first.');
        redirect('tipster/apply.php');
    }
    // Every tipster must have a profile photo: until they upload one they can only open the dashboard and the profile page
    if (empty($t['avatar_url']) && !preg_match('#/tipster/(profile|index)\.php$#', (string)($_SERVER['SCRIPT_NAME'] ?? '')) && PHP_SAPI !== 'cli') {
        set_flash('error', 'Please upload your profile photo first. It is required for every tipster.');
        redirect('tipster/profile.php');
    }
    // Not paid yet: these pages need the one-time joining fee (the dashboard, profile and earnings stay open so they can pay)
    if (tipster_fee_owed($t) && preg_match('#/tipster/(tips|plans|managed)\.php$#', (string)($_SERVER['SCRIPT_NAME'] ?? '')) && PHP_SAPI !== 'cli') {
        $a = tipster_fee_amount($u);
        set_flash('error', 'Pay the one-time joining fee (' . $a['label'] . ') to post tips and create plans.');
        redirect('payments/checkout.php?plan=' . TIPSTER_FEE_SLUG);
    }
    if ($mustBeActive && $t['status'] !== 'active') {
        set_flash('error', 'Your tipster profile is not active yet.');
        redirect('tipster/index.php');
    }
    return $t;
}

function tipster_has_access(int $userId, int $tipsterId): bool
{
    $st = db()->prepare('SELECT 1 FROM tipster_subscriptions WHERE user_id = ? AND tipster_id = ? AND ends_at > NOW() LIMIT 1');
    $st->execute([$userId, $tipsterId]);
    if ($st->fetchColumn()) {
        return true;
    }
    // free trial offered by the tipster
    try {
        $st = db()->prepare('SELECT 1 FROM tipster_trials WHERE user_id = ? AND tipster_id = ? AND ends_at > NOW() LIMIT 1');
        $st->execute([$userId, $tipsterId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * May the visitor see the pick of this tip while it is pending?
 * Site tips: VIP members. Tipster tips: subscribers of that tipster, the tipster, and staff.
 */
function tip_access(array $tip): bool
{
    if (empty($tip['tipster_id'])) {
        return is_vip();
    }
    $u = current_user();
    if (!$u) {
        return false;
    }
    if (function_exists('is_staff') && is_staff()) {
        return true;
    }
    $t = tipster_for_user((int)$u['id']);
    if ($t && (int)$t['id'] === (int)$tip['tipster_id']) {
        return true;
    }
    return tipster_has_access((int)$u['id'], (int)$tip['tipster_id']);
}

function tipster_url(array $t): string
{
    return url('tipster.php?slug=' . rawurlencode((string)$t['slug']));
}

function tipster_slug_unique(string $name, ?int $exceptId = null): string
{
    $base = slugify($name);
    if ($base === '') {
        $base = 'tipster';
    }
    $slug = $base;
    $n = 2;
    while (true) {
        $st = db()->prepare('SELECT id FROM tipsters WHERE slug = ?' . ($exceptId ? ' AND id <> ' . (int)$exceptId : '') . ' LIMIT 1');
        $st->execute([$slug]);
        if (!$st->fetchColumn()) {
            return $slug;
        }
        $slug = $base . '-' . $n++;
    }
}

/** Only https image links are accepted for avatars. */
function tipster_avatar_ok(string $u): bool
{
    return $u === '' || (strlen($u) <= 255 && preg_match('#^https://[^\s<>"\']+$#i', $u) && filter_var($u, FILTER_VALIDATE_URL));
}

function tipster_avatar(array $t, int $size = 56): string
{
    $name = (string)($t['display_name'] ?? '?');
    if (!empty($t['avatar_url'])) {
        $src = media_src((string)$t['avatar_url']);
        return '<img class="tp-av" src="' . e($src) . '" alt="" width="' . $size . '" height="' . $size . '" loading="lazy" referrerpolicy="no-referrer">';
    }
    $ini = mb_strtoupper(mb_substr($name, 0, 1));
    return '<span class="tp-av tp-ini" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . (int)($size * 0.45) . 'px" aria-hidden="true">' . e($ini) . '</span>';
}

/** Five stars as text: filled for the rounded rating. Accessible label included. */
function tipster_stars(float $avg, int $count = 0): string
{
    $full = (int)round($avg);
    $s = str_repeat('&#9733;', max(0, min(5, $full))) . str_repeat('&#9734;', 5 - max(0, min(5, $full)));
    $label = $count > 0 ? number_format($avg, 1) . ' out of 5 from ' . $count . ' rating' . ($count === 1 ? '' : 's') : 'No ratings yet';
    return '<span class="stars tp-stars" role="img" aria-label="' . e($label) . '">' . $s . '</span>';
}

/** Win/loss record of a tipster. Deleted tips count, so a record cannot be tidied up. */
function tipster_stats(int $tipsterId): array
{
    $st = db()->prepare("SELECT SUM(status = 'won') AS w, SUM(status = 'lost') AS l, SUM(status = 'void') AS v,
                                AVG(CASE WHEN status IN ('won','lost') THEN odds END) AS ao,
                                SUM(CASE WHEN status = 'won' THEN odds - 1 WHEN status = 'lost' THEN -1 ELSE 0 END) AS units
                         FROM tips WHERE (tipster_id = ? OR author_id = ?) AND status IN ('won','lost','void')");
    $st->execute([$tipsterId, $tipsterId]);
    $r = $st->fetch() ?: [];
    $w = (int)($r['w'] ?? 0);
    $l = (int)($r['l'] ?? 0);
    $n = $w + $l;
    $units = round((float)($r['units'] ?? 0), 1);
    $st = db()->prepare("SELECT status FROM tips WHERE (tipster_id = ? OR author_id = ?) AND status IN ('won','lost') ORDER BY settled_at DESC, id DESC LIMIT 10");
    $st->execute([$tipsterId, $tipsterId]);
    return [
        'won' => $w, 'lost' => $l, 'void' => (int)($r['v'] ?? 0), 'settled' => $n,
        'win_rate' => $n ? (int)round($w / $n * 100) : null,
        'avg_odds' => !empty($r['ao']) ? number_format((float)$r['ao'], 2) : '-',
        'units' => ($units > 0 ? '+' : '') . number_format($units, 1),
        'units_raw' => $units,
        'form' => $st->fetchAll(PDO::FETCH_COLUMN),
    ];
}

/** Recomputes the stored average rating from visible reviews. */
function tipster_refresh_rating(int $tipsterId): void
{
    $st = db()->prepare("SELECT COUNT(*), COALESCE(AVG(rating), 0) FROM tipster_reviews WHERE tipster_id = ? AND status = 'visible'");
    $st->execute([$tipsterId]);
    $r = $st->fetch(PDO::FETCH_NUM);
    db()->prepare('UPDATE tipsters SET rating_count = ?, rating_avg = ? WHERE id = ?')
        ->execute([(int)$r[0], round((float)$r[1], 2), $tipsterId]);
}

/** A member may rate a tipster only after buying one of their plans (stops fake ratings). Not for the tipster themself. */
function tipster_can_review(int $userId, array $tipster): bool
{
    if ((int)$tipster['user_id'] === $userId) {
        return false;
    }
    $st = db()->prepare('SELECT 1 FROM tipster_subscriptions WHERE user_id = ? AND tipster_id = ? LIMIT 1');
    $st->execute([$userId, (int)$tipster['id']]);
    return (bool)$st->fetchColumn();
}

/** USD price shown/charged on PayPal for a KES plan price, from the rates in Admin > Currencies. */
function tipster_usd_for_kes(int $kes): float
{
    $row = function_exists('currency_row') ? currency_row('KES') : null;
    $rate = $row ? (float)$row['rate_per_usd'] : 129.0;
    if ($rate <= 0) {
        $rate = 129.0;
    }
    return max(0.50, round($kes / $rate, 2));
}

/**
 * Called by complete_payment() INSIDE its transaction for a paid tipster plan.
 * Gives the buyer access, writes the earnings row and sends two notifications. Returns the access end date.
 */
function tipster_record_sale(PDO $pdo, array $p, array $plan): string
{
    $st = $pdo->prepare('SELECT * FROM tipsters WHERE id = ? FOR UPDATE');
    $st->execute([(int)$plan['tipster_id']]);
    $t = $st->fetch();
    if (!$t) {
        throw new RuntimeException('tipster not found for plan ' . (int)$plan['id']);
    }
    $st = $pdo->prepare('SELECT MAX(ends_at) FROM tipster_subscriptions WHERE user_id = ? AND tipster_id = ? AND ends_at > NOW()');
    $st->execute([$p['user_id'], $t['id']]);
    $from = $st->fetchColumn() ?: null;
    $pdo->prepare('INSERT INTO tipster_subscriptions (user_id, tipster_id, plan_id, payment_id, starts_at, ends_at)
                   VALUES (?, ?, ?, ?, COALESCE(?, NOW()), DATE_ADD(COALESCE(?, NOW()), INTERVAL ? DAY))')
        ->execute([$p['user_id'], $t['id'], $plan['id'], $p['id'], $from, $from, (int)$plan['duration_days']]);
    $sid = (int)$pdo->lastInsertId();
    $st = $pdo->prepare('SELECT ends_at FROM tipster_subscriptions WHERE id = ?');
    $st->execute([$sid]);
    $ends = (string)$st->fetchColumn();

    $gross = round((float)$plan['price_kes'], 2);
    $pct   = tipster_commission($t);
    $comm  = round($gross * $pct / 100, 2);
    $net   = round($gross - $comm, 2);
    $hold  = max(0, (int)setting('tipster_hold_days', '7'));
    $pdo->prepare('INSERT INTO tipster_earnings (tipster_id, payment_id, buyer_id, plan_id, gross_kes, commission_percent, commission_kes, net_kes, available_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))')
        ->execute([$t['id'], $p['id'], $p['user_id'], $plan['id'], $gross, $pct, $comm, $net, $hold]);

    $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)')
        ->execute([$p['user_id'], 'Subscription active',
                   'You now have access to ' . $t['display_name'] . "'s premium tips until " . date('j F Y, H:i', strtotime($ends)) . '.']);
    $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)')
        ->execute([$t['user_id'], 'New subscriber',
                   'A member subscribed to your ' . $plan['name'] . ' plan. You earn KES ' . number_format($net, 2) . ' (available after the holding period).']);
    if (function_exists('fcm_queue')) {
        fcm_queue((int)$p['user_id'], 'Subscription active', 'You now have access to ' . $t['display_name'] . "'s premium tips.", 'account/subscription.php');
        fcm_queue((int)$t['user_id'], 'New subscriber', 'A member subscribed to your ' . $plan['name'] . ' plan.', 'tipster/earnings.php');
    }
    return $ends;
}

/** Money summary for one tipster, all in KES. */
function tipster_balance(int $tipsterId): array
{
    $pdo = db();
    $st = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN status = 'ok' THEN gross_kes END), 0) AS gross,
        COALESCE(SUM(CASE WHEN status = 'ok' THEN commission_kes END), 0) AS commission,
        COALESCE(SUM(CASE WHEN status = 'ok' THEN net_kes END), 0) AS net_total,
        COALESCE(SUM(CASE WHEN status = 'ok' AND available_at > NOW() THEN net_kes END), 0) AS held,
        COALESCE(SUM(CASE WHEN status = 'ok' AND available_at <= NOW() THEN net_kes END), 0) AS released
        FROM tipster_earnings WHERE tipster_id = ?");
    $st->execute([$tipsterId]);
    $e = $st->fetch() ?: [];
    $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_kes END), 0) AS paid,
                                COALESCE(SUM(CASE WHEN status IN ('requested','processing') THEN amount_kes END), 0) AS requested
                         FROM tipster_payouts WHERE tipster_id = ?");
    $st->execute([$tipsterId]);
    $p = $st->fetch() ?: [];
    $available = max(0.0, (float)$e['released'] - (float)$p['paid'] - (float)$p['requested']);
    return [
        'gross' => (float)$e['gross'], 'commission' => (float)$e['commission'], 'net_total' => (float)$e['net_total'],
        'held' => (float)$e['held'], 'paid' => (float)$p['paid'], 'requested' => (float)$p['requested'],
        'available' => round($available, 2),
    ];
}

/** Payout details must look like an M-Pesa number or a PayPal email. Never ask for card or bank numbers. */
function tipster_payout_valid(string $method, string $details): ?string
{
    $details = trim($details);
    if ($method === 'mpesa') {
        $n = function_exists('normalize_phone') ? normalize_phone($details) : null;
        return $n ?: null;
    }
    if ($method === 'paypal') {
        return filter_var($details, FILTER_VALIDATE_EMAIL) ? $details : null;
    }
    if ($method === 'airtel') {
        $d = preg_replace('/\D+/', '', $details);
        return (strlen($d) >= 9 && strlen($d) <= 15) ? $d : null;
    }
    return null;
}

/** Plain-words error for bad payout details, or '' when they are fine. */
function tipster_payout_error(string $method, string $details): string
{
    if (tipster_payout_valid($method, $details) !== null) {
        return '';
    }
    return ['mpesa' => 'Enter a valid Safaricom M-Pesa number, like 0712 345 678.', 'paypal' => 'Enter the email address of your PayPal account.',
            'airtel' => 'Enter your Airtel Money number with the country code, like 254733123456.'][$method] ?? 'Choose how you want to be paid.';
}

/** Directory query. $sort: rating | winrate | newest | subs. Only approved tipsters. */
function tipsters_directory(string $sort = 'rating', string $q = ''): array
{
    $order = [
        'rating'  => 'ti.rating_avg DESC, ti.rating_count DESC, ti.id ASC',
        'winrate' => 'win_rate DESC, settled DESC',
        'newest'  => 'ti.approved_at DESC, ti.id DESC',
        'subs'    => 'subs DESC, ti.rating_avg DESC',
    ][$sort] ?? 'ti.rating_avg DESC, ti.rating_count DESC';
    $sql = "SELECT ti.*,
              (SELECT COUNT(*) FROM tips t WHERE t.tipster_id = ti.id AND t.status IN ('won','lost')) AS settled,
              (SELECT ROUND(100 * SUM(t.status = 'won') / NULLIF(SUM(t.status IN ('won','lost')), 0)) FROM tips t WHERE t.tipster_id = ti.id) AS win_rate,
              (SELECT COUNT(DISTINCT s.user_id) FROM tipster_subscriptions s WHERE s.tipster_id = ti.id AND s.ends_at > NOW()) AS subs,
              (SELECT MIN(p.price_kes) FROM plans p WHERE p.tipster_id = ti.id AND p.is_active = 1) AS from_kes
            FROM tipsters ti WHERE ti.status = 'active'" . tipster_live_sql('ti');
    $args = [];
    if ($q !== '') {
        $sql .= ' AND (ti.display_name LIKE ? OR ti.specialties LIKE ? OR ti.country LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $args = [$like, $like, $like];
    }
    $sql .= ' ORDER BY ' . $order . ' LIMIT 60';
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * Contributor share. Called by complete_payment() for a normal (site) VIP sale.
 * The admin sets contributor_pool_percent (0 = off). That percent of the sale goes into a pool that is split between active contributors in
 * proportion to the number of approved VIP tips each posted under the platform in the last 30 days. Paid out through the normal earnings/payout system.
 * Never throws: a problem here must not undo a customer's payment.
 */
function contributor_credit_share(PDO $pdo, array $p, array $plan): void
{
    try {
        $pct = (float)setting('contributor_pool_percent', '0');
        if ($pct <= 0) {
            return;
        }
        $base = floor((float)$plan['price_kes'] * (1 - (int)($p['discount_percent'] ?? 0) / 100));
        if ($base <= 0) {
            return;
        }
        $pool = round($base * min(50.0, $pct) / 100, 2);
        $st = $pdo->query("SELECT t.author_id, COUNT(*) AS n FROM tips t JOIN tipsters ti ON ti.id = t.author_id
                           WHERE t.author_id IS NOT NULL AND t.is_vip = 1 AND t.deleted_at IS NULL AND t.pending_review = 0
                             AND t.posted_at > NOW() - INTERVAL 30 DAY AND ti.status = 'active' AND ti.is_contributor = 1
                           GROUP BY t.author_id");
        $rows = $st->fetchAll();
        $total = 0;
        foreach ($rows as $r) {
            $total += (int)$r['n'];
        }
        if ($total <= 0 || $pool < 0.01) {
            return;
        }
        $hold = max(0, (int)setting('tipster_hold_days', '7'));
        $ins = $pdo->prepare("INSERT IGNORE INTO tipster_earnings (tipster_id, payment_id, buyer_id, plan_id, gross_kes, commission_percent, commission_kes, net_kes, available_at, note)
                              VALUES (?, ?, ?, ?, ?, 0, 0, ?, DATE_ADD(NOW(), INTERVAL ? DAY), 'Contributor share of VIP sale')");
        foreach ($rows as $r) {
            $slice = round($pool * (int)$r['n'] / $total, 2);
            if ($slice >= 0.01) {
                $ins->execute([$r['author_id'], $p['id'], $p['user_id'], $plan['id'], $slice, $slice, $hold]);
            }
        }
    } catch (Throwable $e) {
        error_log('contributor_credit_share: ' . $e->getMessage());
    }
}

/**
 * Leaderboard. $days = 0 for all time. $sort: units | winrate | rating. Includes tips sold by the tipster and tips they posted under the platform.
 * Only tipsters with enough settled tips are ranked, so one lucky tip cannot top the table.
 */
function leaderboard_rows(int $days, string $sort = 'units'): array
{
    $min = $days > 0 ? 5 : 10;
    $order = ['units' => 'units DESC, settled DESC', 'winrate' => 'win_rate DESC, settled DESC', 'rating' => 'ti.rating_avg DESC, ti.rating_count DESC'][$sort] ?? 'units DESC, settled DESC';
    $sql = "SELECT ti.id, ti.slug, ti.display_name, ti.avatar_url, ti.country, ti.tagline, ti.rating_avg, ti.rating_count,
              SUM(t.status = 'won') AS w, SUM(t.status = 'lost') AS l, COUNT(*) AS settled,
              ROUND(100 * SUM(t.status = 'won') / COUNT(*)) AS win_rate, AVG(t.odds) AS avg_odds,
              SUM(CASE WHEN t.status = 'won' THEN t.odds - 1 ELSE -1 END) AS units,
              (SELECT COUNT(DISTINCT s.user_id) FROM tipster_subscriptions s WHERE s.tipster_id = ti.id AND s.ends_at > NOW()) AS subs
            FROM tips t JOIN tipsters ti ON ti.id = COALESCE(t.tipster_id, t.author_id)
            WHERE t.status IN ('won','lost') AND (t.tipster_id IS NOT NULL OR t.author_id IS NOT NULL) AND ti.status = 'active'" . tipster_live_sql('ti')
        . ($days > 0 ? ' AND t.settled_at >= NOW() - INTERVAL ' . (int)$days . ' DAY' : '')
        . " GROUP BY ti.id, ti.slug, ti.display_name, ti.avatar_url, ti.country, ti.tagline, ti.rating_avg, ti.rating_count
            HAVING COUNT(*) >= " . (int)$min . " ORDER BY " . $order . " LIMIT 50";
    return db()->query($sql)->fetchAll();
}

/**
 * Saves an uploaded profile picture. Returns the stored path ('uploads/tipsters/tp-xxxx.jpg'), or null (with $err set when something is wrong,
 * and $err empty when simply no file was chosen). The picture is re-drawn with GD as a 400x400 JPEG, which removes hidden data and anything
 * that is not really an image.
 */
function tipster_save_avatar(?array $file, ?string &$err = null): ?string
{
    $u = current_user();
    $m = media_store($file, 'tipster', $u ? (int)$u['id'] : 0, 'square', $err, 2097152);     // kept in the database
    if ($m !== null || $err !== '') { return $m; }
    $n = picture_store($file, 'tipsters', 'tp-', $err);                                        // fallback: file on disk
    return $n === null ? null : 'uploads/tipsters/' . $n;
}

/**
 * Photo for forms that can fail on other fields (register, apply): the picture is kept in the session after the first upload,
 * so a validation error elsewhere never makes the person upload it again.
 */
function tipster_avatar_pending(?array $file, ?string &$err = null): ?string
{
    $new = tipster_save_avatar($file, $err);
    if ($new) {
        $old = (string)($_SESSION['pending_avatar'] ?? '');
        if ($old !== '' && $old !== $new) { tipster_delete_avatar($old); }
        $_SESSION['pending_avatar'] = $new;
        return $new;
    }
    $p = (string)($_SESSION['pending_avatar'] ?? '');
    if ($err === '' && $p !== '' && (media_is_path($p) ? media_exists($p) : is_file(dirname(__DIR__) . '/' . $p))) { return $p; }
    return null;
}

function tipster_avatar_pending_path(): string
{
    $p = (string)($_SESSION['pending_avatar'] ?? '');
    return ($p !== '' && (media_is_path($p) ? media_exists($p) : is_file(dirname(__DIR__) . '/' . $p))) ? $p : '';
}

function tipster_avatar_pending_clear(): void
{
    unset($_SESSION['pending_avatar']);
}

/** Small preview shown on forms when a photo was already uploaded in this session. */
function tipster_avatar_pending_html(): string
{
    $p = tipster_avatar_pending_path();
    if ($p === '') { return ''; }
    return '<div class="av-kept"><img src="' . e(media_src($p)) . '" alt="" width="64" height="64"><span><strong>Photo saved.</strong> Choose another file only if you want to replace it.</span></div>';
}

function tipster_delete_avatar(?string $path): void
{
    if (media_is_path($path)) { media_delete($path); return; }
    if ($path && preg_match('#^uploads/tipsters/tp-[a-f0-9]{16}\.(jpg|png|webp)$#', $path)) {
        @unlink(dirname(__DIR__) . '/' . $path);
    }
}

/** True for people who registered as a tipster. They only see the tipster area, never the member site. Staff are never restricted. */
function is_tipster_only(?array $u = null): bool
{
    $u = $u ?? current_user();
    if (!$u || empty($u['is_tipster_account'])) {
        return false;
    }
    return !(function_exists('is_staff') && is_staff());
}

/**
 * Called at the end of includes/init.php on every page. A tipster-only account may browse the whole public site (home page, tips, results, blog ...) and use the tipster area.
 * Member-only and buying pages (VIP, game, account area, payments) send them back to their tipster dashboard.
 */
function tipster_only_guard(): void
{
    if (PHP_SAPI === 'cli' || !is_tipster_only()) {
        return;
    }
    $root = realpath(dirname(__DIR__));
    $file = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if (!$root || !$file || strpos($file, $root) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
    // Tipsters may look around the whole public site (home, tips, results, blog ...). Only member-only and buying pages stay closed to them.
    $closed = ['game.php', 'account-management.php', 'vip.php', 'vip-accumulators.php', 'referral-leaderboard.php'];
    if (in_array($rel, $closed, true) || strpos($rel, 'payments/') === 0 || (strpos($rel, 'account/') === 0 && !in_array($rel, ['account/profile.php', 'account/notifications.php'], true))) {
        redirect('tipster/index.php');
    }
    return;
}

/** Plan name without the "Tipster name - " prefix, for display on cards. */
function tipster_plan_label(array $plan): string
{
    $n = (string)$plan['name'];
    $p = strrpos($n, ' - ');
    return $p !== false ? substr($n, $p + 3) : $n;
}

/** ISO code of a tipster's country (the country is stored as a name; a two-letter code also works). '' when unknown. */
function tipster_iso(array $t): string
{
    $c = trim((string)($t['country'] ?? ''));
    if ($c === '') { return ''; }
    if (strlen($c) === 2 && isset(countries()[strtoupper($c)])) { return strtoupper($c); }
    foreach (countries() as $iso => $row) {
        if (strcasecmp($row['name'], $c) === 0) { return $iso; }
    }
    return '';
}

/** Small flag of the tipster's country, always shown on tipster pages. */
function tipster_flag(array $t, int $h = 16): string
{
    $iso = tipster_iso($t);
    return $iso === '' ? '' : flag_html($iso, $h);
}
