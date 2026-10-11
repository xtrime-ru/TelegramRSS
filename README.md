# TelegramRSS
RSS/JSON generator for telegram

Get posts from my [TelegramApiServer](https://github.com/xtrime-ru/TelegramApiServer) and output them as RSS or JSON.

## Features
* Fast async [amphp](https://github.com/amphp/) http server
* Use as micro-service to access Telegram API
* Get any public telegram posts from groups as json or RSS
* fail2ban, Symfony token-bucket request limits, IP blacklist
* Full media support. Access any media from messages via direct links.

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
The separate per-user RPM counter is removed.

## Shared cache policy

Loaders always return `CacheResult<T>`. `remember()` only checks TTL, coalesces
loads through Future and stores non-BYPASS results. It propagates API errors;
complete-feed middleware handles FLOOD_WAIT fallback when introduced.

`TelegramCache` reuses successful results for a fixed TTL (60 seconds by default).
Cache hits retain the original load time. Concurrent loads share an Amp Future,
released after either success or error. FLOOD_WAIT pauses all API methods for the
normalized peer; other peers remain available. Expired pauses are pruned.

Symfony's PSR-6 `ArrayAdapter` stores independent value copies and evicts the
least recently used records beyond the 2,000-entry default. There is no byte limit.
Keys are hashed to comply with PSR-6 restrictions. The cache resets on restart.
The cache provides age-limited lookup of the last successful result; only feed
middleware may use it for fallback. History and messages have no stale fallback.

Tests use PHPUnit 13 and Mockery. Run `composer test` (or `vendor/bin/phpunit`).
Each test runs in a separate process without inherited global state. Mocks,
application settings and Symfony `ClockMock` are initialized inside the test class.

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
