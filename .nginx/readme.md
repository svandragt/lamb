# NGINX configuration

The `data` directory must be writable by the user php-fpm runs under, this is usually `www-data`:

```
sudo chown $USER:www-data data -R
sudo chmod g+w data -R
```

Copy the files in the `site-available` and `snippets` into the respective directories. The `fastcgi`  and `php` configuration might already exist on the system, in which case you can use these as reference.

Update the `lamb.test` file to point to your preferred server_name, logs and document root.


# PHP-FPM

To allow logins, add your login password as an environment variable to `/etc/php/8.1/fpm/pool.d/www.conf`:

```
env[LAMB_LOGIN_PASSWORD] = hackme
```


# Restart services

```
sudo systemctl restart nginx
sudo systemctl restart php8.1-fpm
```