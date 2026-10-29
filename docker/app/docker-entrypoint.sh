#!/bin/sh
set -e

# The project is bind-mounted in development: install the locked dependencies
# into its vendor/ before serving, so a "git pull" never runs on stale packages.
if [ "$1" = 'frankenphp' ] && [ -f composer.json ]; then
    composer install --prefer-dist --no-progress --no-interaction
fi

exec docker-php-entrypoint "$@"
