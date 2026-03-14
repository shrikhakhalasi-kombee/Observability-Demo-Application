# --- Base PHP image with required extensions ---
FROM php:8.3-fpm

# Install system dependencies and PHP extensions used by Laravel
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip \
        zip \
        procps \
        libicu-dev \
        libpng-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        libprotobuf-dev \
        protobuf-compiler \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring exif sockets pcntl bcmath gd zip intl \
    && rm -rf /var/lib/apt/lists/*


RUN pecl install protobuf apcu xdebug \
    && docker-php-ext-enable protobuf apcu xdebug


# Install Composer from official image
COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer

# Install Node.js 20 (required for Vite / Livewire assets)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copy all project files (artisan must be present for composer scripts)
COPY . .

# Allow Git to safely track the directory
RUN git config --global --add safe.directory /var/www/html

# Install PHP dependencies
RUN composer install --optimize-autoloader --no-interaction

# Install Node dependencies and build frontend assets
RUN npm install && npm run build

# Fix storage permissions
RUN chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 775 /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/bootstrap/cache

EXPOSE 8000

ENTRYPOINT ["docker/php/entrypoint.sh"]
