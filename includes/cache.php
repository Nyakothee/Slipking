<?php
/**
 * Tiny file cache for public, member-independent data (home page numbers and tip lists).
 * Entries live 60 seconds by default and every admin action clears them, so edits show up at once.
 * If the cache folder is not writable the data is simply fetched every time (nothing breaks).
 */
function cache_dir(): string
{
    $d = dirname(__DIR__) . '/cache/data';
    if (!is_dir($d)) { @mkdir($d, 0755, true); @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n"); }
    return $d;
}

/** Deletes cache files older than $maxAge seconds so the folder can never grow. */
function cache_prune(int $maxAge = 3600): int
{
    $n = 0;
    foreach ((array)@glob(cache_dir() . '/*.cache') as $f) {
        if (time() - (int)@filemtime($f) > $maxAge && @unlink($f)) { $n++; }
    }
    return $n;
}

function cache_remember(string $key, int $ttl, callable $make)
{
    if (mt_rand(1, 100) === 1) { cache_prune(max(3600, $ttl * 10)); }   // now and then, tidy up expired files
    $f = cache_dir() . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $key) . '.cache';
    if (is_file($f) && time() - (int)@filemtime($f) < $ttl) {
        $raw = @file_get_contents($f);
        $v = $raw === false ? false : @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($v) && array_key_exists('v', $v)) { return $v['v']; }
    }
    $val = $make();
    @file_put_contents($f, serialize(['v' => $val]), LOCK_EX);
    return $val;
}

function cache_flush(): void
{
    foreach ((array)@glob(cache_dir() . '/*.cache') as $f) { @unlink($f); }
}
