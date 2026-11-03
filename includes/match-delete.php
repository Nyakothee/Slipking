<?php
/**
 * Admin-only: remove a whole game (match) at any time, even after it has been played and settled.
 * Its tips, odds, live events, game picks and polls go with it. Tipster records and ratings are recalculated.
 * Returns ['tips' => n, 'picks' => n] or null when the match does not exist.
 */
function admin_delete_match(PDO $pdo, int $mid): ?array
{
    $st = $pdo->prepare('SELECT id FROM matches WHERE id = ?');
    $st->execute([$mid]);
    if (!$st->fetch()) { return null; }

    $st = $pdo->prepare('SELECT id, tipster_id FROM tips WHERE match_id = ?');
    $st->execute([$mid]);
    $tips = $st->fetchAll();
    $tipIds = array_map(function ($t) { return (int)$t['id']; }, $tips);
    $tipsters = array_values(array_unique(array_filter(array_map(function ($t) { return (int)$t['tipster_id']; }, $tips))));

    $picks = 0;
    try { $c = $pdo->prepare('SELECT COUNT(*) FROM game_picks WHERE match_id = ?'); $c->execute([$mid]); $picks = (int)$c->fetchColumn(); } catch (Throwable $e) {}

    $run = function (string $sql, array $args) use ($pdo) {
        try { $pdo->prepare($sql)->execute($args); } catch (Throwable $e) { error_log('match-delete: ' . $e->getMessage()); }
    };

    if ($tipIds) { admin_purge_tips($pdo, $tipIds, true); }   // tips, odds, record entries and audit-trail entries of this game
    $pdo->beginTransaction();
    try {
        foreach (['match_events', 'game_picks', 'poll_votes', 'club_picks', 'community_match_threads'] as $t) {
            $run("DELETE FROM $t WHERE match_id = ?", [$mid]);
        }
        $run('UPDATE jackpot_games SET match_id = NULL WHERE match_id = ?', [$mid]);
        $pdo->prepare('DELETE FROM matches WHERE id = ?')->execute([$mid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    if (function_exists('tipster_refresh_rating')) {
        foreach ($tipsters as $tid) { try { tipster_refresh_rating($tid); } catch (Throwable $e) {} }
    }
    return ['tips' => count($tipIds), 'picks' => $picks];
}
