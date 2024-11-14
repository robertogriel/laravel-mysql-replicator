FROM php:8.3.13

ENV TZ=America/Sao_Paulo \
    DEBIAN_FRONTEND=noninteractive

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      tzdata  \
      libzip-dev \
      libxml2-dev \
      libcurl4-openssl-dev \
      libonig-dev \
      libssl-dev \
      pkg-config \
      unzip \
      curl \
      default-mysql-server && \
    ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN docker-php-ext-install \
    mysqli \
    pdo_mysql \
    mbstring \
    xml \
    zip \
    bcmath \
    curl \
    sockets

RUN pecl install xdebug && \
    docker-php-ext-enable xdebug

RUN usermod -d /var/lib/mysql mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install && composer dump-autoload

COPY . .
COPY my.cnf-tests /etc/mysql/conf.d/custom.cnf

COPY init-db.sh /usr/local/bin/init-db.sh
RUN chmod +x /usr/local/bin/init-db.sh

ENTRYPOINT ["/usr/local/bin/init-db.sh"]
