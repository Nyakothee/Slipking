</main>
<?php if ($wb = widgets_block('footer')): ?><div class="wrap" style="padding-top:12px"><?= $wb ?></div><?php endif; ?>
<footer>
  <div class="wrap">
    <div class="fgrid">
      <div>
        <a class="brand" href="<?= e(url()) ?>" style="font-size:28px;color:#fff"><?= brand_html() ?></a>
        <p style="margin-top:8px">Predictions with a public record.</p>
      </div>
      <div><h4>Site</h4><ul>
        <?php if (!page_file_off('tips.php')): ?><li><a href="<?= e(url('tips.php')) ?>">Today's tips</a></li><?php endif; ?>
        <?php if (!page_file_off('results.php')): ?><li><a href="<?= e(url('results.php')) ?>">Results</a></li><?php endif; ?>
        <?php if (!page_file_off('vip.php')): ?><li><a href="<?= e(url('vip.php')) ?>">VIP plans</a></li><?php endif; ?>
        <?php if (!page_file_off('tipsters.php')): ?><li><a href="<?= e(url('tipsters.php')) ?>">Tipsters</a></li><?php endif; ?>
        <?php if (!page_file_off('account-management.php')): ?><li><a href="<?= e(url('account-management.php')) ?>">Account management</a></li><?php endif; ?>
        <?php if (!page_file_off('leaderboard.php')): ?><li><a href="<?= e(url('leaderboard.php')) ?>">Leaderboard</a></li><?php endif; ?>
        <?php if (!page_file_off('live.php')): ?><li><a href="<?= e(url('live.php')) ?>">Live match centre</a></li><?php endif; ?>
        <?php if (!page_file_off('game.php')): ?><li><a href="<?= e(url('game.php')) ?>">Free prediction game</a></li><?php endif; ?>
        <?php if (!page_file_off('jackpot.php')): ?><li><a href="<?= e(url('jackpot.php')) ?>">Jackpot</a></li><?php endif; ?>
        <?php if (!page_file_off('members.php')): ?><li><a href="<?= e(url('members.php')) ?>">Top members</a></li><?php endif; ?>
        <?php if (!page_file_off('discuss.php')): ?><li><a href="<?= e(url('discuss.php')) ?>">Discussion</a></li><?php endif; ?>
        <?php if (!page_file_off('clubs.php')): ?><li><a href="<?= e(url('clubs.php')) ?>">Tipster clubs</a></li><?php endif; ?>
        <?php if (!page_file_off('arena.php')): ?><li><a href="<?= e(url('arena.php')) ?>">Football Knowledge Arena</a></li><?php endif; ?>
        <?php if (!page_file_off('slips.php')): ?><li><a href="<?= e(url('slips.php')) ?>">Slip Builder Studio</a></li><?php endif; ?>
        <?php if (!page_file_off('football.php')): ?><li><a href="<?= e(url('football.php')) ?>">League tables and stats</a></li><?php endif; ?>
        <?php if (!page_file_off('predictions.php')): ?><li><a href="<?= e(url('predictions.php')) ?>">Match predictions</a></li><?php endif; ?>
<?php if (function_exists('odds_on') && odds_on()): ?>        <?php if (!page_file_off('odds.php')): ?><li><a href="<?= e(url('odds.php')) ?>">Odds comparison</a></li><?php endif; ?>
<?php endif; ?>
        <?php if (!page_file_off('referral-leaderboard.php')): ?><li><a href="<?= e(url('referral-leaderboard.php')) ?>">Top referrers</a></li><?php endif; ?>
        <li><a href="<?= e(url('auth/register-tipster.php')) ?>">Become a tipster</a></li>
        <?php if (!page_file_off('accumulators.php')): ?><li><a href="<?= e(url('accumulators.php')) ?>">Accumulators</a></li><?php endif; ?>
        <?php if (!page_file_off('performance.php')): ?><li><a href="<?= e(url('performance.php')) ?>">Performance</a></li><?php endif; ?>
        <?php if (!page_file_off('audit.php')): ?><li><a href="<?= e(url('audit.php')) ?>">Tip audit log</a></li><?php endif; ?>
        <?php if (!page_file_off('calculator.php')): ?><li><a href="<?= e(url('calculator.php')) ?>">Betting calculator</a></li><?php endif; ?>
        <?php if (!page_file_off('blog.php')): ?><li><a href="<?= e(url('blog.php')) ?>">Blog</a></li><?php endif; ?>
        <?php if (!page_file_off('channels.php')): ?><li><a href="<?= e(url('channels.php')) ?>">Channels</a></li><?php endif; ?>
        <?php if (!function_exists('is_app') || !is_app()): ?><li class="app-dl"><a href="<?= e(url('download-app.php')) ?>">Get the Android app</a></li><?php endif; ?>
        <?php if (!page_file_off('how-it-works.php')): ?><li><a href="<?= e(url('how-it-works.php')) ?>">How it works</a></li><?php endif; ?>
        <li><a href="#" data-tour>Take a tour</a></li>
        <li><a href="#cookies" onclick="try{document.cookie='sk_consent=; max-age=0; path=/'}catch(e){}location.reload();return false;">Cookie settings</a></li>
        <?php if (!page_file_off('about.php')): ?><li><a href="<?= e(url('about.php')) ?>">About</a></li><?php endif; ?>
      </ul></div>
      <div><h4>Support</h4><ul>
        <?php if (!page_file_off('contact.php')): ?><li><a href="<?= e(url('contact.php')) ?>">Contact us</a></li><?php endif; ?>
        <?php if (!page_file_off('terms.php')): ?><li><a href="<?= e(url('terms.php')) ?>">Terms</a></li><?php endif; ?>
        <?php if (!page_file_off('privacy.php')): ?><li><a href="<?= e(url('privacy.php')) ?>">Privacy</a></li><?php endif; ?>
        <?php if (!page_file_off('responsible-gambling.php')): ?><li><a href="<?= e(url('responsible-gambling.php')) ?>">Responsible gambling</a></li><?php endif; ?>
      </ul></div>
      <div><?php include __DIR__ . '/social-box.php'; ?>
        <div class="fbtns">
          <button type="button" class="fbtn fbtn-alerts" data-pwa-alerts data-off="Turn on tip alerts" data-on="Tip alerts: on" aria-pressed="false" hidden><span class="fb-sw" aria-hidden="true"><i></i></span><span class="fb-t">Turn on tip alerts</span></button>
        </div></div>
    </div>
    <?php require_once __DIR__ . '/guarantee.php'; $gb = guarantee_badge(); if ($gb !== ''): ?><div class="warn"><?= $gb ?></div><?php endif; ?>
    <div class="warn">18+ only. Gamble responsibly and never bet more than you can afford to lose. Tips are opinions, not guarantees. If betting stops being fun, seek help from a support service in your country. &copy; <?= date('Y') ?> <?= e(SITE_NAME) ?>
      <?php if (current_currency_code() !== 'KES' && current_currency_code() !== 'USD'): ?><br>Prices in other currencies are estimates. The amount you pay is shown at checkout in the currency of the method you choose (KES, USD, EUR, GBP, TZS...).<?php endif; ?>
      <?php if (rates_attribution_needed()): ?> <a href="https://www.exchangerate-api.com" rel="noopener" style="text-decoration:underline">Rates By Exchange Rate API</a><?php endif; ?></div>
  </div>
</footer>
<?php if (function_exists('wscore_maybe_run')) { wscore_maybe_run(); } if (function_exists('traffic_touch')) { traffic_touch(); } if (function_exists('alerts_maybe_run')) { alerts_maybe_run(); } ?>
<?php $jt = js_translations(); if ($jt): ?><script>window.SK_T=<?= json_encode($jt, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script><?php endif; ?>
<?php if (empty($no_chat)) { include __DIR__ . '/chat-widget.php'; } ?>
<script src="<?= e(url('assets/js/main.js')) ?>"></script>
<script>window.SK_BASE=<?= json_encode(url()) ?>;window.SK_CSRF=<?= json_encode(csrf_token()) ?>;</script>
<script src="<?= e(url('assets/js/stream-player.js')) ?>" defer></script>
<script src="<?= e(url('assets/js/tipcard.js')) ?>" defer></script>
<?php if (empty($no_chat)): ?><script src="<?= e(url('assets/js/chat.js')) ?>"></script><?php endif; ?>
<script src="<?= e(url('assets/js/pwa.js')) ?>" defer></script>
<script src="<?= e(url('assets/js/localtime.js')) ?>" defer></script>
<?php if (function_exists('is_app') && is_app()): ?><style>[data-pwa-install],#push-box,.app-dl{display:none!important}</style>
<script>try{if(window.SlipKingApp&&SlipKingApp.session){SlipKingApp.session(<?= current_user() ? 'true' : 'false' ?>,<?= (current_user() && function_exists('is_staff') && is_staff()) ? 'true' : 'false' ?>);}}catch(e){}</script><?php endif; ?>
<?php foreach (($extra_js ?? []) as $js): ?><script src="<?= e(url($js)) ?>"></script>
<?php endforeach; ?>
<?php if (!empty($_SESSION['user_id']) && function_exists('alert_touch')) { alert_touch((int)$_SESSION['user_id']); } ?>
<script src="<?= e(url('assets/js/cookie-notice.js')) ?>" defer></script>
<script>try{var z=Intl.DateTimeFormat().resolvedOptions().timeZone;if(z&&document.cookie.indexOf('sk_tz='+z)<0){document.cookie='sk_tz='+z+'; max-age=31536000; path=/; samesite=lax';}}catch(e){}</script>
<script>window.SK_WN = <?= json_encode(whats_new()) ?>;</script>
<script src="<?= e(url('assets/js/tour.js')) ?>" defer></script>
</body>
</html>
