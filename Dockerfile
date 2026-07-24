FROM php:8.2-apache

# Install system packages
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    libcurl4-openssl-dev

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        gd \
        zip \
        mbstring \
        intl

# Enable Apache rewrite
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first (better Docker caching)
COPY composer.json composer.lock ./

RUN composer install --no-dev --prefer-dist --no-interaction

# Copy application
COPY . .

# Create writable temp directory for mPDF
RUN mkdir -p /var/www/html/tmp \
    && chmod -R 777 /var/www/html/tmp

EXPOSE 80
