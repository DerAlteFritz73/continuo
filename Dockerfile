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
# Debian (glibc) base rather than Alpine (musl): the audio key detector needs
# librosa, whose numba/llvmlite deps ship no musl wheels and would otherwise
# compile LLVM from source. On glibc all deps install as prebuilt wheels.
FROM php:8.2-fpm-bookworm AS production

# System deps: nginx (web server); ffmpeg + libsndfile1 (audio decoding for the
# tonality detector); python3/venv (librosa sidecar); libzip for the zip ext.
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx libnginx-mod-http-brotli-filter libnginx-mod-http-brotli-static \
        ffmpeg libsndfile1 python3 python3-venv \
        libzip-dev zlib1g-dev \
    && docker-php-ext-install pdo_mysql zip \
    && printf 'upload_max_filesize = 30M\npost_max_size = 32M\nmemory_limit = 512M\n' \
       > /usr/local/etc/php/conf.d/uploads.ini \
    && rm -rf /var/lib/apt/lists/*

# Python venv with librosa for the audio key detector (bin/audio_keychroma.py).
# Requirements copied first so this heavy layer is cached across source changes.
COPY bin/audio-requirements.txt /tmp/audio-requirements.txt
RUN python3 -m venv /opt/audio-venv \
    && /opt/audio-venv/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/audio-venv/bin/pip install --no-cache-dir -r /tmp/audio-requirements.txt
# AudioChromagramExtractor reads this to locate the librosa interpreter.
ENV AUDIO_PYTHON_BIN=/opt/audio-venv/bin/python

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

# nginx vhost (Debian loads /etc/nginx/conf.d/*.conf; drop the stock default site)
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
RUN rm -f /etc/nginx/sites-enabled/default

# Entrypoint
COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV APP_ENV=prod

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
