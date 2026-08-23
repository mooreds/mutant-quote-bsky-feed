<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';

function bsky_post_json(string $url, array $body, ?string $token = null): array
{
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (!is_string($resp)) {
        throw new RuntimeException('request failed');
    }
    $data = json_decode($resp, true);
    if ($status >= 400) {
        $msg = is_array($data) ? ($data['message'] ?? $resp) : $resp;
        throw new RuntimeException("request failed ({$status}): {$msg}");
    }
    return is_array($data) ? $data : [];
}

$handle = getenv('BLUESKY_HANDLE') ?: '';
$password = getenv('BLUESKY_APP_PASSWORD') ?: '';
if ($handle === '' || $password === '') {
    fwrite(STDERR, "Set BLUESKY_HANDLE and BLUESKY_APP_PASSWORD env vars first.\n");
    fwrite(STDERR, "Create an app password at https://bsky.app/settings/app-passwords\n");
    exit(1);
}

try {
    $session = bsky_post_json(
        'https://bsky.social/xrpc/com.atproto.server.createSession',
        ['identifier' => $handle, 'password' => $password]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'login failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$did = $session['did'] ?? '';
if (!is_string($did) || $did === '') {
    fwrite(STDERR, "login failed: no did in session response\n");
    exit(1);
}

$expectedDid = cfg('publisher_did');
if ($expectedDid !== '' && $expectedDid !== $did) {
    fwrite(STDERR, "warning: PUBLISHER_DID={$expectedDid} but logged in as {$did}\n");
}

$record = [
    'did' => service_did(),
    'displayName' => cfg('feed_display_name'),
    'description' => cfg('feed_description'),
    'createdAt' => gmdate('c'),
];

try {
    $result = bsky_post_json(
        'https://bsky.social/xrpc/com.atproto.repo.createRecord',
        [
            'repo' => $did,
            'collection' => 'app.bsky.feed.generator',
            'rkey' => cfg('feed_rkey'),
            'record' => $record,
        ],
        $session['accessJwt']
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'publish failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Feed published:\n";
echo '  uri: ' . ($result['uri'] ?? '?') . "\n";
echo '  cid: ' . ($result['cid'] ?? '?') . "\n\n";
echo "Set PUBLISHER_DID={$did} when running the service.\n";
echo "The feed appears in search once the AppView can reach https://" . cfg('hostname') . "\n";
