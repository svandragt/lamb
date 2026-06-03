---
title: NGINX configuration
---

# NGINX configuration

Copy the files in the `site-available` and `snippets` into the respective directories. The `fastcgi`  and `php`
configuration files might already exist on the system, in which case you can use these as known-good reference.

Update the `lamb.test` file to point to your preferred server_name, logs and document root.

## PHP-FPM

The `data` and `src/assets` directory must be writable by the user php-fpm runs under, this is usually `www-data`.

`src/assets` is the runtime upload directory used for images dropped into posts. Theme CSS and application JavaScript live elsewhere and do not need to be writable at runtime.

```shell
sudo chown $USER:www-data data -R
sudo chmod g+w data -R
sudo chown $USER:www-data src/assets -R
sudo chmod g+w src/assets -R
```

To allow logins, add the output of `HIDDEN=1 php make-password.php hackme` (don't use hackme) as an
environment variable
to `/etc/php/8.4/fpm/pool.d/www.conf` (replace `8.4` with your installed PHP version):

```text
env[LAMB_LOGIN_PASSWORD] = JDJ5JDEwJExMQm1j...k9sdXoyVVFkYTg3bDA1M
```

## Caching static assets

Uploaded files under `src/assets/` use content-addressed names (a hash of the
file), and theme CSS / application JavaScript under `/themes/` and `/scripts/`
are cache-busted by a content hash in their query string (`?ver=…`). In every
case the URL changes whenever the content changes, so all three are safe to
cache aggressively and indefinitely.

The shipped `lamb.conf` snippet already serves them with a long, immutable
cache via this `location` block:

```nginx
location ~ ^/(themes|scripts|assets)/ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}
```

Without it, NGINX serves static files with no `Cache-Control`, which Lighthouse
flags as *"Use efficient cache lifetimes"* and which forces repeat visitors to
re-download fonts, CSS and images on every visit.

## Restart services

```shell
sudo systemctl restart nginx
sudo systemctl restart php8.4-fpm
```
