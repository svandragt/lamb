FROM dunglas/frankenphp:php8.2
LABEL maintainer="Sander van Dragt <sander@vandragt.com>"

# Install application dependencies (gd powers WebP upload conversion)
RUN install-php-extensions yaml gettext pdo_mysql gd

# git is needed by the bin/upgrade tests
RUN apt-get update -y && apt-get install -y --no-install-recommends git && rm -rf /var/lib/apt/lists/*

WORKDIR /srv/app/

RUN touch /usr/local/etc/php/conf.d/app.ini && \
    echo "variables_order = \"EGPCS\"" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "display_errors = off" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "log_errors = On" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "error_log = /dev/stderr" >> /usr/local/etc/php/conf.d/app.ini

# Allow FrankenPHP to bind :80 when running as the (remapped) www-data user.
RUN setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp

COPY .docker/Caddyfile /etc/frankenphp/Caddyfile
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
