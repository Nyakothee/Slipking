#!/bin/sh
# Runs every time the container starts.
# 1) If there is no includes/config.php (it is not kept in the repo), build it from Render environment variables.
# 2) Make sure the writable folders exist (a mounted disk starts empty) and belong to Apache.
set -e
cd /var/www/html

CFG=includes/config.php
if [ ! -f "$CFG" ]; then
  : "${SITE_URL:?Set SITE_URL, e.g. https://yourdomain.com (no trailing slash)}"
  : "${DB_HOST:?Set DB_HOST}" ; : "${DB_NAME:?Set DB_NAME}" ; : "${DB_USER:?Set DB_USER}" ; : "${DB_PASS:?Set DB_PASS}"
  php -r '
    $e = function ($k, $d = "") { $v = getenv($k); return var_export($v === false || $v === "" ? $d : $v, true); };
    $out = "<?php\n"
      . "define(\"SITE_NAME\", " . $e("SITE_NAME", "SlipKing") . ");\n"
      . "\$__siteUrl = rtrim(" . $e("SITE_URL") . ", \"/\");\n"
      . "define(\"SITE_URL\", \$__siteUrl);\n"
      . "define(\"SITE_EMAIL\", " . $e("SITE_EMAIL", "support@localhost") . ");\n"
      . "define(\"DB_HOST\", " . $e("DB_HOST") . ");\n"
      . "define(\"DB_NAME\", " . $e("DB_NAME") . ");\n"
      . "define(\"DB_USER\", " . $e("DB_USER") . ");\n"
      . "define(\"DB_PASS\", " . $e("DB_PASS") . ");\n"
      . "define(\"TIMEZONE\", " . $e("TIMEZONE", "Africa/Nairobi") . ");\n"
      . "define(\"DB_TZ_OFFSET\", " . $e("DB_TZ_OFFSET", "+03:00") . ");\n"
      . "define(\"CURRENCY\", " . $e("CURRENCY", "KES") . ");\n"
      . "define(\"DEBUG\", false);\n"
      . "date_default_timezone_set(TIMEZONE);\n"
      . "ini_set(\"display_errors\", \"0\");\n"
      . "ini_set(\"log_errors\", \"1\");\n"
      . "ini_set(\"error_log\", dirname(__DIR__) . \"/logs/php-errors.log\");\n"
      . "error_reporting(E_ALL);\n";
    file_put_contents("includes/config.php", $out);
  '
  echo "Created includes/config.php from environment variables."
fi

for d in cache/sessions cache/data cache/tipimg logs uploads; do mkdir -p "$d"; done
chown -R www-data:www-data cache logs uploads 2>/dev/null || true

exec docker-php-entrypoint "$@"
