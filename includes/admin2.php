<?php
/** Admin/ops extras: pending counters, maintenance mode, support tickets with rating, scheduled mass mail, what's-new, jackpot rounds. */

/* ---------- pending approvals counter ---------- */
function admin_pending(): array
{
    $one = function (string $sql) { try { return (int)db()->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };
    $r = [
        ['Tips waiting for approval', $one('SELECT COUNT(*) FROM tips WHERE pending_review = 1'), 'admin/contributor-tips.php'],
        ['Payments flagged (double / wrong amount)', $one('SELECT COUNT(*) FROM payment_flags WHERE resolved = 0'), 'admin/payment-flags.php'],
        ['Ban appeals', $one("SELECT COUNT(*) FROM ban_appeals WHERE status = 'open'"), 'admin/appeals.php'],
        ['Support tickets', $one("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','pending')"), 'admin/tickets.php'],
        ['Broken stream links', $one('SELECT COUNT(DISTINCT url_hash) FROM stream_reports WHERE created_at > NOW() - INTERVAL 6 HOUR'), 'admin/watch-reports.php'],
        ['Tipster payouts to pay', $one("SELECT COUNT(*) FROM tipster_payouts WHERE status = 'requested'"), 'admin/tipster-payouts.php'],
        ['Tipster claims', $one("SELECT COUNT(*) FROM tipster_claims WHERE status = 'requested'"), 'admin/tipster-claims.php'],
        ['Chat reports', $one('SELECT COUNT(*) FROM chat_reports WHERE created_at > NOW() - INTERVAL 3 DAY'), 'admin/chat-reports.php'],
    ];
    return $r;
}
function admin_pending_total(): int { $n = 0; foreach (admin_pending() as $p) { $n += $p[1]; } return $n; }

/* ---------- maintenance mode ---------- */
function maintenance_gate(): void
{
    if (PHP_SAPI === 'cli' || setting('maintenance_on', '0') !== '1') { return; }
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (preg_match('#/(admin|cron|payments)/|/auth/(login|logout)\.php$#', $path)) { return; }
    if (function_exists('is_staff') && is_staff()) { return; }
    $msg = (string)setting('maintenance_msg', 'We are making the site better. Back in a few minutes.');
    http_response_code(503);
    header('Retry-After: 600');
    if (strpos($path, '/api/') !== false) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => $msg, 'maintenance' => true]); exit; }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Back soon</title></head><body style="font-family:system-ui;background:#0e1620;color:#fff;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center;padding:20px"><div><h1>&#128736; Maintenance</h1><p>' . htmlspecialchars($msg, ENT_QUOTES) . '</p></div></body></html>';
    exit;
}

/* ---------- what's new / tour ---------- */
function whats_new(): array { return ['id' => (string)setting('whats_new_id', ''), 'text' => (string)setting('whats_new_text', '')]; }

/* ---------- support tickets ---------- */
function tickets_ready(): bool { return sk_ensure_table('support_tickets', 'database-update-44.sql') && sk_ensure_table('support_msgs', 'database-update-44.sql'); }

function ticket_open(int $uid, string $subject, string $body): int
{
    db()->prepare('INSERT INTO support_tickets (user_id, subject) VALUES (?,?)')->execute([$uid, mb_substr($subject, 0, 120)]);
    $id = (int)db()->lastInsertId();
    db()->prepare('INSERT INTO support_msgs (ticket_id, from_staff, body) VALUES (?,0,?)')->execute([$id, mb_substr($body, 0, 3000)]);
    return $id;
}

function ticket_reply(int $ticketId, bool $staff, string $body): void
{
    $body = mb_substr(trim($body), 0, 3000);
    if ($body === '') { return; }
    db()->prepare('INSERT INTO support_msgs (ticket_id, from_staff, body) VALUES (?,?,?)')->execute([$ticketId, $staff ? 1 : 0, $body]);
    db()->prepare('UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$staff ? 'answered' : 'open', $ticketId]);
    if ($staff) {
        $q = db()->prepare('SELECT user_id, subject FROM support_tickets WHERE id = ?'); $q->execute([$ticketId]); $t = $q->fetch();
        if ($t) { notify_user((int)$t['user_id'], 'Support replied: ' . mb_substr($t['subject'], 0, 60), mb_substr($body, 0, 200), 'account/tickets.php?id=' . $ticketId); }
    }
}

function ticket_status_label(string $s): string { return ['open' => 'Open - we are on it', 'answered' => 'Answered - your turn', 'closed' => 'Closed'][$s] ?? $s; }

/* ---------- scheduled mass mail ---------- */
function bulk_scheduled_run(): int
{
    if (!function_exists('bulk_ready') || !bulk_ready()) { return 0; }
    try { sk_ensure_column('bulk_campaigns', 'send_at', 'DATETIME NULL DEFAULT NULL'); } catch (Throwable $e) { return 0; }
    $pdo = db();
    $pdo->query("UPDATE bulk_campaigns SET status = 'sending' WHERE status = 'scheduled' AND send_at <= NOW()");
    $n = 0;
    foreach ($pdo->query("SELECT id FROM bulk_campaigns WHERE status = 'sending' AND (send_at IS NULL OR send_at <= NOW()) AND send_at IS NOT NULL ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        for ($i = 0; $i < 8; $i++) { $c = bulk_send_batch((int)$cid, 8); $n += 8; if (!$c || ($c['status'] ?? '') !== 'sending') { break; } }
    }
    return $n;
}

/* ---------- jackpot rounds (weekly, midweek, mega) ---------- */
function jackpot_cols(): bool
{
    static $ok = null;
    if ($ok === null) { try { $ok = jackpot_ready() && sk_ensure_column('jackpot_rounds', 'kind', "VARCHAR(10) NOT NULL DEFAULT 'weekly'") && sk_ensure_column('jackpot_rounds', 'analysis', 'TEXT NULL'); } catch (Throwable $e) { $ok = false; } }
    return $ok;
}
function jackpot_open_rounds(): array
{
    if (!jackpot_ready()) { return []; }
    jackpot_cols();
    return db()->query("SELECT * FROM jackpot_rounds WHERE status = 'open' ORDER BY (kind = 'mega') DESC, id DESC")->fetchAll();
}
function jackpot_kind_label(string $k): string { return ['weekly' => 'Weekly', 'midweek' => 'Midweek', 'mega' => 'Mega'][$k] ?? ucfirst($k); }
