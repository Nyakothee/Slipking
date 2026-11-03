<?php
/**
 * Languages: English (default), French, Arabic (right-to-left).
 *
 * How it works: pages are written in English. For French and Arabic the finished page is passed through
 * translate_output(), which swaps known English text for the translation in lang/fr.txt or lang/ar.txt.
 * Anything not in the file simply stays in English, so nothing can break.
 * To change or add a translation, edit the .txt file: one line per phrase,  English ||| Translation
 */

const SK_LANGS = ['en' => 'English', 'fr' => 'Français', 'ar' => 'العربية', 'sw' => 'Kiswahili'];

/** Texts used by JavaScript (sent to the browser in the footer). */
const SK_JS_KEYS = [
    'Sending the request to your phone...', 'Waiting for confirmation...', 'Something went wrong.',
    'Could not connect. Check your internet and try again.',
    'We have not received confirmation yet. If you entered your PIN, your plan will switch on by itself within a few minutes. You can check the Payments page in your account.',
    'PayPal could not load. Check your connection and refresh the page.', 'Opening PayPal...', 'Could not start the payment.',
    'Confirming your payment...', 'Payment received. Your VIP plan is now active.', 'The payment could not be confirmed.',
    'Payment cancelled. You have not been charged.', 'PayPal ran into a problem. Please try again.', 'Returns',
    'Something went wrong. Please try again.', 'Starting the payment...', 'Redirecting to Paystack...', 'Verifying your payment...',
    'Payment window closed. You have not been charged.', 'Razorpay could not load. Check your connection and refresh the page.',
    'Accumulator result',
    'Bookmaker implied chance',
    'Combined odds',
    'Each odds value must be above 1.00.',
    'Enter the odds of at least two selections.',
    'Enter your bankroll, odds and win chance.',
    'Even if the odds are fair, the chance of all selections winning is about 1 in',
    'Every selection must win. If just one loses, you lose your whole stake.',
    'Flat stake',
    'Flat staking',
    'Four or more selections is high risk. Keep the stake small.',
    'Full Kelly',
    'Full Kelly is more than a quarter of your bankroll. That is very aggressive and your win estimate may be too optimistic. Consider half or quarter Kelly.',
    'Half Kelly',
    'Kelly criterion',
    'No edge: at these odds your estimated chance does not beat the bookmaker price, so the Kelly stake is 0. Skipping this bet is the sensible choice.',
    'Odds of selection',
    'Profit at that stake',
    'Profit if every selection wins',
    'Profit if it wins',
    'Quarter Kelly',
    'Remove',
    'Return for your stake',
    'Selections',
    'Stake needed for your target return',
    'Suggested for your risk profile',
    'Suggested stake',
    'These are estimates, not guarantees. 18+ only.',
    'These are estimates, not guarantees. Bets can and do lose. 18+ only.',
    'This stake is more than 2% of your bankroll, which is a lot for a bet that usually loses.',
    'Win probability must be between 0 and 100.',
    'With this many selections a win is rare. Treat it as a small-stake lottery ticket, not a plan.',
    'Your estimated chance',
    'Switch to dark mode',
    'Switch to light mode',
    'New VIP tip', 'Tip alerts: on', 'Turn on tip alerts',
    'New free tip',
];

function lang_code(): string
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    // Android app: follow the phone's language (the app adds SlipKingLang/xx to its browser name).
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua !== '' && preg_match('~SlipKingLang/([a-z]{2})~', $ua, $m) && isset(SK_LANGS[$m[1]])
        && ($_COOKIE['sk_applang'] ?? '') !== $m[1] && !headers_sent() && function_exists('set_pref_cookie')) {
        set_pref_cookie('sk_lang', $m[1]);
        set_pref_cookie('sk_applang', $m[1]);
        $_COOKIE['sk_lang'] = $m[1];
        $_COOKIE['sk_applang'] = $m[1];
    }
    $w = (string)($_COOKIE['sk_lang'] ?? '');
    return $c = (isset(SK_LANGS[$w]) ? $w : 'en');
}

function lang_dir(): string
{
    return lang_code() === 'ar' ? 'rtl' : 'ltr';
}

function lang_dict(): array
{
    static $d = null;
    if ($d !== null) {
        return $d;
    }
    $d = ['exact' => [], 'patterns' => [], 'months' => [], 'days' => []];
    $code = lang_code();
    if ($code === 'en') {
        return $d;
    }
    $file = dirname(__DIR__) . '/lang/' . $code . '.txt';
    $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, ' ||| ');
        if ($pos === false) {
            continue;
        }
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 5));
        if ($k === '__months' || $k === '__days') {
            foreach (explode(';', $v) as $pair) {
                $p = explode('=', $pair, 2);
                if (count($p) === 2) {
                    $d[substr($k, 2)][trim($p[0])] = trim($p[1]);
                }
            }
            continue;
        }
        if (strpos($k, '{') !== false) {
            $order = [];
            $re = preg_replace_callback('/\\\\\{(\d)\\\\\}/', function ($m) use (&$order) {
                $order[] = $m[1];
                return '(.+?)';
            }, preg_quote($k, '/'));
            $d['patterns'][] = ['/^' . $re . '$/su', $v, $order];
        } else {
            $d['exact'][$k] = $v;
        }
    }
    return $d;
}

/** Translate a short piece of text (used by server code and JSON replies). */
function t(string $s, array $vars = []): string
{
    $d = lang_dict();
    $out = $d['exact'][$s] ?? $s;
    foreach ($vars as $k => $v) {
        $out = str_replace('{' . $k . '}', (string)$v, $out);
    }
    return $out;
}

function js_translations(): array
{
    if (lang_code() === 'en') {
        return [];
    }
    $o = [];
    foreach (SK_JS_KEYS as $k) {
        $tr = t($k);
        if ($tr !== $k) {
            $o[$k] = $tr;
        }
    }
    return $o;
}

/** Exact match first, then patterns like "Hello, {1}" (the pieces captured are translated too). */
function translate_lookup(string $plain, array $d): ?string
{
    if (isset($d['exact'][$plain])) {
        return $d['exact'][$plain];
    }
    foreach ($d['patterns'] as $p) {
        if (preg_match($p[0], $plain, $m)) {
            $out = $p[1];
            foreach ($p[2] as $i => $num) {
                $piece = $m[$i + 1];
                $tr = translate_lookup($piece, $d);
                $out = str_replace('{' . $num . '}', $tr ?? $piece, $out);
            }
            return $out;
        }
    }
    return null;
}

/** "4 Oct", "Sat 4 Oct 15:00", "Saturday 4 October 2026" in the chosen language. */
function localize_dates(string $s, array $d): string
{
    if ((!$d['months'] && !$d['days']) || !preg_match('/\d/', $s)) {
        return $s;
    }
    if ($d['months']) {
        $mon = $d['months'];
        $s = preg_replace_callback('/(\d{1,2}) (January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\b/', function ($m) use ($mon) {
            return $m[1] . ' ' . ($mon[$m[2]] ?? $m[2]);
        }, $s);
    }
    if ($d['days']) {
        $day = $d['days'];
        $s = preg_replace_callback('/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|Mon|Tue|Wed|Thu|Fri|Sat|Sun)\b(?= \d)/', function ($m) use ($day) {
            return $day[$m[1]] ?? $m[1];
        }, $s);
    }
    return (string)$s;
}

function translate_chunk(string $chunk, array $d): string
{
    $res = preg_replace_callback('/>([^<>]+)</u', function ($m) use ($d) {
        $raw = $m[1];
        if (trim($raw) === '') {
            return $m[0];
        }
        $plain = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($plain === '' || !preg_match('/\pL/u', $plain)) {
            return $m[0];
        }
        $tr   = translate_lookup($plain, $d);
        $out  = $tr ?? $plain;
        $out2 = localize_dates($out, $d);
        if ($tr === null && $out2 === $plain) {
            return $m[0];
        }
        $lead  = substr($raw, 0, strlen($raw) - strlen(ltrim($raw)));
        $trail = substr($raw, strlen(rtrim($raw)));
        return '>' . $lead . htmlspecialchars($out2, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $trail . '<';
    }, $chunk);
    if ($res === null) {
        return $chunk;
    }
    $res2 = preg_replace_callback('/\b(placeholder|aria-label|title|alt)="([^"<>]*)"/u', function ($m) use ($d) {
        $plain = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tr = ($plain !== '') ? translate_lookup($plain, $d) : null;
        return $tr === null ? $m[0] : $m[1] . '="' . htmlspecialchars($tr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }, $res);
    return $res2 === null ? $res : $res2;
}

/** Output-buffer callback: translates the finished HTML page. Any problem returns the page untouched. */
function translate_output(string $html): string
{
    try {
        $d = lang_dict();
        if (!$d['exact'] && !$d['patterns']) {
            return $html;
        }
        $head = ltrim(substr($html, 0, 20));
        if ($head === '' || $head[0] === '{' || $head[0] === '[' || strpos($head, '<?xml') === 0) {
            return $html;                                   // JSON and XML are not translated here
        }
        $parts = preg_split('#(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<textarea\b[^>]*>.*?</textarea>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }
        foreach ($parts as $i => $p) {
            if ($i % 2 === 0) {
                $parts[$i] = translate_chunk($p, $d);
            }
        }
        return implode('', $parts);
    } catch (Throwable $e) {
        return $html;
    }
}
