<?php
/**
 * OPTIONAL extra payment methods: Stripe (cards in USD/EUR/GBP), MTN MoMo and Airtel Money (mobile money).
 * Fill in only what you use; leave '' to keep a method switched off. Keep this file private.
 * (A separate file, so upgrading never overwrites your other settings.)
 */

/* ---- Stripe: card payments (Visa, Mastercard, Apple/Google Pay...) in US dollars, euros or pounds ----
 * Keys: https://dashboard.stripe.com/apikeys  (sk_test_... while testing, sk_live_... when live)
 * Webhook (recommended): add endpoint  https://YOURSITE/payments/stripe-webhook.php  for the events
 * checkout.session.completed and checkout.session.async_payment_succeeded, then paste its signing secret (whsec_...) below. */
if (!defined('STRIPE_SECRET_KEY'))     define('STRIPE_SECRET_KEY', '');
if (!defined('STRIPE_WEBHOOK_SECRET')) define('STRIPE_WEBHOOK_SECRET', '');

/* ---- MTN Mobile Money (MoMo Collection API): https://momodeveloper.mtn.com ----
 * MTN_MOMO_ENV: 'sandbox' for testing (sandbox only accepts currency EUR), or the target environment name MTN gave you for live use,
 * for example 'mtnuganda', 'mtnghana', 'mtnrwanda', 'mtnzambia', 'mtnivorycoast', 'mtncameroon'.
 * MTN_MOMO_CURRENCY: the currency of your MTN country, for example UGX, GHS, RWF, ZMW, XOF, XAF. */
if (!defined('MTN_MOMO_ENV'))              define('MTN_MOMO_ENV', 'sandbox');
if (!defined('MTN_MOMO_SUBSCRIPTION_KEY')) define('MTN_MOMO_SUBSCRIPTION_KEY', '');   // Collection product "primary key"
if (!defined('MTN_MOMO_API_USER'))         define('MTN_MOMO_API_USER', '');           // the API user (a UUID) you created
if (!defined('MTN_MOMO_API_KEY'))          define('MTN_MOMO_API_KEY', '');            // the key generated for that API user
if (!defined('MTN_MOMO_CURRENCY'))         define('MTN_MOMO_CURRENCY', 'UGX');
if (!defined('MTN_MOMO_MSISDN_PREFIX'))    define('MTN_MOMO_MSISDN_PREFIX', '256');   // country dialling code, no plus sign (Uganda 256, Ghana 233, Rwanda 250, Zambia 260)

/* ---- Airtel Money (Airtel Open API, Collections): https://developers.airtel.africa ----
 * AIRTEL_ENV: 'sandbox' or 'production'. AIRTEL_COUNTRY: two-letter code (KE Kenya, TZ Tanzania, UG Uganda, RW Rwanda, ZM Zambia, MW Malawi).
 * AIRTEL_CURRENCY: KES, TZS, UGX, RWF, ZMW, MWK ... AIRTEL_MSISDN_PREFIX: dialling code without plus (Kenya 254, Tanzania 255, Uganda 256). */
if (!defined('AIRTEL_ENV'))           define('AIRTEL_ENV', 'sandbox');
if (!defined('AIRTEL_CLIENT_ID'))     define('AIRTEL_CLIENT_ID', '');
if (!defined('AIRTEL_CLIENT_SECRET')) define('AIRTEL_CLIENT_SECRET', '');
if (!defined('AIRTEL_COUNTRY'))       define('AIRTEL_COUNTRY', 'KE');
if (!defined('AIRTEL_CURRENCY'))      define('AIRTEL_CURRENCY', 'KES');
if (!defined('AIRTEL_MSISDN_PREFIX')) define('AIRTEL_MSISDN_PREFIX', '254');

/* Airtel Money WITHDRAWALS (paying tipsters). Ask Airtel to enable "Disbursements" on your merchant account, then put the PIN of that
 * disbursement wallet here. The PIN is encrypted with Airtel's public key before it leaves your server, and is never shown on any page. */
if (!defined('AIRTEL_DISBURSE_PIN'))  define('AIRTEL_DISBURSE_PIN', '');
