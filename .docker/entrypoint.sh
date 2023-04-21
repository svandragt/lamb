#!/usr/bin/env bash

echo "Checking USER ID"

APP_ROOT=/srv/app/src
APP_DATA=/srv/app/data

WWW_UID=`stat -c "%u" "$APP_ROOT"`
WWW_GID=`stat -c "%g" "$APP_ROOT"`

echo "Host user is $WWW_UID:$WWW_GID"

if [ ! $WWW_UID -eq 0 ]; then
    echo "Updating www-data user and group to match host IDs"
    usermod -u $WWW_UID www-data
    groupmod -g $WWW_GID www-data
fi

mkdir -p "$APP_DATA"
chown www-data:www-data "$APP_DATA"

# Any parameters to this script will now be executed (parent entrypoint)
exec "$@"
