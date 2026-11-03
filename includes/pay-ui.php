<?php
/**
 * Payment method buttons: each one is drawn in the colours of the company behind it (CSS in assets/css/pay.css).
 * Official logos are trademarks, so none are bundled. If you own the right to use them, drop a file named after the
 * method into assets/img/pay/ (mpesa, airtel, mtn, stripe, paypal, paystack, razorpay; .svg, .png or .webp) and it
 * replaces the letter badge automatically. See README section 19.
 */

require_once __DIR__ . '/mpesa.php';
require_once __DIR__ . '/paypal.php';
require_once __DIR__ . '/wallets.php';

function pay_methods(): array
{
    return [
        'mpesa'    => ['name' => 'M-Pesa',       'company' => 'Safaricom',     'tag' => 'Mobile money · Kenya'],
        'airtel'   => ['name' => 'Airtel Money', 'company' => 'Airtel Africa', 'tag' => 'Mobile money'],
        'mtn'      => ['name' => 'MTN MoMo',     'company' => 'MTN Group',     'tag' => 'Mobile money'],
        'stripe'   => ['name' => 'Card',         'company' => 'Stripe',        'tag' => 'Visa, Mastercard · USD, EUR, GBP'],
        'paypal'   => ['name' => 'PayPal',       'company' => 'PayPal',        'tag' => 'PayPal balance or card · USD'],
        'paystack' => ['name' => 'Paystack',     'company' => 'Paystack',      'tag' => 'Card or bank · NGN'],
        'razorpay' => ['name' => 'Razorpay',     'company' => 'Razorpay',      'tag' => 'UPI or card · INR'],
    ];
}

function pay_on(string $slug): bool { return setting('pay_' . $slug . '_on', '1') === '1'; }

/** Online logo for each method (best effort; a missing one falls back to the letter badge). Admin can override per method. */
function pay_logo_defaults(): array
{
    return [
        'mpesa'    => 'https://upload.wikimedia.org/wikipedia/commons/0/0b/M-PESA.png',
        'airtel'   => 'https://upload.wikimedia.org/wikipedia/commons/thumb/3/3a/Airtel_logo.svg/200px-Airtel_logo.svg.png',
        'mtn'      => 'https://upload.wikimedia.org/wikipedia/commons/thumb/9/93/MTN_Logo.svg/200px-MTN_Logo.svg.png',
        'stripe'   => 'https://cdn.simpleicons.org/stripe/635BFF',
        'paypal'   => 'https://cdn.simpleicons.org/paypal/003087',
        'paystack' => 'https://cdn.simpleicons.org/paystack/0BA4DB',
        'razorpay' => 'https://cdn.simpleicons.org/razorpay/0C2451',
    ];
}

function pay_logo_url(string $slug): string
{
    $custom = trim((string)setting('pay_' . $slug . '_logo', ''));
    if ($custom !== '' && preg_match('#^https?://#i', $custom)) {
        return $custom;
    }
    $d = pay_logo_defaults();
    return $d[$slug] ?? '';
}

/** Logo (local file, then online link) with a letter badge fallback if the picture cannot load. */
function pay_mark(string $slug): string
{
    $m = pay_methods();
    $letter = e(mb_substr($m[$slug]['name'], 0, 1));
    $src = '';
    foreach (['svg', 'png', 'webp'] as $ext) {
        if (is_file(dirname(__DIR__) . '/assets/img/pay/' . $slug . '.' . $ext)) {
            $src = url('assets/img/pay/' . $slug . '.' . $ext);
            break;
        }
    }
    if ($src === '') {
        $src = pay_logo_url($slug);
    }
    if ($src === '') {
        return '<span class="pb-mark" aria-hidden="true">' . $letter . '</span>';
    }
    return '<span class="pb-mark has-img" aria-hidden="true"><img src="' . e($src) . '" alt="" width="28" height="28" loading="lazy" referrerpolicy="no-referrer"'
        . ' onerror="var p=this.parentNode;p.classList.remove(\'has-img\');p.textContent=\'' . $letter . '\';"></span>';
}

/** A disabled, brand-coloured placeholder for a method that is not connected yet. */
function pay_soon_button(string $slug): string
{
    $m = pay_methods()[$slug];
    return '<button type="button" class="paybtn is-soon pb-' . e($slug) . '" disabled aria-disabled="true">'
        . pay_mark($slug)
        . '<span class="pb-txt"><b>' . e($m['name']) . '</b><small>' . e($m['company']) . ' · ' . e($m['tag']) . '</small></span>'
        . '<span class="pb-amt pb-soon">Coming soon</span></button>';
}

/** Placeholder buttons for every method that is not live yet. */
function pay_soon_html(): string
{
    $live = pay_enabled_slugs();
    $out = '';
    foreach (array_keys(pay_methods()) as $s) {
        if (!in_array($s, $live, true)) {
            $out .= pay_soon_button($s);
        }
    }
    return $out;
}

/** The big choice button at the top of checkout. */
function pay_choice_button(string $slug, string $amountText, string $tag = '', bool $active = false): string
{
    $m = pay_methods()[$slug];
    return '<button type="button" class="paybtn pb-' . e($slug) . ($active ? ' on' : '') . '" data-pay="' . e($slug) . '" aria-controls="panel-' . e($slug) . '" aria-pressed="' . ($active ? 'true' : 'false') . '">'
        . pay_mark($slug)
        . '<span class="pb-txt"><b>' . e($m['name']) . '</b><small>' . e($m['company']) . ' · ' . e($tag !== '' ? $tag : $m['tag']) . '</small></span>'
        . '<span class="pb-amt">' . e($amountText) . '</span></button>';
}

/** The brand-coloured submit button inside a method's panel. */
function pay_submit_button(string $slug, string $label, string $id = '', string $type = 'button'): string
{
    return '<button class="btn full paysubmit pb-' . e($slug) . '" type="' . e($type) . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>' . pay_mark($slug) . '<span>' . e($label) . '</span></button>';
}

/** Small coloured chips used on the VIP and home pages. Only methods that are really switched on. */
function pay_chips_html(): string
{
    $out = '';
    $live = pay_enabled_slugs();
    foreach (pay_methods() as $s => $m) {
        $on = in_array($s, $live, true);
        $out .= '<span class="paychip pb-' . e($s) . ($on ? '' : ' is-soon') . '">' . pay_mark($s) . e($m['name']) . ($on ? '' : ' <em>soon</em>') . '</span>';
    }
    return $out;
}

/** Slugs of the methods that are configured and switched on. */
function pay_enabled_slugs(): array
{
    static $r = null;
    if ($r !== null) {
        return $r;
    }
    $r = [];
    if (function_exists('mpesa_ready') && mpesa_ready() && pay_on('mpesa')) $r[] = 'mpesa';
    if (airtel_ready()) $r[] = 'airtel';
    if (momo_ready()) $r[] = 'mtn';
    if (stripe_ready()) $r[] = 'stripe';
    if (function_exists('paypal_ready') && paypal_ready() && pay_on('paypal')) $r[] = 'paypal';
    if (paystack_ready() && pay_on('paystack')) $r[] = 'paystack';
    if (razorpay_ready() && pay_on('razorpay')) $r[] = 'razorpay';
    return $r;
}
