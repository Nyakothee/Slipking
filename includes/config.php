<?php
/**
 * SlipKing - main settings. EDIT THIS FILE BEFORE UPLOADING.
 * Never share this file or commit it to a public repository.
 */

define('SITE_NAME', 'SlipKing');
// Full address of the site, NO trailing slash. If it lives in a folder, include it:
// e.g. https://yourdomain.com  or  https://yourdomain.com/tips
define('SITE_URL', 'http://localhost/finale6/');
define('SITE_EMAIL', 'support@localhost.com');

// Database (create the database and user in your hosting control panel)
define('DB_HOST', 'localhost');
define('DB_NAME', 'bet');
define('DB_USER', 'root');
define('DB_PASS', '');

// Kenya = Africa/Nairobi, UTC+3. Keep both lines in step.
define('TIMEZONE', 'Africa/Nairobi');
define('DB_TZ_OFFSET', '+03:00');

define('CURRENCY', 'KES');

// true shows error details on screen. Keep FALSE on the live site.
define('DEBUG', false);

date_default_timezone_set(TIMEZONE);
ini_set('display_errors', DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/logs/php-errors.log');
error_reporting(E_ALL);

// Payment keys are added here later (PayPal + M-Pesa Daraja).
