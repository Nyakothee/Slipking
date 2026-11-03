<?php
/**
 * Keeps the free database small. Runs at most once a day, triggered by a normal page view
 * (no cron job needed); cron/cleanup.php calls it too. Every step is separate so one missing table never stops the rest.
 */
function housekeeping(bool $force = false): array
{
    $done = [];
    try {
        if (!$force && date('Y-m-d') === setting('last_housekeeping', '')) { return $done; }
        set_setting('last_housekeeping', date('Y-m-d'));   // set first so two visitors never run it together
    } catch (Throwable $e) { return $done; }
    $pdo = db();
    $steps = [
        'push queue'      => "DELETE FROM app_push_queue WHERE (sent_at IS NOT NULL AND sent_at < NOW() - INTERVAL 14 DAY) OR created_at < NOW() - INTERVAL 45 DAY",
        // Deletion records are kept for good: they are the proof of what was removed.
        'activity log'    => "DELETE FROM admin_log WHERE created_at < NOW() - INTERVAL 365 DAY AND action NOT LIKE '%delete%' AND action NOT LIKE '%remove%'",
        'old messages'    => "DELETE FROM dm_messages WHERE created_at < NOW() - INTERVAL 365 DAY",
        'closed chats'    => "DELETE FROM chat_threads WHERE status = 'closed' AND last_message_at < NOW() - INTERVAL 90 DAY",
        'login attempts'  => "DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 2 DAY",
        'phone codes'     => "DELETE FROM phone_codes WHERE created_at < NOW() - INTERVAL 2 DAY",
        'reset links'     => "DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 7 DAY",
        'sent alerts'     => "DELETE FROM alert_queue WHERE created_at < NOW() - INTERVAL 30 DAY",
        'old notifications' => "DELETE FROM notifications WHERE created_at < NOW() - INTERVAL 120 DAY",
    ];
    foreach ($steps as $name => $sql) {
        try { $done[$name] = (int)$pdo->exec($sql); } catch (Throwable $e) { /* table may not exist on this install */ }
    }
    if (function_exists('cache_prune')) { $done['cache files'] = cache_prune(3600); }
    return $done;
}
