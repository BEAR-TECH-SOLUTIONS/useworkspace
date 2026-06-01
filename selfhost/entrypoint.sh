#!/usr/bin/env bash
#
# Self-hosted container entrypoint. Boots the license-check guard
# BEFORE php-fpm so docker-compose surfaces a license failure as a
# proper "Exited (1)" container status with a clear log message.
#
# After the guard passes, exec into the CMD (php-fpm by default;
# overridable by docker run so the same image can host the queue
# worker, scheduler, and reverb services).

set -euo pipefail

cd /var/www

if [ -z "${LICENSE_TOKEN:-}" ]; then
    echo "FATAL: LICENSE_TOKEN is unset. See https://usework.space/docs/self-hosted/license."
    exit 1
fi

# Only the primary `app` service migrates. The same image powers
# worker / scheduler / reverb too, and without this gate all four
# containers race `php artisan migrate` against the same DB on boot
# and trip "relation already exists" / "duplicate key" errors. The
# `app` service sets TC_RUN_MIGRATIONS=1 in compose.yml; everyone
# else inherits the default 0 and skips.
if [ "${TC_RUN_MIGRATIONS:-0}" = "1" ]; then
    # Idempotent on subsequent boots — `migrate --force` is a no-op
    # when nothing is pending.
    php artisan migrate --force --no-interaction
fi

# Verify the Ed25519 signature, check expires_at, optionally pin
# domain. Exits non-zero on any failure; the error message lands in
# `docker compose logs app`.
php artisan tc:license:check

exec "$@"
