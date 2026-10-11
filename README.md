# TelegramRSS
RSS/JSON generator for telegram

Get posts from my [TelegramApiServer](https://github.com/xtrime-ru/TelegramApiServer) and output them as RSS or JSON.

## Features
* Fast async [amphp](https://github.com/amphp/) http server
* Use as micro-service to access Telegram API
* Get any public telegram posts from groups as json or RSS
* fail2ban, Symfony token-bucket request limits, IP blacklist
* Full media support. Access any media from messages via direct links.
* Shared feed/history cache and media-message reuse across clients

## Client request limits

[Symfony RateLimiter](https://symfony.com/doc/6.4/rate_limiter.html) uses the
`sliding_window` policy with a 60-second window to restrict requests before cache
access or API calls. It estimates recent usage from the current and previous
windows, smoothing the transition between them. One IP shares a quota across
channels; an authenticated Basic Auth username shares its quota across all IPs.
RSS, JSON and icon requests share the feed quota. Media, preview and Range requests
use an independent media quota, with each stream consuming one request.

| Environment variable | Default | Meaning |
| --- | --- | --- |
| `RPM` | `15` | Feed/icon requests per minute |
| `MEDIA_RPM` | `50` | Media requests per minute |

`CLIENTS_SETTINGS` supports `rpm`, `media_rpm`, `errors_limit` and
`media_errors_limit` overrides. Media inherits client `rpm` when its own override
is absent. `rpm=-1` disables the quota and `rpm=0` denies access. Burst settings are
no longer used. Requests exceeding the quota get HTTP 429 and `Retry-After`,
including cache hits; rejections do not reserve future capacity or escalate bans.

Settings are applied when users are created or restored at startup and remain
fixed until restart. Saved bans/errors and last activity are preserved. Quotas
exist only in memory and start empty after restoring users or restarting the
process. Changing a limit resets that user's quota; unchanged runtime settings
retain it.

User-cache snapshots use JSONL: one client and its persistent fields per line.
Reading and writing stream records without buffering the complete file. Newlines
inside errors are escaped by JSON and restored when decoded. Limiter objects,
quota counters and Retry-After are excluded; no base64 encoding is needed.
Snapshots are written to a temporary file and atomically renamed after a successful
close, preserving the previous snapshot if writing fails. Only the original
one-user PHP serialized lines are converted as they are read, preserving bans, errors
and last activity. Invalid lines are skipped. The next snapshot writes JSONL;
no separate migration pass or intermediate serialized format is supported.
State belongs to one process; multiple workers do not
share quotas. Access logs include `rpm_limit`, `rate_limit_policy`,
`rate_limit_remaining`, `rate_limit_used` and `rate_limit_retry_after`. Remaining
and used quota come from Symfony's read-only `consume(0)` result. `rate_limit_used`
is the sliding-window estimate of accepted requests, excludes rejected attempts,
and is `null` when the quota is disabled or access is denied with `rpm=0`.
Access logs use the limiter’s current usage and remaining quota; no separate RPM counter is maintained.

## Shared cache

Caching is enabled by default, including for existing installations without a
`CACHE_ENABLED` setting. Set `CACHE_ENABLED=0` in `.env` or `.env.docker` and restart
the process to disable it. Telegram FLOOD_WAIT cooldowns remain active.

Successful results are shared across IP addresses and reused for 60 seconds by
default, regardless of request frequency. Cache hits do not renew timestamps.
Even infrequently accessed channels can therefore return data up to 60 seconds old.

All cache policy settings are configured through `.env` / `.env.docker`:

| Variable | Default | Meaning |
| --- | --- | --- |
| `CACHE_ENABLED` | `1` | Enable cache reuse and storage of successful results |
| `CACHE_MAX_ENTRIES` | `2000` | Maximum records; `0` disables storage |
| `CACHE_TTL_SECONDS` | `60` | Freshness of cached results; `0` disables fresh reuse |
| `CACHE_HISTORY_WINDOW_SIZE` | `100` | Number of latest messages fetched for the shared history window; range 1–100 |
| `CACHE_STALE_TTL_SECONDS` | `900` | Maximum complete RSS/JSON response age on FLOOD_WAIT; `0` disables stale fallback |

Limits and TTLs must be non-negative. Restart the process after editing env
settings; omitted variables retain the defaults.

Feed responses and history are cached separately. RSS and JSON, different limits
and pages share a snapshot of the latest 100 messages per channel by default.
A requested range is sliced from this snapshot only if fully covered or its
`count` confirms the end of history. The original count, chats, users and other
response fields are preserved. Ranges extending beyond the configured window,
requests with `id`, and ranges not covered by a partial snapshot use their original
API parameters without caching history or the complete response. Cached and fresh
pages are never stitched together. Such requests have no FLOOD_WAIT fallback;
the peer cooldown returns HTTP 429 with `Retry-After`. Successful messages still
update the shared media index. Channel names are case-insensitive and an
initial `@` is ignored. Page, limit and format affect the complete-response key;
client IP and unused query parameters such as `_cb` do not. Authorization,
individual rate limits, IP/Referer rules and forbidden-peer checks still run on
every request. Concurrent loads of the same key share one API call.

History also supplies a shared message index keyed by channel and message ID.
Only newly fetched history seeds the index; cache hits do not rewrite messages.
Successful history and `getMessages` loads replace all fields of each indexed
message. A `getMessages` lookup confirming absence removes the indexed message;
absence is not negatively cached. An absent ID on a history page does not imply
deletion. History, messages and complete feeds expire independently using their
original load times and the configured TTL. Updating a message does not flush
cached feeds. Overlapping loads may temporarily retain an older snapshot; the
next API refresh replaces it. There are no revisions, tombstones or cross-cache
invalidation, keeping updates cheap and avoiding extra API calls for fresh feeds.

Media requests reuse indexed messages while the configured TTL permits,
otherwise loading them through `getMessages`. Original files and previews share
the message, but each download has its own stream and Range headers. File bytes
and icons are not cached. Cache hits never renew a result's original timestamp.

On FLOOD_WAIT, all API methods for the affected normalized peer are paused within
the client session. Format, IP, page, message ID and method do not split the pause;
other peers remain available. Repeated errors can extend, but never shorten, its
end time. Expired pause records are pruned whenever pauses are checked or recorded.
Cached RSS/JSON responses can still be returned after access checks. Expired complete RSS/JSON responses up to 15 minutes old can serve as fallback,
retaining their original load time; `CACHE_STALE_TTL_SECONDS` controls this age.
Further fallback requests never renew the allowance. History, individual messages
and metadata have no stale fallback. During a pause, a feed without a suitable
complete cached response returns HTTP 429 with `Retry-After` before any API work.

Media checks the peer pause before validation, message lookup and download; direct
client calls and async metadata requests also carry the owning peer. Every HTTP
call checks again immediately before dispatch, in case a pause appeared while
waiting for another request. During a pause, downloads and uncached history ranges
return HTTP 429 with the remaining seconds in `Retry-After`. File bytes are never
retained or used as fallback. Other errors are not cached as successes.

Request cache context is passed explicitly from middleware through controllers to
TgClient, including async sponsored-message lookups. It carries response-cache
eligibility, original load time and access-log fields. The shared cache stores no
current-request state; coalesced callers update their own contexts independently.

Cache loaders always return `CacheResult<T>`. `remember()` checks freshness,
coalesces concurrent loads and stores results unless their status is `BYPASS`.
Only the feed middleware handles FLOOD_WAIT fallback, after checking the loader's
final response-cache eligibility. Coalesced callers share that fallback decision;
reusing a response never renews its source timestamp.

Storage uses Symfony's PSR-6 `ArrayAdapter` for in-memory values, independent copies
and LRU eviction. Keys are hashed to satisfy PSR-6 key restrictions. TTL checks,
message indexing, coalesced loads and FLOOD_WAIT remain application policy.
Storage retains successful results beyond their TTL for complete-feed fallback;
freshness is checked against the original load time.

The cache is local to one process and resets on restart. `CACHE_MAX_ENTRIES` limits
all cached records to 2,000 by default. There is no byte
limit; `CACHE_MAX_BYTES` is no longer used. Active downloads and in-flight loads
are outside the stored-record limit.
Access logs include `cache_status`, `message_source` and `cache_ttl`.

Tests require PHP 8.4.1+ and PHPUnit 13. Install development dependencies with
`composer install`, then run the suite with `composer test` (or `vendor/bin/phpunit`).
Use `--filter` to select a
test, for example `composer test -- --filter FloodWait`. HTTP clients use Mockery
mocks with Telegram response fixtures, a controlled cache clock and Symfony
`ClockMock` for limiter refill. Each test runs in its own process without inheriting
global state; mocks and application settings are initialized inside the test class.
Tests run without Telegram access or writes to the
application's persistent cache files.

## Architecture Example

![Architecture Example](https://hsto.org/webt/j-/ob/ky/j-obkye1dv68ngsrgi12qevutra.png)

## Installation

1. Install docker  
    ```shell
    curl -fsSL https://get.docker.com -o get-docker.sh
    sudo sh ./get-docker.sh
   ```
1. Install and start [Telegram Api Server](https://github.com/xtrime-ru/TelegramApiServer)
1. Install and start
    ```shell 
    git clone https://github.com/xtrime-ru/TelegramRSS.git TelegramRSS
    cd TelegramRSS
    ```
1. `cp .env.docker.example .env.docker` and check settings in .env.docker: domain, ratelimit, allowed refferes, etc...
1. Start container:
    ```
    docker compose pull
    docker compose up -d
    ```

## Upgrade to v4
```shell
git pull
docker compose pull
cp .env.docker .env.docker.v1
cat .env.docker.example > .env.docker
#check new config and move old options from your .env.docker.v1 to new .env.docker
docker compose up -d
```

## Additional steps
1. Edit `.env.docker` if needed. 
1. Restart RSS server `docker compose restart`
1. Example of Nginx config 
    ```
    server {
        listen      %ip%:443 ssl;
        server_name tg.i-c-a.su;
    
        ssl_certificate      /home/admin/conf/web/ssl.tg.i-c-a.su.pem;
        ssl_certificate_key  /home/admin/conf/web/ssl.tg.i-c-a.su.key;
    
        location / {
            proxy_set_header Host $http_host;
            proxy_set_header SERVER_PORT $server_port;
            proxy_set_header REMOTE_ADDR $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header Upgrade $http_upgrade;
    
            fastcgi_param REMOTE_ADDR $http_x_real_ip;
            proxy_http_version 1.1;
            proxy_set_header Connection "keep-alive";
    
            proxy_pass http://127.0.0.1:9504;
        }
    
    }
    ```
   Set `REAL_IP_HEADER=X-Forwarded-For` in .env.docker to retrieve client IP from nginx.
  
## Examples    
### JSON
* URL: https://tg.i-c-a.su/json/breakingmash
* Custom limit: https://tg.i-c-a.su/json/breakingmash?limit=50 
  
  Maximum: 100 posts
  
* Pagination: https://tg.i-c-a.su/json/breakingmash?page=2
* Get one message: https://tg.i-c-a.su/json/breakingmash?id=10738&limit=1

### RSS
* URL: https://tg.i-c-a.su/rss/breakingmash
* Custom limit: https://tg.i-c-a.su/rss/breakingmash?limit=50 

  Maximum: 100 posts
  
* Pagination: https://tg.i-c-a.su/rss/breakingmash/2
* Get one message: https://tg.i-c-a.su/rss/breakingmash?id=10738&limit=1

### Media
* https://tg.i-c-a.su/media/breakingmash/10738/preview
* https://tg.i-c-a.su/media/breakingmash/10738

Default address of RSS server is `http://127.0.0.1:9504/`
    
## Contacts

* Telegram: [@xtrime](tg://resolve?domain=xtrime)
* Email: alexander(at)i-c-a.su
