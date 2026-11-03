SLIPKING - FINAL PACKAGE
Football/sports predictions site: free + VIP tips, 1 Million and VIP accumulators, M-Pesa + PayPal payments, coupons,
currencies (KES default, USD, NGN, INR), English / French / Arabic, live chat, account-management enquiries, admin panel.
Plain PHP + MySQL. Needs PHP 7.4 or newer (8.x best) with the PDO MySQL, cURL and mbstring extensions.

=====================================================================
1. NEW INSTALL (step by step)
=====================================================================
 1. In your hosting panel create a MySQL database + a user, and ADD THE USER TO THE DATABASE (all privileges).
    Write down the database name, user and password (on shared hosting they usually start with your account name).
 2. phpMyAdmin > select the database > Import > choose database.sql > Go.   (It already contains every update below.)
 3. Edit includes/config.php: SITE_URL, SITE_EMAIL, DB_NAME, DB_USER, DB_PASS.  (Use https:// and no slash at the end.)
 4. Upload EVERYTHING in this folder to public_html (keep the folders).
 5. Open check.php (see "If the site shows an error" below) until every line says OK. Then DELETE check.php.
 6. Open your site. You will see sample tips. Register an account, then in phpMyAdmin run:
        UPDATE users SET role='admin' WHERE email='you@example.com';
    Log in at /admin/login.php.
 7. Remove the sample data:  DELETE FROM tips;  DELETE FROM matches;   (and DELETE FROM accumulators; if you add any)
 8. Delete database.sql and the database-update-*.sql files from the server.

UPGRADING an older copy: import the database-update files you have NOT run yet, in order, BEFORE uploading new files:
    database-update-1.sql (admin log) -> -2.sql (coupons) -> -3.sql (currencies + accumulators) -> -4.sql (chat + enquiries)
    -> -5.sql (trial/win-back offers, referral rewards, Paystack + Razorpay)
Do not overwrite your edited includes/config.php or includes/config-payments.php.

=====================================================================
2. IF THE SITE SHOWS "The site is temporarily unavailable"
=====================================================================
That message means the site could not connect to the database. Upload check.php, change the $KEY line at the top to your own
secret text, and open  https://yourdomain.com/check.php?key=YOURSECRET . It shows exactly what is wrong (wrong DB name/user/
password, user not added to the database, missing PHP extension, missing tables...). Fix the red lines, then delete check.php.
Other places to look: logs/php-errors.log, logs/payments.log, logs/mail.log.

=====================================================================
3. SETTINGS
=====================================================================
includes/config.php            site name/address, email, database, timezone (Kenya = Africa/Nairobi, +03:00), DEBUG (keep false)
includes/config-payments.php   M-Pesa Daraja keys (start with 'sandbox'), PayPal keys (start with 'sandbox')
includes/config-gateways.php   OPTIONAL Paystack (naira) and Razorpay (rupees) keys
M-Pesa: https:// site required; callback is built automatically (payments/mpesa-callback.php).
PayPal: add a webhook to /payments/paypal-webhook.php (event: Payment capture completed) and put its Webhook ID in config-payments.php.

=====================================================================
4. CRON JOBS (cPanel > Cron Jobs; command line only, the web is blocked)
=====================================================================
  every 5 minutes : php /home/YOURUSER/public_html/cron/check-pending-mpesa.php
  every hour      : php /home/YOURUSER/public_html/cron/expire-subscriptions.php
  once a day      : php /home/YOURUSER/public_html/cron/send-reminders.php   (plan-ending, unfinished-checkout and win-back messages)
  once a day      : php /home/YOURUSER/public_html/cron/update-rates.php
  once a week     : php /home/YOURUSER/public_html/cron/cleanup.php

=====================================================================
5. ADMIN PANEL  (/admin/login.php)
=====================================================================
Offer settings (trial, referral reward, win-back coupon) is under Currencies in the menu.
Dashboard | Tips + Add a tip (post, settle) | Members | Subscriptions | Payments | Plans and prices | Coupon codes |
Currencies | Accumulators | Live chat | Account management | Sports and leagues | Blog | Messages | Activity log
Integrity rules built in: posting times are set by the server; tips and accumulators are locked after the first kickoff;
nothing can be settled before it starts; every admin action is logged.

=====================================================================
6. LANGUAGES AND CURRENCIES
=====================================================================
Visitors switch language (English / Français / العربية) and currency from the menu in the header. Arabic switches the whole
layout to right-to-left. Pages are written in English; for French/Arabic the finished page is translated using
lang/fr.txt and lang/ar.txt (one line per phrase:  English ||| Translation). Anything missing stays in English, so nothing breaks.
To fix a wording, edit that line. To translate a new phrase, add a line (copy the English exactly as it appears on the page).
- Admin pages and the Terms/Privacy pages stay in English (a notice says so). Have the French and Arabic reviewed by a native speaker.
- Dates use French/Arabic month and day names automatically.
Currencies: Admin > Currencies. Other currencies are ESTIMATES; people are always charged in KES (M-Pesa) or USD (PayPal).
Press "Update rates now" before launch (the seeded NGN/INR rates are placeholders). Rates come from the free Exchange Rate API;
the footer shows the required credit link.

=====================================================================
7. LIVE CHAT AND ACCOUNT MANAGEMENT
=====================================================================
Live chat: a button on every public page. Replies are written in Admin > Live chat (keep that page open; it refreshes itself and
you are also emailed when a new chat starts). Messages that look like passwords/PINs are refused, and visitors are warned.
Account management: a clear application form with a risk notice (Admin > Account management). It deliberately does NOT collect
bookmaker passwords and the site never holds client money. Get legal advice before offering this service: managing other people's
betting accounts may be regulated where you work and may break bookmakers' rules.

=====================================================================
8. BEFORE YOU ANNOUNCE
=====================================================================
- Follow TESTING.txt on the real server (sign-up, tips, M-Pesa + PayPal sandbox payments, chat, languages).
- terms.php and privacy.php are TEMPLATES: edit them for your business (especially Refunds) and have a lawyer in your country check them.
- DEBUG is false. check.php and all .sql files are deleted. robots.txt has your real domain (submit /sitemap.php to Google).
- Replace the sample "testimonials" with real ones only (the section stays hidden while empty).
- Set up automatic backups of the database and files.

=====================================================================
9. NEW IN THIS VERSION (the four additions)
=====================================================================
A) PERFORMANCE PAGE  (/performance.php, linked from Results and the footer)
   Win rate, average odds and profit (in units) split by sport, league, market, free vs VIP and month, with a 12-month win-rate
   chart and a 12-month profit chart. Every settled tip is counted (deleted ones too), so the record cannot be tidied up.
B) TRIAL + WIN-BACK OFFERS
   - Free trial: new members who confirmed their email and added a phone number can claim a short free VIP pass once
     (the same phone number cannot be reused on another account). Length and on/off: Admin > Offer settings.
   - Daily cron (send-reminders.php) sends: a plan-ending reminder (already there), a gentle "your payment did not finish" message
     about an hour after an abandoned checkout, and a win-back message 3-10 days after a plan or trial ends (optionally mentioning a
     coupon code you create). Members can switch these reminder emails off in My account > Profile > Emails.
C) REAL REFERRAL REWARDS
   When an invited friend makes their first payment, the person who invited them gets free VIP days automatically (default 3,
   change in Offer settings, 0 = off). Paid out once per friend. The Referrals page shows the reward.
D) PAYSTACK (NAIRA) AND RAZORPAY (RUPEES)
   Fill in includes/config-gateways.php. Panels appear on checkout only when keys are set AND NGN / INR are switched on in Currencies.
   Amounts are converted from the KES price using your Currencies rates (naira rounded up to the nearest 10, rupees to the nearest 1),
   so keep the rates fresh. Add these webhooks (recommended, they confirm payments even if the customer closes the browser):
     Paystack : https://yourdomain.com/payments/paystack-webhook.php   (Settings > API Keys and Webhooks)
     Razorpay : https://yourdomain.com/payments/razorpay-webhook.php   (events: payment.captured; put the secret in RAZORPAY_WEBHOOK_SECRET)
   Money is only ever confirmed by asking Paystack/Razorpay directly, never from what the browser says.

=====================================================================
10. VERSION 3 ADDITIONS (growth, trust and admin tools)
=====================================================================
UPGRADING AN EXISTING SITE: back up first, import database-update-6.sql in phpMyAdmin (SQL tab, safe to run twice), then upload the
new files over the old ones. Create the folder backups/ (755) if it is missing. Keep includes/config.php and your payment keys.
NEW SETTINGS FILES (all optional, empty by default): includes/config-social.php (Telegram, WhatsApp), includes/config-mail.php (SMTP),
includes/config-results.php (automatic results key).

PUBLIC SITE
- Dark mode: moon/sun button in the header. Remembers the visitor's choice; follows the device setting until they choose.
- Betting calculator (/calculator.php): Kelly, half/quarter Kelly, flat staking, accumulator odds and stake. Estimates only.
- Match and league pages (/match.php?id=, /league.php?id=): SEO pages with JSON-LD, listed in the sitemap. VIP picks stay locked there.
- Bet tracker (My account > Bet tracker): members log their own bets, see profit, ROI, strike rate and a chart; CSV export.
- Odds comparison and "Bet on ..." buttons on unlocked tips (see Bookmakers below). Share buttons (WhatsApp, Telegram, X) on tips.
- App: the site can be installed on a phone (manifest.php, sw.js, offline.html). Optional "tip alerts" show a notification while the
  site is open in the browser. This is NOT true background push: for that you would need a push service.
- "Bad-day guarantee" line on the VIP page and footer, only when you switch it on (Admin > Guarantee).
- New plans seeded if missing: Weekly and Season pass (90 days). PRICES ARE EXAMPLES - edit them in Admin > Plans.

ADMIN
- Security (2FA): Admin > Security. Scan the secret with an authenticator app, confirm a code, SAVE the recovery codes.
  Locked out: use a recovery code. Last resort: in phpMyAdmin set users.totp_enabled = 0 for your admin row.
- Social and Telegram: create a bot with @BotFather, add it as an administrator of your channel(s), fill includes/config-social.php,
  then Admin > Social and Telegram (send a test, switch on auto-post). New VIP tips go to the VIP channel with the pick; the free
  channel only gets a teaser WITHOUT pick or odds.
- Email: edit includes/config-mail.php (MAIL_DRIVER 'smtp' + host/port/user/password, or a service like Brevo). Test it in Admin > Email test.
  Emails are now sent as a branded HTML + plain-text message. Failures are logged in logs/mail.log.
- Bookmakers: Admin > Bookmakers. Four EXAMPLE rows are inactive. Add your real affiliate links, tick Active. Clicks are counted
  (IP addresses are hashed, never stored). Enter odds per bookmaker for a tip with the "Odds" button in Admin > Tips.
  Affiliate links must follow each bookmaker's programme rules and the law where you operate; show the 18+ notice (already included).
- Guarantee: Admin > Guarantee. If every VIP tip of a day loses (and at least N were settled), active paid VIP members get free days.
  Check the cost estimate before switching it on. Run cron/guarantee.php once a day after midnight.
- Bulk tips: Admin > Bulk tips (CSV). Upload or paste a CSV (template link on the page), check the preview, confirm. Only valid rows are saved.
- Automatic results (optional): needs an API-Football key (paid plans; the free one is tiny). Put it in includes/config-results.php,
  link matches to fixture numbers in Admin > Match fixtures, switch it on, and run cron/auto-results.php every 30 minutes. Only simple
  markets (1X2, double chance, over/under x.5, both teams to score, draw no bet) are settled; everything else stays pending for you.
  CHECK its work - feeds can be wrong. It never changes a tip that is already settled. Log: logs/auto-results.log.
- Dashboard charts: revenue per day (other currencies converted with your Currencies rates, so approximate), new members, win rate,
  top plans, free-to-VIP conversion.
- Backups: Admin > Backups (create, download, delete) and cron/backup.php daily. Backups on the same server do not protect you
  if the server is lost: download a copy regularly. To restore: phpMyAdmin > Import the .sql.gz file.

EXTRA CRON JOBS
  php /home/YOURUSER/public_html/cron/guarantee.php        once a day, 00:15
  php /home/YOURUSER/public_html/cron/backup.php           once a day, 03:00
  php /home/YOURUSER/public_html/cron/auto-results.php     every 30 minutes (only if you use automatic results)

11. TIPSTER MARKETPLACE AND STAFF ROLES (version 4)
UPGRADING: import database-update-7.sql once (phpMyAdmin > Import). New installs already include it in database.sql.
- Tipsters: members apply at tipster/apply.php (email must be confirmed). You approve them in Admin > Tipsters. Approved tipsters get a public
  profile (tipsters.php, tipster.php?slug=...), post tips, create up to 4 plans and request payouts.
- Money flow: members pay through the normal checkout, so ALL money lands in YOUR M-Pesa/PayPal/Paystack/Razorpay account. The site records each
  sale, copies your commission % onto it, and works out what the tipster is owed. Coupons do not apply to tipster plans.
- YOUR PERCENTAGE: Admin > Tipsters > "Commission and rules". Change it any time. It applies to FUTURE sales only; past sales keep the rate they
  were sold at. You can also set a different rate for one tipster in the table (leave empty for the default).
- Holding period: earnings can be withdrawn only after N days (default 7), your refund window. Minimum payout default KES 1000.
- Payouts are AUTOMATIC since version 10 (see section 22): tipsters withdraw from their wallet, once every 14 days, and the money is sent at once.
  For a refund or chargeback use "Reverse" on that sale so the tipster is not paid for it. Check the tipster's balance before paying.
- Ratings: 1-5 stars, one per member per tipster, only members who bought a plan. Hide abusive reviews in Admin > Reviews.
- Tipster tips never count in the site's own win rate, performance, results or VIP stats. An admin (or auto-results) settles them in Admin > Tips.
  Suspended tipsters have their plans switched off; members who already paid keep access until it ends.
- Staff roles (Admin > Staff): admin (everything), editor (tips, fixtures, accas, blog, social), support (chat, managed accounts, messages, members
  view, reviews), finance (payments, subscriptions, coupons, currencies, payouts, members view). Add staff by the email of an existing member with a
  confirmed email. You cannot change your own role and at least one admin always remains. Staff are treated as VIP so they can check VIP pages.
- Legal: you collect money on behalf of tipsters. Write tipster terms, check tax and licensing rules where you operate, and have a lawyer review.

12. COUNTRY, MOBILE NUMBER AND SMS CONFIRMATION (version 4)
UPGRADING: import database-update-8.sql once. New installs already include it in database.sql.
- The register page now asks for the country (every country in the world, with its dial code) and a mobile number. Numbers are stored in
  international form (users.mobile, digits only), the member's country in users.country. Kenyan numbers also fill the M-Pesa phone field.
- SMS confirmation is OFF until you choose a provider in includes/config-sms.php: SMS_DRIVER 'africastalking' or 'twilio' and fill the keys.
  Then use Admin > SMS test to send yourself a test message from different countries before you announce anything.
  Driver 'log' only writes the code to logs/sms.log (testing only, never on a live site).
- With SMS on: after registering the member gets a 6-digit code (valid 10 minutes, 5 wrong tries, resend after 1 minute, max 5 per hour per
  member, 3 per hour per number, 15 per hour per visitor). A confirmed number can belong to only one account.
  Unconfirmed members cannot pay (set SMS_REQUIRE_FOR_PURCHASE to false to allow it) and cannot claim the free trial. Members who joined
  before this update have no number and are not blocked; they can add one in Account > Profile.
- Costs and coverage: every SMS costs money, and some countries need a registered sender ID or block SMS from some providers.
  Test the countries your visitors come from. If SMS fails for a country, members there stay unconfirmed. Watch your provider balance.

13. TIPSTER LEADERBOARD AND CONTRIBUTORS (version 4)
UPGRADING: import database-update-9.sql once. New installs already include it in database.sql.
- Leaderboard (leaderboard.php, linked from the Tipsters page and the footer): ranks active tipsters by profit in units, win rate or member rating,
  for 30 days, 90 days or all time. Only tipsters with enough settled tips are listed (5 in a period, 10 all time). Results come from the site's
  own records, and removed tips still count.
- Contributors (optional): in Admin > Tipsters click "Make contributor" on an approved tipster. A contributor sees an extra box on their tips page
  to post a tip UNDER YOUR PLATFORM. Those tips wait in Admin > Contributor tips; when you approve one it goes live in the site's own feed with
  "Analysis by <name>" (the posting time is the approval time) and counts in the site's record. Free or VIP, your choice of what to approve.
- Contributor share: Admin > Tipsters > "Contributor pool". Set a percent of each VIP sale (0 = off). Each sale puts that percent into a pool that is
  split between active contributors in proportion to the approved VIP tips each posted in the last 30 days. It is added to their earnings and paid out
  like any other (holding days, payout request, you pay by hand). Use "Reverse" in Tipster payouts for refunds.
  Decide the percent carefully: it comes out of your VIP revenue. It does not apply to tipster marketplace plans (those use the commission instead).

14. TIPSTER REGISTRATION, PICTURES AND HOME PAGE (version 4)
- Two ways to register, on the same page (tabs): "I want to get tips" (auth/register.php) and "I am a tipster" (auth/register-tipster.php).
  The tipster form creates the login AND the tipster profile (pending) in one go; you approve it in Admin > Tipsters. Existing members can still
  apply at tipster/apply.php. Footer and Tipsters page link to the tipster registration.
- Profile pictures are UPLOADED (JPG, PNG or WebP up to 2 MB). They are cropped to a square 400 x 400 and re-saved as JPEG, which also removes
  anything hidden in the file. Needs PHP GD and fileinfo, and the folder uploads/tipsters must be writable. Tipsters can change or remove the picture in
  Tipster area > Profile.
- The home page has a Tipsters section: the best-rated tipsters with stars, win rate, subscribers and starting price. "View performance" opens
  the tipster's page (record, recent tips, reviews) and "Choose" jumps to their plans.
- If a page like tipsters.php shows Apache's own "Not Found", the file is not in your folder: upload the WHOLE zip again, overwrite every file,
  and run check.php: it now lists any missing file.

15. TIPSTER-ONLY ACCOUNTS AND PLAN DESIGN (version 4)
UPGRADING: import database-update-10.sql once. New installs already include it in database.sql.
- Someone who registers on the "I am a tipster" tab is a tipster-only account. After logging in they ALWAYS land on their tipster dashboard and
  cannot open the member site (home, tips, VIP, tipster directory...). They can open only: the tipster area (dashboard, tips, plans, earnings,
  profile), their own public tipster page, Messages, Account settings, log out, phone/email confirmation and the legal pages. The menu is
  replaced by a tipster menu. Staff (admin, editor, support, finance) are never restricted.
- Existing members who apply later through tipster/apply.php stay normal members and can use both sides.
- Tipster plans are now shown as plan cards (price, length, per-day cost, checklist, "Best value" ribbon). Tipsters see their own plans as cards
  and a live "you earn" calculator while creating one.

16. VERSION 5: GAME, LIVE, COMMUNITY, ALERTS, VERIFIED RECORDS
UPGRADING: import database-update-11.sql once (phpMyAdmin > SQL). New installs already include it in database.sql (Part 18). Upload ALL files, then open check.php.
Cron jobs to add (cPanel > Cron Jobs):
  every 5 minutes   php /home/YOURUSER/public_html/cron/send-alerts.php      (kickoff and VIP-ending alerts, delivers push/email/SMS)
  every 2 minutes   php /home/YOURUSER/public_html/cron/live-scores.php      (OPTIONAL, live scores from API-Football)
  once a day        php /home/YOURUSER/public_html/cron/game-prizes.php      (free VIP days for the game's weekly/monthly winners)
  every 30 minutes  php /home/YOURUSER/public_html/cron/auto-results.php     (existing: now also finishes game matches)
- FREE PREDICTION GAME (game.php): members pick 1/X/2 for football matches in the next 72 hours (changeable until 1 minute before kickoff). A correct pick
  earns points (Admin > Prediction game, default 3). Leaderboards: weekly (Mon-Sun), monthly and all time, with shortened names ("John S.").
  Admin > Prediction game: enter a final score (or void a postponed match), set points, minimum settled picks to qualify (default 5) and prizes in VIP days
  (default weekly 3,2,1 and monthly 10,5,3). Prizes use the "bonus" plan, are given once per period (safe to press twice) and come out of your VIP
  income. No money is staked by players. Check the rules for prize games where you operate.
- STREAKS AND BADGES (Account > Rewards): visiting on consecutive days builds a streak (7 and 30 days give badges and bonus points). Other badges: first
  pick, 10 settled picks, first review, first follow, first comment, Ambassador (a referred friend became VIP), weekly top 3. Bonus points go on the game board.
- LIVE MATCH CENTRE (live.php and the live panel on every match page): scores, minute and events refresh every ~20 seconds without reloading, with the tips on
  each match. Locked premium picks are never sent to the browser. Two ways to feed it: (a) by hand in Admin > Live scores, always available; (b) automatic:
  put RESULTS_API_KEY (API-Football) in includes/config-results.php, link matches to fixture numbers in Admin > Match fixtures, tick "Fetch live scores
  automatically" in Admin > Live scores and add the 2-minute cron. Live updates use many requests: a free API plan (about 100 a day) is not enough for a busy day.
  A "Full time" score also settles the game. Betting tips are still settled in Tips (or by auto-results).
- COMMENTS: logged-in members with a confirmed email can comment on tips, match pages and tipster pages (500 characters, no links, 5 per 10 minutes, one level
  of replies, likes, report). Three reports hide a comment until staff review it in Admin > Comments (hide, restore, delete, stop a member commenting).
  Staff comments show a "Staff" label. Names are shortened. Moderate regularly: you are responsible for what is posted.
- FOLLOW AND ALERTS: "Follow for alerts" on a tipster page. Followers and current subscribers get an alert when the tipster posts a tip (the message never shows a
  premium pick). Account > Alerts: choose browser push, email and SMS (SMS only with a confirmed mobile, maximum 3 a day, costs you money), and which events:
  kickoff in about an hour (for matches picked in the game, tracked bets or followed tipsters), results (tips tracked or followed), VIP ending within 24 hours.
  Everything is always visible in Notifications too. Nothing is sent twice.
- BROWSER PUSH: Admin > Push notifications > Create keys (admin only). Needs the site on HTTPS, PHP openssl + curl. Members turn it on per device in Account > Alerts.
  On iPhone the site must be added to the Home Screen first. No third-party service or library is used. Use "Send me a test push" to check it.
- VERIFIED RESULTS (tipster-record.php?slug=...): every tip a tipster ever posted, posting time, kickoff, odds and result, removed tips marked "Removed
  before kickoff", monthly profit chart (units), last 10, and a stake calculator. Results are settled by the site, not the tipster. "Verified record" badge =
  at least 20 settled tips and 30 days on the platform. Shown on the tipster page and in a summary there.
- TIPSTER FREE TRIAL: tipsters set 0-3 trial days (Tipster area > My plans). A member can start it once per tipster (confirmed email; confirmed mobile if SMS is on;
  the same mobile cannot take it twice; not for people who already subscribed). The trial shows premium tips and earns the tipster nothing.
- MONEY-BACK GUARANTEE: tipsters can opt in. A subscriber can claim after the paid plan ends (within 7 days) if the tipster's premium tips in that period have at least
  5 settled tips and lost at least the limit (Admin setting tipster_guarantee_units, default 5 units). Claims appear in Admin > Money-back claims; "Approve" reverses the
  tipster's earning for that payment and tells the member, but YOU must send the refund by hand. Make the terms clear to tipsters before they opt in.
- REFERRALS: Account > Referrals is now a dashboard (link, WhatsApp/Telegram/X/copy, friends joined and paid, VIP days earned, rank, list of friends with shortened
  names). Public page referral-leaderboard.php ranks members by friends who became VIP (this month / all time). The reward is still the setting "referral_reward_days".
- FRENCH/ARABIC: menu items are translated; the new pages are shown in English with the usual notice. Have a native speaker review translations.

17. VERSION 6: FREE STATISTICS, ODDS COMPARISON, TRUST, LIMITS, ANALYTICS, SWAHILI
UPGRADING: import database-update-12.sql once (phpMyAdmin > SQL). New installs already include it in database.sql (Part 19). Upload ALL files (including the new
cache/ folder, make it writable), then open check.php. Everything in this version is OPTIONAL and stays hidden or inactive until you add the key or switch it on.
Free keys (put them in includes/config-data.php, never in a public place):
  a) FOOTBALL_DATA_KEY - register free at football-data.org. Free plan: 12 competitions (Premier League, La Liga, Serie A, Bundesliga, Ligue 1, Champions League,
     Championship, Eredivisie, Primeira Liga, Brasileirao, Euros, World Cup), 10 requests a minute, scores can be delayed. Powers: football.php (leagues),
     league-table.php (tables, fixtures, results), team.php, fixture.php (auto preview, form, head to head), predictions.php, and a "Statistical estimate" box on match pages
     when one of your matches matches a fixture. Cron every 3 hours: php cron/sync-stats.php
  b) ODDS_API_KEY - register free at the-odds-api.com. Free plan: 500 credits a month. One league fetched once = 1 credit, so 4 leagues x 4 syncs a day = 480.
     Powers odds.php (best prices), odds-event.php (all bookmakers + movement chart) and an odds table on matching match pages. Cron every 6 hours: php cron/sync-odds.php
     The job stops by itself when credits left fall under the guard in Admin > Data sources. Bookmakers whose name matches one of YOUR affiliate bookmakers
     (Admin > Bookmakers) get a "Bet" button through go.php so clicks are counted.
  Admin > Data sources: key status, switch on/off, choose competitions and leagues, test buttons (check that your keys work), manual sync, clear cache, credits left.
  Check each provider's terms (free plans can limit commercial use and may ask for a credit) before launch.
- THE MODEL IS AN ESTIMATE, NOT A TIP. It uses goals scored and conceded this season (Poisson). It knows nothing about injuries or line-ups. Every page says so.
- AUDIT LOG (audit.php): every tip posted or settled (by admin, bulk import, tipster, or auto-results) adds a row with a SHA-256 hash of the previous row. If a row is
  edited or deleted afterwards the check on that page fails. Premium picks are hidden there until settled. Tips posted before this update are not in the log.
  Tip: copy the latest fingerprint to your Telegram or X now and then, so nobody can rewrite the whole chain.
- MULTI-ACCOUNT SIGNALS (Admin > Multi-account signals): IP + browser fingerprint per login, kept 90 days. Same IP AND same browser = "strong". Strong matches automatically
  withhold referral rewards and block a second free trial. Shared Wi-Fi can cause false matches, so nothing else is automatic. The privacy page mentions it.
- RESPONSIBLE GAMBLING (Account > Limits): monthly spending limit (lowering is instant, raising or removing takes 24 hours), self-exclusion for 1, 3, 6 months or forever
  (cannot be shortened), enforced on all five payment methods. Excluded members get no marketing emails. Registration already stores the 18+ confirmation.
  Admin > Data sources: "Hide odds in these countries" (needs Cloudflare's CF-IPCountry header or the member's country). These are self-service tools, not identity or
  age verification and not legal compliance: you are responsible for gambling and affiliate laws in every country you target.
- ANALYTICS (Admin > Analytics): sales per day (plan value in KES, an estimate), signups, signup-to-paid rate, active VIPs, lapsed rate, top plans, payment methods, tipster
  sales, game use, bookmaker clicks.
- EMAILS (cron once a day: php cron/lifecycle.php): welcome email 1 day after signup, win-back email 3-10 days after a plan ended (once each). Also deletes old fingerprints and cache.
- M-PESA B2C TIPSTER PAYOUTS (optional, needs Safaricom approval): get a B2C shortcode, API operator and the public certificate from the Daraja portal. Fill B2C_* in
  includes/config-data.php, tick the box in Admin > Data sources, then Admin > Tipster payouts shows "Pay via M-Pesa now". Callback address is payments/b2c-result.php
  (the secret key is added automatically). The payout is locked as "processing" before it is sent so it cannot be sent twice. TEST IN THE SANDBOX FIRST (MPESA_ENV).
  Without B2C you keep paying by hand as before.
- KISWAHILI: language switcher now has Kiswahili (lang/sw.txt). The new pages are English-only with the usual notice. Have a native speaker review the translations.
- NOT INCLUDED (no free source or out of scope): native mobile app, news feed, A/B price testing, direct Airtel/MTN payouts, automatic subscription renewal.
- AUTOMATIC LANGUAGE (new visitors): on the first visit the site picks French, Arabic or Kiswahili from the browser's language list (English wins if the browser prefers it);
  if the browser gives no supported language, it falls back to the visitor's country (needs Cloudflare's CF-IPCountry header; list in lang_country_map() in includes/prefs.php:
  France, Senegal, Cote d'Ivoire, Cameroon, DR Congo... = French; Gulf and Arab countries = Arabic). The choice is saved in the language cookie, so the switcher always wins afterwards.
  Search-engine crawlers, admin pages, payment callbacks and APIs are never switched. Turn it off in Admin > Data sources > Language.

18. VERSION 6 CHANGES
  - Cash out: on each accumulator page, next to "Your stake", a Cash out button shows the estimated current cash-out value
    (stake x odds of won legs x (1 - margin)). It is an estimate only. Margin is set in Admin > Settings > Accumulator cash out (default 6%).
  - VIP accumulators now live inside the VIP area (VIP plans | VIP Accumulator tabs) and are visible only to Weekly plans and above
    (setting vip_acca_min_days, default 7). Settled tickets remain public as proof of record.
  - database-update-12.sql: column utc_date renamed to kick_utc (MariaDB reserved-word error fixed). The file is safe to re-run.
  - assets/css/polish.css: smaller fonts and navbar, compact footer, dark-mode colour fixes.
  - API keys for football-data.org and The Odds API are set in includes/config-data.php. Do not share that file publicly.
    Odds are fetched for 4 leagues, 4 times a day, to stay inside the 500 free credits a month.

19. VERSION 7: NEW PAYMENT METHODS AND CURRENCIES
  New methods: Stripe (cards), MTN MoMo and Airtel Money. Each one only appears at checkout when its keys are filled in
  AND it is switched on in Admin > Payment methods. M-Pesa, PayPal, Paystack and Razorpay keep working as before.
  a) Run database-update-13.sql once (phpMyAdmin > SQL). It allows the new payment types and adds the currencies Euro, Pound,
     Tanzanian shilling and Ugandan shilling (placeholder rates: press Admin > Currencies > "Update rates now").
  b) Keys go in includes/config-wallets.php (keep it private):
     - Stripe: STRIPE_SECRET_KEY (sk_test_... then sk_live_...). Card payments can be charged in USD, EUR or GBP (the customer picks).
       Add a webhook endpoint https://YOURSITE/payments/stripe-webhook.php for checkout.session.completed and
       checkout.session.async_payment_succeeded, and paste its signing secret in STRIPE_WEBHOOK_SECRET.
     - MTN MoMo (momodeveloper.mtn.com, Collections product): MTN_MOMO_SUBSCRIPTION_KEY, MTN_MOMO_API_USER, MTN_MOMO_API_KEY.
       MTN_MOMO_ENV is 'sandbox' while testing (the sandbox only accepts EUR); for live use put the target environment MTN gave you
       (for example 'mtnuganda') and set MTN_MOMO_CURRENCY (UGX, GHS, RWF...) and MTN_MOMO_MSISDN_PREFIX (256, 233, 250...).
     - Airtel Money (developers.airtel.africa, Collections): AIRTEL_CLIENT_ID, AIRTEL_CLIENT_SECRET, AIRTEL_ENV ('sandbox' or 'production'),
       AIRTEL_COUNTRY (KE, TZ, UG...), AIRTEL_CURRENCY (KES, TZS, UGX...) and AIRTEL_MSISDN_PREFIX (254, 255, 256...).
     The currency used by MTN/Airtel must exist and be active in Admin > Currencies (Euro, TZS and UGX were added by update-13).
  c) Cron job every 5 minutes (settles payments when the customer closed the page, and closes unapproved mobile-money prompts after 30 minutes):
        php /home/YOURUSER/public_html/cron/check-payments.php
  d) Company-coloured buttons: assets/css/pay.css holds each company's colours (edit the --pb-bg / --pb-fg lines to change one).
     Official logos are trademarks and are NOT bundled. If you are allowed to use them, upload a file named after the method into
     assets/img/pay/ (mpesa, airtel, mtn, stripe, paypal, paystack, razorpay with .svg, .png or .webp) and it replaces the letter badge automatically.
  e) Charged amounts: Stripe in USD (the plan's USD price), EUR or GBP (converted from the KES price at the Admin > Currencies rate, rounded up to 0.50);
     Airtel/MTN in their local currency (converted, rounded up to 100 for shillings). Rates are only as fresh as your last rate update.
  f) Always test in each provider's sandbox/test mode first, using the provider's own documentation for test numbers and cards. The
     integration was written against the providers' published API descriptions; confirm it against their current documentation when you go live.

20. VERSION 8: PROFILE PICTURES AND FLAGS
  - Every member can upload a profile picture (Account > Profile > "Profile picture and flag"): JPG, PNG or WebP up to 2 MB, cropped square and
    re-drawn by the server as a 400 x 400 JPEG. Members can also choose their nationality; its flag appears next to their name, and they can switch it off.
    If no nationality is chosen, the flag of the country of their mobile number is used.
  - Pictures and flags show in the header menu, the dashboard, comments, the prediction-game ranking, tipster reviews and Admin > Members.
  - Run database-update-14.sql once. Make the folder uploads/avatars writable (755, or 775). Until the update is imported the site works normally
    and shows initials with no flags.
  - Flags are loaded from the free flagcdn.com service. To host them yourself, upload files named like assets/flags/ke.svg (or .png), lower-case two-letter
    country code, and they are used instead.
  - Moderation: an administrator can remove a member's picture in Admin > Members ("Remove picture").

21. VERSION 9: ACCOUNT MANAGEMENT FOR CLIENTS AND TIPSTERS, BLOG PICTURES AND VIDEOS
  Run database-update-15.sql once. Make the folder uploads/blog writable (755, or 775).
  Account management (before: only the admin could see the enquiries; the form was not linked anywhere on the site):
  - The page account-management.php is now linked in the footer, on the Tipsters page, on every tipster who accepts requests, and as a tab
    "Account management" in My account and in the tipster area.
  - The client chooses who handles the request: the SlipKing team (as before) or any tipster who accepts requests. A consent tick is required for sharing details
    with the tipster.
  - Tipsters switch it on in Tipster area > Profile ("I take account-management requests") and can write their terms (shown on their public profile).
    They get requests in Tipster area > Account management: client details, status (contacted / accepted / declined / closed) and private notes.
  - Client and tipster (and our staff) talk in an on-site conversation on each request; both sides get a notification for new messages. Messages that
    look like passwords, PINs or codes are refused. Admin > Account management shows who handles each request, and staff can read and join any conversation.
  - The site never holds betting money and is not a party to the agreement. Make sure your Terms say this, and take legal advice for your country.
  Blog: Admin > Blog > the article editor now has buttons above the text box:
  - Upload picture or video: JPG/PNG/WebP up to 6 MB (re-drawn, max 1600 px wide) and MP4/WebM up to 60 MB (also limited by your hosting's upload_max_filesize;
    for long videos use YouTube or Vimeo). The code is inserted for you, for example  [img:uploads/blog/b-....jpg|Caption]  or  [video:uploads/blog/b-....mp4|Caption].
  - YouTube / Vimeo / video link button: pastes [video:https://youtu.be/...|Caption] (shown as an embedded player; YouTube uses the privacy-friendly domain).
  - Also: headings (## Heading), **bold**, [link text](https://...) and lists (- item). The Preview button shows the article as visitors see it.
  - The cover picture can now be uploaded as well as linked. Old articles keep working unchanged.
  - Unused uploads stay in uploads/blog until you delete them over FTP.

22. VERSION 10: TIPSTER WALLET AND AUTOMATIC WITHDRAWALS (replaces the old manual payouts)
  Run database-update-16.sql once.
  How it works now:
  - Every subscription sale credits the tipster's wallet (Tipster area > Wallet, the old "Earnings" tab). The money is held for the holding period
    (default 7 days, your refund window) and then becomes available.
  - The tipster presses Withdraw and the whole available balance is sent AT ONCE, automatically, to the M-Pesa number or PayPal email in their profile.
    Nobody has to pay by hand.
  - A tipster can withdraw only ONCE EVERY 14 DAYS (Admin > Tipsters > "Days between withdrawals" if you ever want to change it). The wallet page shows the
    date the next withdrawal opens. A withdrawal that fails does not count: the money stays in the wallet and they can try again at once.
  - The payout is saved and locked as "processing" before any money is sent, so a double click can never pay twice.
  What you must set up (automatic sending needs these; until then tipsters see "not switched on yet" and cannot withdraw):
  a) M-Pesa: the B2C setup in section 17 (Safaricom Daraja go-live, B2C_* values in includes/config-data.php, tick "M-Pesa B2C" in Admin > Data sources).
     Safaricom reports the final result to payments/b2c-result.php; a failure returns the money to the tipster and notifies them.
  b) PayPal: ask PayPal to enable "Payouts" on your business account, then tick "PayPal Payouts is enabled" in Admin > Tipsters. Payouts are sent in US dollars
     (KES converted at the Admin > Currencies rate, rounded down to the cent). Add this cron job every 15 minutes to confirm them:
        php /home/YOURUSER/public_html/cron/check-payouts.php
  c) Admin > Tipsters has the switches: automatic payment on/off, PayPal Payouts, and an optional stop-gap "queue withdrawals for me to pay by hand" (off by default).
  Admin > Tipster payouts now only shows withdrawals in progress and exceptions (check a PayPal payout, reverse a refunded sale).
  Test in the sandbox first: MPESA_ENV=sandbox for B2C and PAYPAL_ENV=sandbox for PayPal.


23. UPDATE 17 (Airtel withdrawals, country picker, pictures, VIP odds, forms)
-----------------------------------------------------------------------------
Import database-update-17.sql once (phpMyAdmin > Import). It lets tipsters choose Airtel Money.
a) AIRTEL WITHDRAWALS: tipsters can now pick Airtel Money as their payout method (Register, Apply, Profile). To pay out by Airtel:
   - Ask Airtel Money to enable "Disbursements" on your merchant account (the same Airtel Open API keys you use for collections).
   - Put the PIN of the disbursement wallet in includes/config-wallets.php (AIRTEL_DISBURSE_PIN). It is encrypted with Airtel's public key and never shown.
   - Admin > Tipsters: tick "Airtel Money withdrawals are enabled".
   - The cron job cron/check-payouts.php (every 15 minutes) now also settles Airtel withdrawals.
   Amounts are paid in the Airtel country's currency (AIRTEL_COUNTRY / AIRTEL_CURRENCY) at your Admin > Currencies rate. Tipsters type the number with the country code.
b) COUNTRY: every registration/profile form now has a searchable list of all countries with flags (assets/js/country-picker.js). The flag appears at the top of the page next to the profile picture after login.
   Flags load from flagcdn.com; to host them yourself drop xx.svg files into assets/flags/.
c) PICTURES: uploads work with or without PHP GD / fileinfo. With GD the picture is re-drawn 400x400; without it the checked file is stored as it is.
d) TIPSTERS can open the normal site: "Main site" in their menu. (VIP pages, the game, the member account area and payments stay closed to them.)
e) VIP ODDS: locked VIP accumulators no longer show total odds, minimum stake or chances; locked tip cards use a solid cover.
f) FIXED: the payout-details check on the tipster forms was inverted (valid numbers were rejected); now correct.
g) Forms, login/registration card and all drop-downs restyled (assets/css/forms.css).


24. UPDATE 18 (job portal, tipster joining fee, mandatory tipster photo)
-----------------------------------------------------------------------
Import database-update-18.sql once (phpMyAdmin > Import).
a) JOB APPLICATIONS: a hidden page at yourdomain.com/work-with-us.php. It is not linked anywhere on the site, not in the sitemap, and marked noindex.
   Send the link to the people you want to apply. The form has 8 sections (job, personal details, education and experience, job questions, online profiles,
   references, documents, declaration) and asks for: passport photo, ID/passport scan, CV (required), certificates and proof of work (optional).
   Admin > Job applications (admins only): list, search, filter, status (New, Reviewing, Shortlisted, Interview, Hired, Not selected), private notes,
   CSV download, delete, and the link with an Open/Close button. New applications also email SITE_EMAIL, and the applicant gets a confirmation email.
   Documents are saved in private-uploads/jobs/ (the web server refuses direct access) and open only through the admin page.
   Make sure private-uploads/jobs is writable, and that your PHP upload limits allow about 20 MB (upload_max_filesize and post_max_size).
b) TIPSTER JOINING FEE: KES 100 for Kenyans and USD 5 for everyone else (changeable). It is OFF until you press "Activate joining fee" in Admin > Tipsters.
   While it is on: after registering, a tipster is sent to the normal checkout (M-Pesa, Airtel, MTN, cards, PayPal ...) to pay once; you cannot approve a
   tipster who has not paid (use "Waive fee" for people you trust). Switch it off any time with the same button. Kenyan = the country chosen at registration.
c) TIPSTER PHOTO IS A MUST: required when registering, applying and saving the profile; an admin cannot approve a tipster with no photo; an active tipster
   with no photo is sent to the profile page until they upload one.


25. UPDATE 19 (tipster profiles, community, arena, slip studio)
-----------------------------------------------------------------------
Import database-update-19.sql once (phpMyAdmin > Import). The new pages also create their own tables if you forget.
a) VIP LOCKED CARDS: the market name is hidden too ("Hidden market"); the cover is now almost opaque.
b) ADMIN BOOKMAKERS: the old message mentioned a file "B-sql.sql" that never existed. The bookmaker, guarantee and tip-odds
   tables now install themselves; if they cannot, the page says to import database-update-6.sql.
c) The country flag no longer shows in the navbar next to "My account" (it still shows on profiles). Dashboard tabs are smaller.
d) TIPSTER PUBLIC PROFILE (tipster.php and tipster-record.php): predictions published, wins, losses, pushes, voids, cancelled,
   win rate with sample size, profit in units, yield/ROI, average odds, 7/30/90/365-day performance, by league and by market,
   the full timestamped history (posted, kickoff, settled) and a record of edited or cancelled tips.
   Admin can now settle a tip as Push (P button) as well as Won, Lost and Void. Edits by admin and removals by tipster or admin
   are written to the public "Edited and cancelled tips" table.
e) MATCH DISCUSSION: every match page has a discussion room (moderated comments and replies, as before) and a link list at
   discuss.php. COMMUNITY POLL: members vote 1/X/2 until kickoff; the bar shows the split; after the match finishes the page
   compares the community favourite with the real result (needs the live score feed so the match has a final score).
f) TIPSTER CLUBS (clubs.php, club.php): create a club, invite by link, code or email, share 1X2 selections with odds, club
   leaderboard in units. The owner chooses Private (members only) or Public record (leaderboard and settled picks visible to all).
g) FOOTBALL KNOWLEDGE ARENA (arena.php): 10 random questions, 15 seconds each, scored on the server, seasonal leaderboard
   (season runs August to July). 62 starter questions; add or disable more in Admin > Arena questions.
h) SLIP BUILDER STUDIO (slips.php, slip.php): add matches, outcomes and odds by hand, remove rows, live total odds, save,
   duplicate, edit, download PDF.

27. UPDATE 21 (navbar bell, account button)
-----------------------------------------------------------------------
a) NOTIFICATION BELL in the top bar for every logged-in member and tipster: red badge with the unread count (9+ when more),
   a dropdown with the latest 6 messages, and "View all messages". It reads the same messages as Account > Messages.
b) The "My account" / "Tipster area" label is gone from the top bar: only your round profile picture shows (hover shows the
   name). Inside the phone menu the text label stays so the link is clear.

28. UPDATE 22 (design refresh: crown logo, search, account chip, dark by default)
-----------------------------------------------------------------------
a) CROWN LOGO: a gold crown before "SlipKing" in the top bar and footer, plus a crown browser-tab icon.
b) SEARCH BOX in the top bar (icon that opens on click on smaller screens, inside the phone menu on phones): search.php finds
   matches, leagues and tipsters.
c) ACCOUNT CHIP: picture, first name and status (Premium / Member / Tipster) with a small menu (Dashboard, Messages, Profile, Log out).
d) The visual refresh stylesheet (modern.css) was removed on request. The original look and colours are back; only the top-bar
   extras (crown, search, bell, account chip) remain, styled in assets/css/nav-extras.css with the original colours.


29. UPDATE 23 (tipster profile page, photo upload fix, discussion rooms)
-----------------------------------------------------------------------
Import database-update-19.sql again if you already imported it (it is safe to repeat): it adds the comment_votes table.
a) TIPSTER PROFILE PAGE (tipster/profile.php) redesigned: header card with the photo and status, a separate photo box,
   cards for the public profile, account management and payout details, and a save bar.
b) PHOTO UPLOAD FIX. Cause: when any other field had an error, the form threw the new photo away and asked for it again.
   Now (1) on the profile page the photo uploads on its own the moment you choose it, and (2) on the register and apply forms the
   photo is kept after the first upload, so a mistake in another field never makes anyone choose it again.
c) MATCH DISCUSSION ROOM (room.php?id=...), a Reddit-style page for every match: composer, Best/New/Top/Oldest sorting,
   threads up to 5 levels deep with up and down votes, collapse lines, Reply, Share (copies the link), Report, Delete,
   staff and tipster flairs, sidebar with match facts, the community poll, room rules and top contributors.
   Moderation is unchanged (3 reports hide a comment; Admin > Comments). The match page now shows a card that opens the room,
   and discuss.php lists every room.

30. UPDATE 24 (slip builder, messages, chat widget look)
 - Slip Builder (slips.php, slip.php): new assets/css/slips.css. Saved slips are cards with a preview and total odds; the builder has numbered selection cards, quick-outcome chips and a sticky ticket with total odds, stake and return.
 - Account > Messages: inbox cards with an icon, type tag, relative time and unread marker.
 - Chat with us: new header with avatar and online dot, bubbles with avatars, round send button. Admin chat bubbles restyled in admin.css. New file assets/css/messages.css. No database changes.

31. UPDATE 25 (navbar, communities, pictures in posts, photos in the database, flags)
 - IMPORT database-update-20.sql once in phpMyAdmin (the site also creates the tables itself the first time it needs them).
 - Navbar: Today's tips has a drop-down with Game; Results has a drop-down with Live scores; new link Discussion (communities, match rooms and polls).
 - Photos and post pictures are now stored IN THE DATABASE (table media_blobs, shown through media.php), so they survive hosts that wipe the disk. Tipster and member photos use this; old uploads/ photos keep working. Tipsters can change their photo any time on Profile.
 - Posts and replies (match rooms and communities) can include one picture (JPG/PNG/WebP, max 4 MB) and emojis (emoji button).
 - Admin > Communities (m/): create topic communities such as m/Arsenal fans with icon, colour, description and rules. Members post with a title, text and picture at m.php?s=slug; threads open at post.php.
 - Tipster pages, cards, leaderboard and Profile show the flag of the tipster's country. Profile page forms restyled (live preview, payout method tiles).
 - If pictures fail: your PHP upload_max_filesize / post_max_size must be at least 4M, and MySQL max_allowed_packet at least 4M.
 - If a picture in a post shows "Picture could not be loaded": open yoursite/media.php?id=1 directly. It must show the picture. Run check.php: it lists whether media_blobs and the comments.image column exist.
 - Pictures are shrunk in the browser before upload (max 1400 px, JPEG), so big phone photos work even when the host's upload limit is 2 MB. If the server still refuses an upload, a message names the limit.
 - The top-bar search box was removed (search.php still exists and can be reached by address if ever needed).
 - Today's tips page: the Yesterday/Today/Tomorrow tabs stay, and a "Choose date" button drops down a calendar covering two weeks back to two weeks ahead (each day shows how many tips it has). Old ?day=yesterday and ?day=tomorrow links still work. Sport and Accumulator filters keep the chosen date.

32. PUBLIC MEMBER PROFILES AND JACKPOT (import database-update-21.sql)
 - Member profiles: Account > Public profile lets a member choose a @username, bio, and whether to show stakes. Bets in the private tracker can be shared one by one (or all at once). Public page: member.php?u=handle; ranking: members.php (Top members). Records are self-reported and the page says so. Members can follow each other and get notified when a followed member shares a bet.
 - Jackpot: Admin > Jackpot. Create a round with 12 games, set the prize (default KES 50,000) and change it any time before settling. Optional prize for 11 correct (default 0). Members enter free on jackpot.php, one entry each, picks editable until the first kick-off (or the closing time you set). When all results are entered, press Settle: members with all 12 correct share the prize equally and are notified. You pay winners yourself and tick Paid with the M-Pesa reference.
 - Check your local rules on prize competitions/gambling licensing before running paid-out jackpots.

33. DISCUSSION HUB REDESIGN (import database-update-22.sql)
 - discuss.php now has a left menu (Your feed, Groups, Match rooms and polls, Top members, Slips), a Top groups row (top 3 by members), a searchable All groups grid with Join / Joined buttons, and "Groups you've joined".
 - Members join or leave a group from the hub or the group page. "Your feed" shows latest posts from joined groups.
 - Admin > Communities: upload a cover picture per group (shown on the cards; without one the group colour and icon are used).

34. GROUP PAGE + GLOBAL CHAT (import database-update-23.sql)
 - m.php: dark group page with banner (cover photo), tabs (Posts, Members, Rules, About), post box with photo and emoji, social post cards (Like, Comment, Share), Group Info, Members, Recent Media, Rules and Popular Topics. Admin > Communities has new fields: cover picture and tags.
 - Global Chat: globalchat.php (menu: Community > Global Chat, and the Discussion drop-down). Members chat live (messages refresh every few seconds); needs a logged-in, email-confirmed account; no links; staff can delete messages with the x button.

35. GROUP PAGE + CHAT UPDATES
 - Comments and likes now work right on the group page (the Comment button opens the comments under the post). Posts no longer open the old Reddit-style page.
 - The group name bug (showing "Ugandan shilling") came from the currency menu in header.php reusing the variable name $c. Fixed.
 - The group's picture (set in Admin > Communities, or changed on the group page with the camera button by the group's creator or an admin) now shows as the round group picture everywhere.
 - Share buttons open the phone's share sheet (WhatsApp, Telegram, ...). On a computer a menu with WhatsApp, Telegram, Facebook, X, Email, SMS and Copy link appears.
 - Global Chat: emoji picker, picture messages (JPG/PNG/WebP), and documents (PDF, Word, Excel, PowerPoint, text, up to 5 MB). Re-import database-update-23.sql or let the site add the columns itself.

36. COMMUNITY UPDATE (import database-update-24.sql, or let the site add it itself)
 Group page (m.php): Report, Delete and Edit (own) in the "..." menu; moderators also get Pin and Remove. Likes and notifications (likes, reactions, comments, replies and @mentions create notifications). Reactions (heart, laugh, fire, wow, sad). Nested replies (up to 4 levels). Polls (2-4 options, 7 days). Attach one of your bet slips to a post. Match threads: in Admin > Communities fill "Team names" and a pinned thread appears by itself before the team's games. Weekly challenge: points from the free prediction game earned by group members this week. Group Chat tab for members. Online counts (people active in the last 5 minutes).
 Admin > Communities: team names, moderators (by email). Admin > Chat reports: reported chat messages (delete, dismiss, ban).
 Global Chat and group chat: reply to a message, "is typing", report, mute (on that device), online count.
 Daily digest: members switch it on at Account > Daily digest. Add a daily cron job: php /home/YOURUSER/public_html/cron/digest.php . WhatsApp: the member taps a button that opens WhatsApp with the digest text; automatic WhatsApp sending needs a paid provider and is not included.

37. COMMUNITY UPDATE 2 (import database-update-25.sql, or let the site add it itself)
 Follow members: members with a public profile show Follow next to their name in groups. Discussion > Following lists their group posts and shared bets.
 Messages (Discussion > Messages, or "Send a message" in a post's menu): private chat between members, with Block and Report. Reports go to Admin > Community stats. Links are not allowed in messages.
 Private groups: Admin > Communities > tick "Private group". New members are sent as requests; moderators approve or decline on the group page. Posts of private groups are hidden from non-members, feeds and search.
 Group events: moderators add events (name, time) on the group page; members tap "I am going"; members get an alert.
 Hashtags and search: #tags in posts become links; Discussion > Search & #tags shows trending tags and searches posts, groups and members.
 Levels: Rookie, Regular, Pro, Expert, Legend, from posts, replies and likes received. "Top contributor" tag = most active 3 members of the group this month.
 Verified: a tick next to the name. Automatic when a member has at least 30 settled shared bets with positive ROI (change the minimum in Admin > Community stats). Admin can verify, remove or block a member. The automatic check runs with the daily cron (php cron/digest.php) or from the button on that page.
 Tips in groups: a verified (approved) tipster sees "Attach my tip" when posting. VIP picks stay hidden from non-VIP viewers until settled.
 Slip share link: Slip Builder > "Share link" gives a public page with a preview card for WhatsApp/Facebook. The page links to sign-up with the slip owner's referral code, so the existing referral reward (VIP days when the friend pays) applies.
 Odds alerts: on any odds page tap "Alert me when the odds move". After each odds sync (cron/sync-odds.php) watchers are told when the best price moved 5% or more (at most once every 3 hours per match). Delivery follows Account > Alerts. List: Account > Odds alerts.
 Spam guard: accounts under 24 hours old cannot post links; more than 6 posts in 2 minutes is paused; members with 3 reported-and-hidden posts in a week are paused. 3 member reports still hide a post automatically.
 Alerts: replies, mentions, likes, reactions, new followers, new messages, join requests and events now also go out as browser push and e-mail when the member turned those on in Account > Alerts. (Push needs cron/send-alerts.php as before.)
 Admin > Community stats: groups, posts, active members, top groups, most reported members, reported messages, verified members.
 Swahili: Discussion, group, messages and search pages now translate to Kiswahili (lang/sw.txt). Please have a native speaker review the new lines at the bottom of that file.
 Already on the site and unchanged: Bet tracker (Account > Bet tracker), Kelly/stake calculator, light/dark toggle (moon icon in the header), referral rewards and installable app with browser push.

38. ANDROID APP DOWNLOAD (import database-update-26.sql, or let the site add it itself)
 Admin > Android app: upload the .apk (up to 300 MB). It is sent in 2 MB pieces, so your host's upload limit does not matter. Enter the version, optional "needs Android" and what is new, then tick "make this the version members download".
 Members open Get the Android app (footer link, or yourdomain/download-app.php): download button, install steps for phones, release notes and the file's SHA-256 fingerprint. Downloads can resume if the connection drops. Download counts show in Admin.
 The file is stored in private-uploads/app/ (not reachable directly) and handed out by app-download.php. That folder must be writable (755 or 775). Keep disk space free for it.
 Admin can also publish a link instead (Google Drive, GitHub, other server), take the app down ("coming soon" page with how to install the website as an app), keep old versions, and add a Google Play link that shows a Play Store button.
 When you build the app: always sign it with the same key, or phones refuse updates. Increase the version for each release. Chrome shows a "this type of file can be harmful" warning for any APK outside Google Play; the install steps on the page explain it.
 Nginx servers: make sure private-uploads/ is not served (deny it). Apache is already covered by its .htaccess.

39. THE ANDROID APP (slipking-android.zip, built with Android Studio, Java)
 The app is a native Java shell around this website: splash screen, bottom tabs, More menu, pull-to-refresh, offline screen, uploads, downloads and notifications. All pages and all payment methods (M-Pesa, PayPal, Paystack, Razorpay, Stripe, Airtel Money, wallet/crypto, manual) run on the site's own pages, so nothing has to be rebuilt when you change the site. See README.md inside slipking-android.zip to set your domain, build and sign.
 Hooks on this server: the app adds "SlipKingApp" to the browser name; inside it, members stay signed in for 30 days (sessions stored in cache/sessions/, keep it writable) and the install-app and browser-push boxes are hidden. api/app-notifications.php (needs login) and api/app-version.php feed the app's alerts and update dialog. Upload each new APK in Admin > Android app with a higher version number.

40. FIREBASE PUSH AND IN-APP LOOK (import database-update-27.sql, or let the site add it itself)
 Admin > Android app > Firebase push: paste the Firebase service-account key (.json) to send instant phone notifications. Every in-app notification (tips from followed tipsters, results, replies, messages, VIP activation, Admin > Messages broadcasts) also goes to the member's phone. "Send a test to my phone" checks it. Needs php, curl and openssl (normal on hosting) and the cron job cron/send-alerts.php.
 Phones register through api/app-register-token.php (private, needs login). Remove the key and the app falls back to checking every 15 minutes.
 Inside the app the website hides its own header, footer and chat bubble and uses assets/css/app.css (dark app look). Edit that file to change how pages look in the app, no app rebuild needed.
 Staff accounts see an Admin panel section in the app's More menu; the admin pages themselves are the normal website admin.
 Optional: copy .well-known/assetlinks.json.example to .well-known/assetlinks.json with your app's signing fingerprint so Android opens your links in the app.

41. APP WIDGET, THEME AND LANGUAGE HOOKS
 api/app-widget.php (public, no login) feeds the phone widget: tips today, 30-day win rate, next matches.
 The app adds SlipKingTheme/light|dark and SlipKingLang/xx to its browser name. Inside the app pages use the matching theme (assets/css/app.css) and the site language switches to the phone's language when it is French, Arabic, Kiswahili or English (one switch per change of phone language; members can still pick another language on the site).

42. THE STANDALONE NATIVE APP API (api/v1/index.php, includes/api.php)
   The separate app "slipking-native.zip" (fully native screens, no website inside) talks to your site through api/v1/?a=<action>.
   Actions: tipsters, leaderboard, blog, config, login, register, logout, me, home, tips, tip, results, live, plans, trial, notifications, notifications_read.
   It uses the normal site login, so VIP picks stay locked on the server exactly as on the website (a locked tip never sends its pick, odds or analysis).
   Nothing to install: upload the two files and the app works. Payments open your own checkout pages inside the app. Open yoursite/api/v1/?a=config in a browser to see it answer.
   The app project is in slipking-native.zip (open the folder in Android Studio; README.md inside explains setting the site address).
   Stage 3 adds to the API: dm_list, dm_thread, dm_send, dm_delete, dm_report, dm_block, groups, group, post.
   The app also uses api/globalchat.php and community/action.php; both now answer the app (header X-SlipKing-App + app user agent) without a page token.

Stage 4 (native app): api/v1 actions accas, acca, jackpot, jackpot_pick (accumulators and free jackpot).
Stage 5 (native app): api/v1 actions subscription, payments, referrals.
Stage 6 (native app): api/v1 actions alerts, limits (GET and POST).
Stage 7 (native app): api/v1 action profile (GET; POST do=profile|emails|password).
Stage 8 (native app): api/v1 action tracker (GET st=; POST do=add|status|delete).
Stage 10 (native app): api/v1 action search.
Stage 11 (native app): api/v1 action page (p=football|table|predictions|odds|audit|performance|members|refboard|badges|oddswatch|dashboard).
Stage 12 (native app): api/v1 actions slips, slip, clubs, club, arena, game, public_profile, league.

Design overhaul (app): register now also accepts password2 (checked when sent); assets/css/app.css restyled to the gold + deep-green palette for pages shown inside the app (header.php cache v=3).

TIPSTER JOINING FEE (final rule)
- ONE-TIME payment, set by the country the tipster registered from: Kenya = KES 150, every other country = USD 1. Editable in Admin > Tipsters.
- ON by default. Import database-update-18.sql (columns) then database-update-28.sql (turns it on, sets the amounts).
- Until paid a tipster can register, open the dashboard, edit the profile and see earnings, but: cannot be approved, is hidden from the tipsters list, leaderboard, search,
  sitemap and managed-service list, their profile page is hidden from the public, their plans cannot be bought, and tips/plans/managed pages send them to pay.
- Admin can "Waive fee" for pending or active tipsters. The commented line at the end of database-update-28.sql keeps ALREADY approved tipsters working without paying.
- The Android app shows the fee on the register form and a "Pay tipster joining fee" row in Me for tipsters who still owe it.

--- Update 29 ---
* Admin control: an administrator can delete any tip (even started/settled) from Admin > Tips, and delete a whole game
  ("Delete game" in Admin > Tips and Admin > Fixtures). Tips, odds, live events and game picks on that game are removed
  and affected tipster ratings are recalculated. Every deletion is written to the activity log. Staff without admin rights
  keep the old rule (only before kickoff).
* Private messages: limit of 40 messages and 10 different people per hour (staff exempt).
* database-update-29.sql: speed-up indexes only. Run once; "Duplicate key name" means it was already there.
