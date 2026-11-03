<?php
/**
 * Display currencies. Prices are stored in KES (and USD for PayPal). Other currencies are shown as
 * ESTIMATES using the rates in the currencies table (units per 1 US dollar). Real charges are always
 * in KES (M-Pesa) or USD (PayPal).
 */

function currencies_all(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    try {
        $c = db()->query('SELECT * FROM currencies ORDER BY sort_order, code')->fetchAll();
    } catch (Throwable $e) {
        $c = [];
    }
    if (!$c) {                                      // table not created yet: the site keeps working in KES
        $c = [['code' => 'KES', 'name' => 'Kenya shilling', 'symbol' => 'KES ', 'rate_per_usd' => 129, 'decimals' => 0,
               'is_active' => 1, 'auto_update' => 0, 'updated_at' => null]];
    }
    return $c;
}

function currencies_active(): array
{
    return array_values(array_filter(currencies_all(), function ($c) { return (int)$c['is_active'] === 1; }));
}

function currency_row(string $code): ?array
{
    foreach (currencies_all() as $c) {
        if ($c['code'] === $code) {
            return $c;
        }
    }
    return null;
}

function default_currency_code(): string
{
    return defined('DEFAULT_CURRENCY') ? DEFAULT_CURRENCY : 'KES';
}

function current_currency_code(): string
{
    $want = strtoupper((string)($_COOKIE['sk_cur'] ?? ''));
    foreach (currencies_active() as $c) {
        if ($c['code'] === $want) {
            return $want;
        }
    }
    // No choice made yet: guess from the member's country (or the visitor's country header).
    $map = ['KE' => 'KES', 'UG' => 'UGX', 'TZ' => 'TZS', 'NG' => 'NGN', 'IN' => 'INR', 'US' => 'USD'];
    $cc = '';
    $u = function_exists('current_user') ? current_user() : null;
    if ($u && !empty($u['country'])) { $cc = strtoupper((string)$u['country']); }
    if ($cc === '') { $cc = strtoupper((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? $_SERVER['HTTP_X_COUNTRY_CODE'] ?? '')); }
    if (isset($map[$cc])) {
        foreach (currencies_active() as $c) { if ($c['code'] === $map[$cc]) { return $c['code']; } }
    }
    return default_currency_code();
}

function current_currency(): array
{
    return currency_row(current_currency_code()) ?? currencies_all()[0];
}

function convert_kes_to(float $kes, string $code): float
{
    $k = currency_row('KES');
    $t = currency_row($code);
    if (!$k || !$t || (float)$k['rate_per_usd'] <= 0) {
        return $kes;
    }
    return $kes / (float)$k['rate_per_usd'] * (float)$t['rate_per_usd'];
}

function format_money(float $amount, array $cur, bool $approx = false): string
{
    return ($approx ? '≈ ' : '') . $cur['symbol'] . number_format($amount, (int)$cur['decimals']);
}

/** A KES amount shown in the visitor's chosen currency (an estimate unless it is KES). */
function display_price_kes(float $kes): string
{
    $cur = current_currency();
    if ($cur['code'] === 'KES') {
        return format_money($kes, $cur);
    }
    return format_money(convert_kes_to($kes, $cur['code']), $cur, true);
}

/** Plan price in the visitor's currency. USD uses the real PayPal price when the plan has one. */
function plan_price_display(array $plan): string
{
    $cur = current_currency();
    if ($cur['code'] === 'USD' && (float)$plan['price_usd'] > 0) {
        return format_money((float)$plan['price_usd'], $cur);
    }
    return display_price_kes((float)$plan['price_kes']);
}

/** Fetches fresh rates (free open endpoint, updates daily; attribution is shown in the site footer). */
function update_rates_from_api(): array
{
    $ch = curl_init('https://open.er-api.com/v6/latest/USD');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
                            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $d = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($d) || ($d['result'] ?? '') !== 'success' || empty($d['rates'])) {
        error_log('rates update failed: ' . $err . ' ' . substr((string)$raw, 0, 200));
        return ['ok' => false, 'message' => 'Could not fetch rates right now.', 'updated' => 0];
    }
    $n = 0;
    $st = db()->prepare('UPDATE currencies SET rate_per_usd = ?, updated_at = NOW() WHERE code = ? AND auto_update = 1');
    foreach (currencies_all() as $c) {
        $r = (float)($d['rates'][$c['code']] ?? 0);
        if ($c['code'] !== 'USD' && $r > 0) {
            $st->execute([number_format($r, 6, '.', ''), $c['code']]);
            $n += $st->rowCount();
        }
    }
    return ['ok' => true, 'message' => "Updated $n currency rate(s).", 'updated' => $n];
}

function rates_attribution_needed(): bool
{
    foreach (currencies_active() as $c) {
        if ((int)$c['auto_update'] === 1 && $c['code'] !== 'USD') {
            return true;
        }
    }
    return false;
}
