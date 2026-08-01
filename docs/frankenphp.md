---
title: FrankenPHP
nav_order: 3
---

# FrankenPHP

> **Well-travelled path.** The FrankenPHP runtime is the same one inside the release Docker image, which the automated acceptance suite verifies before every release (the `release-verify` workflow).

[FrankenPHP](https://frankenphp.dev) is the Caddy web server with a PHP runtime built in: a single binary serves Lamb, with no separate PHP-FPM service to configure. It's the recommended way to host Lamb on a server you control.

The project root includes a working `Caddyfile`. From the project directory, run:

```shell
sudo -E frankenphp run
```

You can also run `composer serve:frankenphp`, which runs the same command. `sudo` binds port 80, and `-E` keeps your environment variables. See [Logins](#logins). Change the `lamb.test` site address in the `Caddyfile` to your own domain. With a public domain on port 443, Caddy provisions HTTPS certificates automatically.

## Logins

To allow logins, set the output of `php make-password.php hackme` as the `LAMB_LOGIN_PASSWORD` environment variable for the process. Use your own password rather than `hackme`:

```shell
export LAMB_LOGIN_PASSWORD='JDJ5JDEwJExMQm1j...GM5S2Q0VWY3Rk9sdXoyVVFkYTg3bDA1M'
sudo -E frankenphp run
```

The `-E` flag makes `sudo` pass the variable through. On a production host, set it in the systemd unit instead, with `Environment=LAMB_LOGIN_PASSWORD=...`.

## Writable directories

The user that FrankenPHP runs as needs write access to the `data` and `src/assets` directories. `data` holds the SQLite database, and `src/assets` is the runtime upload directory for images dropped into posts. Theme CSS and application JavaScript live elsewhere and don't need to be writable at runtime.

## Cache static assets

Uploaded files under `src/assets/` use content-addressed names, and a content hash in the query string (`?ver=…`) cache-busts theme CSS and application JavaScript. All of them are therefore safe to cache aggressively. The shipped `Caddyfile` already serves them with a long, immutable cache:

```caddyfile
@static path /themes/* /scripts/* /assets/*
header @static Cache-Control "public, max-age=31536000, immutable"
```

## Related

- [Installation options]({{ site.baseurl }}{% link index.md %})
- [NGINX configuration]({{ site.baseurl }}{% link nginx.md %})
- [Docker]({{ site.baseurl }}{% link docker.md %})
- [Upgrading]({{ site.baseurl }}{% link upgrading.md %})
