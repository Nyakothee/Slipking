<?php
/** Draws the 2 Odds Streak Challenge card (home page and streak.php). */
function streak_card(): void
{
    if (!streak_ready()) { return; }
    $u = current_user();
    $v = streak_view($u);
    $t = $v['today'];
    $you = $v['you'];
    $shown = $you ? $you : null;
    $days = $shown ? (int)$shown['days'] : (int)$v['days'];
    $bank = $shown ? (float)$shown['bank'] : (float)$v['bank'];
    ?>
    <section id="streak" style="padding-top:0"><div class="wrap">
      <div class="stk">
        <div class="stk-head">
          <div><span class="stk-tag">FREE CHALLENGE</span><h2>2 Odds Streak Challenge</h2>
            <p>One pick a day at about 2.00 odds. Win and the whole return goes on tomorrow's pick. Lose and we start again from <?= e(streak_money($v['start'])) ?>.</p></div>
          <div class="stk-flame" aria-hidden="true">&#128293;</div>
        </div>
        <div class="stk-nums">
          <div class="stk-n"><b><?= $days ?></b><span><?= $days === 1 ? 'day' : 'days' ?> winning streak<?= $you ? ' (you)' : '' ?></span></div>
          <div class="stk-n"><b><?= e(streak_money($bank)) ?></b><span>progress so far<?= $you ? ' (you)' : '' ?></span></div>
          <?php if ($t): ?><div class="stk-n gold"><b><?= e(streak_money($shown ? (float)$shown['return'] : (float)$t['return'])) ?></b><span>if today wins</span></div><?php endif; ?>
        </div>
        <?php if ($v['history']): ?><div class="stk-dots" aria-label="Last days"><?php foreach ($v['history'] as $h): ?><span class="stk-d <?= e($h['status']) ?>" title="<?= e(date('j M', strtotime($h['day'])) . ': ' . $h['status']) ?>"><?= $h['status'] === 'won' ? 'W' : ($h['status'] === 'lost' ? 'L' : ($h['status'] === 'void' ? 'V' : '&middot;')) ?></span><?php endforeach; ?></div><?php endif; ?>
        <?php if ($t): ?>
          <div class="stk-pick">
            <div class="stk-meta"><?= e($t['league'] ?: 'Today') ?> &middot; <time class="lt" datetime="<?= e(date('c', strtotime($t['kickoff_at']))) ?>"><?= e(kickoff_label($t['kickoff_at'])) ?></time> &middot; Day <?= (int)($shown ? $shown['day_no'] : $t['day_no']) ?></div>
            <?= !empty($t['thumb']) ? '<div class="stk-thumb">' . thumb_img($t['thumb'], 'thumb', $t['home'] . ' vs ' . $t['away']) . '</div>' : '' ?>
            <div class="stk-teams"><?= e($t['home']) ?> <span>vs</span> <?= e($t['away']) ?></div>
            <div class="stk-row"><strong><?= e($t['pick']) ?></strong><span class="stk-odds"><?= e(number_format($t['odds'], 2)) ?></span>
              <span class="badge <?= e($t['status']) ?>"><?= e(ucfirst($t['status'])) ?></span></div>
            <div class="stk-stake">Stake today: <b><?= e(streak_money($shown ? (float)$shown['stake'] : (float)$t['stake'])) ?></b></div>
            <?= stream_button(['stream_url' => $t['stream_url'], 'home' => $t['home'], 'away' => $t['away'], 'kickoff_at' => $t['kickoff_at'], 'stream_extra' => $t['stream_extra'] ?? '']) ?>
            <?php if ($t['open']): ?>
              <?php if (!$u): ?><a class="btn gold" href="<?= e(url('auth/login.php?next=' . urlencode('index.php#streak'))) ?>">Log in to play for free</a>
              <?php elseif ($you && $you['joined']): ?><div class="stk-in">&#10003; You are in today. Good luck!</div>
              <?php else: ?><form method="post" action="<?= e(url('streak-join.php')) ?>"><?= csrf_field() ?><input type="hidden" name="day" value="<?= (int)$t['id'] ?>"><button class="btn gold" type="submit">Play today with <?= e(streak_money((float)$you['stake'])) ?></button></form><?php endif; ?>
            <?php elseif ($t['status'] === 'pending'): ?><div class="stk-in">Kickoff has passed. Result coming soon.</div><?php endif; ?>
          </div>
        <?php else: ?><div class="stk-pick"><div class="stk-teams">Today's streak pick is coming soon</div><div class="stk-stake">Check back in a little while.</div></div><?php endif; ?>
        <p class="stk-foot"><a href="<?= e(url('streak.php')) ?>">See the full streak history</a> &middot; Free to play. Odds are not guaranteed. 18+.</p>
      </div>
    </div></section>
    <?php
}
