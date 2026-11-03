<?php
/**
 * Database backup helpers (PDO only - mysqldump is not needed).
 * Dumps are gzip files in /backups, which is closed to the web by backups/.htaccess.
 * NOTE: a backup kept on the same server does not help if the whole server is lost.
 * Download copies regularly (Admin > Backups) or copy the folder somewhere else.
 */

const BACKUP_NAME_RE = '/^slipking-\d{8}-\d{6}\.sql\.gz$/';

function backup_dir(): string
{
    $d = dirname(__DIR__) . '/backups';
    if (!is_dir($d)) {
        @mkdir($d, 0755, true);
    }
    return $d;
}

/** Quotes one value for an INSERT statement. */
function backup_quote(PDO $pdo, $v): string
{
    if ($v === null) {
        return 'NULL';
    }
    return $pdo->quote((string)$v);
}

/** Builds one multi-row INSERT for $rows (list of assoc arrays). */
function backup_insert_sql(PDO $pdo, string $table, array $cols, array $rows): string
{
    $colList = '`' . implode('`,`', array_map(function ($c) { return str_replace('`', '``', $c); }, $cols)) . '`';
    $vals = [];
    foreach ($rows as $r) {
        $one = [];
        foreach ($cols as $c) {
            $one[] = backup_quote($pdo, $r[$c]);
        }
        $vals[] = '(' . implode(',', $one) . ')';
    }
    return 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . $colList . ") VALUES\n" . implode(",\n", $vals) . ";\n";
}

/**
 * Writes a full dump to backups/slipking-YYYYmmdd-HHMMSS.sql.gz and returns the file name.
 * Throws on failure. Memory use is bounded by the batch size.
 */
function backup_create(PDO $pdo): string
{
    @set_time_limit(300);
    $name = 'slipking-' . date('Ymd-His') . '.sql.gz';
    $path = backup_dir() . '/' . $name;
    $gz = @gzopen($path, 'wb6');
    if (!$gz) {
        throw new RuntimeException('Cannot write to the backups folder.');
    }
    gzwrite($gz, "-- SlipKing backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $q = '`' . str_replace('`', '``', $t) . '`';
        $create = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM);
        gzwrite($gz, "DROP TABLE IF EXISTS $q;\n" . $create[1] . ";\n\n");
        $offset = 0;
        $batch  = 500;
        while (true) {
            $rows = $pdo->query('SELECT * FROM ' . $q . ' LIMIT ' . $batch . ' OFFSET ' . $offset)->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }
            gzwrite($gz, backup_insert_sql($pdo, $t, array_keys($rows[0]), $rows));
            $offset += $batch;
            if (count($rows) < $batch) {
                break;
            }
        }
        gzwrite($gz, "\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n-- end of backup\n");
    gzclose($gz);
    @chmod($path, 0640);
    return $name;
}

/** Existing backups, newest first: list of ['name','size','time']. */
function backup_list(): array
{
    $out = [];
    foreach ((array)glob(backup_dir() . '/slipking-*.sql.gz') as $f) {
        $n = basename($f);
        if (preg_match(BACKUP_NAME_RE, $n)) {
            $out[] = ['name' => $n, 'size' => (int)filesize($f), 'time' => (int)filemtime($f)];
        }
    }
    usort($out, function ($a, $b) { return strcmp($b['name'], $a['name']); });
    return $out;
}

/** Deletes all but the newest $keep backups. Returns how many were removed. */
function backup_prune(int $keep): int
{
    $keep = max(1, $keep);
    $n = 0;
    foreach (array_slice(backup_list(), $keep) as $b) {
        if (@unlink(backup_dir() . '/' . $b['name'])) {
            $n++;
        }
    }
    return $n;
}
