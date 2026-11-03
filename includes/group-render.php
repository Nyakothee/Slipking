<?php
/** HTML pieces of the group page (m.php): the ... menu, the like button and the nested comments. $x is the page context array built in m.php. */

function gp_hidden(array $x, string $action, int $id, string $back, string $extra = ''): string
{
    return csrf_field() . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="back" value="' . e($back) . '">' . $extra;
}

/** Back address that returns to the same post with its comments open. */
function gp_back(array $x, int $rootId, int $pos = 0): string
{
    return 'm.php?s=' . $x['slug'] . '&open=' . $rootId . ($pos ? '&rpos=' . $pos : '');
}

/** The "..." menu: edit and delete (own), report (others), pin and remove (moderators). */
function gp_menu(array $x, array $row, int $rootId, bool $isPost): string
{
    $u = $x['u']; if (!$u) { return ''; }
    $id = (int)$row['id']; $mine = (int)$row['user_id'] === (int)$u['id']; $back = gp_back($x, $rootId);
    $h = '<details class="dh-more gp-pm"><summary aria-label="More options">&middot;&middot;&middot;</summary><div>';
    if ($mine) { $h .= '<button type="button" data-toggle="ed' . $id . '">Edit</button>'; }
    if ($mine) { $h .= '<form method="post" action="' . $x['act'] . '" onsubmit="return confirm(\'Delete this?\')">' . gp_hidden($x, 'delete', $id, $isPost ? 'm.php?s=' . $x['slug'] : $back) . '<button type="submit">Delete</button></form>'; }
    if (!$mine) { $h .= '<a class="gp-mi-link" href="' . e(url('account/messages.php?with=' . (int)$row['user_id'])) . '">Send a message</a>'; }
    if (!$mine) { $h .= '<form method="post" action="' . $x['act'] . '" onsubmit="return confirm(\'Report this to the moderators?\')">' . gp_hidden($x, 'report', $id, $back) . '<button type="submit">Report</button></form>'; }
    if ($x['isMod'] && $isPost) { $h .= '<form method="post" action="' . $x['act'] . '">' . gp_hidden($x, 'pin_post', $id, 'm.php?s=' . $x['slug']) . '<button type="submit">' . (!empty($row['pinned']) ? 'Unpin' : 'Pin to top') . '</button></form>'; }
    if ($x['isMod'] && !$mine) { $h .= '<form method="post" action="' . $x['act'] . '" onsubmit="return confirm(\'Remove this for everyone?\')">' . gp_hidden($x, 'mod_remove', $id, $isPost ? 'm.php?s=' . $x['slug'] : $back) . '<button type="submit" class="danger">Remove (moderator)</button></form>'; }
    return $h . '</div></details>';
}

/** Inline edit form (hidden until the Edit button is pressed). */
function gp_edit_form(array $x, array $row, int $rootId, bool $isPost): string
{
    $id = (int)$row['id'];
    return '<form class="gp-ef" id="ed' . $id . '" hidden method="post" action="' . $x['act'] . '">' . gp_hidden($x, 'edit_post', $id, gp_back($x, $rootId))
        . ($isPost ? '<input name="title" maxlength="160" required value="' . e((string)$row['title']) . '" aria-label="Title">' : '')
        . '<textarea name="body" rows="3" maxlength="' . COMMENT_MAX_LEN . '" aria-label="Text">' . e((string)$row['body']) . '</textarea>'
        . '<div><button class="gp-post sm" type="submit">Save</button> <button type="button" class="gp-alt sm" data-toggle="ed' . $id . '">Cancel</button></div></form>';
}

function gp_like_btn(array $x, int $id, int $rootId, bool $isPost, string $cls = ''): string
{
    $liked = !empty($x['iLike'][$id]);
    if (!$x['u']) { return '<a class="' . $cls . '" href="' . e(url('auth/login.php?next=' . rawurlencode('m.php?s=' . $x['slug']))) . '">Like</a>'; }
    $back = $isPost ? 'm.php?s=' . $x['slug'] . $x['sortq'] . '&pos=' . $id : gp_back($x, $rootId, $id);
    return '<form method="post" action="' . $x['act'] . '">' . gp_hidden($x, 'like_post', $id, $back) . '<button type="submit" class="' . $cls . ($liked ? ' on' : '') . '" aria-pressed="' . ($liked ? 'true' : 'false') . '">' . ($liked ? 'Liked' : 'Like') . '</button></form>';
}

/** Comment form (new comment on a post, or a reply to a comment). */
function gp_comment_form(array $x, int $parentId, int $rootId, string $placeholder): string
{
    if (!$x['u']) { return '<p class="gp-muted"><a href="' . e(url('auth/login.php?next=' . rawurlencode('m.php?s=' . $x['slug']))) . '">Log in</a> to comment.</p>'; }
    if ($x['block'] !== '') { return '<p class="gp-muted">' . e($x['block']) . '</p>'; }
    return '<form class="gp-rf" method="post" action="' . $x['act'] . '" enctype="multipart/form-data">' . csrf_field()
        . '<input type="hidden" name="action" value="comment"><input type="hidden" name="type" value="community"><input type="hidden" name="target" value="' . (int)$x['cid'] . '"><input type="hidden" name="parent" value="' . $parentId . '"><input type="hidden" name="back" value="' . e(gp_back($x, $rootId)) . '">'
        . '<div class="gp-rr">' . user_avatar_html($x['u'], 32) . '<textarea name="body" rows="1" maxlength="' . COMMENT_MAX_LEN . '" placeholder="' . e($placeholder) . '" aria-label="' . e($placeholder) . '"></textarea><button class="gp-post sm" type="submit">Send</button></div>'
        . composer_tools_html() . '</form>';
}

/** Comments under a parent, nested up to 4 levels. */
function gp_replies(array $x, int $parentId, int $depth, int $rootId): string
{
    $h = '';
    foreach ($x['kids'][$parentId] ?? [] as $rp) {
        $rid = (int)$rp['id']; $rs = $rp['role'] !== 'user'; $rl = (int)($x['likeN'][$rid] ?? 0);
        $h .= '<div class="gp-rp" id="r' . $rid . '">' . user_avatar_html($rp, $depth > 1 ? 28 : 32) . '<div class="gp-rbw"><div class="gp-rb"><strong>' . gp_author($x, $rp, $rs ? 'Team' : mask_name((string)$rp['name']), $rs) . '</strong><small>' . e(room_ago($rp['created_at'])) . (!empty($rp['edited_at']) ? ' &middot; edited' : '') . '</small>'
            . ($rp['body'] !== '' ? '<p>' . mention_html((string)$rp['body']) . '</p>' : '') . comment_image_html($rp['image'] ?? null) . '</div>'
            . gp_edit_form($x, $rp, $rootId, false)
            . '<div class="gp-rl">' . gp_like_btn($x, $rid, $rootId, false) . ($depth < 4 ? '<button type="button" data-toggle="rf' . $rid . '">Reply</button>' : '')
            . ($rl > 0 ? '<span class="gp-rc">&#128077; ' . $rl . '</span>' : '') . gp_menu($x, $rp, $rootId, false) . '</div>'
            . ($depth < 4 ? '<div class="gp-rpl" id="rf' . $rid . '" hidden>' . gp_comment_form($x, $rid, $rootId, 'Reply to ' . ($rs ? 'Team' : mask_name((string)$rp['name'])) . '...') . '</div>' : '')
            . '<div class="gp-kids">' . gp_replies($x, $rid, $depth + 1, $rootId) . '</div></div></div>';
    }
    return $h;
}

function gp_reactions_row(array $x, int $postId): string
{
    $rx = $x['react'][$postId] ?? ['counts' => [], 'mine' => []];
    $h = '<div class="gp-react" aria-label="Reactions">';
    foreach (CMTY_REACTIONS as $em) {
        $n = (int)($rx['counts'][$em] ?? 0); $on = !empty($rx['mine'][$em]);
        if ($x['u']) { $h .= '<form method="post" action="' . $x['act'] . '">' . gp_hidden($x, 'react_post', $postId, 'm.php?s=' . $x['slug'] . $x['sortq'] . '&pos=' . $postId, '<input type="hidden" name="emoji" value="' . $em . '">') . '<button type="submit" class="' . ($on ? 'on' : '') . '" aria-label="React ' . $em . '">' . $em . ($n ? ' <b>' . $n . '</b>' : '') . '</button></form>'; }
        elseif ($n) { $h .= '<span>' . $em . ' <b>' . $n . '</b></span>'; }
    }
    return $h . '</div>';
}

/** Name with verified tick, level badge and "Top contributor" tag. Links to the public profile when the member has one. */
function gp_author(array $x, array $row, string $name, bool $staff): string
{
    $uid = (int)$row['user_id']; $fl = $x['flags'][$uid] ?? null;
    $h = $fl ? '<a class="gp-un" href="' . e(url('member.php?u=' . $fl['handle'])) . '">' . e($name) . '</a>' : e($name);
    if (!$staff) {
        $h .= member_verified_html($fl);
        $h .= ' ' . member_level_html($x['lv'][$uid] ?? null);
        if (in_array($uid, $x['topc'] ?? [], true)) { $h .= ' <span class="lv top" title="One of the most active members of this group this month">&#9733; Top contributor</span>'; }
    }
    return $h;
}

/** Small Follow / Following button next to a post author (only members with a public profile can be followed). */
function gp_follow_btn(array $x, int $uid): string
{
    if (!$x['u'] || $uid === (int)$x['u']['id'] || empty($x['flags'][$uid])) { return ''; }
    $on = !empty($x['following'][$uid]);
    return '<form class="gp-fw" method="post" action="' . $x['act'] . '">' . csrf_field() . '<input type="hidden" name="action" value="' . ($on ? 'unfollow_member' : 'follow_member') . '"><input type="hidden" name="uid" value="' . $uid . '"><input type="hidden" name="back" value="' . e('m.php?s=' . $x['slug'] . $x['sortq']) . '"><button type="submit" class="' . ($on ? 'on' : '') . '">' . ($on ? 'Following' : 'Follow') . '</button></form>';
}
