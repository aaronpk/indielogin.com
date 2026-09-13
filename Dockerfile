# IndieLogin.com — PHP application image
#
# Base: Debian + Apache. The app is a front-controller (everything goes through
# public/index.php), so Apache is configured with a rewrite that sends all
# non-file/non-directory requests to index.php while still serving the static
# assets under public/assets, /public/images and /public/icons.
FROM php:8.3-apache

# System packages: git/curl/zip/unzip for Composer, libicu + libpng for the
# intl and gd extensions that some dependencies need.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        zip \
        unzip \
        libicu-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo \
        pdo_mysql \
        mysqli \
        sockets \
        gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# PHP modules shipped with the official image: openssl (OpenPGP RSA/DSA/ECDSA)
# and sodium (OpenPGP Ed25519) — both are enabled by default here, so the
# PGP sign-in path has what it needs without extra installs.

# Apache modules for the front-controller rewrite
RUN a2enmod rewrite headers

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first (layered caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Application source
COPY . .

# Regenerate the autoloader now that the source is present. We rely on the
# autoload "files" entries to pull in lib/helpers.php and lib/ATProto.php.
RUN composer dump-autoload --no-dev --optimize

# Apache docroot is public/; the rewrite rule lives in public/.htaccess.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# The app logs to logs/ (gitignored) and writes PGP data under pgp/data/.
RUN mkdir -p /var/www/html/logs /var/www/html/pgp/data \
    && chown -R www-data:www-data /var/www/html/logs /var/www/html/pgp/data

# Tunnel coordinator: discovers the public base URL from the active tunnel and
# writes it into the mounted .env before Apache boots. See docker-compose.yml
# for how TUNNEL_* point at the swap-pable tunnel backend.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
