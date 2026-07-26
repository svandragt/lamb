#!/usr/bin/env bash

# FrankenPHP runs PHP in-process, so the server itself must drop root
# before serving, otherwise a code-execution bug in the app runs as
# container root. Chown first so upgrades from volumes created by
# earlier (root-only) releases still get www-data write access.
mkdir -p /app/data /app/src/assets
chown -R www-data:www-data /app/data /app/src/assets /data /config

# Any parameters to this script will now be executed as www-data,
# keeping the container environment (-p) so LAMB_LOGIN_PASSWORD survives.
exec su -p -s /bin/sh www-data -c "$*"
