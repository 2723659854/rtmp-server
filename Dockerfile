FROM php:8.1.24-cli-alpine

RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories && apk update && \
    apk add --no-cache \
    autoconf \
    build-base \
    libevent-dev \
    libuuid \
    e2fsprogs-dev \
    libzip-dev \
    openssl-dev \
    libpng-dev \
    libwebp-dev \
    libjpeg-turbo-dev \
    freetype-dev && \
    docker-php-ext-configure gd \
    --with-jpeg=/usr/include/ \
    --with-freetype=/usr/include/ && \
    docker-php-ext-install sockets pcntl bcmath zip gd  && \
    pecl install uuid event apcu&& \
    docker-php-ext-enable uuid apcu&& \
    docker-php-ext-enable --ini-name event.ini event && \
    curl -o /usr/local/bin/composer https://mirrors.aliyun.com/composer/composer.phar && chmod +x /usr/local/bin/composer

RUN apk add git

WORKDIR /usr/src/myapp

VOLUME /usr/src/myapp

EXPOSE 8080

STOPSIGNAL SIGKILL

CMD tail -f /dev/null
