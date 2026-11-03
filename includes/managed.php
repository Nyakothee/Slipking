<?php
/**
 * Account management: a client sends an enquiry to the SlipKing team or to a tipster who accepts such requests,
 * and the two talk in an on-site conversation. The site never holds betting money, passwords or codes.
 * Works (as the old single enquiry form) until update-15 is imported.
 */

function managed_cols(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT tipster_id FROM managed_requests LIMIT 0');
            db()->query('SELECT accepts_managed, managed_note FROM tipsters LIMIT 0');
            db()->query('SELECT id FROM managed_messages LIMIT 0');
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

function managed_statuses(): array
{
    return ['new' => 'Received', 'contacted' => 'In contact', 'accepted' => 'Accepted', 'declined' => 'Not accepted', 'closed' => 'Closed'];
}

/** Active tipsters who take account-management requests. */
function managed_tipsters(): array
{
    if (!managed_cols()) {
        return [];
    }
    return db()->query("SELECT id, user_id, slug, display_name, tagline, managed_note, avatar_url FROM tipsters
                        WHERE status = 'active' AND accepts_managed = 1" . tipster_live_sql('tipsters') . " ORDER BY rating_avg DESC, display_name LIMIT 100")->fetchAll();
}

/** Who is this person in this request: 'client', 'tipster', 'staff' or '' (not allowed in). */
function managed_role(array $req, ?array $user): string
{
    if (!$user) {
        return '';
    }
    if ((int)$req['user_id'] === (int)$user['id']) {
        return 'client';
    }
    if (!empty($req['tipster_id'])) {
        $t = tipster_for_user((int)$user['id']);
        if ($t && (int)$t['id'] === (int)$req['tipster_id']) {
            return 'tipster';
        }
    }
    return function_exists('can') && can('managed') ? 'staff' : '';
}

function managed_messages_for(int $reqId): array
{
    $st = db()->prepare('SELECT m.*, u.name FROM managed_messages m JOIN users u ON u.id = m.user_id WHERE m.request_id = ? ORDER BY m.id ASC LIMIT 300');
    $st->execute([$reqId]);
    return $st->fetchAll();
}

/** Adds a message and tells the other side. Returns an error text, or '' when sent. */
function managed_post_message(array $req, string $role, int $userId, string $body): string
{
    $body = trim($body);
    if ($body === '' || mb_strlen($body) > 1500) {
        return 'Write a message of up to 1500 characters.';
    }
    if (looks_like_secret($body)) {
        return 'For your safety, never write passwords, PINs or codes here. Remove them and send again.';
    }
    $st = db()->prepare('SELECT COUNT(*) FROM managed_messages WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 MINUTE');
    $st->execute([$userId]);
    if ((int)$st->fetchColumn() >= 8) {
        return 'You are sending messages very quickly. Please wait a minute.';
    }
    db()->prepare('INSERT INTO managed_messages (request_id, sender, user_id, body) VALUES (?,?,?,?)')->execute([(int)$req['id'], $role, $userId, $body]);
    $title = 'New message about account management';
    $text = 'You have a new message on account-management request #' . (int)$req['id'] . '. Open it to read and reply.';
    if ($role !== 'client') {
        notify_user((int)$req['user_id'], $title, $text . ' ' . url('account/managed.php?id=' . (int)$req['id']));
    }
    if ($role !== 'tipster' && !empty($req['tipster_id'])) {
        $st = db()->prepare('SELECT user_id FROM tipsters WHERE id = ?');
        $st->execute([(int)$req['tipster_id']]);
        if ($tu = $st->fetchColumn()) {
            notify_user((int)$tu, $title, $text . ' ' . url('tipster/managed.php?id=' . (int)$req['id']));
        }
    }
    return '';
}

/**
 * The conversation box: messages, a reply form, and (for tipster/staff) the status buttons.
 * $postUrl is the page that handles the POST; $role is client|tipster|staff.
 */
function managed_thread_html(array $req, string $role, string $postUrl, string $back): string
{
    $msgs = managed_messages_for((int)$req['id']);
    $labels = ['client' => 'Client', 'tipster' => 'Tipster', 'staff' => e(SITE_NAME) . ' team'];
    ob_start();
    ?>
    <div class="mm-box">
      <div class="note" style="margin-top:0"><strong>Safety.</strong> Never share a password, PIN or code here or anywhere. <?= e(SITE_NAME) ?> never holds your betting money and is not a party to any agreement between you. Many betting companies do not allow anyone but the account owner to use an account, so check their rules first. Agree any fees in writing before anything starts.</div>
      <div class="mm-list">
        <?php if (!$msgs): ?><p style="color:var(--mute)">No messages yet. Say hello below.</p><?php endif; ?>
        <?php foreach ($msgs as $m): ?>
          <div class="mm-msg mm-<?= e($m['sender']) ?><?= $m['sender'] === $role ? ' mine' : '' ?>">
            <div class="mm-who"><strong><?= $m['sender'] === $role ? 'You' : $labels[$m['sender']] ?></strong> <small><?= e(date('j M, H:i', strtotime($m['created_at']))) ?></small></div>
            <p><?= nl2br(e($m['body'])) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($req['status'] !== 'closed' && $req['status'] !== 'declined'): ?>
      <form method="post" action="<?= e($postUrl) ?>" class="mm-form"><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$req['id'] ?>"><input type="hidden" name="action" value="message">
        <textarea name="body" rows="3" maxlength="1500" required placeholder="Write your message" aria-label="Your message"></textarea>
        <button class="btn gold" type="submit">Send</button>
      </form>
      <?php else: ?><p style="color:var(--mute)">This request is <?= e(strtolower(managed_statuses()[$req['status']])) ?>, so the conversation is closed.</p><?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}
