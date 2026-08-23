<?php

declare(strict_types=1);

function load_env_file(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        $len = strlen($val);
        if ($len >= 2 && (($val[0] === '"' && $val[$len - 1] === '"') || ($val[0] === "'" && $val[$len - 1] === "'"))) {
            $val = substr($val, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$val");
        }
    }
}

function project_root(): string
{
    return dirname(__DIR__);
}

load_env_file(project_root() . '/.env');

function cfg(string $key)
{
    static $config = null;
    if ($config === null) {
        $root = project_root();
        $config = [
            'hostname' => getenv('FEED_HOSTNAME') ?: 'localhost:2567',
            'port' => (int) (getenv('PORT') ?: 2567),
            'sqlite_path' => getenv('SQLITE_PATH') ?: ($root . '/data/mutant-quotes.db'),
            'jetstream_url' => getenv('JETSTREAM_URL') ?: 'wss://jetstream2.us-east.bsky.network/subscribe',
            'public_api_url' => getenv('PUBLIC_API_URL') ?: 'https://public.api.bsky.app',

            'publisher_did' => getenv('PUBLISHER_DID') ?: '',
            'feed_rkey' => getenv('FEED_RKEY') ?: 'mutant-quotes',
            'feed_display_name' => getenv('FEED_DISPLAY_NAME') ?: 'Mutant Quote Chains',
            'feed_description' => getenv('FEED_DESCRIPTION')
                ?: 'Threads where every quote subtly changes the text of the one before it. A game of telephone on Bluesky.',

            'min_chain_edges' => (int) (getenv('MIN_CHAIN_EDGES') ?: 3),
            'min_text_length' => (int) (getenv('MIN_TEXT_LENGTH') ?: 12),
            'sim_threshold' => (float) (getenv('SIM_THRESHOLD') ?: 0.72),
            'token_coverage_min' => (float) (getenv('TOKEN_COVERAGE_MIN') ?: 0.7),
            'token_shared_min' => (int) (getenv('TOKEN_SHARED_MIN') ?: 8),

            'consume_budget_seconds' => (int) (getenv('CONSUME_BUDGET_SECONDS') ?: 40),
            'eval_batch_size' => (int) (getenv('EVAL_BATCH_SIZE') ?: 150),
            'parent_fetch_max_per_run' => (int) (getenv('PARENT_FETCH_MAX_PER_RUN') ?: 400),
            'parent_fetch_batch_size' => (int) (getenv('PARENT_FETCH_BATCH_SIZE') ?: 25),
            'cache_ttl_days' => (int) (getenv('CACHE_TTL_DAYS') ?: 14),
        ];
        $dir = dirname($config['sqlite_path']);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
    return $config[$key] ?? null;
}

function service_did(): string
{
    $override = getenv('SERVICE_DID');
    if ($override) {
        return $override;
    }
    return 'did:web:' . cfg('hostname');
}

function feed_uri(): string
{
    return sprintf('at://%s/app.bsky.feed.generator/%s', cfg('publisher_did'), cfg('feed_rkey'));
}
