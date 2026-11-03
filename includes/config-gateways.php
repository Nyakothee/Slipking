<?php
/**
 * OPTIONAL: card/bank payments in local currency. Fill in only what you use; leave '' to switch it off.
 * Keep this file private. (A separate file, so upgrading never overwrites your other settings.)
 */

/* ---- Paystack (card/bank payments in Nigerian naira) ---- */
define('PAYSTACK_SECRET_KEY', '');         // sk_test_... while testing, sk_live_... when live

/* ---- Razorpay (UPI/card payments in Indian rupees) ---- */
define('RAZORPAY_KEY_ID', '');             // rzp_test_... / rzp_live_...
define('RAZORPAY_KEY_SECRET', '');
define('RAZORPAY_WEBHOOK_SECRET', '');     // set when you add a webhook in the Razorpay dashboard (optional but recommended)
