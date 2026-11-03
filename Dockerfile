# SlipKing on Render (PHP 8.2 + Apache). Put this file at the root of the repo, next to the site files.
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libcurl4-openssl-dev libonig-dev libssl-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli mbstring gd curl fileinfo \
 && a2enmod rewrite headers expires \
 && rm -rf /var/lib/apt/lists/*

# PHP settings the site needs (uploads, time limits, memory)
RUN { \
      echo 'file_uploads=On'; \
      echo 'upload_max_filesize=64M'; \
      echo 'post_max_size=64M'; \
      echo 'max_execution_time=60'; \
      echo 'memory_limit=256M'; \
      echo 'expose_php=Off'; \
      echo 'date.timezone=Africa/Nairobi'; \
    } > /usr/local/etc/php/conf.d/slipking.ini

# Allow .htaccess and listen on the port Render gives us (PORT, default 10000)
RUN sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && sed -ri 's!Listen 80!Listen ${PORT}!' /etc/apache2/ports.conf \
 && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:${PORT}>!' /etc/apache2/sites-available/000-default.conf \
 && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf && a2enconf servername

COPY . /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
 && mkdir -p /var/www/html/cache/sessions /var/www/html/cache/data /var/www/html/cache/tipimg /var/www/html/logs /var/www/html/uploads \
 && chown -R www-data:www-data /var/www/html

ENV PORT=10000
EXPOSE 10000
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
