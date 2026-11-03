<?php
/** Handles ?cur=XXX (currency) and ?lang=xx (language): remembers the choice in a cookie and returns to the same page. */

function set_pref_cookie(string $name, string $value): void
{
    setcookie($name, $value, [
        'expires'  => time() + 365 * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[$name] = $value;
}

function handle_pref_switch(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || (!isset($_GET['cur']) && !isset($_GET['lang']))) {
        return;
    }
    if (isset($_GET['cur'])) {
        $code = strtoupper((string)$_GET['cur']);
        foreach (currencies_active() as $c) {
            if ($c['code'] === $code) {
                set_pref_cookie('sk_cur', $code);
                break;
            }
        }
    }
    if (isset($_GET['lang'])) {
        $l = strtolower((string)$_GET['lang']);
        if (isset(SK_LANGS[$l])) {
            set_pref_cookie('sk_lang', $l);
        }
    }
    $uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string)strtok($uri, '?');
    if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0 || strpos($path, '\\') !== false) {
        $path = '/';                                  // only ever return to a path on this site
    }
    $q = $_GET;
    unset($q['cur'], $q['lang']);
    header('Location: ' . $path . ($q ? '?' . http_build_query($q) : ''));
    exit;
}

/** Countries whose usual web language is one we offer (used only when the browser's own language list gives no answer). Edit freely. */
function lang_country_map(): array
{
    $m = [];
    foreach (['FR', 'SN', 'CI', 'CM', 'CD', 'CG', 'GA', 'ML', 'BF', 'NE', 'TG', 'BJ', 'GN', 'MG', 'HT', 'DJ', 'BI', 'TD', 'CF', 'KM', 'DZ', 'TN', 'MA', 'MC', 'LU'] as $c) { $m[$c] = 'fr'; }
    foreach (['SA', 'AE', 'EG', 'IQ', 'JO', 'KW', 'QA', 'BH', 'OM', 'LB', 'LY', 'YE', 'SD', 'SY', 'PS', 'MR'] as $c) { $m[$c] = 'ar'; }
    return $m;
}

/** Best supported language from the Accept-Language header, or '' when none of the listed languages is offered. 'en' wins if the browser prefers English. */
function lang_from_header(string $h): string
{
    $found = [];
    foreach (explode(',', $h) as $i => $part) {
        $bits = explode(';', trim($part));
        $tag = strtolower(trim($bits[0]));
        if ($tag === '' || $tag === '*') { continue; }
        $q = 1.0;
        foreach (array_slice($bits, 1) as $b) { if (preg_match('/^\s*q\s*=\s*([0-9.]+)/i', $b, $mm)) { $q = (float)$mm[1]; } }
        $found[] = [$q, $i, substr($tag, 0, 2)];
    }
    usort($found, function ($a, $b) { return $b[0] <=> $a[0] ?: $a[1] <=> $b[1]; });
    foreach ($found as $f) {
        if ($f[0] > 0 && isset(SK_LANGS[$f[2]])) { return $f[2]; }
    }
    return '';
}

/**
 * First visit only (no language cookie yet): choose French/Arabic/Kiswahili/English from the browser language, then from the visitor's country.
 * Never runs for crawlers, admin pages, APIs or after the visitor picked a language. Switch off with the setting lang_autodetect = 0.
 */
function lang_autodetect(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || isset($_COOKIE['sk_lang']) || isset($_GET['lang'])) { return; }
    if (defined('NO_TRANSLATE') || headers_sent()) { return; }
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($script, '/admin/') !== false || strpos($script, '/api/') !== false || strpos($script, '/payments/') !== false || strpos($script, '/cron/') !== false) { return; }
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|monitor|curl|wget|python|headless|lighthouse/i', $ua)) { return; }
    try {
        if (setting('lang_autodetect', '1') !== '1') { return; }
    } catch (Throwable $e) { return; }
    $lang = lang_from_header((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if ($lang === '' && function_exists('geo_country')) {
        $map = lang_country_map();
        $c = geo_country();
        $lang = $map[$c] ?? 'en';
    }
    if ($lang === '') { $lang = 'en'; }
    set_pref_cookie('sk_lang', $lang);
}
