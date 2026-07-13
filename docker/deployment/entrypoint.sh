#!/bin/sh

# exit on any failure
set -e
# exit on unassigned variable
set -u

command=octane
if [ "$#" -gt 0 ]; then
    command="$1"
    shift
fi

_octane() {
  php /app/artisan config:cache
  php /app/artisan route:cache

  exec php /app/artisan octane:start --host=0.0.0.0 "$@"
}

case "$command" in
    artisan) exec php /app/artisan "$@";;
    assets) exec nginx -c /app/docker/deployment/nginx-assets.conf "$@";;
    octane) _octane "$@";;
    *) exec "$command" "$@";;
esac
