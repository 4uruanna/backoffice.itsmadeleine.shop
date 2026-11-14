# Image de développement : même version de PHP que l'hébergement OVH (8.5).
FROM php:8.5-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libzip-dev unzip git \
 && docker-php-ext-install pdo_mysql gd zip \
 && rm -rf /var/lib/apt/lists/*

# Racine web = public/, comme sur OVH (dossier backoffice/public), .htaccess actif.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && a2enmod rewrite

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# var/ (journaux, livraisons de la sonde) vit dans un volume nommé, inscriptible par Apache.
RUN mkdir -p /var/www/html/var/log && chown -R www-data:www-data /var/www/html/var
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
