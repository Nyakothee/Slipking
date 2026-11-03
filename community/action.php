<?php
/** POST handler: comment, like, report, delete (own), follow, unfollow, free-trial start. Always redirects back to a page on this site. */
require_once dirname(__DIR__) . '/includes/init.php';

// The native app sends its custom header: answer in JSON and skip the page token (another website cannot add that header).
if (($_SERVER['HTTP_X_SLIPKING_APP'] ?? '') === '1' && is_app()) { $GLOBALS['SK_API_JSON'] = true; }
$back = community_back(post_str('back')) ?: 'index.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // the whole form was dropped: the upload is bigger than the server's post_max_size
    set_flash('error', 'That picture is too big for this server (limit ' . ini_get('post_max_size') . '). Choose a smaller picture.');
    redirect(community_back((string)($_GET['back'] ?? '')) ?: 'discuss.php');
}
$frag = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect($back); }
$user = current_user();
if (!$user) {
    set_flash('error', 'Please log in first.');
    redirect('auth/login.php?next=' . rawurlencode($back));
}
if (empty($GLOBALS['SK_API_JSON']) && !csrf_check()) {
    set_flash('error', 'Your session expired. Please try again.');
    redirect($back);
}
$pdo = db();
$action = $_POST['action'] ?? '';
$uid = (int)$user['id'];

if ($action === 'comment') {
    $type = (string)($_POST['type'] ?? '');
    $target = (int)($_POST['target'] ?? 0);
    $parent = (int)($_POST['parent'] ?? 0);
    $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)($_POST['body'] ?? '')));
    $body = preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $body));
    $hasImg = !empty($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $title = trim(preg_replace('/\s+/', ' ', (string)($_POST['title'] ?? '')));
    $isPost = ($type === 'community' && !$parent);
    $why = comment_block_reason($user);
    if ($why === '' && $isPost) {
        if (mb_strlen($title) < 3 || mb_strlen($title) > 160) { $why = 'Give your post a title of 3 to 160 characters.'; }
        elseif (($e1 = comment_text_error($title)) !== '') { $why = 'Title: ' . $e1; }
    }
    if ($why === '') {
        if ($body === '' && ($hasImg || $isPost)) { /* a picture or a title alone is enough */ }
        else { $why = comment_text_error($body); }
    }
    if ($why === '' && !comment_target_ok($type, $target)) { $why = 'That page can no longer be commented on.'; }
    if ($why === '' && $type === 'community') {
        $gc = cmty_by_id($target);
        if ($gc && !cmty_can_take_part($gc, $user)) { $why = 'Join this private group first.'; }
    }
    if ($why === '') { $why = spam_block_reason($user, $title . ' ' . $body, 'post'); }
    $image = null;
    if ($why === '' && $hasImg) {
        if (!cmty_ensure_comments()) { $why = 'Pictures are not available yet. The site owner must import database-update-20.sql.'; }
        else {
            $ierr = '';
            $image = media_store($_FILES['image'], 'post', $uid, 'fit', $ierr, 4194304);
            if ($image === null && $ierr === '') {      // database storage unavailable: keep the picture as a file instead
                $fn = picture_store($_FILES['image'], 'posts', 'p-', $ierr);
                if ($fn !== null) { $image = 'uploads/posts/' . $fn; }
            }
            if ($image === null) { $why = $ierr !== '' ? $ierr : 'The picture could not be saved.'; }
        }
    }
    $pc = null;
    if ($why === '' && $parent) {
        $q = $pdo->prepare("SELECT id, user_id, parent_id FROM comments WHERE id = ? AND target_type = ? AND target_id = ? AND status = 'visible'");
        $q->execute([$parent, $type, $target]);
        $pc = $q->fetch();
        if (!$pc) { $why = 'The comment you replied to is gone.'; }
        elseif ($pc['parent_id'] && empty($_POST['nested']) && $type !== 'community') { $parent = (int)$pc['parent_id']; }     // one level of replies only, except in match rooms
        elseif ($pc['parent_id']) {
            // match rooms allow threads up to 5 levels deep; deeper replies attach to the 5th level
            $depth = 1; $cur = (int)$pc['parent_id'];
            while ($cur && $depth < 8) {
                $dq = $pdo->prepare('SELECT parent_id FROM comments WHERE id = ?'); $dq->execute([$cur]);
                $cur = (int)$dq->fetchColumn(); $depth++;
            }
            if ($depth >= 5) { $up = $pdo->prepare('SELECT parent_id FROM comments WHERE id = ?'); $up->execute([$parent]); $parent = (int)$up->fetchColumn() ?: $parent; }
        }
    }
    if ($why === '' && $body !== '') {
        $q = $pdo->prepare('SELECT 1 FROM comments WHERE user_id = ? AND body = ? AND created_at > NOW() - INTERVAL 1 DAY LIMIT 1');
        $q->execute([$uid, $body]);
        if ($q->fetchColumn()) { $why = 'You already posted that.'; }
    }
    if ($why !== '' && $image) { if (media_is_path($image)) { media_delete($image); } else { @unlink(dirname(__DIR__) . '/' . $image); } }
    if ($why !== '') {
        set_flash('error', $why);
        redirect($back . '#comments');
    }
    if ($image !== null || $title !== '') {
        $pdo->prepare('INSERT INTO comments (user_id, target_type, target_id, parent_id, body, title, image) VALUES (?,?,?,?,?,?,?)')
            ->execute([$uid, $type, $target, $parent ?: null, $body, $isPost ? $title : null, $image]);
    } else {
        $pdo->prepare('INSERT INTO comments (user_id, target_type, target_id, parent_id, body) VALUES (?,?,?,?,?)')
            ->execute([$uid, $type, $target, $parent ?: null, $body]);
    }
    $cid = (int)$pdo->lastInsertId();
    if ($type === 'community' && cmty2_ready()) {
        if ($isPost) {
            $slipId = (int)($_POST['attach_slip'] ?? 0);
            if ($slipId && function_exists('slips_ready') && slips_ready()) {
                $sq = $pdo->prepare('SELECT 1 FROM slips WHERE id = ? AND user_id = ?'); $sq->execute([$slipId, $uid]);
                if ($sq->fetchColumn()) { $pdo->prepare("UPDATE comments SET attach_type = 'slip', attach_id = ? WHERE id = ?")->execute([$slipId, $cid]); }
            }
            $tipId = (int)($_POST['attach_tip'] ?? 0);
            if ($tipId && !$slipId) {
                foreach (cmty_my_tips($uid) as $mt) { if ($mt['id'] === $tipId) { $pdo->prepare("UPDATE comments SET attach_type = 'tip', attach_id = ? WHERE id = ?")->execute([$tipId, $cid]); break; } }
            }
            $popts = array_values(array_filter(array_map(function ($o) { return mb_substr(trim(preg_replace('/\s+/', ' ', (string)$o)), 0, 60); }, (array)($_POST['poll'] ?? [])), function ($o) { return $o !== '' && comment_text_error($o . ' ') === '' ; }));
            if (count($popts) >= 2 && sk_ensure_table('community_polls', 'database-update-24.sql')) {
                $pdo->prepare('INSERT INTO community_polls (post_id, options, closes_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 7 DAY))')->execute([$cid, json_encode(array_slice($popts, 0, 4), JSON_UNESCAPED_UNICODE)]);
            }
        }
        if ($body !== '' || $title !== '') { mentions_notify($title . ' ' . $body, $user, $isPost ? 'in a group post' : 'in a group comment', [], cmty_comment_path($cid)); }
    }
    badge_award($uid, 'first_comment', cmty_target_path((string)$type, (int)$target, $cid));
    // a new post in a group: everyone who joined that group hears about it
    if ($type === 'community' && $isPost && function_exists('cmty_members_ready') && cmty_members_ready()) {
        try {
            $gname = (string)(cmty_by_id((int)$target)['name'] ?? 'your group');
            $gmq = $pdo->prepare('SELECT user_id FROM community_members WHERE community_id = ? AND user_id <> ? LIMIT 800');
            $gmq->execute([$target, $uid]);
            foreach ($gmq->fetchAll(PDO::FETCH_COLUMN) as $gmu) {
                cmty_alert((int)$gmu, 'New post in ' . $gname, mask_name($user['name']) . ': ' . mb_substr($title !== '' ? $title : ($body !== '' ? $body : '(picture)'), 0, 100), cmty_comment_path($cid));
            }
        } catch (Throwable $e) { error_log('group post alert: ' . $e->getMessage()); }
    }
    // tell the person being replied to, and the tipster whose page/tip it is
    $told = [$uid];
    if ($pc && !in_array((int)$pc['user_id'], $told, true)) {
        cmty_alert((int)$pc['user_id'], 'New reply to your comment', mask_name($user['name']) . ' replied: ' . mb_substr($body !== '' ? $body : '(picture)', 0, 120), cmty_target_path((string)$type, (int)$target, $cid));
        $told[] = (int)$pc['user_id'];
    }
    if ($type === 'community' && $parent) {          // tell the author of the post as well (not only the one replied to)
        $root = (int)$parent; $hops = 0;
        while ($hops++ < 8) { $rq = $pdo->prepare('SELECT id, user_id, parent_id FROM comments WHERE id = ?'); $rq->execute([$root]); $rr = $rq->fetch(); if (!$rr || !$rr['parent_id']) { break; } $root = (int)$rr['parent_id']; }
        if (!empty($rr) && !in_array((int)$rr['user_id'], $told, true)) {
            cmty_alert((int)$rr['user_id'], 'New comment on your post', mask_name($user['name']) . ': ' . mb_substr($body !== '' ? $body : '(picture)', 0, 120), cmty_comment_path($cid));
            $told[] = (int)$rr['user_id'];
        }
    }
    $owner = 0;
    if ($type === 'tipster') {
        $q = $pdo->prepare('SELECT user_id FROM tipsters WHERE id = ?'); $q->execute([$target]); $owner = (int)$q->fetchColumn();
    } elseif ($type === 'tip') {
        $q = $pdo->prepare('SELECT ti.user_id FROM tips t JOIN tipsters ti ON ti.id = t.tipster_id WHERE t.id = ?'); $q->execute([$target]); $owner = (int)$q->fetchColumn();
    }
    if ($owner && !in_array($owner, $told, true)) {
        notify_user($owner, 'New comment', mask_name($user['name']) . ': ' . mb_substr($body, 0, 120), cmty_target_path((string)$type, (int)$target, $cid));
    }
    set_flash('success', $isPost ? 'Post published.' : 'Comment posted.');
    redirect($back . '#c' . $cid);
}

if ($action === 'group_photo') {
    $cid = (int)($_POST['cid'] ?? 0);
    $cm = cmty_members_ready() ? cmty_by_id($cid) : null;
    if (!$cm) { set_flash('error', 'That community is not available.'); redirect($back); }
    $isOwner = (int)$cm['created_by'] === $uid;
    $isMod = function_exists('is_staff') && is_staff() && function_exists('can') && can('content');
    if (!$isOwner && !$isMod) { set_flash('error', 'Only the creator of the group can change its picture.'); redirect($back); }
    $perr = '';
    $img = media_store($_FILES['photo'] ?? null, 'cover', $uid, 'square', $perr, 4194304);
    if ($img === null) { set_flash('error', $perr !== '' ? $perr : 'Choose a picture first.'); redirect($back); }
    $pdo->prepare('UPDATE communities SET cover = ? WHERE id = ?')->execute([$img, $cid]);
    if (!empty($cm['cover']) && media_is_path($cm['cover'])) { media_delete($cm['cover']); }
    set_flash('success', 'Group picture updated.');
    redirect($back);
}

if ($action === 'join_group' || $action === 'leave_group') {
    $cid = (int)($_POST['cid'] ?? 0);
    $cm = cmty_members_ready() ? cmty_by_id($cid) : null;
    if (!$cm || !(int)$cm['is_active']) { set_flash('error', 'That community is not available.'); redirect($back); }
    if ($action === 'join_group') {
        $isPriv = cmty3_ready() && !empty($cm['is_private']);
        if ($isPriv && !cmty_is_mod($cm, $user)) {
            $had = $pdo->prepare('SELECT 1 FROM community_members WHERE community_id = ? AND user_id = ?'); $had->execute([$cid, $uid]);
            if (!$had->fetchColumn()) {
                $ins = $pdo->prepare('INSERT IGNORE INTO community_requests (community_id, user_id) VALUES (?, ?)'); $ins->execute([$cid, $uid]);
                if ($ins->rowCount() === 1) {
                    cmty_alert((int)$cm['created_by'], 'New join request', mask_name($user['name']) . ' asked to join ' . $cm['name'] . '.', 'm.php?s=' . $cm['slug']);
                    $mq = $pdo->prepare('SELECT user_id FROM community_mods WHERE community_id = ? LIMIT 20'); $mq->execute([$cid]);
                    foreach ($mq->fetchAll(PDO::FETCH_COLUMN) as $mu) { if ((int)$mu !== (int)$cm['created_by']) { cmty_alert((int)$mu, 'New join request', mask_name($user['name']) . ' asked to join ' . $cm['name'] . '.', 'm.php?s=' . $cm['slug']); } }
                }
                set_flash('success', 'Request sent. A moderator of ' . $cm['name'] . ' will review it.');
            }
        } else {
            $pdo->prepare('INSERT IGNORE INTO community_members (community_id, user_id) VALUES (?, ?)')->execute([$cid, $uid]);
            set_flash('success', 'You joined ' . $cm['name'] . '.');
        }
    } else {
        $pdo->prepare('DELETE FROM community_members WHERE community_id = ? AND user_id = ?')->execute([$cid, $uid]);
        set_flash('success', 'You left ' . $cm['name'] . '.');
    }
    redirect($back);
}

if ($action === 'cancel_request') {
    $cid = (int)($_POST['cid'] ?? 0);
    if (cmty3_ready()) { $pdo->prepare('DELETE FROM community_requests WHERE community_id = ? AND user_id = ?')->execute([$cid, $uid]); }
    set_flash('success', 'Request cancelled.');
    redirect($back);
}

if ($action === 'approve_join' || $action === 'decline_join') {
    $cid = (int)($_POST['cid'] ?? 0); $who = (int)($_POST['uid'] ?? 0);
    $cm = cmty3_ready() ? cmty_by_id($cid) : null;
    if (!$cm || !cmty_is_mod($cm, $user)) { set_flash('error', 'Only moderators can do that.'); redirect($back); }
    $q = $pdo->prepare('DELETE FROM community_requests WHERE community_id = ? AND user_id = ?'); $q->execute([$cid, $who]);
    if ($q->rowCount() === 1) {
        if ($action === 'approve_join') {
            $pdo->prepare('INSERT IGNORE INTO community_members (community_id, user_id) VALUES (?, ?)')->execute([$cid, $who]);
            cmty_alert($who, 'You can join in now', 'Your request to join ' . $cm['name'] . ' was approved.', 'm.php?s=' . $cm['slug']);
            set_flash('success', 'Member approved.');
        } else { set_flash('success', 'Request declined.'); }
    }
    redirect($back);
}

if ($action === 'follow_member' || $action === 'unfollow_member') {
    $target = (int)($_POST['uid'] ?? 0);
    if ($target && $target !== $uid && members_ready()) {
        $pub = $pdo->prepare('SELECT handle FROM member_public WHERE user_id = ? AND is_public = 1'); $pub->execute([$target]); $hd = $pub->fetchColumn();
        if ($hd) {
            if ($action === 'follow_member') {
                $ins = $pdo->prepare('INSERT IGNORE INTO member_follows (follower_id, member_id) VALUES (?, ?)'); $ins->execute([$uid, $target]);
                if ($ins->rowCount()) { cmty_alert($target, 'New follower', mask_name($user['name']) . ' started following you.', 'member.php?u=' . $hd); }
                set_flash('success', 'You follow @' . $hd . '. Their posts appear in Following.');
            } else {
                $pdo->prepare('DELETE FROM member_follows WHERE follower_id = ? AND member_id = ?')->execute([$uid, $target]);
                set_flash('success', 'You no longer follow @' . $hd . '.');
            }
        }
    }
    redirect($back);
}

if ($action === 'event_add' || $action === 'event_rsvp' || $action === 'event_delete') {
    if (!cmty3_ready() || !sk_ensure_table('community_events', 'database-update-25.sql')) { set_flash('error', 'Events are not available yet. Import database-update-25.sql.'); redirect($back); }
    if ($action === 'event_add') {
        $cid = (int)($_POST['cid'] ?? 0); $cm = cmty_by_id($cid);
        $title = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['title'] ?? ''))), 0, 120); $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300);
        $ts = strtotime((string)($_POST['starts'] ?? ''));
        if (!$cm || !cmty_is_mod($cm, $user)) { set_flash('error', 'Only moderators can add events.'); }
        elseif (mb_strlen($title) < 3 || comment_text_error($title) !== '' || ($note !== '' && comment_text_error($note) !== '')) { set_flash('error', 'Give the event a clear name (no links).'); }
        elseif (!$ts || $ts < time() - 3600 || $ts > time() + 400 * 86400) { set_flash('error', 'Choose a start time in the future.'); }
        else {
            $pdo->prepare('INSERT INTO community_events (community_id, title, note, starts_at, created_by) VALUES (?,?,?,?,?)')->execute([$cid, $title, $note, date('Y-m-d H:i:s', $ts), $uid]);
            $mq = $pdo->prepare('SELECT user_id FROM community_members WHERE community_id = ? AND user_id <> ? LIMIT 300'); $mq->execute([$cid, $uid]);
            foreach ($mq->fetchAll(PDO::FETCH_COLUMN) as $mu) { cmty_alert((int)$mu, 'New event in ' . $cm['name'], $title . ' - ' . date('D j M, H:i', $ts), 'm.php?s=' . $cm['slug']); }
            set_flash('success', 'Event created. Members were told.');
        }
        redirect($back);
    }
    $evId = (int)($_POST['id'] ?? 0);
    $eq = $pdo->prepare('SELECT e.id, e.community_id FROM community_events e WHERE e.id = ?'); $eq->execute([$evId]); $ev = $eq->fetch();
    $cm = $ev ? cmty_by_id((int)$ev['community_id']) : null;
    if (!$ev || !$cm) { redirect($back); }
    if ($action === 'event_delete') {
        if (cmty_is_mod($cm, $user)) { $pdo->prepare('DELETE FROM community_events WHERE id = ?')->execute([$evId]); $pdo->prepare('DELETE FROM community_event_rsvp WHERE event_id = ?')->execute([$evId]); set_flash('success', 'Event deleted.'); }
    } elseif (cmty_can_take_part($cm, $user)) {
        $del = $pdo->prepare('DELETE FROM community_event_rsvp WHERE event_id = ? AND user_id = ?'); $del->execute([$evId, $uid]);
        if ($del->rowCount() === 0) { $pdo->prepare('INSERT IGNORE INTO community_event_rsvp (event_id, user_id) VALUES (?, ?)')->execute([$evId, $uid]); set_flash('success', 'You are going.'); }
        else { set_flash('success', 'You are no longer going.'); }
    }
    redirect($back);
}

if ($action === 'like_post') {
    // Like / unlike a group post. Anyone logged in can like, including the author. Same tap again removes the like.
    $lid = (int)($_POST['id'] ?? 0);
    $q = $pdo->prepare("SELECT 1 FROM comments WHERE id = ? AND status = 'visible' AND target_type = 'community'");
    $q->execute([$lid]);
    if ($q->fetchColumn() && sk_ensure_table('comment_votes', 'database-update-19.sql')) {
        $cur = $pdo->prepare('SELECT value FROM comment_votes WHERE comment_id = ? AND user_id = ?');
        $cur->execute([$lid, $uid]);
        $have = $cur->fetchColumn();
        if ($have !== false && (int)$have === 1) { $pdo->prepare('DELETE FROM comment_votes WHERE comment_id = ? AND user_id = ?')->execute([$lid, $uid]); }
        else {
            $pdo->prepare('INSERT INTO comment_votes (comment_id, user_id, value) VALUES (?,?,1) ON DUPLICATE KEY UPDATE value = 1')->execute([$lid, $uid]);
            if ($have === false) { cmty_notify_reaction($pdo, $lid, $user, 'liked'); }
        }
    }
    redirect($back);
}

if (in_array($action, ['react_post', 'edit_post', 'pin_post', 'mod_remove', 'poll_vote'], true)) {
    $pid = (int)($_POST['id'] ?? 0);
    $q = $pdo->prepare("SELECT id, user_id, target_id, parent_id, title, body, pinned FROM comments WHERE id = ? AND status = 'visible' AND target_type = 'community'");
    $q->execute([$pid]);
    $pc = $q->fetch();
    if (!$pc || !cmty2_ready()) { set_flash('error', 'That post is not available.'); redirect($back); }
    $grp = cmty_by_id((int)$pc['target_id']);
    if ($action === 'react_post') {
        $em = (string)($_POST['emoji'] ?? '');
        if (in_array($em, CMTY_REACTIONS, true)) {
            $d = $pdo->prepare('DELETE FROM comment_reactions WHERE comment_id = ? AND user_id = ? AND emoji = ?'); $d->execute([$pid, $uid, $em]);
            if ($d->rowCount() === 0) {
                $pdo->prepare('INSERT IGNORE INTO comment_reactions (comment_id, user_id, emoji) VALUES (?,?,?)')->execute([$pid, $uid, $em]);
                cmty_notify_reaction($pdo, $pid, $user, 'reacted ' . $em . ' to');
            }
        }
        redirect($back);
    }
    if ($action === 'edit_post') {
        if ((int)$pc['user_id'] !== $uid) { set_flash('error', 'You can only edit your own posts.'); redirect($back); }
        $nt = trim(preg_replace('/\s+/', ' ', (string)($_POST['title'] ?? ''))); $nb = trim(str_replace("\r", '', (string)($_POST['body'] ?? '')));
        $isTop = !$pc['parent_id']; $why = '';
        if ($isTop && (mb_strlen($nt) < 3 || mb_strlen($nt) > 160)) { $why = 'Give your post a title of 3 to 160 characters.'; }
        elseif ($isTop && ($e1 = comment_text_error($nt)) !== '') { $why = 'Title: ' . $e1; }
        elseif ($nb !== '' && ($e2 = comment_text_error($nb)) !== '') { $why = $e2; }
        elseif ($nb === '' && !$isTop) { $why = 'A comment cannot be empty.'; }
        if ($why !== '') { set_flash('error', $why); redirect($back); }
        if ($isTop) { $pdo->prepare('UPDATE comments SET title = ?, body = ?, edited_at = NOW() WHERE id = ?')->execute([$nt, $nb, $pid]); }
        else { $pdo->prepare('UPDATE comments SET body = ?, edited_at = NOW() WHERE id = ?')->execute([$nb, $pid]); }
        set_flash('success', 'Saved.'); redirect($back);
    }
    if (in_array($action, ['pin_post', 'mod_remove'], true)) {
        if (!$grp || !cmty_is_mod($grp, $user)) { set_flash('error', 'Only group moderators can do that.'); redirect($back); }
        if ($action === 'pin_post') {
            $pdo->prepare('UPDATE comments SET pinned = 1 - pinned WHERE id = ? AND parent_id IS NULL')->execute([$pid]);
            set_flash('success', $pc['pinned'] ? 'Post unpinned.' : 'Post pinned to the top.');
        } else {
            $pdo->prepare("UPDATE comments SET status = 'hidden' WHERE id = ?")->execute([$pid]);
            admin_log('community.remove', (string)$pid, 'by ' . $uid);
            set_flash('success', 'Removed.');
        }
        redirect($back);
    }
    if ($action === 'poll_vote') {
        $opt = (int)($_POST['opt'] ?? -1);
        $pl = $pdo->prepare('SELECT options, closes_at FROM community_polls WHERE post_id = ?'); $pl->execute([$pid]); $row = $pl->fetch();
        $opts = $row ? json_decode((string)$row['options'], true) : null;
        if (is_array($opts) && isset($opts[$opt]) && !($row['closes_at'] && strtotime($row['closes_at']) < time())) {
            $pdo->prepare('INSERT IGNORE INTO community_poll_votes (post_id, user_id, opt) VALUES (?,?,?)')->execute([$pid, $uid, $opt]);
        }
        redirect($back);
    }
}

if ($action === 'cvote') {
    // Reddit-style up/down vote on a comment. Same vote again removes it; the other arrow switches it.
    $cid = (int)($_POST['id'] ?? 0);
    $val = ($_POST['dir'] ?? '') === 'down' ? -1 : 1;
    $q = $pdo->prepare("SELECT user_id FROM comments WHERE id = ? AND status = 'visible'");
    $q->execute([$cid]);
    $owner = $q->fetchColumn();
    if ($owner !== false && (int)$owner !== $uid && sk_ensure_table('comment_votes', 'database-update-19.sql')) {
        $cur = $pdo->prepare('SELECT value FROM comment_votes WHERE comment_id = ? AND user_id = ?');
        $cur->execute([$cid, $uid]);
        $have = $cur->fetchColumn();
        if ($have !== false && (int)$have === $val) {
            $pdo->prepare('DELETE FROM comment_votes WHERE comment_id = ? AND user_id = ?')->execute([$cid, $uid]);
        } else {
            $pdo->prepare('INSERT INTO comment_votes (comment_id, user_id, value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$cid, $uid, $val]);
            if ($val === 1 && $have === false) { cmty_notify_reaction($pdo, $cid, $user, 'liked'); }
        }
    }
    redirect($back . '#c' . $cid);
}

if (in_array($action, ['like', 'report', 'delete'], true)) {
    $cid = (int)($_POST['id'] ?? 0);
    $q = $pdo->prepare('SELECT id, user_id, status FROM comments WHERE id = ?');
    $q->execute([$cid]);
    $c = $q->fetch();
    if (!$c || $c['status'] !== 'visible') {
        set_flash('error', 'That comment is not available.');
        redirect($back . '#comments');
    }
    if ($action === 'like') {
        if ((int)$c['user_id'] !== $uid) {
            $ins = $pdo->prepare('INSERT IGNORE INTO comment_likes (comment_id, user_id) VALUES (?, ?)');
            $ins->execute([$cid, $uid]);
            if ($ins->rowCount() === 1) {
                $pdo->prepare('UPDATE comments SET likes = likes + 1 WHERE id = ?')->execute([$cid]);
                cmty_notify_reaction($pdo, $cid, $user, 'liked');
            } else {
                $del = $pdo->prepare('DELETE FROM comment_likes WHERE comment_id = ? AND user_id = ?');
                $del->execute([$cid, $uid]);
                if ($del->rowCount() === 1) { $pdo->prepare('UPDATE comments SET likes = GREATEST(0, likes - 1) WHERE id = ?')->execute([$cid]); }
            }
        }
        redirect($back . '#c' . $cid);
    }
    if ($action === 'report') {
        if ((int)$c['user_id'] !== $uid) {
            $ins = $pdo->prepare('INSERT IGNORE INTO comment_reports (comment_id, user_id) VALUES (?, ?)');
            $ins->execute([$cid, $uid]);
            if ($ins->rowCount() === 1) {
                $pdo->prepare('UPDATE comments SET reports = reports + 1 WHERE id = ?')->execute([$cid]);
                $pdo->prepare("UPDATE comments SET status = 'hidden' WHERE id = ? AND reports >= " . COMMENT_AUTOHIDE_REPORTS)->execute([$cid]);
            }
            set_flash('success', 'Thanks. A moderator will look at it.');
        }
        redirect($back . '#comments');
    }
    if ($action === 'delete' && (int)$c['user_id'] === $uid) {
        $pdo->prepare("UPDATE comments SET status = 'hidden' WHERE id = ? AND user_id = ?")->execute([$cid, $uid]);
        set_flash('success', 'Comment deleted.');
    }
    redirect($back . '#comments');
}

if (in_array($action, ['follow', 'unfollow', 'trial'], true)) {
    $tid = (int)($_POST['tipster'] ?? 0);
    $t = tipster_by_id($tid);
    if (!$t || $t['status'] !== 'active') {
        set_flash('error', 'Tipster not found.');
        redirect($back);
    }
    if ($action === 'follow') {
        if ((int)$t['user_id'] === $uid) {
            set_flash('error', 'You cannot follow yourself.');
        } else {
            $ins = $pdo->prepare('INSERT IGNORE INTO tipster_follows (user_id, tipster_id) VALUES (?, ?)');
            $ins->execute([$uid, $tid]);
            if ($ins->rowCount() === 1) { badge_award($uid, 'first_follow'); }
            set_flash('success', 'You follow ' . $t['display_name'] . '. You will get an alert when they post a tip. Choose how in Alerts settings.');
        }
    } elseif ($action === 'unfollow') {
        $pdo->prepare('DELETE FROM tipster_follows WHERE user_id = ? AND tipster_id = ?')->execute([$uid, $tid]);
        set_flash('success', 'You no longer follow ' . $t['display_name'] . '.');
    } else {
        $why = tipster_trial_block_reason($user, $t);
        if ($why !== '') {
            set_flash('error', $why);
        } else {
            $days = max(1, min(3, (int)$t['trial_days']));
            try {
                $ins = $pdo->prepare('INSERT INTO tipster_trials (user_id, tipster_id, starts_at, ends_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))');
                $ins->execute([$uid, $tid, $days]);
                $pdo->prepare('INSERT IGNORE INTO tipster_follows (user_id, tipster_id) VALUES (?, ?)')->execute([$uid, $tid]);
                notify_user($uid, 'Free trial started', 'You can see ' . $t['display_name'] . "'s premium tips for $days days.");
                set_flash('success', 'Your free trial has started. Enjoy ' . $days . ' days of premium tips.');
            } catch (Throwable $e) {
                set_flash('error', 'The trial could not be started (maybe you already used it).');
            }
        }
    }
    redirect($back);
}
redirect($back);
