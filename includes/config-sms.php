<?php
/**
 * SlipKing - SMS settings (phone number confirmation). EDIT THIS FILE and keep it private (it holds API keys).
 *
 * SMS_DRIVER:
 *   'off'            = no SMS is sent. Members can still enter a mobile number, but it is NOT confirmed. (Default, safe.)
 *   'africastalking' = Africa's Talking (strong coverage in Kenya and across Africa). Fill AT_USERNAME and AT_API_KEY.
 *   'twilio'         = Twilio (worldwide coverage). Fill TWILIO_SID, TWILIO_TOKEN and either TWILIO_FROM or TWILIO_MSG_SERVICE.
 *   'log'            = TEST ONLY: writes the code to logs/sms.log instead of sending it. NEVER use it on a live site.
 *
 * Every SMS costs money. The site limits resends (1 per minute, 5 per hour per member, 3 per hour per number, 15 per hour per visitor).
 * Sending to every country in the world needs a provider and sender ID that cover those countries; some countries block alphanumeric
 * sender names or need pre-registration. Test with a real number from the countries you care about.
 */
if (!defined('SMS_DRIVER'))   define('SMS_DRIVER', 'off');
if (!defined('SMS_SENDER'))   define('SMS_SENDER', '');            // optional sender name / short code (Africa's Talking)
if (!defined('SMS_REQUIRE_FOR_PURCHASE')) define('SMS_REQUIRE_FOR_PURCHASE', true);   // members must confirm their mobile before paying
if (!defined('DEFAULT_COUNTRY')) define('DEFAULT_COUNTRY', 'KE');   // pre-selected on the register page (2-letter code)

// Africa's Talking
if (!defined('AT_USERNAME'))  define('AT_USERNAME', '');            // use 'sandbox' with a sandbox key to test
if (!defined('AT_API_KEY'))   define('AT_API_KEY', '');

// Twilio
if (!defined('TWILIO_SID'))   define('TWILIO_SID', '');
if (!defined('TWILIO_TOKEN')) define('TWILIO_TOKEN', '');
if (!defined('TWILIO_FROM'))  define('TWILIO_FROM', '');            // your Twilio number in +E.164 form, or leave empty and use a messaging service
if (!defined('TWILIO_MSG_SERVICE')) define('TWILIO_MSG_SERVICE', '');
