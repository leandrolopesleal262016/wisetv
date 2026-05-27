FROM php:8.3-apache

RUN a2enmod rewrite headers expires && \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/apache/wisetv.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/wisetv.ini
COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
