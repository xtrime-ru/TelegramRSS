FROM php:8.0-cli

RUN apt-get update \
    && apt-get install git zip libzip-dev libssl-dev -y \
    # Install additional extension \
    && docker-php-ext-install -j$(nproc) sockets zip \
    && mkdir -p /usr/src/php/ext/ && cd /usr/src/php/ext/ \
    && pecl bundle swoole \
    && docker-php-ext-configure swoole --enable-sockets=yes --enable-openssl=yes \
    && docker-php-ext-install -j$(nproc) swoole \

    && docker-php-source delete \
    && apt-get autoremove --purge -y && apt-get autoclean -y && apt-get clean -y \
    && rm -rf /usr/src \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . /app
WORKDIR /app

RUN cp -a docker/php/conf.d/. "$PHP_INI_DIR/conf.d/" \
    && composer install -o --no-dev && composer clear

#Creating symlink to save .env in volume
RUN mkdir /app/volume/ && \
    touch '/app/volume/.env.docker' && \
    ln -s '/app/volume/.env.docker' '/app/.env.docker'

VOLUME ["/app/volume", "/app/log", "/app/cache"]

EXPOSE 9504

ENTRYPOINT php server.php --docker