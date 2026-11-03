<?php
/** Match discussion rooms: a Reddit-style thread page for every match. Uses the same comments table, moderation and reports. */

function room_ago(string $ts): string
{
    $d = max(0, time() - strtotime($ts));
    if ($d < 60) { return 'just now'; }
    if ($d < 3600) { return (int)($d / 60) . ' min ago'; }
    if ($d < 86400) { return (int)($d / 3600) . ' h ago'; }
    if ($d < 86400 * 7) { return (int)($d / 86400) . ' d ago'; }
    return date('j M Y', strtotime($ts));
}

/** All visible comments of a match with score and the viewer's own vote. */
function room_rows(int $matchId, ?array $u, string $type = 'match', int $limit = 500): array
{
    cmty_ensure_comments();
    $votes = sk_ensure_table('comment_votes', 'database-update-19.sql');
    $uid = $u ? (int)$u['id'] : 0;
    $sc = $votes ? 'COALESCE((SELECT SUM(v.value) FROM comment_votes v WHERE v.comment_id = c.id), 0)' : '0';
    $mine = ($votes && $uid) ? '(SELECT v2.value FROM comment_votes v2 WHERE v2.comment_id = c.id AND v2.user_id = ' . $uid . ')' : 'NULL';
    $st = db()->prepare("SELECT c.id, c.user_id, c.parent_id, c.title, c.image, c.body, c.likes, c.created_at, u.name, u.role" . profile_sql('u') . ",
                                (c.likes + $sc) AS score, $mine AS myvote,
                                (SELECT 1 FROM tipsters tp WHERE tp.user_id = c.user_id AND tp.status = 'active' LIMIT 1) AS is_tipster
                         FROM comments c JOIN users u ON u.id = c.user_id
                         WHERE c.target_type = ? AND c.target_id = ? AND c.status = 'visible'
                         ORDER BY c.created_at DESC, c.id DESC LIMIT " . (int)$limit);
    $st->execute([$type, $matchId]);
    return $st->fetchAll();
}

/** Builds [top-level list, children map] sorted. $sort: best | new | top | old. */
function room_tree(array $rows, string $sort): array
{
    $top = []; $kids = [];
    foreach ($rows as $r) {
        if ($r['parent_id']) { $kids[(int)$r['parent_id']][] = $r; } else { $top[] = $r; }
    }
    $cmp = [
        'new'  => function ($a, $b) { return strcmp($b['created_at'], $a['created_at']) ?: $b['id'] <=> $a['id']; },
        'old'  => function ($a, $b) { return strcmp($a['created_at'], $b['created_at']) ?: $a['id'] <=> $b['id']; },
        'top'  => function ($a, $b) { return $b['score'] <=> $a['score'] ?: strcmp($b['created_at'], $a['created_at']); },
        // "best": score first, but fresh comments get a small boost so new posts are seen
        'best' => function ($a, $b) {
            $sa = $a['score'] + max(0, 3 - (time() - strtotime($a['created_at'])) / 1800);
            $sb = $b['score'] + max(0, 3 - (time() - strtotime($b['created_at'])) / 1800);
            return $sb <=> $sa ?: strcmp($b['created_at'], $a['created_at']);
        },
    ][$sort] ?? null;
    usort($top, $cmp);
    foreach ($kids as &$k) { usort($k, function ($a, $b) { return $b['score'] <=> $a['score'] ?: strcmp($a['created_at'], $b['created_at']); }); }
    unset($k);
    return [$top, $kids];
}

function room_count_replies(int $id, array $kids): int
{
    $n = 0;
    foreach ($kids[$id] ?? [] as $c) { $n += 1 + room_count_replies((int)$c['id'], $kids); }
    return $n;
}

/** One comment and its replies. */
function room_comment_html(array $c, array $kids, ?array $u, int $matchId, string $back, int $depth = 0, string $type = 'match'): string
{
    $id = (int)$c['id'];
    $mine = $u && (int)$u['id'] === (int)$c['user_id'];
    $staff = $c['role'] !== 'user';
    $who = $staff ? 'Team' : mask_name((string)$c['name']);
    $my = $c['myvote'] === null ? 0 : (int)$c['myvote'];
    $act = e(url('community/action.php'));
    $hid = function (string $a, array $extra = []) use ($back) {
        $h = csrf_field() . '<input type="hidden" name="action" value="' . $a . '"><input type="hidden" name="back" value="' . e($back) . '">';
        foreach ($extra as $k => $v) { $h .= '<input type="hidden" name="' . $k . '" value="' . e((string)$v) . '">'; }
        return $h;
    };
    $nrep = room_count_replies($id, $kids);
    $h = '<article class="rm-c" id="c' . $id . '" data-depth="' . $depth . '">';
    // vote column
    $h .= '<div class="rm-v">';
    if ($u && !$mine) {
        $h .= '<form method="post" action="' . $act . '">' . $hid('cvote', ['id' => $id, 'dir' => 'up']) . '<button class="rm-arr up' . ($my === 1 ? ' on' : '') . '" aria-label="Upvote" aria-pressed="' . ($my === 1 ? 'true' : 'false') . '">&#9650;</button></form>';
        $h .= '<b class="rm-score' . ($my === 1 ? ' up' : ($my === -1 ? ' down' : '')) . '">' . (int)$c['score'] . '</b>';
        $h .= '<form method="post" action="' . $act . '">' . $hid('cvote', ['id' => $id, 'dir' => 'down']) . '<button class="rm-arr down' . ($my === -1 ? ' on' : '') . '" aria-label="Downvote" aria-pressed="' . ($my === -1 ? 'true' : 'false') . '">&#9660;</button></form>';
    } else {
        $h .= '<span class="rm-arr dis">&#9650;</span><b class="rm-score">' . (int)$c['score'] . '</b><span class="rm-arr dis">&#9660;</span>';
    }
    $h .= '<button type="button" class="rm-fold" data-fold="' . $id . '" aria-label="Collapse thread" title="Collapse thread"></button></div>';
    // body
    $h .= '<div class="rm-b"><div class="rm-h">' . ($staff ? '<strong class="rm-u">' . e($who) . '</strong>' : user_chip_html($c, 'u/' . $who, 24))
        . ($staff ? '<span class="rm-flair staff">Staff</span>' : (!empty($c['is_tipster']) ? '<span class="rm-flair tip">Tipster</span>' : ''))
        . '<span class="rm-t" title="' . e(date('j M Y, H:i', strtotime($c['created_at']))) . '">&middot; ' . e(room_ago($c['created_at'])) . '</span></div>';
    $h .= '<div class="rm-x">' . (!empty($c['title']) ? '<h3 class="rm-ttl">' . e($c['title']) . '</h3>' : '')
        . ($c['body'] !== '' ? '<p class="rm-body">' . nl2br(e($c['body'])) . '</p>' : '') . comment_image_html($c['image'] ?? null) . '<div class="rm-f">';
    if ($u) { $h .= '<button type="button" class="rm-act" data-reply="' . $id . '">&#128172; Reply</button>'; }
    $h .= '<button type="button" class="rm-act" data-share="' . e(url($back) . '#c' . $id) . '">&#10150; Share</button>';
    if ($nrep) { $h .= '<span class="rm-act static">' . $nrep . ' repl' . ($nrep === 1 ? 'y' : 'ies') . '</span>'; }
    if ($u && !$mine) {
        $h .= '<form method="post" action="' . $act . '" onsubmit="return confirm(\'Report this comment to the moderators?\')">' . $hid('report', ['id' => $id]) . '<button class="rm-act">&#9873; Report</button></form>';
    }
    if ($mine) {
        $h .= '<form method="post" action="' . $act . '" onsubmit="return confirm(\'Delete your comment?\')">' . $hid('delete', ['id' => $id]) . '<button class="rm-act">&#128465; Delete</button></form>';
    }
    $h .= '</div>';
    if ($u) {
        $h .= '<form method="post" action="' . $act . '" class="rm-rf" id="reply-' . $id . '" enctype="multipart/form-data" hidden>' . $hid('comment', ['type' => $type, 'target' => $matchId, 'parent' => $id, 'nested' => 1])
            . '<textarea name="body" rows="3" maxlength="' . COMMENT_MAX_LEN . '" placeholder="Reply to u/' . e($who) . ' (text, emoji or a picture)"></textarea>' . composer_tools_html() . '<div class="rm-rb"><button type="button" class="rm-act" data-cancel="' . $id . '">Cancel</button><button class="btn dark small" type="submit">Reply</button></div></form>';
    }
    $h .= '</div>';
    if (!empty($kids[$id])) {
        $h .= '<div class="rm-k">';
        foreach ($kids[$id] as $k) { $h .= room_comment_html($k, $kids, $u, $matchId, $back, $depth + 1, $type); }
        $h .= '</div>';
    }
    return $h . '</div></article>';
}

/** Most active members in this room (masked names). */
function room_top_members(int $matchId, int $limit = 5): array
{
    try {
        $st = db()->prepare("SELECT u.name, COUNT(*) AS n FROM comments c JOIN users u ON u.id = c.user_id
                             WHERE c.target_type = 'match' AND c.target_id = ? AND c.status = 'visible' AND u.role = 'user'
                             GROUP BY c.user_id, u.name ORDER BY n DESC LIMIT " . (int)$limit);
        $st->execute([$matchId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}
