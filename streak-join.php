<?php
require_once __DIR__ . '/includes/init.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check() || !streak_ready()) { redirect('index.php'); }
$u = current_user();
$st = db()->prepare('SELECT * FROM streak_days WHERE id = ?'); $st->execute([(int)($_POST['day'] ?? 0)]);
$d = $st->fetch();
if ($d && streak_join((int)$u['id'], $d)) { set_flash('success', 'You are in today\'s 2 Odds Streak. Good luck!'); }
else { set_flash('error', 'Today\'s streak pick is closed.'); }
redirect('index.php#streak');
