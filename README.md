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

# Optional (defaults shown):
# PUBLIC_API_URL=https://public.api.bsky.app    # AppView reads; see Outbound connectivity
# JETSTREAM_URL=wss://jetstream2.us-east.bsky.network/subscribe
```

### Outbound connectivity

Some datacenter hosts have broken routing to specific CDNs (we hit a host
that could not reach `public.api.bsky.app`, which sits behind BunnyCDN).
The API client therefore tries endpoints in order and fails over
automatically on transport errors or non-200 responses:
`PUBLIC_API_URL`, then `api.bsky.app` (Bluesky-run, same backend, no CDN),
then `public.api.bsky.app`. The first endpoint returning <400 is pinned for
the process. To check reachability from your host:

```sh
for h in public.api.bsky.app api.bsky.app jetstream2.us-east.bsky.network; do
  printf '%-35s ' "$h"
  curl -4 -sI --max-time 8 "https://$h/" > /dev/null 2>&1 && echo reachable || echo BLOCKED
done
```

If `public.api.bsky.app` is blocked but `api.bsky.app` is not, set
`PUBLIC_API_URL=https://api.bsky.app` in `.env` to skip the doomed first
attempt each process would otherwise make; the failover makes this
optional, not required.


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

## Disk maintenance

The database grows with every quote post on the network (~0.5 KB each);
only mutation edges and chain members are ever needed long-term.
`bin/prune.php` deletes evaluated non-mutation posts older than the
retention window plus expired parent-cache rows. It never touches pending
posts, chain members (including chain roots), or cursor state; pruned
parents quoted again later are simply re-fetched from the API.

```sh
php bin/prune.php                # delete + truncate WAL (file size unchanged)
php bin/prune.php --vacuum       # also shrink the file (needs ~2x db size free transiently)
php bin/prune.php --dry-run      # report only
php bin/prune.php --days=7       # override retention window (default PRUNE_KEEP_DAYS=14)
```

Weekly from cron:

```
17 4 * * 1 cd /path/to/mutant-quotes-php && php bin/prune.php --vacuum >> data/prune.log 2>&1
```


## Caching headers

Responses carry explicit `Cache-Control` so CDNs behave predictably:

| Endpoint | Header |
| --- | --- |
| `/.well-known/did.json` | `public, max-age=3600` |
| `/xrpc/app.bsky.feed.describeFeedGenerator` | `public, max-age=3600` |
| `/xrpc/app.bsky.feed.getFeedSkeleton` | `public, max-age=30, s-maxage=60` |
| `/health`, all errors | `no-store` |

### Behind CloudFront

- Use a cache policy that **honors origin Cache-Control** (the managed
  `CachingOptimized` policy works: origin TTLs are clamped to its
  min=1s/max=1y window, and our values fit). With no policy override,
  CloudFront falls back to an 86400s default for header-less responses —
  which is why every route sends an explicit header.
- Keep query strings in the cache key (managed policies include them by
  default) or cursor pagination will collapse to one cached page.
- `s-maxage=60` gives the edge a 60s TTL while browsers hold pages 30s;
  both align with the minute-level cron ingestion cadence.
- Responses are identical for all users (the AppView's optional
  Authorization header is ignored), so shared edge caching is safe; no
  `Vary` header is needed.
- Enable behavior compression for `application/json` if you want gzip.

## Limitations

- Text-only detection (image-mutation chains would need perceptual hashing).
- Up to ~60s ingestion latency by design (cron).
- Feed pagination cursor is timestamp-based; posts sharing an exact
  microsecond can be skipped across page boundaries.
