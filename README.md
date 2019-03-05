# TelegramRSS
RSS/JSON generator for telegram

Get posts via [this telegram client](https://github.com/xtrime-ru/TelegramSwooleClient) and output them as RSS or JSON.

 **Features**
  * Fast async swoole server
  * Use as micro-service to access telegram api
  * Get any public telegram posts from groups as json or RSS
  * fail2ban, RPM limits, IP blacklist
  * Full media support. Access any media from messages via direct links.

 **Installation**
 
 1. Swoole extension required: [Install swoole](https://github.com/swoole/swoole-src#%EF%B8%8F-installation)
 1. Install and run [my telegram client](https://github.com/xtrime-ru/TelegramSwooleClient)
 1. Clone this project
 1. `composer install`
   
     _Optional:_
 1. Create .env from .env.example
 1. Edit .env if needed
 1. Use supervisor to monitor and restart swoole servers. Example of `/etc/supervisor/conf.d/telegram_rss.conf`: 
     ```
    [program:telegram_rss]
    command=/usr/bin/php /home/admin/web/tg.i-c-a.su/TelegramRSS/server.php
    numprocs=1
    directory=/home/admin/web/tg.i-c-a.su/TelegramRSS/
    autostart=true
    autorestart=true
    nodaemon=true
    logfile=/dev/null
    logfile_maxbytes=0
     ```
  
 **Usage**
 1. Run [client](https://github.com/xtrime-ru/TelegramSwooleClient) 
 1. Check that address and port of client are correct in .env file
 1. run rss server `php server.php`

    Examples:
    
    * https://tg.i-c-a.su/json/breakingmash
    * https://tg.i-c-a.su/rss/breakingmash
    * https://tg.i-c-a.su/media/breakingmash/10738/preview
    * https://tg.i-c-a.su/media/breakingmash/10738
    
    Default address of rss server is http://127.0.0.1:9504/
