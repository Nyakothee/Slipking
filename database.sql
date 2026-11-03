-- SlipKing database. Import once with phpMyAdmin (Import tab) into an EMPTY database.
-- Delete this file from the server after importing.
SET NAMES utf8mb4;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  phone VARCHAR(20) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  referral_code VARCHAR(12) DEFAULT NULL UNIQUE,
  referred_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  slug VARCHAR(60) NOT NULL UNIQUE,
  price_kes INT UNSIGNED NOT NULL,
  price_usd DECIMAL(8,2) NOT NULL,
  duration_days INT UNSIGNED NOT NULL,
  features TEXT NOT NULL,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  status ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_status (user_id, status, ends_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  method ENUM('mpesa','paypal') NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  coupon_code VARCHAR(30) DEFAULT NULL,
  discount_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  reference VARCHAR(40) NOT NULL UNIQUE,
  phone VARCHAR(20) DEFAULT NULL,
  mpesa_checkout_id VARCHAR(80) DEFAULT NULL,
  mpesa_receipt VARCHAR(30) DEFAULT NULL UNIQUE,
  paypal_order_id VARCHAR(40) DEFAULT NULL,
  raw_response MEDIUMTEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME DEFAULT NULL,
  KEY idx_checkout (mpesa_checkout_id),
  KEY idx_paypal (paypal_order_id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  slug VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE leagues (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sport_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  FOREIGN KEY (sport_id) REFERENCES sports(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE matches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  league_id INT UNSIGNED NOT NULL,
  home_team VARCHAR(80) NOT NULL,
  away_team VARCHAR(80) NOT NULL,
  kickoff_at DATETIME NOT NULL,
  KEY idx_kickoff (kickoff_at),
  FOREIGN KEY (league_id) REFERENCES leagues(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tips (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  match_id INT UNSIGNED NOT NULL,
  market VARCHAR(40) NOT NULL,
  pick VARCHAR(120) NOT NULL,
  odds DECIMAL(7,2) NOT NULL,
  confidence TINYINT UNSIGNED NOT NULL DEFAULT 3,
  analysis TEXT,
  is_vip TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','won','lost','void') NOT NULL DEFAULT 'pending',
  posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  settled_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  KEY idx_status (status, posted_at),
  FOREIGN KEY (match_id) REFERENCES matches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  excerpt VARCHAR(300) DEFAULT NULL,
  body MEDIUMTEXT NOT NULL,
  image_url VARCHAR(255) DEFAULT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE coupons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  percent_off TINYINT UNSIGNED NOT NULL,
  max_uses INT UNSIGNED DEFAULT NULL,
  used INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE referrals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  referrer_id INT UNSIGNED NOT NULL,
  referred_id INT UNSIGNED NOT NULL,
  reward_given TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED DEFAULT NULL,
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  email VARCHAR(150) DEFAULT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Starter data ----------
INSERT INTO sports (name, slug) VALUES ('Football','football'),('Basketball','basketball'),('Tennis','tennis');
INSERT INTO leagues (sport_id, name) VALUES
 (1,'Premier League'),(1,'La Liga'),(1,'Serie A'),(1,'Bundesliga'),(2,'NBA'),(3,'ATP'),(1,'Champions League');

INSERT INTO plans (name, slug, price_kes, price_usd, duration_days, features, is_featured, sort_order) VALUES
 ('Weekly','weekly',500,4.00,7,'All VIP tips\nTelegram alerts\nResults archive',0,1),
 ('Monthly','monthly',1500,12.00,30,'Everything in Weekly\nAccumulators\nCorrect score picks\nPrivate VIP group',1,2),
 ('Elite','elite',4000,30.00,90,'Everything in Monthly\nHighest-confidence picks\nEarly access to tips\nPriority support',0,3);

-- SAMPLE tips so the home page has something to show. DELETE THESE before you go live:
--   DELETE FROM tips; DELETE FROM matches;
INSERT INTO matches (league_id, home_team, away_team, kickoff_at) VALUES
 (1,'Arsenal','Chelsea',TIMESTAMP(CURDATE(),'20:00:00')),
 (2,'Real Madrid','Sevilla',TIMESTAMP(CURDATE(),'21:00:00')),
 (5,'Lakers','Celtics',TIMESTAMP(CURDATE(),'23:30:00')),
 (4,'Dortmund','Leipzig',TIMESTAMP(CURDATE(),'18:30:00')),
 (1,'4 matches','Weekend',TIMESTAMP(CURDATE(),'15:00:00')),
 (6,'Alcaraz','Rune',TIMESTAMP(CURDATE(),'17:00:00')),
 (3,'Inter','Torino',TIMESTAMP(CURDATE()-INTERVAL 1 DAY,'20:00:00')),
 (1,'Man City','Brighton',TIMESTAMP(CURDATE()-INTERVAL 1 DAY,'20:00:00')),
 (1,'Liverpool','Everton',TIMESTAMP(CURDATE()-INTERVAL 2 DAY,'20:00:00')),
 (2,'Barcelona','Getafe',TIMESTAMP(CURDATE()-INTERVAL 2 DAY,'20:00:00')),
 (6,'Djokovic','Sinner',TIMESTAMP(CURDATE()-INTERVAL 3 DAY,'20:00:00')),
 (5,'Bucks','Heat',TIMESTAMP(CURDATE()-INTERVAL 3 DAY,'20:00:00')),
 (7,'PSG','Lille',TIMESTAMP(CURDATE()-INTERVAL 4 DAY,'20:00:00')),
 (3,'Napoli','Roma',TIMESTAMP(CURDATE()-INTERVAL 4 DAY,'20:00:00'));

INSERT INTO tips (match_id, market, pick, odds, confidence, analysis, is_vip, status, posted_at, settled_at) VALUES
 (1,'Over/Under','Over 2.5 goals',1.85,4,'Both sides score freely in this fixture.',0,'pending',TIMESTAMP(CURDATE(),'08:00:00'),NULL),
 (2,'1X2','Real Madrid to win',1.55,5,'Strong home record.',0,'pending',TIMESTAMP(CURDATE(),'08:05:00'),NULL),
 (3,'Handicap','Celtics -3.5',1.90,3,'Celtics are the stronger side on form.',0,'pending',TIMESTAMP(CURDATE(),'08:10:00'),NULL),
 (4,'BTTS','Both teams to score',1.70,4,'Open games, weak defences.',1,'pending',TIMESTAMP(CURDATE(),'08:15:00'),NULL),
 (5,'Acca','Acca, 4 legs',6.40,3,'Four short-priced home wins.',1,'pending',TIMESTAMP(CURDATE(),'08:20:00'),NULL),
 (6,'Set betting','Alcaraz in 2 sets',2.05,4,'Alcaraz is strong on this surface.',1,'pending',TIMESTAMP(CURDATE(),'08:25:00'),NULL),
 (7,'1X2','Inter to win, over 1.5',1.72,4,'',0,'won',TIMESTAMP(CURDATE()-INTERVAL 1 DAY,'09:00:00'),TIMESTAMP(CURDATE()-INTERVAL 1 DAY,'22:30:00')),
 (8,'Correct score','3-1',11.00,2,'',1,'lost',TIMESTAMP(CURDATE()-INTERVAL 1 DAY,'09:05:00'),TIMESTAMP(CURDATE()-INTERVAL 1 DAY,'22:30:00')),
 (9,'Handicap','Liverpool -1',1.80,4,'',0,'won',TIMESTAMP(CURDATE()-INTERVAL 2 DAY,'09:00:00'),TIMESTAMP(CURDATE()-INTERVAL 2 DAY,'22:30:00')),
 (10,'Over/Under','Over 2.5 goals',1.65,4,'',0,'won',TIMESTAMP(CURDATE()-INTERVAL 2 DAY,'09:05:00'),TIMESTAMP(CURDATE()-INTERVAL 2 DAY,'22:30:00')),
 (11,'Total games','Over 22.5 games',1.90,3,'',1,'won',TIMESTAMP(CURDATE()-INTERVAL 3 DAY,'09:00:00'),TIMESTAMP(CURDATE()-INTERVAL 3 DAY,'22:30:00')),
 (12,'Handicap','Bucks -4.5',1.88,3,'',0,'lost',TIMESTAMP(CURDATE()-INTERVAL 3 DAY,'09:05:00'),TIMESTAMP(CURDATE()-INTERVAL 3 DAY,'22:30:00')),
 (13,'1X2','PSG to win',1.50,5,'',0,'won',TIMESTAMP(CURDATE()-INTERVAL 4 DAY,'09:00:00'),TIMESTAMP(CURDATE()-INTERVAL 4 DAY,'22:30:00')),
 (14,'Double chance','Napoli or draw',1.40,4,'',1,'won',TIMESTAMP(CURDATE()-INTERVAL 4 DAY,'09:05:00'),TIMESTAMP(CURDATE()-INTERVAL 4 DAY,'22:30:00'));

-- ---------- Admin activity log (added in part 6) ----------
CREATE TABLE IF NOT EXISTS admin_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED DEFAULT NULL,
  action VARCHAR(40) NOT NULL,
  target VARCHAR(60) DEFAULT NULL,
  details VARCHAR(500) DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Part 11: currencies + accumulators ----------

CREATE TABLE IF NOT EXISTS currencies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code CHAR(3) NOT NULL UNIQUE,
  name VARCHAR(40) NOT NULL,
  symbol VARCHAR(8) NOT NULL,
  rate_per_usd DECIMAL(18,6) NOT NULL,
  decimals TINYINT UNSIGNED NOT NULL DEFAULT 2,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  auto_update TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  updated_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PLACEHOLDER rates. Open Admin > Currencies and press "Update rates now" (or set them by hand) before launch.
INSERT IGNORE INTO currencies (code, name, symbol, rate_per_usd, decimals, sort_order) VALUES
 ('KES','Kenya shilling','KES ',129.000000,0,1),
 ('USD','US dollar','$',1.000000,2,2),
 ('NGN','Nigerian naira','₦',1500.000000,0,3),
 ('INR','Indian rupee','₹',85.000000,0,4);

CREATE TABLE IF NOT EXISTS accumulators (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('million','vip') NOT NULL,
  title VARCHAR(120) NOT NULL,
  target_kes INT UNSIGNED NOT NULL DEFAULT 1000000,
  total_odds DECIMAL(18,2) NOT NULL,
  status ENUM('pending','won','lost','void') NOT NULL DEFAULT 'pending',
  notes TEXT,
  posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  first_kickoff DATETIME NOT NULL,
  last_kickoff DATETIME NOT NULL,
  settled_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  KEY idx_kind (kind, posted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accumulator_legs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  accumulator_id INT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL,
  league VARCHAR(80) NOT NULL,
  home_team VARCHAR(80) NOT NULL,
  away_team VARCHAR(80) NOT NULL,
  kickoff_at DATETIME NOT NULL,
  pick VARCHAR(120) NOT NULL,
  odds DECIMAL(7,2) NOT NULL,
  status ENUM('pending','won','lost','void') NOT NULL DEFAULT 'pending',
  KEY idx_acca (accumulator_id, position),
  FOREIGN KEY (accumulator_id) REFERENCES accumulators(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Part 12: account management + live chat ----------

CREATE TABLE IF NOT EXISTS managed_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  phone VARCHAR(25) NOT NULL,
  country VARCHAR(60) NOT NULL,
  bookmaker VARCHAR(80) NOT NULL,
  budget DECIMAL(14,2) NOT NULL,
  budget_currency CHAR(3) NOT NULL,
  risk_level ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  message TEXT,
  status ENUM('new','contacted','accepted','declined','closed') NOT NULL DEFAULT 'new',
  admin_notes TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  KEY idx_status (status, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_threads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED DEFAULT NULL,
  visitor_token CHAR(32) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL DEFAULT '',
  email VARCHAR(150) DEFAULT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unread_admin INT UNSIGNED NOT NULL DEFAULT 0,
  unread_user INT UNSIGNED NOT NULL DEFAULT 0,
  ip VARCHAR(45) DEFAULT NULL,
  KEY idx_status (status, last_message_at),
  KEY idx_user (user_id),
  KEY idx_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id INT UNSIGNED NOT NULL,
  sender ENUM('visitor','admin') NOT NULL,
  body VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_thread (thread_id, id),
  FOREIGN KEY (thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Part 13: trial/win-back offers, referral rewards, Paystack + Razorpay ----------

ALTER TABLE users ADD COLUMN email_optout TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE payments
  MODIFY method ENUM('mpesa','paypal','paystack','razorpay') NOT NULL,
  ADD COLUMN gateway_ref VARCHAR(80) DEFAULT NULL AFTER paypal_order_id,
  ADD KEY idx_gateway (gateway_ref);

CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(60) NOT NULL PRIMARY KEY,
  value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (name, value) VALUES
 ('referral_reward_days','3'),('trial_enabled','1'),('trial_days','1'),('winback_coupon','');

-- hidden plans used for free trials and referral rewards (never shown for sale)
INSERT IGNORE INTO plans (name, slug, price_kes, price_usd, duration_days, features, is_featured, is_active, sort_order) VALUES
 ('Free trial','trial',1,0.00,1,'Free trial',0,0,90),
 ('Bonus days','bonus',1,0.00,3,'Bonus days',0,0,91);

-- ---------- Part 13: update-6 features (2FA, bookmakers, odds, guarantee, tracker, auto-results) ----------
SET NAMES utf8mb4;
-- Run ONCE in phpMyAdmin (SQL tab) on an existing install (after update-5). New installs: already included in database.sql.
-- Safe to run twice.

-- ===== part A =====
-- Run in phpMyAdmin (SQL tab) on an existing install. Safe to run more than once:
-- each column is only added if it is missing, the table and setting only if missing.
-- New installs: run it once after importing database.sql.

-- users.totp_secret
SET @q = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL DEFAULT NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_secret');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- users.totp_enabled
SET @q = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_enabled');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- one-time recovery codes (stored only as keyed hashes)
CREATE TABLE IF NOT EXISTS admin_recovery_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  used_at DATETIME DEFAULT NULL,
  KEY idx_user (user_id, used_at),
  KEY idx_hash (user_id, code_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Telegram auto-post is OFF until the admin switches it on (Admin > Social and Telegram)
INSERT IGNORE INTO settings (name, value) VALUES ('telegram_autopost', '0');

-- ===== part B =====
-- Run ONCE in phpMyAdmin (SQL tab). Safe to run twice (CREATE IF NOT EXISTS / guarded inserts).

CREATE TABLE IF NOT EXISTS bookmakers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  slug VARCHAR(60) NOT NULL UNIQUE,
  affiliate_url VARCHAR(500) NOT NULL,
  logo_text VARCHAR(40) DEFAULT NULL,
  bonus_text VARCHAR(160) DEFAULT NULL,
  countries VARCHAR(80) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookmaker_clicks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bookmaker_id INT UNSIGNED NOT NULL,
  tip_id INT UNSIGNED DEFAULT NULL,
  user_id INT UNSIGNED DEFAULT NULL,
  ip_hash CHAR(64) NOT NULL,
  page VARCHAR(60) NOT NULL DEFAULT '',
  clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bm_time (bookmaker_id, clicked_at),
  KEY idx_dedupe (bookmaker_id, ip_hash, clicked_at),
  FOREIGN KEY (bookmaker_id) REFERENCES bookmakers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tip_odds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tip_id INT UNSIGNED NOT NULL,
  bookmaker_id INT UNSIGNED NOT NULL,
  odds DECIMAL(7,2) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tip_bm (tip_id, bookmaker_id),
  FOREIGN KEY (tip_id) REFERENCES tips(id) ON DELETE CASCADE,
  FOREIGN KEY (bookmaker_id) REFERENCES bookmakers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS guarantee_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  day DATE NOT NULL UNIQUE,
  tips_count INT UNSIGNED NOT NULL DEFAULT 0,
  days_granted INT UNSIGNED NOT NULL DEFAULT 0,
  members_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (name, value) VALUES
 ('guarantee_enabled','0'),('guarantee_days','1'),('guarantee_min_tips','3'),('guarantee_rule','all_lost');

-- 4 EXAMPLE bookmakers. Inactive with a placeholder link: nothing goes live until the owner edits them
-- (Admin > Bookmakers) with a real affiliate link and ticks "Active". Names are examples only.
INSERT IGNORE INTO bookmakers (name, slug, affiliate_url, logo_text, bonus_text, countries, sort_order, is_active) VALUES
 ('Example Bookie 1','example-bookie-1','https://example.com/replace-me','EB1','Welcome bonus (edit me)','',1,0),
 ('Example Bookie 2','example-bookie-2','https://example.com/replace-me','EB2','Welcome bonus (edit me)','',2,0),
 ('Example Bookie 3','example-bookie-3','https://example.com/replace-me','EB3','Welcome bonus (edit me)','',3,0),
 ('Example Bookie 4','example-bookie-4','https://example.com/replace-me','EB4','Welcome bonus (edit me)','',4,0);

-- Plans: weekly + season pass, added only if missing. PRICES ARE EXAMPLES: edit them in Admin > Plans and prices.
-- (A fresh install already has 'weekly'; this does nothing in that case.)
INSERT INTO plans (name, slug, price_kes, price_usd, duration_days, features, is_featured, is_active, sort_order)
SELECT 'Weekly','weekly',500,4.00,7,'All VIP tips\nResults archive',0,1,1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM plans WHERE slug = 'weekly' OR name = 'Weekly');

INSERT INTO plans (name, slug, price_kes, price_usd, duration_days, features, is_featured, is_active, sort_order)
SELECT 'Season pass','season-pass',3500,27.00,90,'Every VIP tip for 90 days\nBest price per day\nAccumulators\nPriority support',0,1,4 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM plans WHERE slug = 'season-pass' OR name = 'Season pass');

-- ===== part C =====
CREATE TABLE IF NOT EXISTS user_bets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  tip_id INT UNSIGNED DEFAULT NULL,
  description VARCHAR(160) NOT NULL,
  bookmaker VARCHAR(60) DEFAULT NULL,
  stake DECIMAL(12,2) NOT NULL,
  odds DECIMAL(7,2) NOT NULL,
  status ENUM('pending','won','lost','void') NOT NULL DEFAULT 'pending',
  placed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  settled_at DATETIME DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  KEY idx_user (user_id, deleted_at, placed_at),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== part D =====
-- Run ONCE in phpMyAdmin (SQL tab). Safe to run twice (the column is added only if missing).

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'matches' AND COLUMN_NAME = 'api_fixture_id');
SET @ddl := IF(@has_col = 0,
               'ALTER TABLE matches ADD COLUMN api_fixture_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY idx_api_fixture (api_fixture_id)',
               'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 'settings' table already exists (database-update-5.sql). Number of backup files to keep:
INSERT IGNORE INTO settings (name, value) VALUES ('backup_keep','7'), ('auto_results_enabled','0');

-- ---------- Part 14: update-7 (tipster marketplace + staff roles) ----------

-- Staff roles: editor (tips + content), support (chat + enquiries + reviews), finance (payments + payouts), admin (everything).
ALTER TABLE users MODIFY role ENUM('user','admin','editor','support','finance') NOT NULL DEFAULT 'user';

-- tips.tipster_id: NULL = the site's own tip. A number = posted by that tipster.
SET @q = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE tips ADD COLUMN tipster_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY idx_tip_tipster (tipster_id, status, posted_at)',
  'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tips' AND COLUMN_NAME = 'tipster_id');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- plans.tipster_id: NULL = a normal VIP plan. A number = a plan sold by that tipster (never shown on the VIP page).
SET @q = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE plans ADD COLUMN tipster_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY idx_plan_tipster (tipster_id)',
  'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plans' AND COLUMN_NAME = 'tipster_id');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS tipsters (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL UNIQUE,
  slug VARCHAR(60) NOT NULL UNIQUE,
  display_name VARCHAR(60) NOT NULL,
  tagline VARCHAR(120) DEFAULT NULL,
  bio TEXT,
  country VARCHAR(40) DEFAULT NULL,
  specialties VARCHAR(120) DEFAULT NULL,
  avatar_url VARCHAR(255) DEFAULT NULL,
  payout_method ENUM('mpesa','paypal') NOT NULL DEFAULT 'mpesa',
  payout_details VARCHAR(120) DEFAULT NULL,
  status ENUM('pending','active','suspended','rejected') NOT NULL DEFAULT 'pending',
  commission_percent TINYINT UNSIGNED DEFAULT NULL,
  admin_note VARCHAR(255) DEFAULT NULL,
  rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  rating_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME DEFAULT NULL,
  KEY idx_status (status),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tipster_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  tipster_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  payment_id INT UNSIGNED DEFAULT NULL UNIQUE,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_access (user_id, tipster_id, ends_at),
  KEY idx_tipster (tipster_id, ends_at),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (tipster_id) REFERENCES tipsters(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per paid tipster plan. The commission % is copied here at sale time, so changing the rate later
-- only affects future sales.
CREATE TABLE IF NOT EXISTS tipster_earnings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipster_id INT UNSIGNED NOT NULL,
  payment_id INT UNSIGNED NOT NULL UNIQUE,
  buyer_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  gross_kes DECIMAL(12,2) NOT NULL,
  commission_percent DECIMAL(5,2) NOT NULL,
  commission_kes DECIMAL(12,2) NOT NULL,
  net_kes DECIMAL(12,2) NOT NULL,
  available_at DATETIME NOT NULL,
  status ENUM('ok','reversed') NOT NULL DEFAULT 'ok',
  note VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tipster (tipster_id, status, available_at),
  FOREIGN KEY (tipster_id) REFERENCES tipsters(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tipster_payouts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipster_id INT UNSIGNED NOT NULL,
  amount_kes DECIMAL(12,2) NOT NULL,
  method ENUM('mpesa','paypal') NOT NULL,
  details VARCHAR(120) NOT NULL,
  status ENUM('requested','paid','rejected') NOT NULL DEFAULT 'requested',
  reference VARCHAR(60) DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME DEFAULT NULL,
  processed_by INT UNSIGNED DEFAULT NULL,
  KEY idx_tipster (tipster_id, status),
  FOREIGN KEY (tipster_id) REFERENCES tipsters(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tipster_reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipster_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(600) DEFAULT NULL,
  status ENUM('visible','hidden') NOT NULL DEFAULT 'visible',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_review (tipster_id, user_id),
  KEY idx_tipster (tipster_id, status),
  FOREIGN KEY (tipster_id) REFERENCES tipsters(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Marketplace settings (editable in Admin > Tipsters). The commission is the share the SITE keeps of each sale.
INSERT IGNORE INTO settings (name, value) VALUES
 ('tipster_commission_percent','30'),
 ('tipster_hold_days','7'),
 ('tipster_min_payout_kes','1000'),
 ('tipster_signups_open','1');

-- ============================================================
-- Part 15: mobile number with country code and SMS confirmation
-- ============================================================
SET @q = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE users ADD COLUMN country CHAR(2) NULL DEFAULT NULL, ADD COLUMN mobile VARCHAR(20) NULL DEFAULT NULL, ADD COLUMN mobile_verified TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN mobile_verified_at DATETIME NULL DEFAULT NULL, ADD KEY idx_users_mobile (mobile)', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'mobile');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS phone_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  mobile VARCHAR(20) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used TINYINT(1) NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pc_user (user_id, created_at),
  KEY idx_pc_mobile (mobile, created_at),
  KEY idx_pc_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Part 16: leaderboard and contributor tipsters
-- ============================================================
-- tips.author_id: the contributor tipster who wrote a PLATFORM tip (tips.tipster_id stays NULL for those).
-- tips.pending_review = 1: waiting for an admin to approve before it goes live.
SET @q = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE tips ADD COLUMN author_id INT UNSIGNED NULL DEFAULT NULL, ADD COLUMN pending_review TINYINT(1) NOT NULL DEFAULT 0, ADD KEY idx_tip_author (author_id, status), ADD KEY idx_tip_review (pending_review)', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tips' AND COLUMN_NAME = 'author_id');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE tipsters ADD COLUMN is_contributor TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tipsters' AND COLUMN_NAME = 'is_contributor');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- One payment can now pay several contributors: the unique key becomes (payment_id, tipster_id).
SET @q = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE tipster_earnings DROP INDEX payment_id', 'SELECT 1')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tipster_earnings' AND INDEX_NAME = 'payment_id');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE tipster_earnings ADD UNIQUE KEY uq_earn_payment_tipster (payment_id, tipster_id)', 'SELECT 1')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tipster_earnings' AND INDEX_NAME = 'uq_earn_payment_tipster');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Share of every VIP plan sale (percent) put into a pool for contributors. 0 = switched off.
INSERT IGNORE INTO settings (name, value) VALUES ('contributor_pool_percent', '0');

-- ============================================================
-- Part 17: tipster-only accounts
-- ============================================================
SET @q = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE users ADD COLUMN is_tipster_account TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_tipster_account');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ============================================================
-- Part 18: game, live centre, comments, follows/alerts/push, trial, guarantee
-- ============================================================
-- Live scores on matches
SET @q = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE matches ADD COLUMN live_status VARCHAR(8) NULL DEFAULT NULL, ADD COLUMN live_minute TINYINT UNSIGNED NULL DEFAULT NULL, ADD COLUMN home_score TINYINT UNSIGNED NULL DEFAULT NULL, ADD COLUMN away_score TINYINT UNSIGNED NULL DEFAULT NULL, ADD COLUMN score_updated_at DATETIME NULL DEFAULT NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'matches' AND COLUMN_NAME = 'live_status');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Tipster free trial (days, 0 = off) and money-back guarantee opt-in
SET @q = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE tipsters ADD COLUMN trial_days TINYINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN guarantee TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tipsters' AND COLUMN_NAME = 'trial_days');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS match_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  match_id INT UNSIGNED NOT NULL,
  minute TINYINT UNSIGNED DEFAULT NULL,
  extra TINYINT UNSIGNED DEFAULT NULL,
  type VARCHAR(12) NOT NULL,
  team ENUM('home','away','none') NOT NULL DEFAULT 'none',
  player VARCHAR(60) DEFAULT NULL,
  detail VARCHAR(120) DEFAULT NULL,
  ext_key VARCHAR(40) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event (match_id, ext_key),
  KEY idx_match (match_id, minute)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Free prediction game: one pick (1, X or 2) per member per match, points in a ledger
CREATE TABLE IF NOT EXISTS game_picks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  match_id INT UNSIGNED NOT NULL,
  pick CHAR(1) NOT NULL,
  status ENUM('open','won','lost','void') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_pick (user_id, match_id),
  KEY idx_match (match_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_points (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  points INT NOT NULL,
  reason VARCHAR(20) NOT NULL,
  ref VARCHAR(30) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ledger (user_id, reason, ref),
  KEY idx_time (created_at, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_prizes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_type ENUM('week','month') NOT NULL,
  period_key VARCHAR(10) NOT NULL,
  rank_no TINYINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  points INT NOT NULL,
  days INT UNSIGNED NOT NULL,
  awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prize (period_type, period_key, rank_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_streaks (
  user_id INT UNSIGNED PRIMARY KEY,
  current_streak INT UNSIGNED NOT NULL DEFAULT 0,
  best_streak INT UNSIGNED NOT NULL DEFAULT 0,
  last_day DATE DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_badges (
  user_id INT UNSIGNED NOT NULL,
  code VARCHAR(30) NOT NULL,
  awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Comments, likes, reports
CREATE TABLE IF NOT EXISTS comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  target_type ENUM('tip','tipster','match') NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED DEFAULT NULL,
  body VARCHAR(600) NOT NULL,
  status ENUM('visible','hidden') NOT NULL DEFAULT 'visible',
  likes INT UNSIGNED NOT NULL DEFAULT 0,
  reports INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_target (target_type, target_id, status, created_at),
  KEY idx_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS comment_likes (
  comment_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (comment_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS comment_reports (
  comment_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  reason VARCHAR(120) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  handled TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (comment_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS comment_bans (
  user_id INT UNSIGNED PRIMARY KEY,
  reason VARCHAR(120) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Follow tipsters, alert preferences and the alert queue
CREATE TABLE IF NOT EXISTS tipster_follows (
  user_id INT UNSIGNED NOT NULL,
  tipster_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, tipster_id),
  KEY idx_tipster (tipster_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alert_prefs (
  user_id INT UNSIGNED PRIMARY KEY,
  push TINYINT(1) NOT NULL DEFAULT 1,
  email TINYINT(1) NOT NULL DEFAULT 0,
  sms TINYINT(1) NOT NULL DEFAULT 0,
  kickoff TINYINT(1) NOT NULL DEFAULT 1,
  results TINYINT(1) NOT NULL DEFAULT 1,
  vip TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alert_queue (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  channel ENUM('push','email','sms') NOT NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(300) NOT NULL,
  url VARCHAR(200) DEFAULT NULL,
  dedupe_key VARCHAR(60) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME DEFAULT NULL,
  tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error VARCHAR(120) DEFAULT NULL,
  UNIQUE KEY uq_alert (user_id, channel, dedupe_key),
  KEY idx_pending (sent_at, tries, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS push_subs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  endpoint_hash CHAR(64) NOT NULL UNIQUE,
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(160) NOT NULL,
  auth VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tipster free trial and money-back guarantee claims
CREATE TABLE IF NOT EXISTS tipster_trials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  tipster_id INT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  UNIQUE KEY uq_trial (user_id, tipster_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tipster_claims (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  tipster_id INT UNSIGNED NOT NULL,
  subscription_id INT UNSIGNED NOT NULL,
  payment_id INT UNSIGNED DEFAULT NULL,
  amount_kes DECIMAL(12,2) NOT NULL DEFAULT 0,
  units DECIMAL(8,2) NOT NULL DEFAULT 0,
  status ENUM('requested','approved','rejected') NOT NULL DEFAULT 'requested',
  note VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME DEFAULT NULL,
  processed_by INT UNSIGNED DEFAULT NULL,
  UNIQUE KEY uq_claim (subscription_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (name, value) VALUES
 ('game_points_correct','3'),
 ('game_min_picks','5'),
 ('game_weekly_prizes','3,2,1'),
 ('game_monthly_prizes','10,5,3'),
 ('live_enabled','0'),
 ('tipster_guarantee_units','5');

-- ===== Part 19: update 12 (statistics, odds, audit chain, limits, B2C) =====


CREATE TABLE IF NOT EXISTS stat_competitions (
  code VARCHAR(8) PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  area VARCHAR(60) DEFAULT NULL,
  emblem VARCHAR(255) DEFAULT NULL,
  season INT UNSIGNED DEFAULT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  synced_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stat_matches (
  id INT UNSIGNED PRIMARY KEY,
  comp_code VARCHAR(8) NOT NULL,
  season INT UNSIGNED DEFAULT NULL,
  matchday SMALLINT UNSIGNED DEFAULT NULL,
  kick_utc DATETIME NOT NULL,
  status VARCHAR(16) NOT NULL,
  home_id INT UNSIGNED DEFAULT NULL,
  home_name VARCHAR(80) NOT NULL,
  home_crest VARCHAR(255) DEFAULT NULL,
  away_id INT UNSIGNED DEFAULT NULL,
  away_name VARCHAR(80) NOT NULL,
  away_crest VARCHAR(255) DEFAULT NULL,
  home_goals TINYINT UNSIGNED DEFAULT NULL,
  away_goals TINYINT UNSIGNED DEFAULT NULL,
  ht_home TINYINT UNSIGNED DEFAULT NULL,
  ht_away TINYINT UNSIGNED DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_comp_date (comp_code, kick_utc),
  KEY idx_home (home_id, kick_utc),
  KEY idx_away (away_id, kick_utc),
  KEY idx_status (status, kick_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stat_standings (
  comp_code VARCHAR(8) NOT NULL,
  `type` VARCHAR(8) NOT NULL DEFAULT 'TOTAL',
  team_id INT UNSIGNED NOT NULL,
  `position` SMALLINT UNSIGNED NOT NULL,
  team_name VARCHAR(80) NOT NULL,
  crest VARCHAR(255) DEFAULT NULL,
  played SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  won SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  draw SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  lost SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  points SMALLINT NOT NULL DEFAULT 0,
  gf SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ga SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  gd SMALLINT NOT NULL DEFAULT 0,
  `form` VARCHAR(20) DEFAULT NULL,
  PRIMARY KEY (comp_code, `type`, team_id),
  KEY idx_team (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Odds comparison
CREATE TABLE IF NOT EXISTS odds_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ext_id VARCHAR(40) NOT NULL UNIQUE,
  sport_key VARCHAR(40) NOT NULL,
  commence_at DATETIME NOT NULL,
  home_team VARCHAR(80) NOT NULL,
  away_team VARCHAR(80) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_commence (commence_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS odds_quotes (
  event_id INT UNSIGNED NOT NULL,
  bookmaker_key VARCHAR(40) NOT NULL,
  bookmaker_title VARCHAR(60) NOT NULL,
  home_odds DECIMAL(7,2) DEFAULT NULL,
  draw_odds DECIMAL(7,2) DEFAULT NULL,
  away_odds DECIMAL(7,2) DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id, bookmaker_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS odds_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  fetched_at DATETIME NOT NULL,
  best_home DECIMAL(7,2) DEFAULT NULL,
  best_draw DECIMAL(7,2) DEFAULT NULL,
  best_away DECIMAL(7,2) DEFAULT NULL,
  avg_home DECIMAL(7,2) DEFAULT NULL,
  avg_draw DECIMAL(7,2) DEFAULT NULL,
  avg_away DECIMAL(7,2) DEFAULT NULL,
  books TINYINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_event (event_id, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tamper-evident tip log: each row holds the SHA-256 of the previous row, so editing or deleting an old row breaks the chain.
CREATE TABLE IF NOT EXISTS audit_chain (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tip_id INT UNSIGNED NOT NULL,
  `event` VARCHAR(10) NOT NULL,
  payload VARCHAR(400) NOT NULL,
  prev_hash CHAR(64) NOT NULL,
  `hash` CHAR(64) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL,
  KEY idx_tip (tip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Responsible gambling: self-exclusion, monthly spend limit, 18+ confirmation
CREATE TABLE IF NOT EXISTS user_limits (
  user_id INT UNSIGNED PRIMARY KEY,
  excluded_until DATETIME DEFAULT NULL,
  excluded_forever TINYINT(1) NOT NULL DEFAULT 0,
  monthly_limit_kes INT UNSIGNED DEFAULT NULL,
  age_confirmed_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Multi-account signals (IP and browser seen per member). Old rows are deleted after 90 days by cron/lifecycle.php.
CREATE TABLE IF NOT EXISTS user_fingerprints (
  user_id INT UNSIGNED NOT NULL,
  ip VARCHAR(45) NOT NULL,
  ua_hash CHAR(16) NOT NULL,
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  PRIMARY KEY (user_id, ip, ua_hash),
  KEY idx_ip (ip, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lifecycle_log (
  user_id INT UNSIGNED NOT NULL,
  `kind` VARCHAR(20) NOT NULL,
  `ref` VARCHAR(30) NOT NULL DEFAULT '',
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, `kind`, `ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- M-Pesa B2C automatic payouts: new state "processing" and the provider's references
ALTER TABLE tipster_payouts MODIFY status ENUM('requested','processing','paid','rejected') NOT NULL DEFAULT 'requested';
SET @q = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE tipster_payouts ADD COLUMN b2c_conversation VARCHAR(60) NULL DEFAULT NULL, ADD COLUMN b2c_note VARCHAR(200) NULL DEFAULT NULL, ADD KEY idx_b2c (b2c_conversation)', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tipster_payouts' AND COLUMN_NAME = 'b2c_conversation');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

INSERT IGNORE INTO settings (name, value) VALUES
 ('stats_enabled','1'),
 ('stats_comps','PL,PD,SA,BL1,FL1,CL'),
 ('odds_enabled','1'),
 ('cashout_margin_pct','6'),
 ('vip_acca_min_days','7'),
 ('odds_sports','soccer_epl,soccer_spain_la_liga,soccer_italy_serie_a,soccer_germany_bundesliga'),
 ('odds_min_credits','30'),
 ('odds_blocked_countries',''),
 ('b2c_enabled','0'),
 ('welcome_email_enabled','1'),
 ('winback_email_enabled','1');

-- ---------- Part 20 (update-13): Stripe, MTN MoMo, Airtel Money, and the euro, pound, Tanzanian shilling and Ugandan shilling ----------

ALTER TABLE payments MODIFY method ENUM('mpesa','paypal','paystack','razorpay','stripe','mtn','airtel') NOT NULL;

-- Placeholder rates (units per 1 US dollar). Admin > Currencies > "Update rates now" replaces them with today's rates.
INSERT IGNORE INTO currencies (code, name, symbol, rate_per_usd, decimals, sort_order) VALUES
 ('EUR','Euro','€',0.920000,2,5),
 ('GBP','British pound','£',0.780000,2,6),
 ('TZS','Tanzanian shilling','TSh ',2600.000000,0,7),
 ('UGX','Ugandan shilling','USh ',3700.000000,0,8);

INSERT IGNORE INTO settings (name, value) VALUES
 ('pay_mpesa_on','1'),
 ('pay_paypal_on','1'),
 ('pay_stripe_on','1'),
 ('pay_mtn_on','1'),
 ('pay_airtel_on','1'),
 ('pay_paystack_on','1'),
 ('pay_razorpay_on','1');
-- ---------- Part 21 (update-14): member profile pictures and nationality flags ----------

SET @q = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE users ADD COLUMN avatar VARCHAR(60) NULL DEFAULT NULL, ADD COLUMN nationality CHAR(2) NULL DEFAULT NULL, ADD COLUMN show_flag TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
-- ---------- Part 22 (update-15): account management between clients and tipsters ----------

SET @q = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE managed_requests ADD COLUMN tipster_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY idx_mr_tipster (tipster_id, status)', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'managed_requests' AND COLUMN_NAME = 'tipster_id');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE tipsters ADD COLUMN accepts_managed TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN managed_note VARCHAR(300) NULL DEFAULT NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tipsters' AND COLUMN_NAME = 'accepts_managed');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS managed_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  sender ENUM('client','tipster','staff') NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_req (request_id, id),
  FOREIGN KEY (request_id) REFERENCES managed_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Part 23 (update-16): automatic tipster withdrawals, one every 14 days ----------

INSERT IGNORE INTO settings (name, value) VALUES
 ('tipster_withdraw_days','14'),
 ('tipster_auto_payout','1'),
 ('paypal_payouts_on','0'),
 ('tipster_manual_fallback','0');

-- ---------- Part 24 (update-17): Airtel Money withdrawals for tipsters ----------

ALTER TABLE tipsters MODIFY payout_method ENUM('mpesa','paypal','airtel') NOT NULL DEFAULT 'mpesa';
ALTER TABLE tipster_payouts MODIFY method ENUM('mpesa','paypal','airtel') NOT NULL;
INSERT IGNORE INTO settings (name, value) VALUES ('airtel_payouts_on','0');

-- ---------- Part 25 (update-18): tipster joining fee (off by default) and job applications ----------

SET @q = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE tipsters ADD COLUMN fee_paid_at DATETIME NULL DEFAULT NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tipsters' AND COLUMN_NAME = 'fee_paid_at');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
INSERT IGNORE INTO settings (name, value) VALUES ('tipster_fee_on','1'),('tipster_fee_kes','150'),('tipster_fee_usd','1');
INSERT INTO plans (name, slug, price_kes, price_usd, duration_days, features, is_featured, is_active, sort_order, tipster_id)
  SELECT 'Tipster joining fee', 'tipster-fee', 150, 1.00, 0, 'One-time fee to join as a tipster', 0, 1, 999, 0 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM plans WHERE slug = 'tipster-fee');

-- Job applications (hidden job form). Applications are read by admins only.
CREATE TABLE IF NOT EXISTS job_applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(20) NOT NULL,
  status ENUM('new','reviewing','shortlisted','interview','hired','rejected') NOT NULL DEFAULT 'new',
  role VARCHAR(80) NOT NULL,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  country VARCHAR(80) NOT NULL,
  city VARCHAR(80) NOT NULL,
  dob DATE NOT NULL,
  gender VARCHAR(20) NULL DEFAULT NULL,
  answers MEDIUMTEXT NULL,
  photo_file VARCHAR(60) NULL DEFAULT NULL,
  id_file VARCHAR(60) NULL DEFAULT NULL,
  cv_file VARCHAR(60) NULL DEFAULT NULL,
  cert_file VARCHAR(60) NULL DEFAULT NULL,
  work_file VARCHAR(60) NULL DEFAULT NULL,
  admin_note TEXT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  user_id INT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ref (ref),
  KEY idx_status (status, created_at),
  KEY idx_email (email),
  KEY idx_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('jobs_open','1');

-- ---------- Part 26 (update-19): tipster record log, polls, clubs, arena, slip studio ----------

CREATE TABLE IF NOT EXISTS tip_changes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tip_id INT UNSIGNED NOT NULL,
  tipster_id INT UNSIGNED NULL DEFAULT NULL,
  kind ENUM('edited','cancelled') NOT NULL,
  by_role VARCHAR(12) NOT NULL DEFAULT 'admin',
  detail VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tc_tip (tip_id),
  KEY idx_tc_tipster (tipster_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @q = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE tips ADD COLUMN void_kind VARCHAR(6) NULL DEFAULT NULL', 'SELECT 1')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tips' AND COLUMN_NAME = 'void_kind');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS poll_votes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  match_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  choice CHAR(1) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_poll (match_id, user_id),
  KEY idx_poll_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clubs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  about VARCHAR(300) NOT NULL DEFAULT '',
  invite_code VARCHAR(16) NOT NULL,
  public_record TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_club_code (invite_code),
  KEY idx_club_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS club_members (
  club_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (club_id, user_id),
  KEY idx_cm_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS club_picks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  club_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  match_id INT UNSIGNED NOT NULL,
  pick CHAR(1) NOT NULL,
  odds DECIMAL(6,2) NOT NULL DEFAULT 2.00,
  note VARCHAR(160) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cp (club_id, user_id, match_id),
  KEY idx_cp_match (match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quiz_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  qhash CHAR(32) NOT NULL,
  category VARCHAR(12) NOT NULL,
  question VARCHAR(300) NOT NULL,
  opt1 VARCHAR(120) NOT NULL,
  opt2 VARCHAR(120) NOT NULL,
  opt3 VARCHAR(120) NOT NULL,
  opt4 VARCHAR(120) NOT NULL,
  correct TINYINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_qhash (qhash),
  KEY idx_qcat (category, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quiz_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  season VARCHAR(9) NOT NULL,
  category VARCHAR(12) NOT NULL,
  qids VARCHAR(200) NOT NULL,
  seconds_allowed SMALLINT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL DEFAULT NULL,
  score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  seconds_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_qa_user (user_id, started_at),
  KEY idx_qa_season (season, score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS slips (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(80) NOT NULL,
  stake DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_slip_user (user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS slip_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slip_id INT UNSIGNED NOT NULL,
  sort_no SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  home VARCHAR(80) NOT NULL,
  away VARCHAR(80) NOT NULL,
  kickoff VARCHAR(20) NOT NULL DEFAULT '',
  outcome VARCHAR(60) NOT NULL,
  odds DECIMAL(8,2) NOT NULL,
  KEY idx_si_slip (slip_id, sort_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO quiz_questions (qhash, category, question, opt1, opt2, opt3, opt4, correct) VALUES
(MD5('The Bundesliga is the top football league of which country?'),'leagues','The Bundesliga is the top football league of which country?','Netherlands','Austria','Germany','Switzerland',3),
(MD5('How many clubs play in the English Premier League each season?'),'leagues','How many clubs play in the English Premier League each season?','18','22','24','20',4),
(MD5('Serie A is the top football league of which country?'),'leagues','Serie A is the top football league of which country?','Spain','Italy','France','Portugal',2),
(MD5('La Liga is the top football league of which country?'),'leagues','La Liga is the top football league of which country?','Portugal','Mexico','Spain','Italy',3),
(MD5('Which country''s top division is called the Eredivisie?'),'leagues','Which country''s top division is called the Eredivisie?','Denmark','Netherlands','Belgium','Norway',2),
(MD5('Ligue 1 is the top football division of which country?'),'leagues','Ligue 1 is the top football division of which country?','Switzerland','Canada','France','Belgium',3),
(MD5('Celtic and Rangers play in the top league of which country?'),'leagues','Celtic and Rangers play in the top league of which country?','Ireland','England','Wales','Scotland',4),
(MD5('Which is Europe''s second-biggest club competition after the Champions League?'),'leagues','Which is Europe''s second-biggest club competition after the Champions League?','FA Cup','UEFA Europa League','Copa del Rey','UEFA Nations League',2),
(MD5('The Copa Libertadores is contested by clubs from which continent?'),'leagues','The Copa Libertadores is contested by clubs from which continent?','Africa','Asia','South America','Europe',3),
(MD5('Gor Mahia and AFC Leopards play in the top league of which country?'),'leagues','Gor Mahia and AFC Leopards play in the top league of which country?','Uganda','Ethiopia','Tanzania','Kenya',4),
(MD5('Which is the top club competition in Africa?'),'leagues','Which is the top club competition in Africa?','COSAFA Cup','CAF Champions League','Copa Africa','Africa Super Series',2),
(MD5('Which English competition is a knockout cup open to clubs at all levels of the league pyramid?'),'leagues','Which English competition is a knockout cup open to clubs at all levels of the league pyramid?','Nations Cup','Community Shield','Super Cup','FA Cup',4),
(MD5('Which club plays its home games at Old Trafford?'),'clubs','Which club plays its home games at Old Trafford?','Everton','Liverpool','Manchester United','Manchester City',3),
(MD5('Which club plays its home games at Anfield?'),'clubs','Which club plays its home games at Anfield?','Everton','Newcastle United','Liverpool','Leeds United',3),
(MD5('Which club plays its home games at the Santiago Bernabeu?'),'clubs','Which club plays its home games at the Santiago Bernabeu?','Atletico Madrid','Sevilla','Barcelona','Real Madrid',4),
(MD5('Which club plays its home games at the Allianz Arena in Munich?'),'clubs','Which club plays its home games at the Allianz Arena in Munich?','Bayern Munich','Borussia Dortmund','Schalke 04','RB Leipzig',1),
(MD5('Which club is nicknamed The Gunners?'),'clubs','Which club is nicknamed The Gunners?','Arsenal','West Ham United','Chelsea','Tottenham Hotspur',1),
(MD5('Which Italian club is nicknamed The Old Lady?'),'clubs','Which Italian club is nicknamed The Old Lady?','Roma','Juventus','AC Milan','Inter Milan',2),
(MD5('Which club plays its home games at Stamford Bridge?'),'clubs','Which club plays its home games at Stamford Bridge?','Crystal Palace','Chelsea','Arsenal','Fulham',2),
(MD5('Ajax is based in which city?'),'clubs','Ajax is based in which city?','Utrecht','Rotterdam','Eindhoven','Amsterdam',4),
(MD5('Which two clubs share the San Siro stadium?'),'clubs','Which two clubs share the San Siro stadium?','AC Milan and Inter Milan','Roma and Lazio','Juventus and Torino','Napoli and Atalanta',1),
(MD5('Which club plays its home games at the Emirates Stadium?'),'clubs','Which club plays its home games at the Emirates Stadium?','Tottenham Hotspur','Arsenal','Brentford','West Ham United',2),
(MD5('Which club has won the most European Cup and Champions League titles?'),'clubs','Which club has won the most European Cup and Champions League titles?','Liverpool','AC Milan','Bayern Munich','Real Madrid',4),
(MD5('Which club is based in the city of Dortmund?'),'clubs','Which club is based in the city of Dortmund?','Bayer Leverkusen','Borussia Dortmund','VfL Bochum','Schalke 04',2),
(MD5('Which country is Mohamed Salah from?'),'players','Which country is Mohamed Salah from?','Algeria','Egypt','Morocco','Nigeria',2),
(MD5('Which country is Sadio Mane from?'),'players','Which country is Sadio Mane from?','Cameroon','Mali','Ghana','Senegal',4),
(MD5('Who captained Argentina to the 2022 World Cup title?'),'players','Who captained Argentina to the 2022 World Cup title?','Lionel Messi','Angel Di Maria','Paulo Dybala','Lautaro Martinez',1),
(MD5('Who scored 36 goals in the 2022/23 Premier League season, a record for a 38-game season?'),'players','Who scored 36 goals in the 2022/23 Premier League season, a record for a 38-game season?','Erling Haaland','Harry Kane','Mohamed Salah','Son Heung-min',1),
(MD5('Who is the all-time top scorer in World Cup history with 16 goals?'),'players','Who is the all-time top scorer in World Cup history with 16 goals?','Gerd Muller','Ronaldo Nazario','Just Fontaine','Miroslav Klose',4),
(MD5('Which Kenyan international played in the Premier League for Southampton and Tottenham Hotspur?'),'players','Which Kenyan international played in the Premier League for Southampton and Tottenham Hotspur?','McDonald Mariga','Victor Wanyama','Dennis Oliech','Michael Olunga',2),
(MD5('Didier Drogba is a legend of which national team?'),'players','Didier Drogba is a legend of which national team?','Cameroon','Ghana','Nigeria','Ivory Coast',4),
(MD5('Pele won three World Cups with which country?'),'players','Pele won three World Cups with which country?','Brazil','Portugal','Argentina','Uruguay',1),
(MD5('Cristiano Ronaldo was born in which country?'),'players','Cristiano Ronaldo was born in which country?','Italy','Spain','Portugal','Brazil',3),
(MD5('Kylian Mbappe plays international football for which country?'),'players','Kylian Mbappe plays international football for which country?','Belgium','Spain','France','Cameroon',3),
(MD5('Which player has won the most Ballon d Or awards?'),'players','Which player has won the most Ballon d Or awards?','Cristiano Ronaldo','Michel Platini','Lionel Messi','Johan Cruyff',3),
(MD5('Which position does a player called Sweeper-keeper mainly play?'),'players','Which position does a player called Sweeper-keeper mainly play?','Striker','Defensive midfielder','Goalkeeper','Winger',3),
(MD5('Which country won the 2018 World Cup?'),'history','Which country won the 2018 World Cup?','France','Belgium','Croatia','Germany',1),
(MD5('Which country won the 2010 World Cup?'),'history','Which country won the 2010 World Cup?','Germany','Netherlands','Uruguay','Spain',4),
(MD5('Which country won the 2014 World Cup?'),'history','Which country won the 2014 World Cup?','Germany','Argentina','Brazil','Netherlands',1),
(MD5('Which country hosted the 2022 World Cup?'),'history','Which country hosted the 2022 World Cup?','Qatar','Saudi Arabia','United Arab Emirates','Russia',1),
(MD5('Which two countries co-hosted the 2002 World Cup?'),'history','Which two countries co-hosted the 2002 World Cup?','Japan and South Korea','Germany and France','China and Japan','Mexico and USA',1),
(MD5('In which year was the first World Cup played?'),'history','In which year was the first World Cup played?','1950','1934','1930','1920',3),
(MD5('Which country has won the most men''s World Cups?'),'history','Which country has won the most men''s World Cups?','Argentina','Italy','Brazil','Germany',3),
(MD5('Which club won the Premier League in 2015/16 as 5000-1 outsiders?'),'history','Which club won the Premier League in 2015/16 as 5000-1 outsiders?','Everton','West Ham United','Leicester City','Tottenham Hotspur',3),
(MD5('Which country won Euro 2004 in one of football''s biggest shocks?'),'history','Which country won Euro 2004 in one of football''s biggest shocks?','Greece','Czech Republic','Denmark','Portugal',1),
(MD5('Which country won Euro 2024?'),'history','Which country won Euro 2024?','France','England','Germany','Spain',4),
(MD5('Which club beat AC Milan on penalties in the 2005 Champions League final in Istanbul?'),'history','Which club beat AC Milan on penalties in the 2005 Champions League final in Istanbul?','Liverpool','Chelsea','Juventus','Arsenal',1),
(MD5('Which country won the 1966 World Cup, hosting it?'),'history','Which country won the 1966 World Cup, hosting it?','Portugal','England','Brazil','West Germany',2),
(MD5('Which country won the 2023 Africa Cup of Nations, played in early 2024?'),'history','Which country won the 2023 Africa Cup of Nations, played in early 2024?','Ivory Coast','Nigeria','Senegal','Egypt',1),
(MD5('Which English club went unbeaten through the 2003/04 Premier League season?'),'history','Which English club went unbeaten through the 2003/04 Premier League season?','Arsenal','Manchester United','Chelsea','Liverpool',1),
(MD5('How many players from one team are on the pitch at kickoff?'),'rules','How many players from one team are on the pitch at kickoff?','12','9','10','11',4),
(MD5('How far is the penalty spot from the goal line?'),'rules','How far is the penalty spot from the goal line?','9 metres','16.5 metres','7 metres','11 metres (12 yards)',4),
(MD5('How long is a standard football match, not counting added time?'),'rules','How long is a standard football match, not counting added time?','100 minutes','90 minutes','120 minutes','80 minutes',2),
(MD5('What happens to a player who receives two yellow cards in one match?'),'rules','What happens to a player who receives two yellow cards in one match?','They get a warning','They miss the next half','They are fined only','They are sent off',4),
(MD5('What does VAR stand for?'),'rules','What does VAR stand for?','Video Action Review','Verified Assistant Referee','Visual Approved Ruling','Video Assistant Referee',4),
(MD5('Can a player be offside directly from a throw-in?'),'rules','Can a player be offside directly from a throw-in?','Only in the box','Only in extra time','Yes','No',4),
(MD5('Can a player be offside directly from a goal kick?'),'rules','Can a player be offside directly from a goal kick?','Only if the keeper kicks','Yes','Only in the second half','No',4),
(MD5('How many kicks does each team take at the start of a penalty shootout?'),'rules','How many kicks does each team take at the start of a penalty shootout?','5','11','7','3',1),
(MD5('What is awarded when the ball crosses the goal line last touched by an attacker?'),'rules','What is awarded when the ball crosses the goal line last touched by an attacker?','A goal kick','A corner kick','A drop ball','A throw-in',1),
(MD5('What is awarded when the ball crosses the goal line last touched by a defender?'),'rules','What is awarded when the ball crosses the goal line last touched by a defender?','A goal kick','A throw-in','A corner kick','An indirect free kick',3),
(MD5('What is the colour of the card that sends a player off immediately?'),'rules','What is the colour of the card that sends a player off immediately?','Red','Green','Yellow','Blue',1),
(MD5('Which rule, removed from UEFA competitions in 2021, gave extra weight to goals scored in the opponent stadium?'),'rules','Which rule, removed from UEFA competitions in 2021, gave extra weight to goals scored in the opponent stadium?','Offside rule','Away goals rule','Golden goal rule','Back-pass rule',2);

CREATE TABLE IF NOT EXISTS comment_votes (
  comment_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  value TINYINT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (comment_id, user_id),
  KEY idx_cv_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== Part 27 (update 20): database pictures, communities, comment pictures =====
CREATE TABLE IF NOT EXISTS media_blobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(20) NOT NULL DEFAULT 'post',
  owner_id INT UNSIGNED NOT NULL DEFAULT 0,
  mime VARCHAR(40) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  data MEDIUMBLOB NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_owner (owner_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS communities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(300) NOT NULL DEFAULT '',
  rules TEXT NULL,
  icon VARCHAR(16) NOT NULL DEFAULT '',
  color VARCHAR(7) NOT NULL DEFAULT '#155240',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_no INT NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO communities (slug, name, description, rules, icon, color, sort_no) VALUES
 ('general', 'General football talk', 'Anything football: transfers, tactics, news and banter.', 'Be respectful.\nNo links, ads or tout accounts.\nNever share passwords or codes.', '⚽', '#155240', 1);
SET @q = (SELECT IF(COUNT(*) = 0, "ALTER TABLE comments ADD COLUMN title VARCHAR(160) NULL DEFAULT NULL, ADD COLUMN image VARCHAR(60) NULL DEFAULT NULL, MODIFY target_type ENUM('tip','tipster','match','community') NOT NULL", 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'image');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ===== Part 28 (update 21): member public profiles and Jackpot =====
CREATE TABLE IF NOT EXISTS member_public (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  handle VARCHAR(20) NOT NULL,
  bio VARCHAR(160) NOT NULL DEFAULT '',
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  show_stakes TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_handle (handle),
  KEY idx_public (is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS member_follows (
  follower_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (follower_id, member_id),
  KEY idx_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jackpot_rounds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  amount_kes DECIMAL(12,2) NOT NULL DEFAULT 50000,
  consolation_kes DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('draft','open','settled') NOT NULL DEFAULT 'draft',
  closes_at DATETIME NULL DEFAULT NULL,
  note VARCHAR(600) NOT NULL DEFAULT '',
  winners_n INT UNSIGNED NOT NULL DEFAULT 0,
  prize_each DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  settled_at DATETIME NULL DEFAULT NULL,
  KEY idx_status (status, closes_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jackpot_games (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  round_id INT UNSIGNED NOT NULL,
  sort_no TINYINT UNSIGNED NOT NULL DEFAULT 0,
  home VARCHAR(80) NOT NULL,
  away VARCHAR(80) NOT NULL,
  league VARCHAR(80) NOT NULL DEFAULT '',
  kickoff_at DATETIME NULL DEFAULT NULL,
  match_id INT UNSIGNED NULL DEFAULT NULL,
  result CHAR(1) NULL DEFAULT NULL,
  KEY idx_round (round_id, sort_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jackpot_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  round_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  correct_n TINYINT UNSIGNED NOT NULL DEFAULT 0,
  prize_kes DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_at DATETIME NULL DEFAULT NULL,
  pay_note VARCHAR(120) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_entry (round_id, user_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jackpot_picks (
  entry_id INT UNSIGNED NOT NULL,
  game_id INT UNSIGNED NOT NULL,
  pick CHAR(1) NOT NULL,
  PRIMARY KEY (entry_id, game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @q = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE user_bets ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_bets' AND COLUMN_NAME = 'is_public');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Part 29 (update 22): community membership and covers

CREATE TABLE IF NOT EXISTS community_members (
  community_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (community_id, user_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE communities ADD COLUMN cover VARCHAR(60) NULL DEFAULT NULL;

-- Part 30 (update 23): global chat and group tags

CREATE TABLE IF NOT EXISTS global_chat (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  body VARCHAR(400) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE communities ADD COLUMN tags VARCHAR(200) NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS global_chat_seen (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  last_id BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Part 31 (update 24): reactions, mods, polls, attachments, match threads, chat extras, digest
CREATE TABLE IF NOT EXISTS comment_reactions (
  comment_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  emoji VARCHAR(8) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (comment_id, user_id, emoji),
  KEY idx_cr_comment (comment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_mods (
  community_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (community_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_polls (
  post_id INT UNSIGNED NOT NULL PRIMARY KEY,
  options TEXT NOT NULL,
  closes_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_poll_votes (
  post_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  opt TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (post_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_match_threads (
  community_id INT UNSIGNED NOT NULL,
  match_id INT UNSIGNED NOT NULL,
  post_id INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (community_id, match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_presence (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  seen_at DATETIME NOT NULL,
  typing_at DATETIME NULL,
  typing_room INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  msg_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cr (msg_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS digest_prefs (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  email_daily TINYINT(1) NOT NULL DEFAULT 0,
  last_sent DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'pinned');
SET @s := IF(@c = 0, 'ALTER TABLE comments ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'edited_at');
SET @s := IF(@c = 0, 'ALTER TABLE comments ADD COLUMN edited_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'attach_type');
SET @s := IF(@c = 0, 'ALTER TABLE comments ADD COLUMN attach_type VARCHAR(8) NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'attach_id');
SET @s := IF(@c = 0, 'ALTER TABLE comments ADD COLUMN attach_id INT UNSIGNED NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communities' AND COLUMN_NAME = 'team_keys');
SET @s := IF(@c = 0, 'ALTER TABLE communities ADD COLUMN team_keys VARCHAR(200) NOT NULL DEFAULT \'\'', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'global_chat' AND COLUMN_NAME = 'room');
SET @s := IF(@c = 0, 'ALTER TABLE global_chat ADD COLUMN room INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'global_chat' AND COLUMN_NAME = 'reply_to');
SET @s := IF(@c = 0, 'ALTER TABLE global_chat ADD COLUMN reply_to BIGINT UNSIGNED NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ===== Part 32 (database-update-25.sql) =====
-- SlipKing update 25: private groups, events, direct messages, odds watch, verified members.
-- Safe to run more than once. (The site also creates these by itself the first time they are used.)

CREATE TABLE IF NOT EXISTS community_requests (
  community_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (community_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  community_id INT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  note VARCHAR(300) NOT NULL DEFAULT '',
  starts_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ev (community_id, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_event_rsvp (
  event_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (event_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dm_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_id INT UNSIGNED NOT NULL,
  to_id INT UNSIGNED NOT NULL,
  body VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL DEFAULT NULL,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_pair (from_id, to_id, id),
  KEY idx_to (to_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dm_blocks (
  user_id INT UNSIGNED NOT NULL,
  blocked_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, blocked_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dm_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  msg_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rep (msg_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS odds_watch (
  user_id INT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  base_home DECIMAL(7,2) NULL DEFAULT NULL,
  base_draw DECIMAL(7,2) NULL DEFAULT NULL,
  base_away DECIMAL(7,2) NULL DEFAULT NULL,
  notified_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Guarded column changes go last so an earlier statement can never be skipped by an error.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communities' AND COLUMN_NAME = 'is_private');
SET @s := IF(@c = 0, 'ALTER TABLE communities ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_public' AND COLUMN_NAME = 'verified');
SET @s := IF(@c = 0, 'ALTER TABLE member_public ADD COLUMN verified TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ===== Part 33 (database-update-26.sql) =====
-- SlipKing update 26: Android app releases (upload the APK in Admin > Android app, members download it from download-app.php).
-- Safe to run more than once.
CREATE TABLE IF NOT EXISTS app_releases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version VARCHAR(30) NOT NULL,
  notes TEXT NULL,
  min_android VARCHAR(20) NOT NULL DEFAULT '',
  file_name VARCHAR(80) NOT NULL DEFAULT '',
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL DEFAULT '',
  external_url VARCHAR(300) NOT NULL DEFAULT '',
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  downloads INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_current (is_current, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== Part 34 (database-update-27.sql) =====
-- SlipKing update 27: Firebase push for the Android app (phone tokens and the send queue).
-- Safe to run more than once.
CREATE TABLE IF NOT EXISTS app_devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token VARCHAR(255) NOT NULL,
  platform VARCHAR(12) NOT NULL DEFAULT 'android',
  app_version VARCHAR(30) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token(191)),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS app_push_queue (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(150) NOT NULL DEFAULT '',
  body VARCHAR(300) NOT NULL DEFAULT '',
  path VARCHAR(250) NOT NULL DEFAULT '',
  tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sent_at DATETIME NULL,
  error VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pending (sent_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
