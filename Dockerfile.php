FROM php:8.2-fpm

# Установка зависимостей
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libicu-dev \
    zlib1g-dev \
    zip \
    unzip \
    git \
    curl \
    ca-certificates \
    msmtp \
    msmtp-mta \
    && rm -rf /var/lib/apt/lists/*

# Установка PHP расширений для Битрикс
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
    gd \
    mysqli \
    pdo \
    pdo_mysql \
    zip \
    mbstring \
    xml \
    curl \
    intl \
    opcache \
    exif \
    bcmath \
    soap

# Установка дополнительных расширений через PECL
RUN pecl install redis apcu \
    && docker-php-ext-enable redis apcu

# Копирование конфигурации PHP
COPY php-bitrix.ini /usr/local/etc/php/conf.d/99-bitrix.ini
COPY php-fpm-bitrix.conf /usr/local/etc/php-fpm.d/zz-bitrix.conf

# Entry-point: generate ~/.msmtprc from env (optional)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Установка прав
RUN chown -R www-data:www-data /var/www

# Рабочая директория
WORKDIR /var/www/html

# Пользователь
USER www-data

# Expose порт PHP-FPM
EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]

