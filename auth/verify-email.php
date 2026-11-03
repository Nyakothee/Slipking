<?php
require_once dirname(__DIR__) . '/includes/init.php';

// 1) Member asks for a new link (button on the dashboard)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login();
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif (too_many_attempts(5)) {
        set_flash('error', 'Too many requests. Please wait 15 minutes.');
    } else {
        $u = current_user();
        record_attempt($u['email']);
        send_verification_email((int)$u['id'], $u['email'], $u['name']);
        set_flash('success', 'We sent a new confirmation link to ' . $u['email'] . '.');
    }
    redirect('account/dashboard.php');
}

// 2) Member clicks the link in the email
$id  = (int)($_GET['u'] ?? 0);
$exp = (int)($_GET['e'] ?? 0);
$sig = (string)($_GET['s'] ?? '');

if ($id > 0 && $exp > time() && hash_equals(verify_sig($id, $exp), $sig)) {
    db()->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')->execute([$id]);
    set_flash('success', 'Your email is confirmed. Thank you!');
} else {
    set_flash('error', 'This confirmation link is not valid or has expired. Log in and request a new one.');
}
redirect(is_logged_in() ? 'account/dashboard.php' : 'auth/login.php');
