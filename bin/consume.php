<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/jetstream.php';
require_once __DIR__ . '/../src/chains.php';

/**
 * Returns an exclusive lock resource, or null if another run is active.
 */
function acquire_lock(): mixed
{
    $lockPath = dirname(cfg('sqlite_path')) . '/consume.lock';
    $fp = fopen($lockPath, 'c');
    if ($fp === false) {
        return null;
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return null;
    }
    return $fp;
}

function drain_jetstream(): int
{
    $cursor = get_cursor();
    $client = new JetstreamClient(cfg('jetstream_url'));
    $budget = cfg('consume_budget_seconds');
    $startedAt = microtime(true);
    $nowUs = (int) (microtime(true) * 1000000);
    $lastUs = $cursor;
    $inserted = 0;

    try {
        $client->connect($cursor);
    } catch (RuntimeException $e) {
        error_log("jetstream: connect failed: {$e->getMessage()}");
        save_cursor($cursor);
        return 0;
    }

    fprintf(STDERR, "jetstream: connected, draining from cursor {$cursor}\n");

    while (true) {
        if (microtime(true) - $startedAt >= $budget) {
            break;
        }
        $msg = $client->nextMessage();
        if ($msg === null || $msg === []) {
            if ($msg === null) {
                break;
            }
            continue;
        }

        if (($msg['kind'] ?? '') !== 'commit' || !is_int($msg['time_us'] ?? null)) {
            continue;
        }
        $timeUs = $msg['time_us'];
        $lastUs = max($lastUs, $timeUs);

        if ($timeUs >= $nowUs - 2000000) {
            break;
        }

        $commit = $msg['commit'] ?? null;
        if (!is_array($commit)) {
            continue;
        }
        if (($commit['operation'] ?? '') !== 'create' || ($commit['collection'] ?? '') !== 'app.bsky.feed.post') {
            continue;
        }
        $record = $commit['record'] ?? null;
        if (!is_array($record)) {
            continue;
        }
        $quotedUri = extract_quoted_uri($record);
        if ($quotedUri === null) {
            continue;
        }
        $did = is_string($msg['did'] ?? null) ? $msg['did'] : '';
        $rkey = is_string($commit['rkey'] ?? null) ? $commit['rkey'] : '';
        if ($did === '' || $rkey === '') {
            continue;
        }

        $uri = "at://{$did}/app.bsky.feed.post/{$rkey}";
        $text = is_string($record['text'] ?? null) ? $record['text'] : '';
        $createdAt = is_string($record['createdAt'] ?? null) ? $record['createdAt'] : gmdate('c', 0);
        if (post_insert($uri, null, $did, $text, $createdAt, $timeUs, $quotedUri, 0)) {
            $inserted++;
        }

        if ($inserted > 0 && $inserted % 500 === 0) {
            save_cursor($lastUs);
        }
    }

    $client->disconnect();
    save_cursor($lastUs);
    fprintf(
        STDERR,
        "jetstream: drained in %.1fs, %d new quote posts, cursor now %d\n",
        microtime(true) - $startedAt,
        $inserted,
        $lastUs
    );
    return $inserted;
}

function evaluate_pending(): int
{
    $batch = unevaluated_posts(cfg('eval_batch_size'));
    if ($batch === []) {
        return 0;
    }

    // Resolve parents from local storage first; collect the rest for fetching.
    $resolved = [];
    $missing = [];
    foreach ($batch as $row) {
        $pu = (string) $row['parent_uri'];
        if (array_key_exists($pu, $resolved)) {
            continue;
        }
        $cached = cached_post_get($pu);
        if ($cached !== null) {
            $resolved[$pu] = $cached;
            continue;
        }
        $stored = post_get($pu);
        if ($stored !== null) {
            $resolved[$pu] = $stored;
            continue;
        }
        $missing[] = $pu;
    }

    // Fetch missing parents in batches, within this run's budget. Chunks that
    // fail with a transport error stay unevaluated for a retry next run;
    // chunks that succeed but lack the post mean it's gone -> give up on it.
    $attempted = [];
    $cap = cfg('parent_fetch_max_per_run');
    $size = cfg('parent_fetch_batch_size');
    $missing = array_values(array_unique($missing));
    for ($i = 0; $i < count($missing) && $i < $cap; $i += $size) {
        $chunk = array_slice($missing, $i, $size);
        try {
            $fetched = bsky_fetch_posts($chunk);
        } catch (RuntimeException $e) {
            error_log("evaluate: parent fetch failed, will retry chunk: {$e->getMessage()}");
            continue;
        }
        foreach ($chunk as $pu) {
            $attempted[$pu] = true;
            if (isset($fetched[$pu])) {
                cached_post_put($fetched[$pu], time() * 1000000);
                store_fetched_parent($fetched[$pu]);
                $resolved[$pu] = $fetched[$pu];
            } else {
                $resolved[$pu] = null;
            }
        }
    }

    $mutations = 0;
    foreach ($batch as $row) {
        $parentUri = (string) $row['parent_uri'];
        if (!array_key_exists($parentUri, $resolved)) {
            continue; // unresolvable this run -> retried next run
        }
        $parent = $resolved[$parentUri];
        if ($parent === null && !isset($attempted[$parentUri])) {
            continue; // local lookup miss but never fetched -> retry
        }
        if ($parent === null) {
            mark_evaluated((string) $row['uri']); // deleted or unavailable
            continue;
        }
        $chainId = evaluate_mutation_edge(
            $parent,
            (string) $row['uri'],
            (string) $row['text'],
            (int) $row['indexed_us']
        );
        if ($chainId !== null) {
            $mutations++;
        } else {
            mark_evaluated((string) $row['uri']);
        }
    }
    return $mutations;
}

$lock = acquire_lock();
if ($lock === null) {
    fwrite(STDERR, "another consume run is active, skipping\n");
    exit(0);
}

$startStats = stats();
$inserted = drain_jetstream();
$mutations = evaluate_pending();
$endStats = stats();

fprintf(
    STDERR,
    "run done: +%d posts, %d new mutations | totals: %d posts, %d mutations, %d qualifying chains\n",
    $inserted,
    $mutations,
    $endStats['posts'],
    $endStats['mutations'],
    $endStats['qualifying_chains']
);

flock($lock, LOCK_UN);
fclose($lock);
