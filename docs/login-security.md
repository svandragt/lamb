---
title: Login security
parent: Site customisation
---

# Login security

Lamb has a single admin account, and `/login` is the only page an anonymous
visitor can reach that accepts a password. Two things guard it out of the box —
neither needs configuring.

## Failed-attempt throttle

After **10 failed attempts**, further attempts from the same address are refused
for **15 minutes**. The login page comes back with the wait spelled out, and the
response carries `429 Too Many Requests` with a `Retry-After` header.

Details worth knowing:

* The counter is **per client address**, so someone hammering your login form
  cannot lock you out from your own machine.
* A refused attempt is **not** counted, so retrying while blocked does not
  extend the block — the 15 minutes always run from your last real attempt.
* A **successful login clears the counter** immediately. A run of typos costs
  you nothing once you get the password right.
* The counter is refused *before* the password is checked, so a blocked client
  cannot make the server do password-hashing work.

Locked yourself out? Wait it out — there is no reset command, by design. The
window is short and the block lapses on its own.

## Failed-attempt logging

Every failed attempt writes a line to PHP's error log:

```
failed admin login from 203.0.113.7
```

Your webserver or PHP-FPM configuration decides where that lands (commonly
`/var/log/php-fpm/error.log` or the container's stderr). Grep for
`failed admin login` to see whether anyone has been trying. The line never
contains the submitted password.

## Behind a reverse proxy

Both features identify the client by its network address (`REMOTE_ADDR`) and
deliberately ignore the `X-Forwarded-For` header, which any client can forge
when Lamb is reachable directly.

If Lamb sits behind a reverse proxy, CDN, or tunnel, that means every visitor
looks like the proxy. The throttle then behaves like a single shared counter,
and log lines show the proxy's address. Configure your proxy to present the real
client address to PHP (for example with nginx's `realip` module) if you want
per-visitor accuracy.

## Choosing a password

`make-password.php` warns on standard error when the password you give it is
weak. The throttle raises the cost of guessing, but a strong password is what
actually makes guessing hopeless.

## Changing the password later

`make-password.php` writes `.env` in the directory you run it from, and refuses
to overwrite one that already exists:

```
.env already exists in /var/www/example.com
Refusing to overwrite it. Move it aside, or re-run with --force.
```

To replace the file, pass `--force`:

```
php make-password.php <your-new-password> --force
```

The refusal protects a live install. A `.env` on a server holds the hash your
site runs on, and an accidental second run — a test, a forgotten password —
used to replace it silently, leaving logins failing for a reason nothing
reported.

Under php-fpm, FrankenPHP, or Docker, Lamb does not read `.env` at all: it
reads the environment, and the hash reaches it from your pool config or
container settings. Regenerating `.env` on those setups changes nothing on its
own — copy the printed hash into `LAMB_LOGIN_PASSWORD` where the server sets
it. See [Nginx]({{ site.baseurl }}{% link nginx.md %}) for the php-fpm form.

## Related

* [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}): Settings are edited at `/settings`, behind this login.
* [Nginx]({{ site.baseurl }}{% link nginx.md %}): Where to configure the real client address behind a proxy.
* [Docker]({{ site.baseurl }}{% link docker.md %}): Setting `LAMB_LOGIN_PASSWORD` for a container install.
