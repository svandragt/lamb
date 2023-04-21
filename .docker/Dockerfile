FROM php:8.1-fpm
LABEL maintainer="Sander van Dragt <sander@vandragt.com>"

# Install application dependencies
RUN apt-get update -y && \
	apt-get install libyaml-dev -y && \
	pecl install yaml && echo "extension=yaml.so" > /usr/local/etc/php/conf.d/ext-yaml.ini && docker-php-ext-enable yaml && \
	docker-php-ext-install gettext

COPY . /srv/app
WORKDIR /srv/app/

RUN echo "variables_order = \"EGPCS\"" > /usr/local/etc/php/conf.d/app.ini
RUN echo "display_errors = off" > /usr/local/etc/php/conf.d/app.ini
RUN echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh", "docker-php-entrypoint", "php-fpm"]
