---
title: FrankenPHP
---

# FrankenPHP

> **Well-travelled path.** The FrankenPHP runtime is the same one inside the release Docker image, which is verified by the automated acceptance suite before every release (the `release-verify` workflow).

[FrankenPHP](https://frankenphp.dev) is the Caddy webserver with a PHP runtime built in: a single binary serves Lamb with no separate php-fpm service to configure. It is the recommended way to host Lamb on a server you control.

A working `Caddyfile` is provided in the project root. From the project directory:

```shell
sudo -E frankenphp run
```

(or `composer serve:frankenphp`, which runs the same command). `sudo` is needed to bind port 80; `-E` keeps your environment variables — see below. Update the `lamb.test` site address in the `Caddyfile` to your own domain; with a public domain on port 443 Caddy provisions HTTPS certificates automatically.

## Logins

To allow logins, set the output of `php make-password.php hackme` (don't use hackme) as the `LAMB_LOGIN_PASSWORD` environment variable for the process:

```shell
export LAMB_LOGIN_PASSWORD='JDJ5JDEwJExMQm1j...GM5S2Q0VWY3Rk9sdXoyVVFkYTg3bDA1M'
sudo -E frankenphp run
```

The `-E` flag makes `sudo` pass the variable through. For a production host, set it in the systemd unit (`Environment=LAMB_LOGIN_PASSWORD=...`) instead.

## Writable directories

The `data` and `src/assets` directories must be writable by the user FrankenPHP runs as. `data` holds the SQLite database; `src/assets` is the runtime upload directory used for images dropped into posts. Theme CSS and application JavaScript live elsewhere and do not need to be writable at runtime.

## Caching static assets

Uploaded files under `src/assets/` use content-addressed names, and theme CSS / application JavaScript are cache-busted by a content hash in their query string (`?ver=…`), so all are safe to cache aggressively. The shipped `Caddyfile` already serves them with a long, immutable cache:

```caddyfile
@static path /themes/* /scripts/* /assets/*
header @static Cache-Control "public, max-age=31536000, immutable"
```

## Related

- [Installation options]({{ site.baseurl }}{% link index.md %})
- [NGINX configuration]({{ site.baseurl }}{% link nginx.md %})
- [Docker]({{ site.baseurl }}{% link docker.md %})
- [Upgrading]({{ site.baseurl }}{% link upgrading.md %})
