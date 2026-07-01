FROM php:8.4-apache

RUN apt-get update && apt-get install -y \
    default-libmysqlclient-dev \
    libcurl4-openssl-dev \
    unzip \
    && docker-php-ext-install pdo pdo_mysql curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY src/api /var/www/api
COPY src/public /var/www/html

RUN cd /var/www/api && composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data /var/www
