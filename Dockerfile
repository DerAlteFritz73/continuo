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
# NOTE (Mini PC migration, 2026-08-06): both pip installs below are hardened against
# this network's flaky transfers — a bare `pip install` hits `Read timed out` on the
# larger wheels (llvmlite 60MB, opencv 60MB). Hardening: a buildkit pip cache mount
# (dropped `--no-cache-dir` so completed wheels persist across retries/rebuilds),
# `--retries 30 --timeout 120`, and an until-loop so a hard failure just retries.
# Revert to the plain committed `pip install --no-cache-dir` once the network is
# reliable again. (Do not push this local hardening upstream as-is.)

# Python venv with librosa for the audio key detector (bin/audio_keychroma.py).
# Requirements copied first so this heavy layer is cached across source changes.
COPY bin/audio-requirements.txt /tmp/audio-requirements.txt
RUN --mount=type=cache,target=/root/.cache/pip \
    python3 -m venv /opt/audio-venv \
    && /opt/audio-venv/bin/pip install --retries 30 --timeout 120 --upgrade pip \
    && i=0; until /opt/audio-venv/bin/pip install --retries 30 --timeout 120 -r /tmp/audio-requirements.txt; do \
        i=$((i+1)); [ "$i" -ge 10 ] && echo "audio venv pip failed after $i attempts" && exit 1; \
        echo "pip stalled/failed (flaky network), retry $i..."; sleep 3; \
    done
# AudioChromagramExtractor reads this to locate the librosa interpreter.
ENV AUDIO_PYTHON_BIN=/opt/audio-venv/bin/python

# Second venv for the facsimile staff re-liner (bin/staff_reline.py).  Kept
# apart from the audio one so a numpy bump for librosa cannot break OpenCV, and
# so neither venv has to be rebuilt when the other's pins move.
COPY bin/reline-requirements.txt /tmp/reline-requirements.txt
RUN --mount=type=cache,target=/root/.cache/pip \
    python3 -m venv /opt/reline-venv \
    && /opt/reline-venv/bin/pip install --retries 30 --timeout 120 --upgrade pip \
    && i=0; until /opt/reline-venv/bin/pip install --retries 30 --timeout 120 -r /tmp/reline-requirements.txt; do \
        i=$((i+1)); [ "$i" -ge 10 ] && echo "reline venv pip failed after $i attempts" && exit 1; \
        echo "pip stalled/failed (flaky network), retry $i..."; sleep 3; \
    done
# StaffRelineService reads this to locate the OpenCV interpreter.
ENV RELINE_PYTHON_BIN=/opt/reline-venv/bin/python

WORKDIR /var/www/continuo

# Copy vendor from composer stage
COPY --from=composer /app/vendor ./vendor

# Copy built assets from node stage
COPY --from=node /app/public/build ./public/build

# Copy application source (honoured by .dockerignore)
COPY . .

# Ensure runtime dirs exist and are writable
RUN mkdir -p var/cache var/log var/share var/reline \
    && chown -R www-data:www-data var/ public/build/

# nginx vhost (Debian loads /etc/nginx/conf.d/*.conf; drop the stock default site)
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
RUN rm -f /etc/nginx/sites-enabled/default

# Entrypoint
COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV APP_ENV=prod

# Stamped at build time so the running app can show when it was deployed.
# Last line of the stage on purpose: it changes on every build and would
# otherwise invalidate the layers above it.
#   docker compose build --build-arg BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)" app
#   docker compose build --build-arg BUILD_DATE=... --build-arg BUILD_REV=$(git rev-parse --short HEAD) app
ARG BUILD_DATE=""
ARG BUILD_REV=""
ENV APP_DEPLOYED_AT=$BUILD_DATE
ENV APP_VERSION=$BUILD_REV

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
