# syntax=docker/dockerfile:1.6
#
# Self-hosted usework.space image.
#
# Cloud-only code (Modules/Cloud, migrations-cloud) is excluded by
# omission: we explicitly never COPY those directories. The
# selfhost/ci-check.sh script grep-asserts the result on every build
# so a future "convenience" COPY app/ never accidentally drags Cloud
# back in.
#
# Build:
#   docker build -t ghcr.io/usework/server:dev -f selfhost/Dockerfile .

FROM php:8.4-fpm-alpine AS base
RUN apk add --no-cache \
        bash \
        git \
        unzip \
        icu-dev \
        oniguruma-dev \
        postgresql-client \
        postgresql-dev \
        libsodium-dev \
        libzip-dev \
        $PHPIZE_DEPS \
        linux-headers \
    && docker-php-ext-install pdo_pgsql intl mbstring bcmath pcntl sodium zip sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS linux-headers

WORKDIR /var/www

# ---- composer install in a vendor stage so the runtime image stays slim
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock /var/www/
# Stub every PSR-4 root that composer.json declares so the
# `--optimize-autoloader` scan doesn't fail on a missing directory.
# composer.json registers three roots:
#   App\\               -> app/
#   Database\\Factories -> database/factories/
#   Database\\Seeders   -> database/seeders/
# bootstrap/cache must exist before any artisan call (post-autoload
# scripts are skipped via --no-scripts, but better to have it than
# debug a future regression). storage/ subtree mirrors Laravel's
# stock layout for the same reason.
RUN mkdir -p \
        app \
        database/factories \
        database/seeders \
        bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs
COPY artisan /var/www/artisan
# `-v` so the next composer regression surfaces the actual error in
# the Actions log instead of just `exit code: 2`. Plumes a few more
# lines into a successful build — acceptable trade for diagnosability.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        -v

# ---- final runtime image
FROM base
WORKDIR /var/www

COPY --from=vendor /var/www/vendor ./vendor

# Core app code. We deliberately COPY only the directories that live
# in either edition's allow-list — Modules/Cloud is never copied.
# When the full Core code-move into Modules/Core lands, swap this for
# `COPY app/Modules/Core` instead of the flat layout below.
COPY app/Casts               ./app/Casts
COPY app/Console             ./app/Console
COPY app/Contracts           ./app/Contracts
COPY app/Enums               ./app/Enums
COPY app/Events              ./app/Events
COPY app/Exceptions          ./app/Exceptions
COPY app/Http                ./app/Http
COPY app/Models              ./app/Models
COPY app/Observers           ./app/Observers
COPY app/Policies            ./app/Policies
COPY app/Providers           ./app/Providers
COPY app/Rules               ./app/Rules
COPY app/Services            ./app/Services
COPY app/Support             ./app/Support
COPY app/Modules/SelfHosted  ./app/Modules/SelfHosted

# Migrations: core + self-hosted-only. NEVER migrations-cloud.
COPY database/migrations             ./database/migrations
COPY database/migrations-selfhosted  ./database/migrations-selfhosted
COPY database/seeders                ./database/seeders

# Explicit route file allow-list — routes/cloud.php imports
# Modules/Cloud and must never appear in the self-hosted image.
COPY routes/api.php         ./routes/api.php
COPY routes/channels.php    ./routes/channels.php
COPY routes/console.php     ./routes/console.php
COPY routes/web.php         ./routes/web.php
COPY routes/selfhosted.php  ./routes/selfhosted.php

COPY config        ./config
COPY bootstrap     ./bootstrap
COPY public        ./public
COPY artisan       ./artisan

# Laravel introspects composer.json at runtime (Application::version,
# package:discover, etc.) so it MUST land in the final image even
# though composer install ran in the vendor stage. composer.lock is
# also copied for any code that reads installed-package metadata.
COPY composer.json composer.lock ./

# Public Ed25519 key baked into the image at build time. The matching
# private key never leaves the cloud env.
COPY licensing/public_key.pem ./licensing/public_key.pem

COPY selfhost/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Defensive: even if a sloppy future edit re-introduces
# `COPY app/ ./app/`, fail the build loudly when cloud bits are
# present in the image filesystem.
RUN if find /var/www -path '*/Modules/Cloud*' -print -quit | grep -q .; then \
        echo 'ERROR: app/Modules/Cloud present in self-hosted image'; exit 1; \
    fi \
 && if find /var/www -path '*/migrations-cloud*' -print -quit | grep -q .; then \
        echo 'ERROR: database/migrations-cloud present in self-hosted image'; exit 1; \
    fi

ENV TC_EDITION=self_hosted \
    APP_ENV=production \
    APP_DEBUG=false

# Laravel's writable runtime tree. Created in the IMAGE (not at first
# boot) because compose.yml mounts a named volume at
# /var/www/storage — and Docker seeds a fresh named volume from the
# image's directory contents on its first attach, preserving
# ownership. Without these directories existing pre-mount, the volume
# comes up empty + root-owned, php-fpm runs as www-data, can't write
# storage/logs/laravel.log, and crashes in a restart loop.
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/public \
        bootstrap/cache \
 && chown -R www-data:www-data /var/www \
 && chmod -R ug+rwX /var/www/storage /var/www/bootstrap/cache

USER www-data

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]
