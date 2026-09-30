FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev unzip \
    && docker-php-ext-install pdo_mysql zip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# Stock limits: the import must fit max_execution_time=30; only the upload size is raised for the ~11 MB file.
RUN printf "max_execution_time=30\nmemory_limit=128M\nupload_max_filesize=64M\npost_max_size=64M\ndisplay_errors=Off\nlog_errors=On\n" > /usr/local/etc/php/conf.d/app.ini
# Web root is public/; src/ and vendor/ are not reachable over HTTP.
ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

WORKDIR /var/www/app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-autoloader
COPY public/ public/
COPY src/ src/
RUN composer dump-autoload --no-dev --optimize
