<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . cfg('sqlite_path'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        db_migrate($pdo);
    }
    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS posts (
  uri         TEXT PRIMARY KEY,
  cid         TEXT,
  author_did  TEXT NOT NULL,
  text        TEXT NOT NULL,
  created_at  TEXT NOT NULL,
  indexed_us  INTEGER NOT NULL,
  parent_uri  TEXT,
  chain_id    INTEGER,
  is_mutation INTEGER NOT NULL DEFAULT 0,
  evaluated   INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_posts_parent ON posts(parent_uri);
CREATE INDEX IF NOT EXISTS idx_posts_unevaluated ON posts(evaluated);
CREATE INDEX IF NOT EXISTS idx_posts_chain ON posts(chain_id);
CREATE INDEX IF NOT EXISTS idx_posts_indexed ON posts(indexed_us);

CREATE TABLE IF NOT EXISTS chains (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  edge_count INTEGER NOT NULL DEFAULT 0,
  created_us INTEGER NOT NULL,
  updated_us INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_chains_edges ON chains(edge_count);

CREATE TABLE IF NOT EXISTS post_cache (
  uri        TEXT PRIMARY KEY,
  cid        TEXT,
  author_did TEXT NOT NULL,
  text       TEXT NOT NULL,
  created_at TEXT NOT NULL,
  fetched_us INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS cursor_state (
  id    INTEGER PRIMARY KEY CHECK (id = 1),
  value INTEGER NOT NULL
);
SQL);
}

function post_get(string $uri): ?array
{
    $stmt = db()->prepare('SELECT * FROM posts WHERE uri = ?');
    $stmt->execute([$uri]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function cached_post_get(string $uri): ?array
{
    $minUs = (time() - cfg('cache_ttl_days') * 86400) * 1000000;
    $stmt = db()->prepare('SELECT * FROM post_cache WHERE uri = ? AND fetched_us > ?');
    $stmt->execute([$uri, $minUs]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cached_post_put(array $post, int $fetchedUs): void
{
    db()->prepare(
        'INSERT INTO post_cache (uri, cid, author_did, text, created_at, fetched_us)
         VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT(uri) DO UPDATE SET
           cid=excluded.cid, author_did=excluded.author_did, text=excluded.text,
           created_at=excluded.created_at, fetched_us=excluded.fetched_us'
    )->execute([
        $post['uri'], $post['cid'], $post['author_did'], $post['text'], $post['created_at'], $fetchedUs,
    ]);
}

function post_insert(
    string $uri,
    ?string $cid,
    string $authorDid,
    string $text,
    string $createdAt,
    int $indexedUs,
    ?string $parentUri,
    int $evaluated = 0
): bool {
    $stmt = db()->prepare(
        'INSERT OR IGNORE INTO posts (uri, cid, author_did, text, created_at, indexed_us, parent_uri, chain_id, is_mutation, evaluated)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 0, ?)'
    );
    $stmt->execute([$uri, $cid, $authorDid, $text, $createdAt, $indexedUs, $parentUri, $evaluated]);
    return $stmt->rowCount() > 0;
}

function add_mutation_edge(string $parentUri, string $childUri, int $nowUs): int
{
    $pdo = db();
    $parent = post_get($parentUri);
    if ($parent !== null && $parent['chain_id'] !== null) {
        $chainId = (int) $parent['chain_id'];
    } else {
        $pdo->prepare('INSERT INTO chains (edge_count, created_us, updated_us) VALUES (0, ?, ?)')
            ->execute([$nowUs, $nowUs]);
        $chainId = (int) $pdo->lastInsertId();
        if ($parent !== null) {
            $pdo->prepare('UPDATE posts SET chain_id = ? WHERE uri = ?')->execute([$chainId, $parentUri]);
        }
    }
    $pdo->prepare('UPDATE posts SET chain_id = ?, is_mutation = 1, evaluated = 1 WHERE uri = ?')
        ->execute([$chainId, $childUri]);
    $pdo->prepare('UPDATE chains SET edge_count = edge_count + 1, updated_us = ? WHERE id = ?')
        ->execute([$nowUs, $chainId]);
    return $chainId;
}

function chain_edges(int $chainId): int
{
    $stmt = db()->prepare('SELECT edge_count FROM chains WHERE id = ?');
    $stmt->execute([$chainId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function chain_root_uri(int $chainId): ?string
{
    $stmt = db()->prepare('SELECT uri FROM posts WHERE chain_id = ? ORDER BY indexed_us ASC LIMIT 1');
    $stmt->execute([$chainId]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string) $v;
}

function unevaluated_posts(int $limit): array
{
    $stmt = db()->prepare(
        'SELECT uri, parent_uri, text, indexed_us FROM posts
         WHERE evaluated = 0 AND parent_uri IS NOT NULL
         ORDER BY indexed_us ASC LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function mark_evaluated(string $uri): void
{
    db()->prepare('UPDATE posts SET evaluated = 1 WHERE uri = ?')->execute([$uri]);
}

function feed_page(int $minEdges, ?int $cursorUs, int $limit): array
{
    $stmt = db()->prepare(
        'SELECT p.uri, p.indexed_us FROM posts p
         JOIN chains c ON c.id = p.chain_id
         WHERE c.edge_count >= ?
           AND (? IS NULL OR p.indexed_us < ?)
         ORDER BY p.indexed_us DESC
         LIMIT ?'
    );
    $stmt->execute([$minEdges, $cursorUs, $cursorUs, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_cursor(): int
{
    $v = db()->query('SELECT value FROM cursor_state WHERE id = 1')->fetchColumn();
    return $v === false ? 0 : (int) $v;
}

function save_cursor(int $us): void
{
    db()->prepare(
        'INSERT INTO cursor_state (id, value) VALUES (1, ?)
         ON CONFLICT(id) DO UPDATE SET value = excluded.value'
    )->execute([$us]);
}

function stats(): array
{
    $minEdges = cfg('min_chain_edges');
    $row = db()->query(
        "SELECT
           (SELECT COUNT(*) FROM posts) AS posts,
           (SELECT COUNT(*) FROM posts WHERE is_mutation = 1) AS mutations,
           (SELECT COUNT(*) FROM chains WHERE edge_count >= {$minEdges}) AS qualifying_chains"
    )->fetch(PDO::FETCH_ASSOC);
    return [
        'posts' => (int) $row['posts'],
        'mutations' => (int) $row['mutations'],
        'qualifying_chains' => (int) $row['qualifying_chains'],
    ];
}

function iso_to_micros(string $iso): int
{
    try {
        $dt = new DateTimeImmutable($iso);
    } catch (Exception) {
        return 0;
    }
    return ((int) $dt->format('U')) * 1000000 + (int) $dt->format('u');
}
