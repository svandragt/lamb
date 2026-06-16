---
title: Lamb
---

# Lamb — Literally Another Micro Blog.

<img src="{{ site.baseurl }}/og-image-lamb.webp" alt="Lamb made out of circuitry" />

Barrier free super simple blogging, self-hosted. [Read about the features](https://github.com/svandragt/lamb/blob/main/README.md).

## Requirements

- PHP 8.2 – 8.5
- SQLite3, gettext, simplexml, mbstring, pdo_mysql extensions (pdo_mysql is required by the database library even though Lamb uses SQLite)
- gd extension, recommended: converts image uploads to WebP (without it originals are stored as-is)

## Getting started

There are three ways to install Lamb. All of them track the stable release channel.

### 1. Docker image (easiest)

No PHP, git, or Composer needed on the host — just Docker:

```
# Generate a password hash first (any machine with PHP), or copy one from make-password.php output
docker run -d --name lamb -p 80:80 \
  -e LAMB_LOGIN_PASSWORD='<your-hash>' \
  -v lamb-data:/app/data -v lamb-assets:/app/src/assets \
  ghcr.io/svandragt/lamb:latest
```

See [Docker]({{ site.baseurl }}{% link docker.md %}) for details.

### 2. Release tarball

For shared hosting or servers without git/Composer. Download `lamb-<version>.tar.gz` from the [releases page](https://github.com/svandragt/lamb/releases) — it includes all dependencies:

```
mkdir lamb && tar -xzf lamb-<version>.tar.gz --strip-components=1 -C lamb
cd lamb
php make-password.php <your-password>
```

Point your webserver at the `src/` directory ([FrankenPHP]({{ site.baseurl }}{% link frankenphp.md %}) or [Nginx]({{ site.baseurl }}{% link nginx.md %})). Those pages also cover making `data/` and `src/assets/` writable by the webserver user.

### 3. Git checkout

Requires git and [Composer](https://getcomposer.org):

```
# Checkout project - release branch is stable
git clone --branch release https://github.com/svandragt/lamb.git
cd lamb
composer install --no-dev
php make-password.php <your-password>
```

This route gets you the `bin/upgrade` script for one-command (or cron-driven) upgrades — see [Upgrading]({{ site.baseurl }}{% link upgrading.md %}).

Lamb can be run locally with the builtin PHP webserver, or with other tooling.

## Verified setups

The well-travelled paths — verified automatically by the acceptance test suite — are the [Docker image]({{ site.baseurl }}{% link docker.md %}) and [Nginx]({{ site.baseurl }}{% link nginx.md %}) (checked before every release by the `release-verify` workflow), [FrankenPHP]({{ site.baseurl }}{% link frankenphp.md %}) (same runtime as the Docker image), and the [built-in PHP webserver]({{ site.baseurl }}{% link local-php-setup.md %}) (checked on every change). [Devbox]({{ site.baseurl }}{% link devbox.md %}) wraps the built-in webserver and is the maintainer's daily development environment, so it is well-travelled too. [DDev]({{ site.baseurl }}{% link ddev.md %}) is a convenience wrapper and is not separately tested.

## Deployment options

Webservers:

1. [FrankenPHP]({{ site.baseurl }}{% link frankenphp.md %})
2. [Nginx]({{ site.baseurl }}{% link nginx.md %})

Containers:

1. [Docker]({{ site.baseurl }}{% link docker.md %})

Devtools / local environments / sandbox:

1. [DDev]({{ site.baseurl }}{% link ddev.md %}) local environments wrapper around Docker. Convenient.
2. [Devbox]({{ site.baseurl }}{% link devbox.md %}) portable, isolated, developer environments. Tidy.
3. [Local PHP setup]({{ site.baseurl }}{% link local-php-setup.md %}) DIY. Control.

## Main Topics

* [Cron Scheduled Tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %})
* [Cross-posting]({{ site.baseurl }}{% link cross-posting.md %})
* [Drafts]({{ site.baseurl }}{% link drafts.md %})
* [Feeds]({{ site.baseurl }}{% link feeds.md %})
* [Media]({{ site.baseurl }}{% link media.md %})
* [Menu Items]({{ site.baseurl }}{% link menu-items.md %})
* [Micropub]({{ site.baseurl }}{% link micropub.md %})
* [Post Types]({{ site.baseurl }}{% link post-types.md %})
* [Preconnect]({{ site.baseurl }}{% link preconnect.md %})
* [Project Goals]({{ site.baseurl }}{% link project-goals.md %})
* [Redirections]({{ site.baseurl }}{% link redirections.md %})
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %})
* [Search]({{ site.baseurl }}{% link search.md %})
* [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %})
* [Themes]({{ site.baseurl }}{% link themes.md %})
* [Trash]({{ site.baseurl }}{% link trash.md %})
* [Upgrading]({{ site.baseurl }}{% link upgrading.md %})
