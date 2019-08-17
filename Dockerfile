FROM alpine

ENV DOCROOT /docroot
WORKDIR /docroot

RUN \
    apk update \
    \
    # install php
    && apk add php7 \
    && apk add php7-apcu \
    && apk add php7-ctype \
    && apk add php7-curl \
    && apk add php7-dom \
    && apk add php7-fileinfo \
    && apk add php7-ftp \
    && apk add php7-iconv \
    && apk add php7-intl \
    && apk add php7-json \
    && apk add php7-mbstring \
    && apk add php7-mcrypt \
    && apk add php7-mysqlnd \
    && apk add php7-opcache \
    && apk add php7-openssl \
    && apk add php7-pdo \
    && apk add php7-pdo_mysql \
    && apk add php7-phar \
    && apk add php7-posix \
    && apk add php7-session \
    && apk add php7-simplexml \
    && apk add php7-mysqli \
    && apk add php7-tokenizer \
    && apk add php7-xml \
    && apk add php7-xmlreader \
    && apk add php7-xmlwriter \
    && apk add php7-zlib \
    \
    # install php-fpm
    && apk add php7-fpm \
    # install mysql client
    && apk add mysql-client \
    # make php-fpm listen to not tcp port but unix socket
    && sed -i -E "s/127\.0\.0\.1:9000/\/var\/run\/php-fpm\/php-fpm.sock/" /etc/php7/php-fpm.d/www.conf \
    && mkdir /var/run/php-fpm \
    \
    # install nginx and create default pid directory
    && apk add nginx \
    && mkdir -p /run/nginx \
    \
    # forward nginx logs to docker log collector
    && sed -i -E "s/error_log .+/error_log \/dev\/stderr warn;/" /etc/nginx/nginx.conf \
    && sed -i -E "s/access_log .+/access_log \/dev\/stdout main;/" /etc/nginx/nginx.conf \
    \
    # install supervisor
    && apk add supervisor \
    && mkdir -p /etc/supervisor.d/ \
    \
    # remove caches to decrease image size
    && rm -rf /var/cache/apk/* \
    \
    # install composer
    && EXPECTED_SIGNATURE=$(wget -q -O - https://composer.github.io/installer.sig) \
    && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php -r "if (hash_file('SHA384', 'composer-setup.php') === '$EXPECTED_SIGNATURE') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;" \
    && php composer-setup.php --install-dir=/usr/bin --filename=composer \
    && php -r "unlink('composer-setup.php');"

ENV PHP_INI_DIR /etc/php7
ENV NGINX_CONFD_DIR /etc/nginx/conf.d

COPY docker/php.ini /etc/php7/php.ini
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisor.programs.ini /etc/supervisor.d/supervisor.programs.ini
COPY docker/start.sh /start.sh

RUN \
    # add non-root user
    # @see https://devcenter.heroku.com/articles/container-registry-and-runtime#run-the-image-as-a-non-root-user
    adduser -D nonroot \
    \
    # followings are just for local environment
    # (on heroku dyno there is no permission problem because most of the filesystem owned by the current non-root user)
    && chmod a+x /start.sh \
    \
    # to update conf files and create temp files under the directory via sed command on runtime
    && chmod -R a+w /etc/php7/php-fpm.d \
    && chmod -R a+w /etc/nginx \
    \
    # to run php-fpm (socker directory)
    && chmod a+w /var/run/php-fpm \
    \
    # to run nginx (default pid directory and tmp directory)
    && chmod -R a+w /run/nginx \
    && chmod -R a+wx /var/tmp/nginx \
    \
    # to run supervisor (read conf and create socket)
    && chmod -R a+r /etc/supervisor* \
    && sed -i -E "s/^file=\/run\/supervisord\.sock/file=\/run\/supervisord\/supervisord.conf/" /etc/supervisord.conf \
    && mkdir -p /run/supervisord \
    && chmod -R a+w /run/supervisord \
    \
    # to output logs
    && chmod -R a+w /var/log \
    && chown -R nonroot /var/log \
    \
    # add nonroot to sudoers
    && apk add --update sudo \
    && echo "nonroot ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers

ADD . /docroot/

ONBUILD RUN \
    # attempt to composer install
    # (if depends on any commands that don't exist at this time, like npm, explicit doing composer install on downstream Dockerfile is necessary)
    if [ -f "composer.json" ]; then \
        composer install --no-interaction || : \
    ; fi \
    \
    # get logs to work...
    sed -i -E "s/^;catch_workers_output = .*/catch_workers_output = yes/" /etc/php7/php-fpm.d/www.conf && \
    sed -i -E "s/^;clear_env = .*/clear_env = no/" /etc/php7/php-fpm.d/www.conf && \
    # fix permission of docroot for non-root user
    && chmod -R a+w /docroot

# Add non root user
USER nonroot

CMD ["/start.sh"]
