FROM node:22-slim AS assets

WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-progress
COPY vite.config.js ./
COPY resources/ resources/
RUN npm run build


FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
        libzip-dev \
        libicu-dev \
        unzip \
    && docker-php-ext-install \
        intl \
        zip \
        opcache \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APACHE_DOCUMENT_ROOT=/var/www/html/www

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

RUN echo '<Directory /var/www/html/www>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/nette.conf \
    && a2enconf nette

COPY docker-php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html

COPY . .
COPY --from=assets /build/www/dist www/dist

RUN composer install --no-dev --no-interaction --optimize-autoloader --classmap-authoritative

RUN chown -R www-data:www-data temp log www/dist

EXPOSE 80
