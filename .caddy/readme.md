# Caddy configuration

A working Caddyfile is provided in the project root.

## PHP-FPM

The `data` directory must be writable by the user php-fpm runs under, this is usually `www-data`:

```
sudo chown $USER:www-data data -R
sudo chmod g+w data -R
```

To allow logins, add the output of `php make_password_hash.php hackme` (don't use hackme) as an
environment variable
to `/etc/php/8.1/fpm/pool.d/www.conf`:

```
env[LAMB_LOGIN_PASSWORD] = JDJ5JDEwJExMQm1j...GM5S2Q0VWY3Rk9sdXoyVVFkYTg3bDA1M
```

# Restart services

For production hosts:

```
sudo systemctl restart php8.1-fpm
sudo systemctl restart caddy
```

Alternatively, for local development you can run `sudo caddy run` in the project root.
