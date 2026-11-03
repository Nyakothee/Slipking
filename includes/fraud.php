<?php
/**
 * Multi-account signals. We store the IP and a short hash of the browser for each member who logs in (kept 90 days).
 * Shared mobile networks mean many honest people can share one IP, so a match on IP alone is only a hint.
 * A "strong" match means same IP AND same browser hash. Strong matches block referral rewards and second free trials.
 */

function fraud_record(int $userId): void
{
    try {
        $ip = substr(client_ip(), 0, 45);
        $ua = substr(sha1((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
        $now = date('Y-m-d H:i:s');
        db()->prepare('INSERT INTO user_fingerprints (user_id, ip, ua_hash, first_seen, last_seen) VALUES (?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)')->execute([$userId, $ip, $ua, $now, $now]);
    } catch (Throwable $e) {}
}

/** Other user ids with the same IP and the same browser hash as this user (last 90 days). */
function fraud_strong_matches(int $userId): array
{
    try {
        $st = db()->prepare('SELECT DISTINCT b.user_id FROM user_fingerprints a JOIN user_fingerprints b ON b.ip = a.ip AND b.ua_hash = a.ua_hash AND b.user_id <> a.user_id
                             WHERE a.user_id = ? AND a.last_seen > NOW() - INTERVAL 90 DAY AND b.last_seen > NOW() - INTERVAL 90 DAY LIMIT 50');
        $st->execute([$userId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}

function fraud_are_linked(int $a, int $b): bool
{
    return in_array($b, fraud_strong_matches($a), true);
}

/** True when another account that looks like the same person already had a free trial. */
function fraud_trial_blocked(int $userId): bool
{
    $ids = fraud_strong_matches($userId);
    if (!$ids) { return false; }
    $in = implode(',', array_map('intval', $ids));
    try {
        return (int)db()->query("SELECT COUNT(*) FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE p.slug = 'trial' AND s.user_id IN ($in)")->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Groups of accounts that share an IP, strongest first. */
function fraud_clusters(int $limit = 60): array
{
    $st = db()->query("SELECT ip, ua_hash, COUNT(DISTINCT user_id) AS n, GROUP_CONCAT(DISTINCT user_id ORDER BY user_id) AS ids, MAX(last_seen) AS last_seen
                       FROM user_fingerprints WHERE last_seen > NOW() - INTERVAL 90 DAY GROUP BY ip, ua_hash HAVING n >= 2 ORDER BY n DESC, last_seen DESC LIMIT " . (int)$limit);
    return $st->fetchAll();
}

function fraud_cleanup(): void
{
    try { db()->exec('DELETE FROM user_fingerprints WHERE last_seen < NOW() - INTERVAL 90 DAY'); } catch (Throwable $e) {}
}
