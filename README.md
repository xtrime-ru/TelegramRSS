# TelegramRSS
RSS/JSON generator for telegram

Get posts from my [TelegramApiServer](https://github.com/xtrime-ru/TelegramApiServer) and output them as RSS or JSON.

## Features
* Fast async [amphp](https://github.com/amphp/) http server
* Use as micro-service to access Telegram API
* Get any public telegram posts from groups as json or RSS
* fail2ban, per-client request limits, IP blacklist
* Full media support. Access any media from messages via direct links.
* Shared compressed channel data and media-message reuse across clients

The current dependencies require PHP 8.4.1+.

## Client request limits

Requests are limited per IP, or per Basic Auth username across all IPs.
RSS, JSON and icons share one quota; media, previews and Range requests share
an independent quota. Each request counts, including cache hits.

| Environment variable | Default | Meaning |
| --- | --- | --- |
| `RPM` | `15` | Feed/icon requests per minute |
| `MEDIA_RPM` | `50` | Media requests per minute |

Limits use a sliding 60-second window. Exceeding the quota returns HTTP 429
with `Retry-After`, without extending a ban. Temporary bans also return
`Retry-After` with the remaining seconds.

`CLIENTS_SETTINGS` supports `rpm`, `media_rpm`, `errors_limit` and
`media_errors_limit` overrides. Media inherits client `rpm` when `media_rpm`
is absent. Set `rpm=-1` for unlimited requests or `rpm=0` to deny access.
Restart after changing settings. Quotas reset on restart; saved bans and errors
are preserved. Each process maintains its own quotas.

Saved user state uses JSONL. Original user files are read automatically and
converted on the next save; invalid records are skipped.

Access logs include `rpm_limit`, `rate_limit_policy`, `rate_limit_remaining`,
`rate_limit_used` and `rate_limit_retry_after`. Usage is an estimate of accepted
requests; rejected requests are excluded.

## Shared cache

Caching is enabled by default and shared across clients. It stores data for
channels, groups and private dialogs:
messages, peer information, full information and metadata needed to render
feeds. Ready RSS/JSON responses and file bytes are not stored. When optional
`ext-zstd >=0.18` is available, payloads use zstd level 1, each message separately.
Without it, the same data is stored as uncompressed JSON. No configuration is needed.

Configure caching in `.env` or `.env.docker` and restart:

| Variable | Default | Meaning |
| --- | --- | --- |
| `CACHE_ENABLED` | `1` | Enable caching; `0` disables it |
| `CACHE_MAX_CHANNELS` | `500` | Maximum peers (channels/groups/dialogs); `0` disables storage |
| `CACHE_TTL_SECONDS` | `60` | Freshness period; `0` disables fresh reuse |
| `CACHE_STALE_TTL_SECONDS` | `900` | Maximum age of stale peer data; `0` disables stale reuse |
| `CACHE_CLEANUP_INTERVAL_SECONDS` | `60` | Minimum interval between expiry sweeps on peer updates; `0` cleans on every update |

History loads exactly the requested number of messages. A request for 10 and
then 50 messages retains 50, shared between RSS, JSON and media. A shorter refresh
preserves the longer order when the received prefix agrees; a conflicting head
replaces the order. Only received messages become fresh. Cache hits never extend
freshness. Old messages remain available for media until expiry or eviction.
Concurrent smaller requests share a pending larger history load. Peer information
from history also serves information lookups, without storing a second copy.
Pagination within the retained window reuses it; anchored requests and pages
outside it bypass history caching. Channel names are case-insensitive and an
initial `@`, client IP or unused parameters do not create separate entries.

Each process has its own cache. Least recently used peers are evicted as a whole.
There is no byte limit or separate limit on messages within a peer;
expired entries are cleaned periodically when the peer is updated. Expired data
is never reused beyond its configured TTL while awaiting cleanup. Access rules and client
quotas apply before cache reuse. Groups and private dialogs still require
`ONLY_PUBLIC_CHANNELS=0`.

A snapshot is streamed on graceful shutdown and restored at startup. With zstd,
`cache/telegram-cache.jsonl.zst` is compressed without building a full file in
memory; otherwise `cache/telegram-cache.jsonl` is written. With the extension,
either format can be read; without it, compressed snapshots are skipped. A
successful save replaces the previous format. Data age, history order and active FLOOD_WAIT pauses
survive restarts; downtime counts toward TTLs. Changed capacity applies on
restore. Older snapshot schemas are ignored with a notice. Failed saves preserve
the previous file and log errors. Forced termination cannot save a new snapshot.
`CACHE_ENABLED=0` disables snapshot reads and writes.

On FLOOD_WAIT, API requests for that channel pause; other channels remain
available. RSS/JSON can be rebuilt from suitable stale data without extending its
age. Each required piece of data handles its own fallback; the request is rendered
once. Other errors do not enable stale fallback. Media first searches for its
message, including stale data, before calling the API. Original files, previews
and Range requests each use an independent download stream. Downloads during a
pause still return HTTP 429 with `Retry-After`; icons and file bytes are not cached.
Disabling caching does not disable FLOOD_WAIT pauses.

Access logs include `cache_status`, `message_source` and `cache_ttl`.
RSS/JSON responses expose `Cache-Status`: `hit; ttl=N`, `fwd=miss`, or
`fwd=bypass`, prefixed with `TelegramRSS;`. A negative TTL indicates stale
fallback. Media access-log cache status describes message reuse, not cached file bytes.
Media streams report `fwd=bypass; detail=media-stream`. Error responses include
`Cache-Control: no-store` and `Cache-Status` with a reason: `flood-wait` for an API
flood wait or a local pause, `rate-limit`, `ban`, `access-denied`,
or `error`. HTTP 429 includes `Retry-After` when the wait duration is known.
Successful request logs require `LOGS_LEVEL=1`.

## Dependency patches

Composer applies the local `amphp/http-server` request-cancellation patch.
`composer install` runs `composer reinstall amphp/http-server` through its
post-install hook, applying added patches and removing deleted ones even with
an existing `vendor` directory. Failure stops startup.

## Tests

Install development dependencies with `composer install`, then run `composer test`.
To select tests, use `composer test -- --filter FloodWait`. Tests run without
Telegram access or changes to the application's saved user state.

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
