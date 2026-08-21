FROM php:8.4-apache

WORKDIR /var/www/glpi

COPY . /var/www/glpi

RUN apt-get update \
    && apt-get install -y \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libldap2-dev \
        libbz2-dev \
        libxml2-dev \
        libonig-dev \
        unzip \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        mysqli \
        intl \
        gd \
        zip \
        bz2 \
        exif \
        bcmath \
        opcache \
        soap \
    && a2enmod rewrite \
    && chown -R www-data:www-data /var/www/glpi \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /var/glpi \
    && chown -R www-data:www-data /var/glpi

ENV GLPI_CONFIG_DIR=/var/glpi/config
ENV GLPI_VAR_DIR=/var/glpi/files
ENV GLPI_LOG_DIR=/var/glpi/logs

VOLUME /var/glpi

RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/glpi/public#' /etc/apache2/sites-available/000-default.conf

EXPOSE 80

CMD ["apache2-foreground"]
