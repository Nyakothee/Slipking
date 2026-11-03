<?php
require_once __DIR__ . '/tipster-fee.php';
/** Shared payment + subscription logic (used by every payment method). */
require_once __DIR__ . '/offers.php';
require_once __DIR__ . '/tipsters.php';

function payments_log(string $tag, $data = null): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $tag . ' ' . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES)) . "\n";
    @file_put_contents(dirname(__DIR__) . '/logs/payments.log', $line, FILE_APPEND | LOCK_EX);
}

function json_out(array $data, int $code = 200): void
{
    if (isset($data['message']) && function_exists('t')) {
        $data['message'] = t((string)$data['message']);
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

/**
 * Marks a payment as paid and gives the member their VIP days.
 * Safe to call twice for the same payment: the second call does nothing, so a repeated
 * callback can never extend a plan twice. Returns true only when it activated the plan now.
 * $extra may hold: receipt, phone, raw
 */
function complete_payment(int $paymentId, array $extra = []): bool
{
    $done = complete_payment_core($paymentId, $extra);
    if ($done && function_exists('payment_after_paid')) { payment_after_paid($paymentId); }
    return $done;
}

function complete_payment_core(int $paymentId, array $extra = []): bool
{
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
        $st->execute([$paymentId]);
        $p = $st->fetch();
        if (!$p) {
            $pdo->rollBack();
            return false;
        }
        if ($p['status'] === 'paid') {          // already handled
            $pdo->commit();
            return false;
        }

        $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = NOW(),
                       mpesa_receipt = COALESCE(?, mpesa_receipt), phone = COALESCE(?, phone), raw_response = ?
                       WHERE id = ?")
            ->execute([$extra['receipt'] ?? null, $extra['phone'] ?? null, $extra['raw'] ?? $p['raw_response'], $paymentId]);

        if (!empty($p['coupon_code'])) {
            $pdo->prepare('UPDATE coupons SET used = used + 1 WHERE code = ?')->execute([$p['coupon_code']]);
        }

        $st = $pdo->prepare('SELECT id, name, slug, duration_days, price_kes, tipster_id FROM plans WHERE id = ?');
        $st->execute([$p['plan_id']]);
        $plan = $st->fetch();

        // The weekly access fee: no VIP time, it only adds 7 days of access.
        if (($plan['slug'] ?? '') === 'app-access') {
            access_grant((int)$p['user_id'], $paymentId);
            $pdo->commit();
            return true;
        }

        // The one-time tipster joining fee: no VIP time, it only marks the tipster as paid.
        if (($plan['slug'] ?? '') === TIPSTER_FEE_SLUG) {
            tipster_fee_mark_paid((int)$p['user_id'], $paymentId);
            $pdo->commit();
            return true;
        }

        // A tipster's plan: the buyer gets access to that tipster (not site VIP) and the tipster earns their share.
        if (!empty($plan['tipster_id'])) {
            $tEnds = tipster_record_sale($pdo, $p, $plan);
            $pdo->commit();
            payments_log('paid-tipster', ['payment' => $paymentId, 'user' => $p['user_id'], 'method' => $p['method'], 'tipster' => $plan['tipster_id'], 'ends' => $tEnds]);
            return true;
        }

        // Optional: part of the sale goes to contributor tipsters (off unless the admin sets a pool percent).
        contributor_credit_share($pdo, $p, $plan);

        // If the member still has VIP time left, the new days start when that ends.
        $st = $pdo->prepare("SELECT MAX(ends_at) FROM subscriptions WHERE user_id = ? AND status = 'active' AND ends_at > NOW()");
        $st->execute([$p['user_id']]);
        $from = $st->fetchColumn() ?: null;

        $pdo->prepare("INSERT INTO subscriptions (user_id, plan_id, starts_at, ends_at, status)
                       VALUES (?, ?, COALESCE(?, NOW()), DATE_ADD(COALESCE(?, NOW()), INTERVAL ? DAY), 'active')")
            ->execute([$p['user_id'], $p['plan_id'], $from, $from, (int)$plan['duration_days']]);
        $subId = (int)$pdo->lastInsertId();

        $st = $pdo->prepare('SELECT ends_at FROM subscriptions WHERE id = ?');
        $st->execute([$subId]);
        $ends = $st->fetchColumn();

        $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)')
            ->execute([$p['user_id'], 'VIP plan active',
                       'Thank you! Your ' . $plan['name'] . ' plan is active until ' . date('j F Y, H:i', strtotime($ends)) . '.']);
        if (function_exists('fcm_queue')) { fcm_queue((int)$p['user_id'], 'VIP plan active', 'Thank you! Your ' . $plan['name'] . ' plan is active.', 'account/subscription.php'); }

        $pdo->commit();
        payments_log('paid', ['payment' => $paymentId, 'user' => $p['user_id'], 'method' => $p['method'], 'ends' => $ends]);
        try {
            award_referral((int)$p['user_id']);
        } catch (Throwable $re) {
            payments_log('referral-error', $re->getMessage());
        }
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        payments_log('complete-error', ['payment' => $paymentId, 'error' => $e->getMessage()]);
        return false;
    }
}

/** Marks a still-pending payment as failed or cancelled. Paid payments are never changed. */
function fail_payment(int $paymentId, string $status, string $note = ''): void
{
    if (!in_array($status, ['failed', 'cancelled'], true)) {
        $status = 'failed';
    }
    db()->prepare("UPDATE payments SET status = ?, raw_response = CONCAT(COALESCE(raw_response, ''), ?) WHERE id = ? AND status = 'pending'")
        ->execute([$status, "\n" . substr($note, 0, 500), $paymentId]);
}

function new_payment_reference(): string
{
    return 'SK' . strtoupper(bin2hex(random_bytes(5)));      // 12 characters, fits M-Pesa's account reference
}

/**
 * Gives a member VIP days without a payment (gift, compensation, manual fix).
 * $days = null uses the plan's own length. Returns the new end date.
 */
function grant_vip(int $userId, int $planId, ?int $days = null): ?string
{
    $pdo = db();
    $st = $pdo->prepare('SELECT name, duration_days FROM plans WHERE id = ?');
    $st->execute([$planId]);
    $plan = $st->fetch();
    if (!$plan) {
        return null;
    }
    $days = $days ?: (int)$plan['duration_days'];

    $st = $pdo->prepare("SELECT MAX(ends_at) FROM subscriptions WHERE user_id = ? AND status = 'active' AND ends_at > NOW()");
    $st->execute([$userId]);
    $from = $st->fetchColumn() ?: null;

    $pdo->prepare("INSERT INTO subscriptions (user_id, plan_id, starts_at, ends_at, status)
                   VALUES (?, ?, COALESCE(?, NOW()), DATE_ADD(COALESCE(?, NOW()), INTERVAL ? DAY), 'active')")
        ->execute([$userId, $planId, $from, $from, $days]);
    $st = $pdo->prepare('SELECT ends_at FROM subscriptions WHERE id = ?');
    $st->execute([(int)$pdo->lastInsertId()]);
    return (string)$st->fetchColumn();
}


/* ---------- coupon codes ---------- */

/**
 * Checks a coupon code for a member. Returns ['ok'=>bool, 'coupon'=>array|null, 'message'=>string].
 * An empty code is fine (ok = true, coupon = null).
 */
function find_coupon(string $code, int $userId): array
{
    $code = strtoupper((string)preg_replace('/[^A-Za-z0-9\-]/', '', $code));
    if ($code === '') {
        return ['ok' => true, 'coupon' => null, 'message' => ''];
    }
    $st = db()->prepare('SELECT * FROM coupons WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $c = $st->fetch();
    if (!$c || !$c['is_active']) {
        return ['ok' => false, 'coupon' => null, 'message' => 'That code is not valid.'];
    }
    if ($c['expires_at'] !== null && strtotime($c['expires_at']) < time()) {
        return ['ok' => false, 'coupon' => null, 'message' => 'That code has expired.'];
    }
    if ($c['max_uses'] !== null && (int)$c['used'] >= (int)$c['max_uses']) {
        return ['ok' => false, 'coupon' => null, 'message' => 'That code has been fully used.'];
    }
    $st = db()->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ? AND coupon_code = ? AND status = 'paid'");
    $st->execute([$userId, $code]);
    if ((int)$st->fetchColumn() > 0) {
        return ['ok' => false, 'coupon' => null, 'message' => 'You have already used that code.'];
    }
    return ['ok' => true, 'coupon' => $c, 'message' => ''];
}

function discounted_kes(int $price, int $percent): int
{
    return max(1, (int)round($price * (100 - $percent) / 100));
}

function discounted_usd(float $price, int $percent): string
{
    return number_format(max(0.01, round($price * (100 - $percent) / 100, 2)), 2, '.', '');
}


/** Gives the member who invited this person free VIP days after the friend's first real payment (once). */
function award_referral(int $userId): void
{
    $days = (int)setting('referral_reward_days', '3');
    if ($days < 1) {
        return;
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT id, referrer_id FROM referrals WHERE referred_id = ? AND reward_given = 0 LIMIT 1');
    $st->execute([$userId]);
    $ref = $st->fetch();
    if (!$ref) {
        return;
    }
    $st = $pdo->prepare("SELECT id FROM plans WHERE slug = 'bonus' LIMIT 1");
    $st->execute();
    $bonusId = (int)$st->fetchColumn();
    if (!$bonusId) {
        return;
    }
    if (function_exists('fraud_are_linked') && fraud_are_linked((int)$ref['referrer_id'], $userId)) {
        $pdo->prepare('UPDATE referrals SET reward_given = 1 WHERE id = ?')->execute([$ref['id']]);   // closed without a reward: same IP and browser
        admin_log('fraud.referral', 'user#' . $userId, 'Referral reward withheld: same IP and browser as referrer #' . $ref['referrer_id']);
        return;
    }
    $mark = $pdo->prepare('UPDATE referrals SET reward_given = 1 WHERE id = ? AND reward_given = 0');
    $mark->execute([$ref['id']]);
    if ($mark->rowCount() !== 1) {
        return;                                                    // someone else just paid it out
    }
    $ends = grant_vip((int)$ref['referrer_id'], $bonusId, $days);
    if ($ends) {
        notify_user((int)$ref['referrer_id'], 'You earned free VIP days', "A friend you invited just became a paying member, so we added $days VIP days to your account. Thank you! Your VIP now runs until " . date('j F Y, H:i', strtotime($ends)) . '.');
        payments_log('referral-reward', ['referrer' => $ref['referrer_id'], 'friend' => $userId, 'days' => $days]);
        if (function_exists('badge_award')) { badge_award((int)$ref['referrer_id'], 'ambassador'); }
    }
}
