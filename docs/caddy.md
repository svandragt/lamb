# Caddy configuration

A working Caddyfile is provided in the project root.

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
to `/etc/php/8.2/fpm/pool.d/www.conf`:

```text
env[LAMB_LOGIN_PASSWORD] = JDJ5JDEwJExMQm1j...GM5S2Q0VWY3Rk9sdXoyVVFkYTg3bDA1M
```

# Restart services

```shell
sudo systemctl restart php8.2-fpm
```

For production hosts:

```shell
sudo systemctl restart caddy
```

Alternatively, for local development you can run `sudo caddy run` in the project root.
