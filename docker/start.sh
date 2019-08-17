#!/bin/sh

echo "Running as user '$(whoami)' ${UID}"
echo "Using port ${PORT:-8888}"
echo "Running in ${PWD} path"
ls -lhaR

# set port number to be listened as $PORT or 8888
sed -i -E "s/listen.*;/listen ${PORT:-8888};/" /etc/nginx/conf.d/*.conf

# "/var/tmp/nginx" owned by "nginx" user is unusable on heroku dyno so re-create on runtime
mkdir -p /var/tmp/nginx

# make php-fpm be able to listen request from nginx (current user is nginx executor)
sed -i -E "s/^;listen.owner = .*/listen.owner = $(whoami)/" /etc/php7/php-fpm.d/www.conf
# get logs to work...
sed -i -E "s/^;catch_workers_output = .*/catch_workers_output = yes/" /etc/php7/php-fpm.d/www.conf
sed -i -E "s/^;clear_env = .*/clear_env = no/" /etc/php7/php-fpm.d/www.conf

# make current user the executor of nginx and php-fpm expressly for local environment
sed -i -E "s/^user = .*/user = $(whoami)/" /etc/php7/php-fpm.d/www.conf
sed -i -E "s/^group = (.*)/;group = \1/" /etc/php7/php-fpm.d/www.conf
sed -i -E "s/^user .*/user $(whoami);/" /etc/nginx/nginx.conf

cp lib/config.12-factor.php lib/config.php

# cat /etc/nginx/nginx.conf
# cat /etc/php7/php-fpm.d/www.conf
# ls -lhaR /etc/nginx/conf.d/
# cat /etc/nginx/conf.d/*.conf

composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

supervisord --nodaemon --configuration /etc/supervisord.conf
