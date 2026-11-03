<?php
require_once __DIR__ . '/bookmakers.php';
/**
 * Draws one betting-slip card.
 * SECURITY: for locked VIP tips the real pick and odds are never sent to the browser,
 * so nobody can read them with "view source".
 */
function tip_card(array $t, bool $vip_access): void
{
    // A VIP tip stays locked only until the match is settled; after that it is public proof of the record.
    $locked = !empty($t['is_vip']) && !$vip_access && $t['status'] === 'pending';
    $pick   = $locked ? 'Locked VIP pick' : $t['pick'];
    $odds   = $locked ? '0.00' : number_format((float)$t['odds'], 2);
    ?>
    <?php $uid = function_exists('current_user') && ($cu = current_user()) ? (int)$cu['id'] : 0; $followed = $uid && in_array((int)$t['id'], tipx_followed($uid), true); $prog = $locked ? null : tip_progress($t); $ph = match_phase($t); $copy = tip_copy_line($t, !$locked); ?>
    <div class="slip<?= $locked ? ' locked' : '' ?>" data-mid="<?= (int)$t['match_id'] ?>" data-ts="<?= (int)strtotime($t['kickoff_at']) ?>"<?= $copy !== '' ? ' data-copy="' . e($copy) . '"' : '' ?>>
      <div class="top">
        <span><?= e($t['league']) ?> &middot; <time class="lt" datetime="<?= e(date('c', strtotime($t['kickoff_at']))) ?>"><?= e(kickoff_label($t['kickoff_at'])) ?></time><?php if (strtotime($t['kickoff_at']) > time() && strtotime($t['kickoff_at']) < time() + 86400): ?> <small class="st-in" data-ts="<?= (int)strtotime($t['kickoff_at']) ?>"></small><?php endif; ?></span>
        <span><?php if (!$locked): ?><button type="button" class="tf<?= $followed ? ' on' : '' ?>" data-tip="<?= (int)$t['id'] ?>" title="Follow this tip: alerts for goals, kickoff and result" aria-label="Follow this tip" aria-pressed="<?= $followed ? 'true' : 'false' ?>">&#128276;</button><?php endif; ?>
          <?php if (!empty($t['is_vip'])): ?><span class="vipflag"><?= !empty($t['tipster_slug']) ? 'PREMIUM' : 'VIP' ?></span><?php endif; ?>
          <?= live_badge($t) ?><span class="badge <?= e($t['status']) ?>"><?= e(ucfirst($t['status'])) ?></span>
        </span>
      </div>
      <?php if (!empty($t['thumb']) && !$locked): ?><div class="tc-thumb"><?= thumb_img($t['thumb'], 'thumb', $t['home_team'] . ' vs ' . $t['away_team']) ?></div><?php endif; ?>
      <div class="body">
        <?php if (($ph === 'live' || $ph === 'ended') && $t['home_score'] !== null): ?><div class="lvs<?= $ph === 'live' ? ' on' : '' ?>" data-mid="<?= (int)$t['match_id'] ?>"><b class="lvs-s"><?= (int)$t['home_score'] ?> - <?= (int)$t['away_score'] ?></b> <span class="lvs-l"><?= e($ph === 'live' ? (live_status_label($t['live_status'] ?? '', isset($t['live_minute']) ? (int)$t['live_minute'] : null) ?: 'Live') : 'Full time') ?></span></div><?php endif; ?>
        <?php if ($ph === 'live' && ($ms = match_stats_get((int)$t['match_id']))): ?><div class="lvx" title="Live statistics"><span>Shots <b><?= (int)$ms['shots_h'] ?>-<?= (int)$ms['shots_a'] ?></b></span><span>On target <b><?= (int)$ms['sot_h'] ?>-<?= (int)$ms['sot_a'] ?></b></span><span>Possession <b><?= (int)$ms['poss_h'] ?>%-<?= (int)$ms['poss_a'] ?>%</b></span><span>Corners <b><?= (int)$ms['corners_h'] ?>-<?= (int)$ms['corners_a'] ?></b></span></div><?php endif; ?>
        <div class="teams">
          <div class="team"><img src="<?= e(team_logo($t['home_team'])) ?>" alt="" width="44" height="44" loading="lazy"><?= e($t['home_team']) ?><?= form_dots_html($t['home_team']) ?></div>
          <span class="vs">VS</span>
          <div class="team"><img src="<?= e(team_logo($t['away_team'])) ?>" alt="" width="44" height="44" loading="lazy"><?= e($t['away_team']) ?><?= form_dots_html($t['away_team']) ?></div>
        </div>
        <div class="pick"><strong><?= e($pick) ?></strong><span class="odds"><?= e($odds) ?></span></div>
        <div class="meta">
          <span class="stars" aria-label="Confidence <?= (int)$t['confidence'] ?> of 5"><?= stars((int)$t['confidence']) ?></span>
          <span class="meter" title="Strength <?= (int)$t['confidence'] ?> of 5"><i style="width:<?= max(0, min(5, (int)$t['confidence'])) * 20 ?>%"></i></span>
          <span><?= $locked ? 'Hidden market' : e($t['market']) ?></span>
        </div>
        <?php if ($prog && $ph !== ''): ?><div class="tp-prog <?= e($prog['state']) ?>" data-mid="<?= (int)$t['match_id'] ?>"><div class="tp-bar"><i style="width:<?= (int)$prog['pct'] ?>%"></i></div><small><?= e($prog['label']) ?> &middot; <?= $prog['state'] === 'won' ? 'looking good' : ($prog['state'] === 'lost' ? 'missed' : 'on track') ?></small></div><?php endif; ?>
        <?php if (!$locked && !empty($t['analysis'])): ?>
          <details class="why-d"><summary>Why this pick</summary><p class="why"><?= e($t['analysis']) ?></p></details>
        <?php endif; ?>
        <?php if (!empty($t['tipster_slug'])): ?><p class="tp-by"><?php if (!empty($t['tipster_avatar'])): ?><img class="tp-av" src="<?= e(media_src((string)$t['tipster_avatar'])) ?>" alt="" width="22" height="22" loading="lazy"><?php else: ?><span class="tp-av tp-ini"><?= e(mb_strtoupper(mb_substr((string)$t['tipster_name'], 0, 1))) ?></span><?php endif; ?>Tip by <a href="<?= e(url('tipster.php?slug=' . rawurlencode($t['tipster_slug']))) ?>"><?= e($t['tipster_name']) ?></a></p><?php endif; ?>
        <?php if (!empty($t['author_slug'])): ?><p class="tp-by">Analysis by <a href="<?= e(url('tipster.php?slug=' . rawurlencode($t['author_slug']))) ?>"><?= e($t['author_name']) ?></a></p><?php endif; ?>
        <?php if (isset($t['posted_at'])): $pf = tip_proof($t); ?>
          <p class="proof<?= $pf['early'] ? '' : ' late' ?>" title="Every tip is timestamped when it is posted and cannot be moved afterwards."><?= $pf['early'] ? '&#10003; Posted ' . e(span_label($pf['minutes'])) . ' before kickoff' : 'Posted after kickoff' ?></p>
        <?php endif; ?>
        <?php if (!$locked && function_exists('tip_reactions_of')): $rxs = tip_reactions_of((int)$t['id'], $uid); ?>
          <div class="tp-rx" data-tip="<?= (int)$t['id'] ?>"><?php foreach (tip_react_emojis() as $em): $n = (int)($rxs['counts'][$em] ?? 0); ?><button type="button" class="rxb<?= $rxs['mine'] === $em ? ' on' : '' ?>" data-e="<?= e($em) ?>" aria-label="React <?= e($em) ?>"><?= $em ?><b><?= $n ?: '' ?></b></button><?php endforeach; ?><a class="rxb rx-img" href="<?= e(url('tip-image.php?id=' . (int)$t['id'] . '&dl=1')) ?>" title="Save as picture" aria-label="Save as picture">&#128247;</a></div>
        <?php endif; ?>
        <a class="more" href="<?= e(url('tip.php?id=' . (int)$t['id'])) ?>">Match details</a>
        <?php if (!$locked && !tip_is_locked($t)) { echo bookmaker_buttons($t, 'card'); } ?>
        <?= stream_button($t) ?>
      </div>
      <?php if ($locked): ?>
        <?php if (!empty($t['tipster_slug'])): ?>
          <div class="lock"><p>Premium tip</p><a class="btn gold" href="<?= e(url('tipster.php?slug=' . rawurlencode($t['tipster_slug']))) ?>">Subscribe to unlock</a></div>
        <?php else: ?>
          <div class="lock"><p>VIP tip</p><a class="btn gold" href="<?= e(url('vip.php')) ?>">Unlock with VIP</a></div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
}
