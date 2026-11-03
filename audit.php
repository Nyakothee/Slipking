<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
$english_only = true;
$v = audit_verify();
$rows = db()->query('SELECT id, tip_id, event, payload, hash, created_at FROM audit_chain ORDER BY id DESC LIMIT 50')->fetchAll();
$page_title = 'Tip audit log - ' . SITE_NAME;
$page_desc = 'Every tip we post and settle is written to a chained log. Changing an old line breaks the chain.';
$head_extra = seo_meta(['title' => $page_title, 'description' => $page_desc, 'url' => url('audit.php')]);
$active = 'performance';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top"><h1>Tip audit log</h1>
  <p style="color:var(--mute);max-width:720px">When we post or settle a tip, a line is added here. Each line contains a fingerprint (SHA-256) of the line before it. If anyone edits or deletes an old line, every fingerprint after it stops matching and the check below fails.</p>
  <div class="note" style="margin-top:12px"><?= $v['ok'] ? '<strong style="color:var(--win)">Chain intact.</strong> ' . (int)$v['rows'] . ' entries checked.' : '<strong style="color:var(--loss)">Chain broken at entry #' . (int)$v['broken_at'] . '.</strong>' ?>
    <?php if ($v['rows']): ?><br><small>Latest fingerprint: <code style="word-break:break-all"><?= e($v['head']) ?></code></small><?php endif; ?></div>
</div>
<section style="padding-top:6px"><div class="wrap">
  <?php if (!$rows): ?><div class="note" style="margin-top:0">No entries yet.</div><?php else: ?>
  <div class="scroll"><table class="bm-table"><thead><tr><th>#</th><th>Logged</th><th>Event</th><th>Details</th><th>Fingerprint</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= (int)$r['id'] ?></td><td><?= e(date('j M Y H:i', strtotime($r['created_at']))) ?></td><td><?= $r['event'] === 'post' ? 'Posted' : 'Settled' ?></td>
    <td><?php if ($r['event'] === 'post') { $parts = explode(' | ', $r['payload']); echo e(strpos($r['payload'], '| vip') !== false ? $parts[0] . ' (premium tip, pick hidden until settled)' : $r['payload']); } else { echo 'Tip #' . (int)$r['tip_id'] . ': ' . e($r['payload']); } ?></td>
    <td><code><?= e(substr($r['hash'], 0, 12)) ?>&hellip;</code></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  <div class="note">This log proves order and content of what was logged. Showing the first and last fingerprints on social media makes it even harder to rewrite. Premium picks are hidden here until settled.</div>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
