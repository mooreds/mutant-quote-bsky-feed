# Mutant Quote Chains (PHP) — Bluesky feed generator for shared hosting

Detects threads where each quote subtly changes the text of the one before
it — typo chains and template remixes like the classic
@scalzi.com "Optimist / Pessimist / [profession]" thread.

Designed for **shared hosting**: no daemons, no Composer, PHP 8.1+,
SQLite via PDO. Ingestion runs from cron once a minute using Jetstream's
cursor replay.

## How it works

```
cron (every minute): bin/consume.php
  ├─ flock() guard against overlap
  ├─ connect wss://jetstream2...?cursor=<saved>&wantedCollections=app.bsky.feed.post
  ├─ drain events until caught up or CONSUME_BUDGET_SECONDS (40s default)
  ├─ insert quote posts into SQLite
  ├─ evaluate pending quotes: fetch parents via public API, detectMutation()
  └─ save cursor

web: public/index.php
  ├─ /.well-known/did.json                  did:web identity
  ├─ /xrpc/app.bsky.feed.describeFeedGenerator
  ├─ /xrpc/app.bsky.feed.getFeedSkeleton    recent posts of qualifying chains
  └─ /health                                {ok, posts, mutations, qualifying_chains}
```

A quote is a **mutation** when either:
1. normalized text is near-identical but not equal (Levenshtein >= `SIM_THRESHOLD`), or
2. >= `TOKEN_COVERAGE_MIN` of the quote's words come from the quoted post
   with >= `TOKEN_SHARED_MIN` shared words (catches punchline swaps; rejects commentary).

Chains are connected components of mutation edges; they appear in the feed at
`MIN_CHAIN_EDGES`+ edges.

## Local testing

```sh
php bin/test.php                                   # detector suite
php bin/consume.php                                # one manual cron run (~40s)
php -S 127.0.0.1:2567 public/index.php             # serve endpoints
curl localhost:2567/health
curl -s "localhost:2567/xrpc/app.bsky.feed.getFeedSkeleton?feed=x&limit=5"

# seed history from an existing thread:
php bin/backfill.php https://bsky.app/profile/scalzi.com/post/3lbnhx33ns22g --depth 8
```

## Deploy to shared hosting

1. Upload the project; make `data/` writable by PHP.
2. Create `.env` (see below); set `FEED_HOSTNAME` to your domain.
3. Point the web root (or a subdomain) at `public/`.
   Apache/LiteSpeed need nothing extra (`.htaccess` included);
   nginx users: see `deploy/nginx.conf.sample`.
4. Verify both PHP runtimes see SQLite: `php -m | grep sqlite` AND a browser
   hit on `/health` must work.
5. Add the cron job (`crontab.example`):
   ```
   * * * * * cd /path/to/mutant-quotes-php && php bin/consume.php >> data/consume.log 2>&1
   ```
6. Publish the feed record (once):
   ```
   BLUESKY_HANDLE=you.bsky.social BLUESKY_APP_PASSWORD=xxxx php bin/publish.php
   ```
7. Check `https://yourdomain/health`, then search your feed name in Bluesky.

### .env

```
FEED_HOSTNAME=feed.yourdomain.com
PUBLISHER_DID=did:plc:xxxxxxxx        # your account DID
BLUESKY_HANDLE=you.bsky.social        # only needed for publish.php
BLUESKY_APP_PASSWORD=xxxx-xxxx-xxxx
```

## Tuning

| Env | Default | Meaning |
| --- | --- | --- |
| `MIN_CHAIN_EDGES` | 3 | mutations before a chain feeds |
| `SIM_THRESHOLD` | 0.72 | char-similarity cutoff |
| `TOKEN_COVERAGE_MIN` | 0.7 | fraction of quote words inherited from parent |
| `TOKEN_SHARED_MIN` | 8 | floor on absolute shared word count |
| `MIN_TEXT_LENGTH` | 12 | ignore shorter texts |
| `CONSUME_BUDGET_SECONDS` | 40 | max time per cron run draining jetstream |
| `EVAL_BATCH_SIZE` | 150 | quote posts evaluated per run |
| `PARENT_FETCH_MAX_PER_RUN` | 400 | cap on parent API fetches per run |

If the feed feels noisy raise `TOKEN_COVERAGE_MIN`; if big remix threads go
missing lower it. Keep `CONSUME_BUDGET_SECONDS` well under your host's
cron interval and max runtime.

## Limitations

- Text-only detection (image-mutation chains would need perceptual hashing).
- Up to ~60s ingestion latency by design (cron).
- Feed pagination cursor is timestamp-based; posts sharing an exact
  microsecond can be skipped across page boundaries.
