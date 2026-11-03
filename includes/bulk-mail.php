<?php
/** Mass email: CSV of recipients, branded template with the SlipKing logo, batch sending with progress, opt-out links. */

const BULK_MAIL_MAX = 5000;

function bulk_ready(): bool
{
    return sk_ensure_table('bulk_campaigns', 'database-update-34.sql') && sk_ensure_table('bulk_queue', 'database-update-34.sql') && sk_ensure_table('mail_optout', 'database-update-34.sql');
}

function bulk_unsub_sig(string $email): string { return substr(hash_hmac('sha256', 'unsub|' . strtolower($email), app_secret()), 0, 20); }

function bulk_unsub_url(string $email): string
{
    return url('unsubscribe.php?e=' . rtrim(strtr(base64_encode(strtolower($email)), '+/', '-_'), '=') . '&s=' . bulk_unsub_sig($email));
}

/** Parses pasted text or an uploaded CSV into [['email' =>, 'name' =>], ...] plus counts. */
function bulk_parse(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $seen = []; $rows = []; $bad = 0; $dupe = 0;
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        $cells = str_getcsv($line, strpos($line, ';') !== false && strpos($line, ',') === false ? ';' : (strpos($line, "\t") !== false ? "\t" : ','), '"', '');
        $email = ''; $name = '';
        foreach ($cells as $i => $c) {
            $c = trim($c);
            if ($email === '' && filter_var($c, FILTER_VALIDATE_EMAIL)) { $email = strtolower($c); }
            elseif ($name === '' && $c !== '' && !filter_var($c, FILTER_VALIDATE_EMAIL) && !preg_match('/^(e-?mail|name|email address)$/i', $c)) { $name = mb_substr($c, 0, 100); }
        }
        if ($email === '') {
            if (!preg_match('/e-?mail/i', $line)) { $bad++; }   // a header row is not an error
            continue;
        }
        if (isset($seen[$email])) { $dupe++; continue; }
        $seen[$email] = true;
        $rows[] = ['email' => $email, 'name' => $name];
    }
    return ['rows' => $rows, 'bad' => $bad, 'dupe' => $dupe];
}

function bulk_first_name(string $name): string
{
    $n = trim($name);
    return $n === '' ? '' : explode(' ', $n)[0];
}

/** Replaces {name} / {first_name} / {email}. */
function bulk_merge(string $text, string $name, string $email): string
{
    $first = bulk_first_name($name);
    return str_replace(['{first_name}', '{name}', '{email}'], [$first !== '' ? $first : 'there', $name !== '' ? $name : 'there', $email], $text);
}

/** The branded HTML email (inline CSS, table layout, dark-green header with the logo, gold accents). */
function bulk_html(string $subject, string $body, string $ctaText, string $ctaUrl, string $unsubUrl, string $preheader = ''): string
{
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $logo = $h(url('assets/img/logo-wordmark.png'));
    $site = $h(url());
    $name = $h(SITE_NAME);
    $paras = '';
    foreach (preg_split('/\n{2,}/', trim(str_replace(["\r\n", "\r"], "\n", $body))) as $p) {
        $p = trim($p);
        if ($p === '') { continue; }
        $paras .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.65;color:#2a3a33">' . mail_linkify($p) . '</p>';
    }
    $btn = '';
    if ($ctaText !== '' && preg_match('~^https?://~i', $ctaUrl)) {
        $btn = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px auto 8px"><tr><td align="center" bgcolor="#f0a91c" style="border-radius:999px;background:linear-gradient(180deg,#ffc94d,#f0a91c)">'
             . '<a href="' . $h($ctaUrl) . '" style="display:inline-block;padding:15px 34px;font:800 16px Arial,Helvetica,sans-serif;color:#261600;text-decoration:none;border-radius:999px;letter-spacing:.3px">' . $h($ctaText) . '</a></td></tr></table>';
    }
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h($subject) . '</title></head>'
      . '<body style="margin:0;padding:0;background:#eef1ec;font-family:Arial,Helvetica,sans-serif">'
      . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#eef1ec">' . $h($preheader !== '' ? $preheader : mb_substr(preg_replace('/\s+/', ' ', strip_tags($body)), 0, 110)) . '</div>'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1ec"><tr><td align="center" style="padding:26px 12px">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(10,47,35,.12)">'
      // header
      . '<tr><td align="center" bgcolor="#0d3b2c" style="background:#0d3b2c;background-image:linear-gradient(135deg,#0a2f23,#155240);padding:30px 24px 24px">'
      . '<a href="' . $site . '"><img src="' . $logo . '" width="230" alt="' . $name . '" style="display:block;border:0;max-width:230px;height:auto"></a>'
      . '<div style="margin-top:10px;font:600 11px Arial,sans-serif;letter-spacing:3px;color:#f0c35a;text-transform:uppercase">Smart bets &bull; Bigger wins</div></td></tr>'
      . '<tr><td bgcolor="#f0a91c" style="height:5px;line-height:5px;font-size:0;background:linear-gradient(90deg,#f0a91c,#ffc94d,#f0a91c)">&nbsp;</td></tr>'
      // body
      . '<tr><td style="padding:34px 34px 14px">'
      . '<h1 style="margin:0 0 18px;font:800 26px/1.2 Arial,Helvetica,sans-serif;color:#0a2f23">' . $h($subject) . '</h1>' . $paras . $btn . '</td></tr>'
      // sign-off
      . '<tr><td style="padding:6px 34px 30px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="border-top:1px solid #e3e8e2;padding-top:18px;font-size:15px;color:#2a3a33">Good luck,<br><b style="color:#0a2f23">The ' . $name . ' team</b></td></tr></table></td></tr>'
      // footer
      . '<tr><td bgcolor="#0a2f23" align="center" style="background:#0a2f23;padding:22px 24px;font-size:12px;line-height:1.6;color:#9fc2b3">'
      . '<a href="' . $site . '" style="color:#f0c35a;text-decoration:none;font-weight:700">' . $h(parse_url(SITE_URL, PHP_URL_HOST) ?: SITE_NAME) . '</a><br>'
      . '18+ only. Tips are opinions, not guarantees. Please bet responsibly.<br>'
      . '<a href="' . $h($unsubUrl) . '" style="color:#9fc2b3;text-decoration:underline">Unsubscribe from these emails</a></td></tr>'
      . '</table></td></tr></table></body></html>';
}

/** Plain-text version. */
function bulk_text(string $subject, string $body, string $ctaText, string $ctaUrl, string $unsubUrl): string
{
    return $subject . "\n\n" . trim($body) . ($ctaText !== '' && $ctaUrl !== '' ? "\n\n" . $ctaText . ': ' . $ctaUrl : '') . "\n\n-- " . SITE_NAME . "\n18+ only. Please bet responsibly.\nUnsubscribe: " . $unsubUrl . "\n";
}

/** Sends up to $limit pending recipients of a campaign. Returns the campaign row after the run. */
function bulk_send_batch(int $campaignId, int $limit = 8): array
{
    $pdo = db();
    $c = $pdo->prepare('SELECT * FROM bulk_campaigns WHERE id = ?'); $c->execute([$campaignId]); $c = $c->fetch();
    if (!$c || $c['status'] === 'cancelled') { return $c ?: []; }
    $st = $pdo->prepare("SELECT * FROM bulk_queue WHERE campaign_id = ? AND status = 'pending' ORDER BY id LIMIT " . (int)$limit);
    $st->execute([$campaignId]);
    $t0 = microtime(true);
    foreach ($st->fetchAll() as $r) {
        if (microtime(true) - $t0 > 20) { break; }
        // claim it so two open tabs never send the same email twice
        $claim = $pdo->prepare("UPDATE bulk_queue SET status = 'sending' WHERE id = ? AND status = 'pending'");
        $claim->execute([$r['id']]);
        if (!$claim->rowCount()) { continue; }
        $optout = $pdo->prepare('SELECT 1 FROM mail_optout WHERE email = ?'); $optout->execute([$r['email']]);
        if ($optout->fetchColumn()) { $pdo->prepare("UPDATE bulk_queue SET status = 'skipped', error = 'unsubscribed' WHERE id = ?")->execute([$r['id']]); continue; }
        $subj = bulk_merge((string)$c['subject'], (string)$r['name'], (string)$r['email']);
        $body = bulk_merge((string)$c['body'], (string)$r['name'], (string)$r['email']);
        $un = bulk_unsub_url((string)$r['email']);
        $ok = send_mail((string)$r['email'], $subj, bulk_text($subj, $body, (string)$c['cta_text'], (string)$c['cta_url'], $un), null,
                        bulk_html($subj, $body, (string)$c['cta_text'], (string)$c['cta_url'], $un), $un);
        if ($ok) { $pdo->prepare("UPDATE bulk_queue SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$r['id']]); }
        else { $pdo->prepare("UPDATE bulk_queue SET status = 'failed', error = ? WHERE id = ?")->execute([mb_substr(mail_last_error(), 0, 190), $r['id']]); }
        usleep(150000);
    }
    $pdo->prepare("UPDATE bulk_campaigns c SET
        sent = (SELECT COUNT(*) FROM bulk_queue q WHERE q.campaign_id = c.id AND q.status = 'sent'),
        failed = (SELECT COUNT(*) FROM bulk_queue q WHERE q.campaign_id = c.id AND q.status = 'failed'),
        skipped = (SELECT COUNT(*) FROM bulk_queue q WHERE q.campaign_id = c.id AND q.status = 'skipped'),
        status = IF((SELECT COUNT(*) FROM bulk_queue q WHERE q.campaign_id = c.id AND q.status IN ('pending','sending')) = 0, 'done', c.status)
        WHERE id = ?")->execute([$campaignId]);
    $c = $pdo->prepare('SELECT * FROM bulk_campaigns WHERE id = ?'); $c->execute([$campaignId]);
    return $c->fetch();
}
