FROM php:8.3.12-apache

RUN apt-get update && apt-get install -y \
    curl \
    git \
    libzip-dev \
    unzip \
    && docker-php-ext-install zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
RUN php composer-setup.php
RUN php -r "unlink('composer-setup.php');"
RUN mv composer.phar /usr/local/bin/composer

COPY . /var/www/html/

RUN composer install --optimize-autoloader

EXPOSE 80
