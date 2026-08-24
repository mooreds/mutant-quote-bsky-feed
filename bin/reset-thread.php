<?php

declare(strict_types=1);

/**
 * Deletes a seed post and every descendant reachable via quoted-parent
 * links, so the next backfill of that URL re-inserts and re-judges
 * everything under current detection thresholds.
 *
 * Only ever touches plain posts: rejected posts have chain_id NULL, so
 * chains, mutation flags elsewhere, and cursor state are untouched.
 *
 * Usage: php bin/reset-thread.php https://bsky.app/profile/handle/post/rkey
 */

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/bsky.php';

if ($argc < 2) {
    fwrite(STDERR, "usage: php bin/reset-thread.php <bsky.app post url>\n");
    exit(1);
}

$uri = null;
foreach ([['at://', $argv[1]], ['https://bsky.app/profile/', $argv[1]], ['http://bsky.app/profile/', $argv[1]]] as [$prefix, $arg]) {
    if (str_starts_with($arg, $prefix)) {
        $rest = substr($arg, strlen($prefix));
        if ($prefix === 'at://') {
            $uri = $rest;
            break;
        }
        if (preg_match('#^[^/]+/post/([a-z0-9]+)#i', $rest, $m)) {
            $handle = explode('/post/', $rest)[0];
            $did = bsky_resolve_handle($handle);
            if ($did === null) {
                fwrite(STDERR, "error: could not resolve handle {$handle}\n");
                exit(1);
            }
            $uri = "at://{$did}/app.bsky.feed.post/{$m[1]}";
            break;
        }
    }
}
if ($uri === null) {
    fwrite(STDERR, "error: unrecognized post url: {$argv[1]}\n");
    exit(1);
}

$stmt = db()->prepare(
    'WITH RECURSIVE sub(uri) AS (
       SELECT ?
       UNION
       SELECT p.uri FROM posts p JOIN sub s ON p.parent_uri = s.uri
     )
     SELECT COUNT(*), COALESCE(SUM(is_mutation), 0) FROM posts WHERE uri IN (SELECT uri FROM sub)'
);
$stmt->execute([$uri]);
[$total, $mutations] = $stmt->fetch(PDO::FETCH_NUM);

$del = db()->prepare(
    'DELETE FROM posts WHERE uri IN (
       WITH RECURSIVE sub(uri) AS (
         SELECT ?
         UNION
         SELECT p.uri FROM posts p JOIN sub s ON p.parent_uri = s.uri
       )
       SELECT uri FROM sub
     )'
);
$del->execute([$uri]);

fprintf(STDERR, "reset: removed %d posts (%d had been flagged mutations) rooted at\n  %s\nrerun backfill on this url to re-judge under current thresholds.\n", $total, $mutations, $uri);
