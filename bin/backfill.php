<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/chains.php';

function parse_arg_value(string $name, int $default): int
{
    global $argv;
    $i = array_search("--{$name}", $argv, true);
    if ($i === false || !isset($argv[$i + 1]) || !is_numeric($argv[$i + 1])) {
        return $default;
    }
    return (int) $argv[$i + 1];
}

function resolve_seed_uri(string $input): string
{
    if (str_starts_with($input, 'at://')) {
        return $input;
    }
    if (preg_match('#^https?://bsky\.app/profile/([^/]+)/post/([a-z0-9]+)$#i', $input, $m) === 1) {
        [, $handleOrDid, $rkey] = $m;
        if (str_starts_with($handleOrDid, 'did:')) {
            return "at://{$handleOrDid}/app.bsky.feed.post/{$rkey}";
        }
        $did = bsky_resolve_handle($handleOrDid);
        if ($did === null) {
            throw new RuntimeException("could not resolve handle: {$handleOrDid}");
        }
        return "at://{$did}/app.bsky.feed.post/{$rkey}";
    }
    throw new InvalidArgumentException("cannot parse '{$input}'; use an at:// URI or a bsky.app post URL");
}

$input = $argv[1] ?? null;
if ($input === null) {
    fwrite(STDERR, "Usage: php bin/backfill.php <post-uri-or-url> [--depth 6] [--max 2000]\n");
    exit(1);
}

$depth = parse_arg_value('depth', 6);
$maxPosts = parse_arg_value('max', 2000);

try {
    $seedUri = resolve_seed_uri($input);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}

fprintf(STDERR, "backfilling from {$seedUri} (max depth {$depth}, max {$maxPosts} posts)\n");

$seed = bsky_fetch_posts([$seedUri])[$seedUri] ?? null;
if ($seed === null) {
    fwrite(STDERR, "error: seed post not found or has no text\n");
    exit(1);
}

if (post_get($seedUri) === null) {
    post_insert(
        $seed['uri'], $seed['cid'], $seed['author_did'], $seed['text'],
        $seed['created_at'], iso_to_micros($seed['created_at']), null, 1
    );
}

$processed = 1;
$frontier = [$seed];

for ($level = 1; $level <= $depth && $frontier !== []; $level++) {
    $next = [];

    foreach ($frontier as $parent) {
        if ($processed >= $maxPosts) {
            break;
        }

        $cursor = null;
        do {
            $page = bsky_get_quotes_page($parent['uri'], $cursor);
            if ($page === null) {
                fwrite(STDERR, "warning: getQuotes failed for {$parent['uri']}, skipping rest of node\n");
                break;
            }
            $cursor = $page['nextCursor'];

            foreach ($page['posts'] as $child) {
                if ($processed >= $maxPosts) {
                    break;
                }
                if (post_get($child['uri']) !== null) {
                    continue; // already processed
                }

                post_insert(
                    $child['uri'], $child['cid'], $child['author_did'], $child['text'],
                    $child['created_at'], iso_to_micros($child['created_at']), $parent['uri'], 0
                );
                $processed++;

                $result = detect_mutation($parent['text'], $child['text']);
                if ($result['isMutation']) {
                    $chainId = add_mutation_edge($parent['uri'], $child['uri'], iso_to_micros($child['created_at']));
                    fprintf(
                        STDERR,
                        "d%d mutation: cov=%.2f via %s %s <- %s (chain #%d)\n",
                        $level, $result['similarity'], $result['reason'],
                        $parent['uri'], $child['uri'], $chainId
                    );
                    $next[] = $child;
                } else {
                    fprintf(STDERR, "d%d plain quote: %s <- %s (%s)\n", $level, $parent['uri'], $child['uri'], $result['reason']);
                }
            }
        } while ($cursor !== null && $processed < $maxPosts);

        if ($processed >= $maxPosts) {
            break;
        }
    }

    $frontier = $next;
    fprintf(STDERR, "--- depth {$level}: %d chain posts to expand, {$processed} total\n", count($frontier));
}

$s = stats();
fprintf(
    STDERR,
    "\ndone. db now has %d posts, %d mutations, %d qualifying chains (>= %d edges).\n",
    $s['posts'], $s['mutations'], $s['qualifying_chains'], cfg('min_chain_edges')
);
