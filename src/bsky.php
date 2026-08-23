<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const BSKY_FALLBACK_API_URLS = ['https://api.bsky.app', 'https://public.api.bsky.app'];

/**
 * Endpoints to try in order: configured first, known-good mirrors after.
 * @return string[]
 */
function bsky_api_base_urls(): array
{
    $urls = [rtrim((string) cfg('public_api_url'), '/')];
    foreach (BSKY_FALLBACK_API_URLS as $u) {
        if (!in_array($u, $urls, true)) {
            $urls[] = $u;
        }
    }
    return $urls;
}

/**
 * GET against the Bluesky API, failing over between endpoints on transport
 * errors or non-200s. Only a sub-400 response pins the endpoint as preferred
 * for the lifetime of the process.
 * @return array{0:string|false,1:int,2:string} [body, http status, curl error]
 */
function bsky_http_get(string $path): array
{
    static $preferred = null;
    $urls = $preferred !== null ? [$preferred] : bsky_api_base_urls();
    $body = false;
    $status = 0;
    $err = 'no endpoints configured';
    foreach ($urls as $base) {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'mutant-quotes-php/1.0',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($body !== false && $status > 0 && $status < 400) {
            $preferred = $base;
            return [$body, $status, $err];
        }
        error_log("bsky api: {$base}{$path} failed: {$err} status={$status}");
    }
    return [$body, $status, $err];
}

function bsky_xrpc_get(string $endpoint, array $params): ?array
{
    $query = [];
    foreach ($params as $k => $v) {
        if ($v !== null) {
            $query[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
    }
    $path = '/xrpc/' . $endpoint;
    if ($query) {
        $path .= '?' . implode('&', $query);
    }
    [$body, $status, $err] = bsky_http_get($path);

    if ($body === false || $status >= 500) {
        error_log("bsky api: {$endpoint} failed: {$err} status={$status}");
        return null;
    }
    if ($status >= 400) {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * @param string[] $uris max 25
 * @return array<string,array> uri => post
 */
function bsky_fetch_posts(array $uris): array
{
    $out = [];
    $uris = array_values(array_filter($uris));
    if ($uris === []) {
        return $out;
    }
    $query = implode('&', array_map(fn ($u) => 'uris=' . rawurlencode($u), $uris));
    [$body, $status, $err] = bsky_http_get('/xrpc/app.bsky.feed.getPosts?' . $query);

    if ($body === false || $status >= 500) {
        throw new RuntimeException("bsky api: getPosts failed: {$err} status={$status}");
    }
    if ($status >= 400) {
        return $out;
    }
    $data = json_decode($body, true);
    foreach (($data['posts'] ?? []) as $p) {
        $text = $p['record']['text'] ?? null;
        if (!is_string($text) || $text === '') {
            continue;
        }
        $out[$p['uri']] = [
            'uri' => $p['uri'],
            'cid' => $p['cid'] ?? '',
            'author_did' => $p['author']['did'] ?? '',
            'text' => $text,
            'created_at' => is_string($p['record']['createdAt'] ?? null)
                ? $p['record']['createdAt']
                : gmdate('c', 0),
        ];
    }
    return $out;
}

/** @return array{posts:array,nextCursor:?string}|null */
function bsky_get_quotes_page(string $uri, ?string $cursor): ?array
{
    $data = bsky_xrpc_get('app.bsky.feed.getQuotes', [
        'uri' => $uri,
        'limit' => 100,
        'cursor' => $cursor,
    ]);
    if ($data === null) {
        return null;
    }
    $posts = [];
    foreach (($data['posts'] ?? []) as $p) {
        $text = $p['record']['text'] ?? null;
        if (!is_string($text) || $text === '') {
            continue;
        }
        $posts[] = [
            'uri' => $p['uri'],
            'cid' => $p['cid'] ?? '',
            'author_did' => $p['author']['did'] ?? '',
            'text' => $text,
            'created_at' => is_string($p['record']['createdAt'] ?? null)
                ? $p['record']['createdAt']
                : gmdate('c', 0),
        ];
    }
    $next = $data['cursor'] ?? null;
    return ['posts' => $posts, 'nextCursor' => is_string($next) ? $next : null];
}

function bsky_resolve_handle(string $handle): ?string
{
    $data = bsky_xrpc_get('com.atproto.identity.resolveHandle', ['handle' => $handle]);
    $did = $data['did'] ?? null;
    return is_string($did) && str_starts_with($did, 'did:') ? $did : null;
}
