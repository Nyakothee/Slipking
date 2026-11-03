<?php
/**
 * SlipKing - free data sources (all optional, all OFF until you add a key). EDIT THIS FILE and keep it private.
 *
 * 1) FOOTBALL_DATA_KEY  - free key from https://www.football-data.org/client/register  (free plan: 12 big competitions such as the
 *    Premier League, La Liga, Serie A, Bundesliga, Ligue 1, Champions League; 10 requests a minute; current season data).
 *    Feeds: league tables, fixtures and results, team pages, match previews and the statistical prediction model.
 * 2) ODDS_API_KEY       - free key from https://the-odds-api.com  (free plan: 500 requests a month, so the site fetches only a few leagues a few times a day).
 *    Feeds: odds comparison across bookmakers and the odds movement chart.
 * 3) Live scores and automatic results still use API-Football in includes/config-results.php (free plan: about 100 requests a day).
 *
 * Leave a key empty and that part of the site simply stays hidden. Check the terms of each provider (for example, free plans may
 * limit commercial use or require a credit) and show their attribution if they ask for it.
 */
if (!defined('FOOTBALL_DATA_KEY')) define('FOOTBALL_DATA_KEY', '6cac60ed85df424195bf281bb81e1378');
if (!defined('ODDS_API_KEY'))      define('ODDS_API_KEY', 'a8a7a9408caccdad06182df2ee121d61');

/**
 * M-Pesa B2C (automatic tipster payouts). Needs a Safaricom Daraja B2C-enabled shortcode (approval from Safaricom).
 * Leave B2C_INITIATOR empty to keep paying tipsters by hand. Uses the consumer key/secret and MPESA_ENV from config-payments.php.
 */
if (!defined('B2C_SHORTCODE'))        define('B2C_SHORTCODE', '');      // your B2C shortcode (PartyA)
if (!defined('B2C_INITIATOR'))        define('B2C_INITIATOR', '');      // API operator username
if (!defined('B2C_INITIATOR_PASSWORD')) define('B2C_INITIATOR_PASSWORD', '');
if (!defined('B2C_CERT_PATH'))        define('B2C_CERT_PATH', '');      // path to Safaricom's public certificate (.cer/.pem), from the Daraja portal
