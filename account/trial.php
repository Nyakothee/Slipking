<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    set_flash('error', 'Your session expired. Please try again.');
    redirect('account/dashboard.php');
}
[$ok, $why] = trial_eligibility($user);
if (!$ok) {
    $msgs = ['verify' => 'Please confirm your email address first.', 'phone' => 'Please add your mobile number in your profile first.', 'phone-verify' => 'Please confirm your mobile number with the SMS code first.',
             'used' => 'The free trial is for new members who have not had a plan yet.', 'phone-used' => 'This phone number has already been used for a free trial.',
             'off' => 'The free trial is not available right now.'];
    set_flash('error', $msgs[$why] ?? 'The free trial is not available.');
    redirect('account/dashboard.php');
}
$st = db()->prepare("SELECT id FROM plans WHERE slug = 'trial' LIMIT 1");
$st->execute();
$planId = (int)$st->fetchColumn();
$days = max(1, (int)setting('trial_days', '1'));
if (!$planId || !($ends = grant_vip((int)$user['id'], $planId, $days))) {
    set_flash('error', 'The free trial is not set up yet. Please contact us.');
    redirect('account/dashboard.php');
}
notify_user((int)$user['id'], 'Your free VIP trial has started', 'Enjoy every VIP pick until ' . date('j F Y, H:i', strtotime($ends)) . '. After that you keep the free tips, and you can choose a plan any time.');
payments_log('trial', ['user' => $user['id'], 'days' => $days]);
set_flash('success', 'Your free VIP trial is active until ' . date('j M Y, H:i', strtotime($ends)) . '.');
redirect('account/dashboard.php');
