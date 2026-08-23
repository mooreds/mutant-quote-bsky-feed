<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bsky.php';
require_once __DIR__ . '/textsim.php';

function store_fetched_parent(array $post): void
{
    if (post_get($post['uri']) === null) {
        post_insert(
            $post['uri'],
            $post['cid'] ?: null,
            $post['author_did'],
            $post['text'],
            $post['created_at'],
            iso_to_micros($post['created_at']),
            null,
            1
        );
    }
}

function evaluate_mutation_edge(array $parent, string $childUri, string $childText, int $nowUs): ?int
{
    $result = detect_mutation($parent['text'], $childText);
    if (!$result['isMutation']) {
        return null;
    }
    $chainId = add_mutation_edge((string) $parent['uri'], $childUri, $nowUs);
    fprintf(
        STDERR,
        "mutation: cov=%.2f via %s %s <- %s (chain #%d, %d edges)\n",
        $result['similarity'],
        $result['reason'],
        $parent['uri'],
        $childUri,
        $chainId,
        chain_edges($chainId)
    );
    if (chain_edges($chainId) === cfg('min_chain_edges')) {
        $root = chain_root_uri($chainId);
        fprintf(
            STDERR,
            "*** chain #%d just qualified (%d+ mutations)%s\n",
            $chainId,
            cfg('min_chain_edges'),
            $root !== null ? ", root: {$root}" : ''
        );
    }
    return $chainId;
}
