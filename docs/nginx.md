# NGINX configuration

Copy the files in the `site-available` and `snippets` into the respective directories. The `fastcgi`  and `php`
configuration files might already exist on the system, in which case you can use these as known-good reference.

Update the `lamb.test` file to point to your preferred server_name, logs and document root.

## PHP-FPM

The `data` and `src/assets` directory must be writable by the user php-fpm runs under, this is usually `www-data`:

```shell
sudo chown $USER:www-data data -R
sudo chmod g+w data -R
sudo chown $USER:www-data src/assets -R
sudo chmod g+w src/assets -R
```

To allow logins, add the output of `HIDDEN=1 php make_password_hash.php hackme` (don't use hackme) as an
environment variable
to `/etc/php/8.1/fpm/pool.d/www.conf`:

```text
env[LAMB_LOGIN_PASSWORD] = JDJ5JDEwJExMQm1j...k9sdXoyVVFkYTg3bDA1M
```

## Restart services

```shell
sudo systemctl restart nginx
sudo systemctl restart php8.1-fpm
```
