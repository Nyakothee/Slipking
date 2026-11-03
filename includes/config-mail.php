<?php
/**
 * SlipKing - email settings. EDIT THIS FILE and keep it private (it holds the SMTP password).
 *
 * MAIL_DRIVER 'mail' = PHP's built-in mail() (default, works on most shared hosting, but often lands in spam).
 * MAIL_DRIVER 'smtp' = send through a real mail server (your hosting mailbox, Brevo, Mailgun, Gmail app password...).
 *   Typical: host smtp.example.com, port 587, secure 'tls'   (STARTTLS)
 *            or port 465, secure 'ssl'                        (implicit SSL)
 * If SMTP fails, the site falls back to mail() and writes the reason to logs/mail.log.
 * Tip: make SITE_EMAIL (config.php) the same address as MAIL_SMTP_USER, or the server may reject the message.
 */
if (!defined('MAIL_DRIVER'))      define('MAIL_DRIVER', 'mail');     // 'mail' or 'smtp'
if (!defined('MAIL_SMTP_HOST'))   define('MAIL_SMTP_HOST', '');
if (!defined('MAIL_SMTP_PORT'))   define('MAIL_SMTP_PORT', 587);
if (!defined('MAIL_SMTP_SECURE')) define('MAIL_SMTP_SECURE', 'tls'); // 'tls' (STARTTLS), 'ssl' or ''
if (!defined('MAIL_SMTP_USER'))   define('MAIL_SMTP_USER', '');
if (!defined('MAIL_SMTP_PASS'))   define('MAIL_SMTP_PASS', '');
