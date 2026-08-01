---
title: Login security
---

# Login security

Lamb has a single admin account, and `/login` is the only page an anonymous
visitor can reach that accepts a password. Two features guard it out of the box,
and neither needs configuring.

## Failed-attempt throttle

After **10 failed attempts**, Lamb refuses further attempts from the same
address for **15 minutes**. The login page comes back with the wait spelled out,
and the response carries `429 Too Many Requests` with a `Retry-After` header.

Details worth knowing:

* The counter is **per client address**, so someone hammering your login form
  can't lock you out from your own machine.
* Lamb **doesn't count a refused attempt**, so retrying while blocked doesn't
  extend the block. The 15 minutes always run from your last real attempt.
* A **successful login clears the counter** immediately. A run of typos costs
  you nothing once you get the password right.
* Lamb checks the counter *before* the password, so a blocked client can't make
  the server do password-hashing work.

If you lock yourself out, wait it out. There's no reset command, by design. The
window is short and the block lapses on its own.

## Failed-attempt logging

Every failed attempt writes a line to PHP's error log:

```
failed admin login from 203.0.113.7
```

Your web server or PHP-FPM configuration decides where that lands, commonly
`/var/log/php-fpm/error.log` or the container's stderr. To see whether anyone
has been trying, search the log for `failed admin login`. The line never
contains the submitted password.

## Behind a reverse proxy

Both features identify the client by its network address (`REMOTE_ADDR`) and
deliberately ignore the `X-Forwarded-For` header, which any client can forge
when Lamb is reachable directly.

If Lamb sits behind a reverse proxy, CDN, or tunnel, every visitor therefore
looks like the proxy. The throttle then behaves like a single shared counter,
and log lines show the proxy's address. For per-visitor accuracy, configure your
proxy to present the real client address to PHP, for example with NGINX's
`realip` module.

## Choose a password

`make-password.php` warns on standard error when the password you give it is
weak. The throttle raises the cost of guessing, but a strong password is what
makes guessing hopeless.

## Related

* [Site configuration]({{ site.baseurl }}{% link site-configuration.md %}): You edit settings at `/settings`, behind this login.
* [NGINX]({{ site.baseurl }}{% link nginx.md %}): Where to configure the real client address behind a proxy.
* [Docker]({{ site.baseurl }}{% link docker.md %}): Setting `LAMB_LOGIN_PASSWORD` for a container install.
