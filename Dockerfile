# Stage 1: Build Frontend Assets
FROM node:20 as frontend
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

# Stage 2: Install Backend Dependencies
FROM composer:2 as backend
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs --no-scripts

# Stage 3: Production Image
FROM php:8.2-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    mysql-client \
    git \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    linux-headers

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    bcmath \
    zip \
    gd \
    intl \
    opcache \
    exif \
    pcntl

# Configure PHP
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# Configure Nginx
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# Configure Supervisor
COPY docker/supervisord.conf /etc/supervisord.conf

# Setup Working Directory
WORKDIR /var/www/html

# Copy Backend Dependencies from Stage 2
COPY --from=backend /app/vendor ./vendor

# Copy Frontend Assets from Stage 1
COPY --from=frontend /app/public/build ./public/build

# Copy Application Code
COPY . .

# Set Permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Expose Port
EXPOSE 80

# Entrypoint
COPY entrypoint.sh /opt/bin/entrypoint.sh
RUN sed -i 's/\r$//' /opt/bin/entrypoint.sh
RUN chmod +x /opt/bin/entrypoint.sh
ENTRYPOINT ["/opt/bin/entrypoint.sh"]
