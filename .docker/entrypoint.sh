#!/usr/bin/env bash

echo "Checking USER ID"

APP_ROOT=/srv/app/src
APP_DATA=/srv/app/data
APP_ASSETS=/srv/app/src/assets

WWW_UID=`stat -c "%u" "$APP_ROOT"`
WWW_GID=`stat -c "%g" "$APP_ROOT"`

echo "Host user is $WWW_UID:$WWW_GID"

if [ ! $WWW_UID -eq 0 ]; then
    echo "Updating www-data user and group to match host IDs"
    usermod -u $WWW_UID www-data
    groupmod -g $WWW_GID www-data
fi

mkdir -p "$APP_DATA"
mkdir -p "$APP_ASSETS"
chown www-data:www-data "$APP_DATA"
chown www-data:www-data "$APP_ASSETS"

# FrankenPHP runs PHP in-process, so the server itself must run as www-data
# for uploads and the SQLite database to stay owned by the host user.
# Caddy needs its state directories writable by that user too.
chown -R www-data:www-data /data /config

# Any parameters to this script will now be executed as www-data,
# keeping the container environment (-p) so LAMB_LOGIN_PASSWORD survives.
exec su -p -s /bin/sh www-data -c "$*"
