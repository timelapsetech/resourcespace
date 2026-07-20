FROM ubuntu:24.04

LABEL org.opencontainers.image.title="ResourceSpace (custom)"
LABEL org.opencontainers.image.description="ResourceSpace with image_sequence and local plugins"

ENV DEBIAN_FRONTEND=noninteractive
ENV APACHE_RUN_USER=www-data
ENV APACHE_RUN_GROUP=www-data
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
    apache2 \
    composer \
    cron \
    curl \
    ffmpeg \
    ghostscript \
    git \
    imagemagick \
    libapache2-mod-php \
    libimage-exiftool-perl \
    php \
    php-apcu \
    php-cli \
    php-curl \
    php-gd \
    php-intl \
    php-mbstring \
    php-mysql \
    php-xml \
    php-zip \
    unzip \
    wget \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# PHP limits suitable for large stills / ZIP ingest / proxy encodes
RUN printf '%s\n' \
        'upload_max_filesize = 512M' \
        'post_max_size = 512M' \
        'max_execution_time = 600' \
        'max_input_time = 600' \
        'memory_limit = 1G' \
        > /etc/php/8.3/apache2/conf.d/99-resourcespace.ini \
    && cp /etc/php/8.3/apache2/conf.d/99-resourcespace.ini /etc/php/8.3/cli/conf.d/99-resourcespace.ini

RUN a2enmod rewrite headers \
    && printf '%s\n' \
        '<Directory /var/www/html>' \
        '    Options FollowSymLinks' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/resourcespace.conf \
    && a2enconf resourcespace \
    && rm -f /var/www/html/index.html

WORKDIR /var/www/html

# App code from this repo (plugins + StaticSync hook included)
COPY --chown=www-data:www-data . /var/www/html/

RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
    && mkdir -p /var/www/html/filestore /data/syncdir /data/originals \
    && if [ ! -f plugins/image_sequence/config/config.php ] \
         && [ -f plugins/image_sequence/config/config.php.example ]; then \
         cp plugins/image_sequence/config/config.php.example \
            plugins/image_sequence/config/config.php; \
       fi \
    && chown -R www-data:www-data /var/www/html/filestore /data \
    && chmod -R ug+rwX /var/www/html/filestore /var/www/html/include

COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/crontab /tmp/resourcespace.cron
RUN chmod +x /entrypoint.sh \
    && crontab -u www-data /tmp/resourcespace.cron \
    && rm -f /tmp/resourcespace.cron

EXPOSE 80

CMD ["/entrypoint.sh"]
