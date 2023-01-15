FROM php:8.2-cli

RUN apt-get update \
    && apt-get install git zip libzip-dev libssl-dev -y \
    # Install additional extension \
    && docker-php-ext-install -j$(nproc) sockets zip \
    && mkdir -p /usr/src/php/ext/ && cd /usr/src/php/ext/ \
    && pecl bundle openswoole \
    && docker-php-ext-configure openswoole --enable-openssl=yes \
    && docker-php-ext-install -j$(nproc) openswoole \
    # Cleanup
    && docker-php-source delete \
    && apt-get autoremove --purge -y && apt-get autoclean -y && apt-get clean -y \
    && rm -rf /usr/src \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer


ADD docker/php/conf.d/. "$PHP_INI_DIR/conf.d/"

EXPOSE 9504

ENTRYPOINT php server.php --docker