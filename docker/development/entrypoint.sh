#!/bin/sh

uid=$(stat -c "%u" .)
gid=$(stat -c "%g" .)

if [ "$uid" != 0 ]; then
    usermod -u "$uid" -o osuweb > /dev/null
    groupmod -g "$gid" -o osuweb > /dev/null
fi

chmod 755 ./docker/development/php-wrapper
ln -sf /app/docker/development/php-wrapper /usr/local/bin/php

chown -f "${uid}:${gid}" ./storage/testjs-*

mkdir -p ./.docker/.cache/node/corepack
chown -Rf "$(id -u osuweb):$(id -g osuweb)" ./.docker

case "${1:-octane}" in
    assets|watch)
        mkdir -p ./node_modules ./public/assets ./resources/builds
        chown -Rf "$(id -u osuweb):$(id -g osuweb)" ./node_modules ./public/assets ./resources/builds
        ;;
    artisan|job|migrate|octane|schedule|test)
        mkdir -p \
            ./bootstrap/cache \
            ./public/uploads/central \
            ./public/uploads/default \
            ./storage/framework \
            ./storage/logs
        chmod 755 ./public/uploads
        chown -Rf "$(id -u osuweb):$(id -g osuweb)" \
            ./bootstrap/cache \
            ./public/uploads/central \
            ./public/uploads/default \
            ./storage/framework \
            ./storage/logs
        ;;
esac

exec runuser -u osuweb -- ./docker/development/run.sh "$@"
