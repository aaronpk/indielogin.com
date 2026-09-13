FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git curl zip unzip \
        libicu-dev libpng-dev libjpeg-dev libfreetype6-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" intl pdo pdo_mysql mysqli sockets gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# openssl (RSA/DSA/ECDSA) and sodium (Ed25519) for the PGP path are enabled by
# default in the base image and need no extra install.
RUN a2enmod rewrite headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --no-dev --optimize

# Front-controller: docroot is public/, rewrite lives in public/.htaccess.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN mkdir -p /var/www/html/logs /var/www/html/pgp/data \
    && chown -R www-data:www-data /var/www/html/logs /var/www/html/pgp/data

# Tunnel coordinator sets BASE_URL from the tunnel before Apache starts.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
