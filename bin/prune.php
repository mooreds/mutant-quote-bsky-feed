<?php

declare(strict_types=1);

/**
 * Reclaims disk space by deleting rows that can never affect the feed:
 *
 *   - posts_cache entries past their TTL (the evaluator ignores them anyway)
 *   - posts that were evaluated as non-mutations, belong to no chain,
 *     and are older than the retention window
 *
 * Never touches: pending (unevaluated) posts, chain members (including
 * chain roots, whose is_mutation is 0), chains themselves, cursor state.
 *
 * If a pruned parent is quoted again later, evaluation re-fetches it from
 * the public API, so pruning is always safe.
 *
 * Usage:
 *   php bin/prune.php                  # delete + truncate WAL
 *   php bin/prune.php --dry-run        # show what would go
 *   php bin/prune.php --vacuum         # also shrink the db file itself
 *                                        (needs transiently ~2x db size free)
 *   php bin/prune.php --days=7         # override retention window
 */

require_once __DIR__ . '/../src/db.php';

$opts = getopt('', ['dry-run', 'vacuum', 'days::']);
$dryRun = isset($opts['dry-run']);
$doVacuum = isset($opts['vacuum']);
$days = isset($opts['days']) ? max(1, (int) $opts['days']) : (int) (getenv('PRUNE_KEEP_DAYS') ?: 14);

$dbPath = cfg('sqlite_path');
$walPath = $dbPath . '-wal';
$sizeOf = fn (string $p): int => is_file($p) ? (int) filesize($p) : 0;
$before = $sizeOf($dbPath) + $sizeOf($walPath);
$fmt = fn (int $b): string => number_format((float) $b / 1048576, 1) . ' MB';

$postCutoffUs = (time() - $days * 86400) * 1000000;
$cacheTtlDays = cfg('cache_ttl_days');
$cacheCutoffUs = (time() - $cacheTtlDays * 86400) * 1000000;

$pdo = db();
$count = static function (string $sql, array $args) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return (int) $stmt->fetchColumn();
};

$expiredCache = $count(
    'SELECT COUNT(*) FROM post_cache WHERE fetched_us <= ?',
    [$cacheCutoffUs]
);
$prunablePosts = $count(
    'SELECT COUNT(*) FROM posts
     WHERE chain_id IS NULL AND is_mutation = 0 AND evaluated = 1 AND indexed_us <= ?',
    [$postCutoffUs]
);
$pendingOld = $count(
    'SELECT COUNT(*) FROM posts
     WHERE evaluated = 0 AND indexed_us <= ?',
    [$postCutoffUs]
);

fprintf(STDERR, "prune: keeping %d days | %s db (+wal)\n", $days, $fmt($before));
fprintf(STDERR, "prune: %d expired cache rows, %d prunable posts (%d old posts still pending evaluation, kept)\n",
    $expiredCache, $prunablePosts, $pendingOld);

if ($dryRun) {
    fprintf(STDERR, "prune: dry run, nothing deleted\n");
    exit(0);
}

$pdo->beginTransaction();
if ($expiredCache > 0) {
    $pdo->prepare('DELETE FROM post_cache WHERE fetched_us <= ?')->execute([$cacheCutoffUs]);
}
if ($prunablePosts > 0) {
    $pdo->prepare(
        'DELETE FROM posts
         WHERE chain_id IS NULL AND is_mutation = 0 AND evaluated = 1 AND indexed_us <= ?'
    )->execute([$postCutoffUs]);
}
$pdo->commit();

$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

if ($doVacuum) {
    $needed = $sizeOf($dbPath) * 2;
    $free = (int) (@disk_free_space(dirname($dbPath) ?: '.'));
    if ($free > 0 && $free < $needed) {
        fprintf(STDERR,
            "prune: skipping VACUUM, only %s free (want ~%s transiently). Free up space and retry.\n",
            $fmt($free), $fmt($needed));
    } else {
        fprintf(STDERR, "prune: vacuuming (may take a moment)...\n");
        $pdo->exec('VACUUM');
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    }
}

$after = $sizeOf($dbPath) + $sizeOf($walPath);
$s = stats();
fprintf(STDERR, "prune: done in %s -> %s | feed totals unchanged: %d posts, %d mutations, %d qualifying chains\n",
    $fmt($before), $fmt($after), $s['posts'], $s['mutations'], $s['qualifying_chains']);
