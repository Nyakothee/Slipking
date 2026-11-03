<?php
/**
 * Daily database backup. Add to Cron Jobs (once a day, e.g. 03:00):
 *   php /home/YOURUSER/public_html/cron/backup.php
 * Keeps the newest N files (Admin > Backups, default 7).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/backup.php';

$pdo = db();
try {
    $name = backup_create($pdo);
    $keep = (int)($pdo->query("SELECT value FROM settings WHERE name = 'backup_keep'")->fetchColumn() ?: 7);
    $gone = backup_prune($keep);
    echo date('c') . " backup: $name, removed $gone old\n";
} catch (Throwable $e) {
    error_log('backup failed: ' . $e->getMessage());
    echo date('c') . ' backup FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}
