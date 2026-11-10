FROM php:8.4-fpm

RUN docker-php-ext-install pdo_mysql opcache

RUN usermod  -u 1000 www-data \
 && groupmod -g 1000 www-data \
 && chown -R www-data:www-data /var/www

RUN { \
      echo 'display_errors=On'; \
      echo 'display_startup_errors=On'; \
      echo 'error_reporting=E_ALL'; \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/zz-xal-dev.ini

WORKDIR /var/www/html
