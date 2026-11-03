<?php
/**
 * Self-installing tables. When a page needs a table that is missing (the owner did not import a SQL update), it can create it itself.
 * Only CREATE TABLE IF NOT EXISTS and INSERT IGNORE statements are ever run, so this can never delete or change existing data.
 */

/** Runs the CREATE TABLE IF NOT EXISTS / INSERT IGNORE statements of an SQL text. Returns how many ran without error. */
function sk_install_sql_text(string $sql): int
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $ran = 0;
    foreach (preg_split('/;\s*(\r?\n|$)/', (string)$sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || !preg_match('/^(CREATE TABLE IF NOT EXISTS|INSERT IGNORE)\b/i', $stmt)) {
            continue;
        }
        try { db()->exec($stmt); $ran++; } catch (Throwable $e) { error_log('installer: ' . $e->getMessage()); }
    }
    return $ran;
}

/** Makes sure $table exists, creating it from the SQL file if needed. Returns true when the table is usable. */
function sk_ensure_table(string $table, string $sqlFile): bool
{
    static $done = [];
    if (isset($done[$table])) {
        return $done[$table];
    }
    $q = function () use ($table) { try { db()->query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 0'); return true; } catch (Throwable $e) { return false; } };
    if ($q()) { return $done[$table] = true; }
    $path = dirname(__DIR__) . '/' . ltrim($sqlFile, '/');
    if (is_file($path)) { sk_install_sql_text((string)file_get_contents($path)); }
    return $done[$table] = $q();
}

/** Adds a column when it is missing (guarded, never changes existing data). Returns true when the column exists afterwards. */
function sk_ensure_column(string $table, string $col, string $ddl): bool
{
    static $done = [];
    $key = $table . '.' . $col;
    if (isset($done[$key])) {
        return $done[$key];
    }
    $t = str_replace('`', '', $table);
    $c = str_replace('`', '', $col);
    $has = function () use ($t, $c) { try { return (bool)db()->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch(); } catch (Throwable $e) { return false; } };
    if (!$has()) {
        try { db()->exec("ALTER TABLE `$t` ADD COLUMN `$c` $ddl"); } catch (Throwable $e) { error_log('ensure_column: ' . $e->getMessage()); }
    }
    return $done[$key] = $has();
}
