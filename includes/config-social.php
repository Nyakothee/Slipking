<?php
/**
 * SlipKing - social / Telegram settings. EDIT THIS FILE and keep it private (it holds your bot token).
 * Every value may stay '' - the site simply hides what is not set up.
 *
 * Telegram: create a bot with @BotFather, add the bot as an ADMINISTRATOR of your channels (permission: post messages).
 * A channel is written as @channelname (public channel) or as the numeric id like -1001234567890 (private channel).
 */
if (!defined('TELEGRAM_BOT_TOKEN'))      define('TELEGRAM_BOT_TOKEN', '');       // e.g. 123456789:AAE...
if (!defined('TELEGRAM_FREE_CHANNEL'))   define('TELEGRAM_FREE_CHANNEL', '');    // public channel: free tips + VIP teasers (no picks)
if (!defined('TELEGRAM_VIP_CHANNEL'))    define('TELEGRAM_VIP_CHANNEL', '');     // private channel: full VIP picks
if (!defined('TELEGRAM_VIP_INVITE_LINK')) define('TELEGRAM_VIP_INVITE_LINK', ''); // https://t.me/+xxxx  (shown only to VIP members)
if (!defined('WHATSAPP_CHANNEL_LINK'))   define('WHATSAPP_CHANNEL_LINK', '');    // https://whatsapp.com/channel/xxxx
if (!defined('WHATSAPP_NUMBER'))         define('WHATSAPP_NUMBER', '');          // digits with country code, e.g. 254700000000
