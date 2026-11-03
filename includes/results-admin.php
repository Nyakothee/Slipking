<?php
/**
 * Admin-only: permanently remove settled tips ("results"). Gone from the results archive, the track record numbers,
 * tipster records and (optionally) the tamper-evident audit trail, which is then re-sealed so it still verifies.
 * Returns how many tips were removed.
 */
function admin_purge_tips(PDO $pdo, array $tipIds, bool $alsoAudit = true): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $tipIds))));
    if (!$ids) { return 0; }
    $in = implode(',', $ids);
    $tipsters = array_map('intval', $pdo->query("SELECT DISTINCT tipster_id FROM tips WHERE id IN ($in) AND tipster_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
    $run = function (string $sql) use ($pdo) { try { $pdo->exec($sql); } catch (Throwable $e) { error_log('purge: ' . $e->getMessage()); } };
    $pdo->beginTransaction();
    try {
        $run("UPDATE user_bets SET tip_id = NULL WHERE tip_id IN ($in)");
        $run("UPDATE bookmaker_clicks SET tip_id = NULL WHERE tip_id IN ($in)");
        $run("DELETE FROM tip_odds WHERE tip_id IN ($in)");
        $run("DELETE FROM tip_changes WHERE tip_id IN ($in)");
        $n = (int)$pdo->exec("DELETE FROM tips WHERE id IN ($in)");
        if ($alsoAudit) {
            $run("DELETE FROM audit_chain WHERE tip_id IN ($in)");
            audit_reseal($pdo);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    foreach ($tipsters as $tid) { if (function_exists('tipster_refresh_rating')) { try { tipster_refresh_rating($tid); } catch (Throwable $e) {} } }
    if (function_exists('cache_flush')) { cache_flush(); }
    return $n;
}

/** Re-links the audit chain after rows were removed, so audit_verify() still passes for what remains. */
function audit_reseal(PDO $pdo): void
{
    try {
        $prev = str_repeat('0', 64);
        $rows = $pdo->query('SELECT id, tip_id, event, payload, created_at FROM audit_chain ORDER BY id ASC')->fetchAll();
        $up = $pdo->prepare('UPDATE audit_chain SET prev_hash = ?, `hash` = ? WHERE id = ?');
        foreach ($rows as $r) {
            $h = audit_hash($prev, (int)$r['tip_id'], $r['event'], $r['payload'], $r['created_at']);
            $up->execute([$prev, $h, $r['id']]);
            $prev = $h;
        }
    } catch (Throwable $e) { error_log('audit_reseal: ' . $e->getMessage()); }
}
