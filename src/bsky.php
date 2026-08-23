<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function bsky_xrpc_get(string $endpoint, array $params): ?array
{
    $query = [];
    foreach ($params as $k => $v) {
        if ($v !== null) {
            $query[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
    }
    $url = rtrim(cfg('public_api_url'), '/') . '/xrpc/' . $endpoint;
    if ($query) {
        $url .= '?' . implode('&', $query);
    }

    $ch = curl_init($url);
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
    $url = rtrim(cfg('public_api_url'), '/') . '/xrpc/app.bsky.feed.getPosts?' . $query;

    $ch = curl_init($url);
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
