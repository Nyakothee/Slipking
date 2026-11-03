<?php
/** Comments, likes, reports, follows. Actions are handled by community/action.php; this file renders and checks rules. */

const COMMENT_MAX_LEN = 500;
const COMMENT_RATE_MAX = 5;          // comments per 10 minutes
const COMMENT_AUTOHIDE_REPORTS = 3;  // hidden for review after this many reports

/** Empty string = this member may comment now. Otherwise the reason, in words. */
function comment_block_reason(?array $u): string
{
    if (!$u) {
        return 'Log in to join the conversation.';
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT 1 FROM comment_bans WHERE user_id = ?');
    $st->execute([$u['id']]);
    if ($st->fetchColumn()) {
        return 'Your commenting has been switched off by a moderator.';
    }
    if (empty($u['email_verified']) && !(function_exists('is_staff') && is_staff())) {
        return 'Confirm your email address to comment.';
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE user_id = ? AND created_at > NOW() - INTERVAL 10 MINUTE');
    $st->execute([$u['id']]);
    if ((int)$st->fetchColumn() >= COMMENT_RATE_MAX) {
        return 'You are commenting very fast. Please wait a few minutes.';
    }
    return '';
}

/** Checks the text of a comment. Returns an error message or ''. */
function comment_text_error(string $body): string
{
    $len = mb_strlen($body);
    if ($len < 2) {
        return 'Write at least a couple of characters.';
    }
    if ($len > COMMENT_MAX_LEN) {
        return 'Comments can be up to ' . COMMENT_MAX_LEN . ' characters.';
    }
    if (preg_match('~(https?:|www\.|\b[a-z0-9\-]+\.(com|net|org|co|io|ke|ng|info|biz|me|tv|bet|xyz)\b)~i', $body)) {
        return 'Links are not allowed in comments.';
    }
    if (function_exists('looks_like_secret') && looks_like_secret($body)) {
        return 'Never share passwords or codes.';
    }
    if (preg_match('/(.)\1{9,}/u', $body)) {
        return 'Please do not repeat the same character over and over.';
    }
    return '';
}

/** Does this comment target exist and is it public? */
function comment_target_ok(string $type, int $id): bool
{
    $pdo = db();
    if ($type === 'tip') {
        $st = $pdo->prepare('SELECT 1 FROM tips WHERE id = ? AND deleted_at IS NULL');
    } elseif ($type === 'tipster') {
        $st = $pdo->prepare("SELECT 1 FROM tipsters WHERE id = ? AND status = 'active'");
    } elseif ($type === 'match') {
        $st = $pdo->prepare('SELECT 1 FROM matches WHERE id = ?');
    } elseif ($type === 'community') {
        if (!function_exists('cmty_ready') || !cmty_ready()) { return false; }
        $st = $pdo->prepare('SELECT 1 FROM communities WHERE id = ? AND is_active = 1');
    } else {
        return false;
    }
    $st->execute([$id]);
    return (bool)$st->fetchColumn();
}

function comments_count(string $type, int $id): int
{
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM comments WHERE target_type = ? AND target_id = ? AND status = 'visible'");
        $st->execute([$type, $id]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Only same-site relative links, with .php and a simple query. Returns '' if it is not safe. */
function community_back(string $back): string
{
    $back = (string)preg_replace('/#.*$/', '', $back);
    return preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*\.php(\?[A-Za-z0-9_=&%\-]*)?$#', $back) ? $back : '';
}

/** Prints the comment box and the list. $back = page to return to after an action (for example "tip.php?id=5"). */
function comments_render(string $type, int $id, string $back): void
{
    $pdo = db();
    $u = current_user();
    $rows = [];
    try {
        $st = $pdo->prepare("SELECT c.id, c.user_id, c.parent_id, c.body, c.likes, c.created_at, u.name, u.role" . profile_sql('u') . ",
                                    (SELECT 1 FROM tipsters tp WHERE tp.user_id = c.user_id AND tp.status = 'active' LIMIT 1) AS is_tipster
                             FROM comments c JOIN users u ON u.id = c.user_id
                             WHERE c.target_type = ? AND c.target_id = ? AND c.status = 'visible'
                             ORDER BY c.created_at DESC, c.id DESC LIMIT 120");
        $st->execute([$type, $id]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        return;     // tables missing (update 11 not imported): show nothing instead of breaking the page
    }
    $liked = [];
    if ($u && $rows) {
        $ids = array_map('intval', array_column($rows, 'id'));
        $q = $pdo->prepare('SELECT comment_id FROM comment_likes WHERE user_id = ? AND comment_id IN (' . implode(',', $ids) . ')');
        $q->execute([$u['id']]);
        $liked = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
    }
    $top = []; $kids = [];
    foreach ($rows as $r) {
        if ($r['parent_id']) { $kids[(int)$r['parent_id']][] = $r; } else { $top[] = $r; }
    }
    foreach ($kids as &$k) { $k = array_reverse($k); }      // replies oldest first
    unset($k);
    $block = comment_block_reason($u);
    $isStaffUser = function_exists('is_staff') && is_staff();
    $item = function (array $c, bool $reply) use ($u, $liked, $back, $type, $id, $isStaffUser) {
        $mine = $u && (int)$u['id'] === (int)$c['user_id'];
        $who = $c['role'] !== 'user' ? 'Team' : mask_name((string)$c['name']);
        ?>
        <div class="cm-item<?= $reply ? ' cm-reply' : '' ?>" id="c<?= (int)$c['id'] ?>">
          <div class="cm-head"><?php if ($c['role'] === 'user'): ?><?= user_chip_html($c, $who, 28) ?><?php else: ?><strong><?= e($who) ?></strong><?php endif; ?>
            <?php if ($c['role'] !== 'user'): ?><span class="cm-tag cm-staff">Staff</span><?php elseif (!empty($c['is_tipster'])): ?><span class="cm-tag">Tipster</span><?php endif; ?>
            <span class="cm-time"><?= e(date('j M, H:i', strtotime($c['created_at']))) ?></span></div>
          <p class="cm-body"><?= nl2br(e($c['body'])) ?></p>
          <div class="cm-actions">
            <?php if ($u && !$mine): ?>
              <form method="post" action="<?= e(url('community/action.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="like"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                <button type="submit" class="cm-btn<?= isset($liked[$c['id']]) ? ' on' : '' ?>" aria-pressed="<?= isset($liked[$c['id']]) ? 'true' : 'false' ?>">&#128077; <?= (int)$c['likes'] ?></button></form>
            <?php else: ?><span class="cm-btn cm-static">&#128077; <?= (int)$c['likes'] ?></span><?php endif; ?>
            <?php if ($u && !$reply): ?><a class="cm-btn" href="#reply-<?= (int)$c['id'] ?>" data-reply="<?= (int)$c['id'] ?>">Reply</a><?php endif; ?>
            <?php if ($u && !$mine): ?>
              <form method="post" action="<?= e(url('community/action.php')) ?>" onsubmit="return confirm('Report this comment to the moderators?');"><?= csrf_field() ?><input type="hidden" name="action" value="report"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><button type="submit" class="cm-btn">Report</button></form>
            <?php endif; ?>
            <?php if ($mine): ?>
              <form method="post" action="<?= e(url('community/action.php')) ?>" onsubmit="return confirm('Delete your comment?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><button type="submit" class="cm-btn">Delete</button></form>
            <?php endif; ?>
          </div>
          <?php if ($u && !$reply): ?>
          <form method="post" action="<?= e(url('community/action.php')) ?>" class="cm-replyform" id="reply-<?= (int)$c['id'] ?>" hidden><?= csrf_field() ?>
            <input type="hidden" name="action" value="comment"><input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="target" value="<?= (int)$id ?>"><input type="hidden" name="parent" value="<?= (int)$c['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
            <textarea name="body" rows="2" maxlength="<?= COMMENT_MAX_LEN ?>" required aria-label="Your reply"></textarea><button class="btn outline" type="submit">Reply</button></form>
          <?php endif; ?>
        </div>
        <?php
    };
    ?>
    <div class="cm-box" id="comments">
      <h3>Comments <span class="cm-count"><?= count($rows) ?></span></h3>
      <?php if ($block === ''): ?>
        <form method="post" action="<?= e(url('community/action.php')) ?>" class="cm-form"><?= csrf_field() ?>
          <input type="hidden" name="action" value="comment"><input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="target" value="<?= (int)$id ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
          <label for="cm-body" class="sr">Your comment</label>
          <textarea id="cm-body" name="body" rows="3" maxlength="<?= COMMENT_MAX_LEN ?>" required placeholder="Share your thoughts (no links, be respectful)"></textarea>
          <button class="btn gold" type="submit">Post comment</button>
        </form>
      <?php elseif (!$u): ?>
        <p class="cm-note"><a href="<?= e(url('auth/login.php?next=' . rawurlencode($back))) ?>">Log in</a> or <a href="<?= e(url('auth/register.php')) ?>">create a free account</a> to join the conversation.</p>
      <?php else: ?><p class="cm-note"><?= e($block) ?></p><?php endif; ?>
      <?php if (!$top): ?><p class="cm-note">No comments yet. Be the first.</p><?php endif; ?>
      <?php foreach ($top as $c): $item($c, false); foreach ($kids[(int)$c['id']] ?? [] as $r) { $item($r, true); } endforeach; ?>
      <p class="cm-rules">Comments are moderated. Not betting advice. Be kind, no links, no spam.</p>
    </div>
    <script>
    (function(){var b=document.querySelectorAll('[data-reply]');for(var i=0;i<b.length;i++){b[i].addEventListener('click',function(e){e.preventDefault();var f=document.getElementById('reply-'+this.getAttribute('data-reply'));if(f){f.hidden=!f.hidden;if(!f.hidden){f.querySelector('textarea').focus();}}});}})();
    </script>
    <?php
}

/* ---------- follows ---------- */

function tipster_follower_count(int $tipsterId): int
{
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM tipster_follows WHERE tipster_id = ?');
        $st->execute([$tipsterId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function tipster_is_followed(int $userId, int $tipsterId): bool
{
    try {
        $st = db()->prepare('SELECT 1 FROM tipster_follows WHERE user_id = ? AND tipster_id = ?');
        $st->execute([$userId, $tipsterId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/* ---------- tipster free trial ---------- */

/** Active trial of this member with this tipster, or null. */
function tipster_trial_active(int $userId, int $tipsterId): ?array
{
    try {
        $st = db()->prepare('SELECT * FROM tipster_trials WHERE user_id = ? AND tipster_id = ? AND ends_at > NOW() LIMIT 1');
        $st->execute([$userId, $tipsterId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Can this member start the free trial now? Returns '' when yes, otherwise the reason. */
function tipster_trial_block_reason(?array $u, array $tipster): string
{
    if ((int)($tipster['trial_days'] ?? 0) < 1 || $tipster['status'] !== 'active') {
        return 'This tipster does not offer a free trial.';
    }
    if (!$u) {
        return 'Log in to start the free trial.';
    }
    if ((int)$tipster['user_id'] === (int)$u['id']) {
        return 'This is your own page.';
    }
    if (empty($u['email_verified'])) {
        return 'Confirm your email address first.';
    }
    if (sms_enabled() && (empty($u['mobile']) || empty($u['mobile_verified']))) {
        return 'Confirm your mobile number first (Account > Profile).';
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT 1 FROM tipster_trials WHERE user_id = ? AND tipster_id = ?');
    $st->execute([$u['id'], $tipster['id']]);
    if ($st->fetchColumn()) {
        return 'You have already used the free trial with this tipster.';
    }
    $st = $pdo->prepare('SELECT 1 FROM tipster_subscriptions WHERE user_id = ? AND tipster_id = ? LIMIT 1');
    $st->execute([$u['id'], $tipster['id']]);
    if ($st->fetchColumn()) {
        return 'The free trial is for new members of this tipster.';
    }
    if (!empty($u['mobile'])) {      // same phone number on another account that already took the trial
        $st = $pdo->prepare('SELECT 1 FROM tipster_trials tt JOIN users x ON x.id = tt.user_id WHERE tt.tipster_id = ? AND x.mobile = ? LIMIT 1');
        $st->execute([$tipster['id'], $u['mobile']]);
        if ($st->fetchColumn()) {
            return 'This phone number has already been used for the free trial.';
        }
    }
    return '';
}
