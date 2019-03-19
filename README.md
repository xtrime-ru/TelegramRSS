# TelegramRSS
RSS/JSON generator for telegram

Get posts via my [TelegramSwooleClient](https://github.com/xtrime-ru/TelegramSwooleClient) and output them as RSS or JSON.

 **Features**
  * Fast async swoole server
  * Use as micro-service to access telegram api
  * Get any public telegram posts from groups as json or RSS
  * fail2ban, RPM limits, IP blacklist
  * Full media support. Access any media from messages via direct links.
  
 **Proposed Architecture**
 
 ![Proposed Architecture](https://habrastorage.org/webt/qz/ax/ct/qzaxctkgwehhhsqglgszy4rowwa.png)

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
    stdout_logfile=none
    redirect_stderr=true
     ```
 1. Nginx config     
    ```
    server {
        listen      %ip%:443 ssl;
        server_name tg.i-c-a.su www.tg.i-c-a.su;
    
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
  
 **Usage**
 1. Run [client](https://github.com/xtrime-ru/TelegramSwooleClient) 
 1. Check that address and port of client are correct in .env file
 1. run rss server `php server.php`

    Examples:
    
    * https://tg.i-c-a.su/json/breakingmash
    * https://tg.i-c-a.su/rss/breakingmash
    * Custom limit: https://tg.i-c-a.su/rss/breakingmash?limit=50 
      
      Maximum - 100 posts
    * Pagination: https://tg.i-c-a.su/rss/breakingmash/2
    * https://tg.i-c-a.su/media/breakingmash/10738/preview
    * https://tg.i-c-a.su/media/breakingmash/10738
    
    Default address of rss server is http://127.0.0.1:9504/
    
 **Contacts**

 * Telegram: [@xtrime](tg://resolve?domain=xtrime)
 * Email: alexander(at)i-c-a.su
