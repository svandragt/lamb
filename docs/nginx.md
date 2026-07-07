---
title: NGINX configuration
---

# NGINX configuration

> **Well-travelled path.** The shipped `.nginx/` configuration is deployed and verified by the automated acceptance suite before every release (the `release-verify` workflow), so this is a supported, regularly-tested way to run Lamb.

Copy the files in the `site-available` and `snippets` into the respective directories. The `fastcgi`  and `php`
configuration files might already exist on the system, in which case you can use these as known-good reference.

Update the `lamb.test` file to point to your preferred server_name, logs and document root.

## PHP-FPM

The `data` and `src/assets` directory must be writable by the user php-fpm runs under, this is usually `www-data`.

`src/assets` is the runtime upload directory used for images and video dropped into posts. Theme CSS and application JavaScript live elsewhere and do not need to be writable at runtime.

### Upload size limits

PHP's defaults (`upload_max_filesize = 2M`, `post_max_size = 8M`) reject photos larger than 2&nbsp;MB, and will reject essentially all video. Raise them in your php.ini or FPM pool, for example:

```text
upload_max_filesize = 100M
post_max_size = 100M
```

The shipped `snippets/lamb.conf` sets the matching NGINX limit (`client_max_body_size 100m;` — its default is only 1m). See [Media]({{ site.baseurl }}{% link media.md %}) for details.

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

## Caching PHP responses (optional)

The static-asset block above only helps repeat visits to the same browser. If
you expect traffic spikes (a link from Hacker News, Reddit, etc.) you can also
let NGINX cache the **HTML responses** themselves with `fastcgi_cache`, serving
them straight from disk without invoking PHP-FPM or SQLite at all.

This is **opt-in and not enabled by default.** Lamb already emits correct cache
headers for anonymous visitors (`Cache-Control: max-age=300`, `Vary: Cookie`)
and `private, no-store` for logged-in ones, so a CDN such as Cloudflare in front
of Lamb gives you the same edge-caching for free. Only reach for `fastcgi_cache`
if you are running NGINX directly *and* expect load that the browser cache can't
absorb (many distinct first-time visitors).

> **Footgun warning.** `fastcgi_cache` ignores `Vary`, so it will *not*
> automatically key anonymous and logged-in responses apart the way the browser
> cache does. You **must** bypass the cache for logged-in requests explicitly
> (below), or NGINX may store and re-serve a logged-in page — complete with the
> admin toolbar and a stale CSRF token — to anonymous visitors.

### 1. Define the cache zone and bypass rules (http context)

Create `/etc/nginx/conf.d/lamb-cache.conf` (the `http {}` context — these
directives cannot live inside a `server {}` snippet):

```nginx
# Where cached responses live, plus a 100 MB in-memory key zone.
fastcgi_cache_path /var/cache/nginx/lamb levels=1:2 keys_zone=lamb:100m
                   inactive=60m max_size=1g;

# Skip the cache for logged-in visitors: Lamb sets the signed `lamb_logged_in`
# cookie on login, so its presence means "do not cache / do not serve cached".
map $cookie_lamb_logged_in $lamb_skip_cache_cookie {
    default 1;   # cookie present → skip
    ""      0;   # anonymous      → cacheable
}

# Only ever cache safe, idempotent methods.
map $request_method $lamb_skip_cache_method {
    default 1;
    GET     0;
    HEAD    0;
}
```

`/var/cache/nginx/` must be writable by the NGINX worker user (usually
`www-data`); create it with `sudo install -d -o www-data -g www-data
/var/cache/nginx/lamb`.

### 2. Enable the cache on the PHP location (server context)

Add the cache directives to the `location ~ \.php$` block in
`snippets/php-82.conf` (or your own copy):

```nginx
location ~ \.php$  {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;

    # --- fastcgi_cache (optional) ---
    fastcgi_cache       lamb;
    fastcgi_cache_key   "$scheme$request_method$host$request_uri";

    # Honour the app's own Cache-Control (max-age=300 for anonymous pages);
    # this fallback only applies to responses without a cache lifetime.
    fastcgi_cache_valid 200 5m;
    fastcgi_cache_valid 404 1m;

    # Never store, and never serve from cache, when either rule says skip.
    # `_bypass` = don't read from cache; `no_cache` = don't write to it.
    fastcgi_cache_bypass $lamb_skip_cache_cookie $lamb_skip_cache_method;
    fastcgi_no_cache     $lamb_skip_cache_cookie $lamb_skip_cache_method;

    # Optional: expose hits/misses for debugging (HIT / MISS / BYPASS).
    add_header X-Cache-Status $upstream_cache_status always;
}
```

Lamb's `private, no-store` header on logged-in responses already prevents NGINX
from caching them, so the cookie bypass is defence-in-depth — but keep it: it
also stops a cached *anonymous* page from being served back to a logged-in user
within the TTL.

### 3. Verify

```shell
sudo systemctl reload nginx
curl -sI https://your-site/ | grep -i x-cache-status   # MISS, then HIT
curl -sI https://your-site/ -b lamb_logged_in=anything | grep -i x-cache-status  # BYPASS
```

Because the TTL matches the app's `max-age` (5 minutes), a freshly published
post can take up to that long to appear for anonymous visitors — the same
staleness window the browser cache already has. If that's not acceptable, drop
the TTL or purge the zone on publish (a paid `ngx_cache_purge` / NGINX Plus
feature).

## Restart services

```shell
sudo systemctl restart nginx
sudo systemctl restart php8.4-fpm
```

## Related

- [Installation options]({{ site.baseurl }}{% link index.md %})
- [FrankenPHP]({{ site.baseurl }}{% link frankenphp.md %})
