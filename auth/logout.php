<?php
require_once dirname(__DIR__) . '/includes/init.php';
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $c = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $c['path'], $c['domain'] ?? '', $c['secure'], $c['httponly']);
}
session_destroy();
session_start();                       // fresh session, only to carry the message below
session_regenerate_id(true);
set_flash('info', 'You have been logged out.');
redirect('index.php');
