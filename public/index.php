<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';

function json_response($payload, int $status = 200, ?string $cacheControl = null): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    if ($cacheControl !== null) {
        header("Cache-Control: {$cacheControl}");
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

function error_response(int $status, string $name, string $message): void
{
    json_response(['error' => $name, 'message' => $message], $status, 'no-store');
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

switch ($path) {
    case '/health':
        json_response(array_merge(['ok' => true], stats()), 200, 'no-store');
        break;

    case '/.well-known/did.json':
        $did = service_did();
        json_response([
            '@context' => ['https://www.w3.org/ns/did/v1'],
            'id' => $did,
            'service' => [
                [
                    'id' => "{$did}#bsky_fg",
                    'type' => 'BskyFeedGenerator',
                    'serviceEndpoint' => 'https://' . cfg('hostname'),
                ],
            ],
        ], 200, 'public, max-age=3600');
        break;

    case '/xrpc/app.bsky.feed.describeFeedGenerator':
        json_response([
            'did' => service_did(),
            'feeds' => [['uri' => feed_uri()]],
        ], 200, 'public, max-age=3600');
        break;

    case '/xrpc/app.bsky.feed.getFeedSkeleton':
        $feed = $_GET['feed'] ?? null;
        if (!is_string($feed) || $feed === '') {
            error_response(400, 'ParamsInvalid', 'feed parameter is required');
            break;
        }
        $publisherDid = cfg('publisher_did');
        if ($publisherDid !== '' && $feed !== feed_uri()) {
            error_response(400, 'ParamsInvalid', "unknown feed: {$feed}");
            break;
        }

        $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT);
        if ($limit === false || $limit === null) {
            $limit = 20;
        }
        $limit = min(max($limit, 1), 100);

        $cursorUs = null;
        $rawCursor = $_GET['cursor'] ?? null;
        if (is_string($rawCursor) && $rawCursor !== '') {
            if (preg_match('/^\d+$/', $rawCursor) !== 1) {
                error_response(400, 'ParamsInvalid', 'invalid cursor');
                break;
            }
            $cursorUs = (int) $rawCursor;
        }

        $rows = feed_page((int) cfg('min_chain_edges'), $cursorUs, $limit + 1);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $payload = ['feed' => array_map(fn ($r) => ['post' => $r['uri']], $rows)];
        if ($hasMore && $rows !== []) {
            $payload['cursor'] = (string) end($rows)['indexed_us'];
        }
        json_response($payload, 200, 'public, max-age=30, s-maxage=60');
        break;

    case '/debug-qs':
        if (getenv('DEBUG_QS') !== '1') {
            error_response(404, 'NotFound', 'not found');
            break;
        }
        json_response([
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'query_string' => $_SERVER['QUERY_STRING'] ?? null,
            'get_feed' => $_GET['feed'] ?? null,
            'host_header' => $_SERVER['HTTP_HOST'] ?? null,
        ], 200, 'no-store');
        break;

    default:
        error_response(404, 'NotFound', "no handler for {$path}");
}
