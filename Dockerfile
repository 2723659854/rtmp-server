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
    libpq-dev \
    rabbitmq-c-dev \
    libpng-dev \
    libwebp-dev \
    libjpeg-turbo-dev \
    freetype-dev && \
    docker-php-ext-configure gd \
    --with-jpeg=/usr/include/ \
    --with-freetype=/usr/include/ && \
    docker-php-ext-install sockets pcntl pdo_mysql mysqli pdo_pgsql bcmath zip gd  && \
    pecl install redis mongodb uuid amqp event apcu&& \
    docker-php-ext-enable redis mongodb uuid amqp apcu&& \
    docker-php-ext-enable --ini-name event.ini event && \
    curl -o /usr/local/bin/composer https://mirrors.aliyun.com/composer/composer.phar && chmod +x /usr/local/bin/composer


RUN apk add git


RUN git clone https://github.com/websupport-sk/pecl-memcache.git /usr/src/php/ext/memcache


RUN docker-php-ext-install /usr/src/php/ext/memcache

RUN apk add --no-cache libmemcached libmemcached-dev

RUN git clone https://github.com/php-memcached-dev/php-memcached.git /usr/src/php/ext/memcached

RUN docker-php-ext-install /usr/src/php/ext/memcached


RUN apk add ffmpeg

WORKDIR /usr/src/myapp

VOLUME /usr/src/myapp

EXPOSE 8080
EXPOSE 6379

STOPSIGNAL SIGKILL

CMD tail -f /dev/null