<?php
/** Admin-editable settings, the free trial, and small helpers for the naira/rupee gateways. */

$__cfg = __DIR__ . '/config-gateways.php';
if (is_file($__cfg)) {
    require_once $__cfg;
}
foreach (['PAYSTACK_SECRET_KEY', 'RAZORPAY_KEY_ID', 'RAZORPAY_KEY_SECRET', 'RAZORPAY_WEBHOOK_SECRET'] as $__n) {
    if (!defined($__n)) {
        define($__n, '');
    }
}

/** A value from the settings table (Admin > Offer settings). Falls back to the default if the table is missing. */
function setting(string $name, $default = '')
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT name, value FROM settings')->fetchAll() as $r) {
                $cache[$r['name']] = $r['value'];
            }
        } catch (Throwable $e) {
        }
    }
    return array_key_exists($name, $cache) ? $cache[$name] : $default;
}

function set_setting(string $name, string $value): void
{
    db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$name, $value]);
}

function method_label(string $m): string
{
    $map = ['mpesa' => 'M-Pesa', 'paypal' => 'PayPal', 'paystack' => 'Paystack', 'razorpay' => 'Razorpay', 'stripe' => 'Stripe (card)', 'mtn' => 'MTN MoMo', 'airtel' => 'Airtel Money'];
    return $map[$m] ?? ucfirst($m);
}

function paystack_ready(): bool
{
    return PAYSTACK_SECRET_KEY !== '' && currency_row('NGN') !== null;
}

function razorpay_ready(): bool
{
    return RAZORPAY_KEY_ID !== '' && RAZORPAY_KEY_SECRET !== '' && currency_row('INR') !== null;
}

/** A plan price (KES, after any coupon) converted to a local currency, rounded UP to a tidy whole number. */
function local_price(int $kes, string $code, int $roundTo = 1, int $min = 1): int
{
    $v = (int)ceil(convert_kes_to((float)$kes, $code) / $roundTo) * $roundTo;
    return max($min, $v);
}

/**
 * Can this member claim the free VIP trial? Returns [bool, reason].
 * Rules: switched on, email confirmed, phone number added, never had a plan or paid, and the phone number
 * has not been used for a trial on another account.
 */
function trial_eligibility(?array $u): array
{
    if (setting('trial_enabled', '1') !== '1') {
        return [false, 'off'];
    }
    if (!$u) {
        return [false, 'login'];
    }
    if (!(int)($u['email_verified'] ?? 0)) {
        return [false, 'verify'];
    }
    if (sms_enabled()) {
        if (empty($u['mobile'])) {
            return [false, 'phone'];
        }
        if (empty($u['mobile_verified'])) {
            return [false, 'phone-verify'];
        }
    } elseif (empty($u['phone']) && empty($u['mobile'])) {
        return [false, 'phone'];
    }
    $pdo = db();
    $st = $pdo->prepare("SELECT (SELECT COUNT(*) FROM subscriptions WHERE user_id = ?) + (SELECT COUNT(*) FROM payments WHERE user_id = ? AND status = 'paid')");
    $st->execute([$u['id'], $u['id']]);
    if ((int)$st->fetchColumn() > 0) {
        return [false, 'used'];
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM subscriptions s JOIN plans p ON p.id = s.plan_id JOIN users x ON x.id = s.user_id WHERE p.slug = 'trial' AND (x.phone = ? OR x.mobile = ?) AND x.id <> ?");
    $st->execute([$u['phone'] ?: '-', $u['mobile'] ?: '-', $u['id']]);
    if ((int)$st->fetchColumn() > 0) {
        return [false, 'phone-used'];
    }
    if (function_exists('fraud_trial_blocked') && fraud_trial_blocked((int)$u['id'])) {
        return [false, 'phone-used'];
    }
    return [true, ''];
}
