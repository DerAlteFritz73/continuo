# ─── Stage 1: Composer dependencies ─────────────────────────────────────────
FROM composer:2 AS composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-scripts \
    --no-interaction

# ─── Stage 2: Node / Webpack Encore assets ───────────────────────────────────
FROM node:24-alpine AS node

WORKDIR /app

COPY package.json ./
RUN npm install

COPY webpack.config.js ./
COPY assets/ ./assets/
RUN npm run build

# ─── Stage 3: Production image ───────────────────────────────────────────────
FROM php:8.2-fpm-alpine AS production

# nginx (php-fpm-alpine base already has all required PHP extensions: ctype,
# iconv, dom, simplexml, xml, libxml — they are core and compiled in)
RUN apk add --no-cache nginx libzip-dev \
    && docker-php-ext-install pdo_mysql zip \
    && printf 'upload_max_filesize = 10M\npost_max_size = 15M\nmemory_limit = 512M\n' \
       > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/continuo

# Copy vendor from composer stage
COPY --from=composer /app/vendor ./vendor

# Copy built assets from node stage
COPY --from=node /app/public/build ./public/build

# Copy application source (honoured by .dockerignore)
COPY . .

# Ensure runtime dirs exist and are writable
RUN mkdir -p var/cache var/log var/share \
    && chown -R www-data:www-data var/ public/build/

# nginx vhost
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Entrypoint
COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV APP_ENV=prod

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
