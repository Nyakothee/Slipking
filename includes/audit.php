<?php
/**
 * Tamper-evident tip log. Every time a tip is posted or settled, one row is added whose hash covers the previous row's hash.
 * Changing or deleting an old row breaks every hash after it, and audit_verify() (shown on audit.php) will report it.
 * This proves the order and content of what was logged. It cannot prove anything about tips that were never logged.
 */

function audit_tip(int $tipId, string $event): void
{
    try {
        $pdo = db();
        $st = $pdo->prepare('SELECT t.match_id, t.market, t.pick, t.odds, t.is_vip, t.status, m.home_team, m.away_team, m.kickoff_at
                             FROM tips t JOIN matches m ON m.id = t.match_id WHERE t.id = ?');
        $st->execute([$tipId]);
        $t = $st->fetch();
        if (!$t) { return; }
        $payload = $event === 'post'
            ? $t['home_team'] . ' vs ' . $t['away_team'] . ' | ' . $t['market'] . ' | ' . $t['pick'] . ' @ ' . number_format((float)$t['odds'], 2, '.', '') . ' | kickoff ' . $t['kickoff_at'] . ($t['is_vip'] ? ' | vip' : '')
            : 'result ' . $t['status'];
        audit_append($pdo, $tipId, $event === 'settle' ? 'settle' : 'post', substr($payload, 0, 400));
    } catch (Throwable $e) {
        error_log('audit_tip: ' . $e->getMessage());
    }
}

function audit_hash(string $prev, int $tipId, string $event, string $payload, string $at): string
{
    return hash('sha256', $prev . '|' . $tipId . '|' . $event . '|' . $payload . '|' . $at);
}

function audit_append(PDO $pdo, int $tipId, string $event, string $payload): void
{
    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }
    try {
        $last = $pdo->query('SELECT hash FROM audit_chain ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
        $prev = $last ?: str_repeat('0', 64);
        $at = date('Y-m-d H:i:s');
        $h = audit_hash($prev, $tipId, $event, $payload, $at);
        $pdo->prepare('INSERT INTO audit_chain (tip_id, event, payload, prev_hash, hash, created_at) VALUES (?,?,?,?,?,?)')->execute([$tipId, $event, $payload, $prev, $h, $at]);
        if ($own) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/** Walks the whole chain. Returns ['ok'=>bool,'rows'=>int,'broken_at'=>?int,'head'=>string]. */
function audit_verify(): array
{
    $prev = str_repeat('0', 64);
    $n = 0;
    $st = db()->query('SELECT id, tip_id, event, payload, prev_hash, hash, created_at FROM audit_chain ORDER BY id ASC');
    while ($r = $st->fetch()) {
        $n++;
        if ($r['prev_hash'] !== $prev || !hash_equals(audit_hash($prev, (int)$r['tip_id'], $r['event'], $r['payload'], $r['created_at']), $r['hash'])) {
            return ['ok' => false, 'rows' => $n, 'broken_at' => (int)$r['id'], 'head' => $prev];
        }
        $prev = $r['hash'];
    }
    return ['ok' => true, 'rows' => $n, 'broken_at' => null, 'head' => $prev];
}
