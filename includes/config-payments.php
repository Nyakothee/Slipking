<?php
/**
 * PAYMENT SETTINGS - fill this in before you upload. Keep this file private.
 * (This is a separate file so it does not overwrite your includes/config.php.)
 */

/* ---------------- M-Pesa (Safaricom Daraja, M-Pesa Express / STK Push) ---------------- */

// Use 'sandbox' while testing with fake money. Switch to 'live' only after Safaricom has approved Go Live.
define('MPESA_ENV', 'sandbox');

// From your app on developer.safaricom.co.ke
define('MPESA_CONSUMER_KEY', '');
define('MPESA_CONSUMER_SECRET', '');

// Sandbox: 174379.  Live: YOUR PAYBILL NUMBER.
define('MPESA_SHORTCODE', '174379');

// Sandbox: the test passkey shown on Daraja's M-Pesa Express simulator page.
// Live: the STK Push passkey Safaricom gave you for your paybill.
define('MPESA_PASSKEY', '');

// Paybill = 'CustomerPayBillOnline'.  (Till number = 'CustomerBuyGoodsOnline')
define('MPESA_TXN_TYPE', 'CustomerPayBillOnline');

// Leave '' for a Paybill (the shortcode is used). For a Till, put the TILL number here
// and keep your store/head-office number in MPESA_SHORTCODE.
define('MPESA_PARTY_B', '');

/* ---------------- PayPal (added in the next step) ---------------- */
define('PAYPAL_ENV', 'sandbox');          // 'sandbox' or 'live'
define('PAYPAL_CLIENT_ID', '');
define('PAYPAL_SECRET', '');
define('PAYPAL_WEBHOOK_ID', '');
