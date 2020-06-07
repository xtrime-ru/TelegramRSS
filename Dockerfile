FROM php:7.4-cli

COPY . /app
WORKDIR /app

RUN apt-get update \
    && apt-get install git -y \
    && PHP_OPENSSL=yes pecl install swoole \
    && docker-php-ext-enable swoole \
    && docker-php-source delete \
    && apt-get autoremove --purge -y && apt-get autoclean -y && apt-get clean -y \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install -o --no-dev

VOLUME ["/app/volume", "/app/log"]

#Creating symlink to save .env in volume
RUN touch '/app/volume/.env' && \
    ln -s '/app/volume/.env' '/app/.env'

EXPOSE 9504

ENTRYPOINT php server.php --docker