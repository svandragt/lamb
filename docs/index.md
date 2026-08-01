---
title: Lamb
---

# Lamb — literally another micro blog

<img src="{{ site.baseurl }}/og-image-lamb.webp" alt="Lamb made out of circuitry" />

Lamb is barrier-free, self-hosted blogging. [Read about the features](https://github.com/svandragt/lamb/blob/main/README.md).

## Requirements

- PHP 8.2 to 8.5.
- The `sqlite3`, `gettext`, `simplexml`, `mbstring`, and `pdo_mysql` extensions. The database library requires `pdo_mysql` even though Lamb uses SQLite.
- The `gd` extension, recommended. It converts image uploads to WebP. Without it, Lamb stores the originals unchanged.

## Get started

There are three ways to install Lamb. All of them track the stable release channel.

### Option 1: Docker image (simplest)

You don't need PHP, Git, or Composer on the host — only Docker:

```
# Generate a password hash first on any machine with PHP,
# or copy one from the output of make-password.php.
docker run -d --name lamb -p 80:80 \
  -e LAMB_LOGIN_PASSWORD='<your-hash>' \
  -v lamb-data:/app/data -v lamb-assets:/app/src/assets \
  ghcr.io/svandragt/lamb:latest
```

For details, see [Docker]({{ site.baseurl }}{% link docker.md %}).

### Option 2: Release tarball

Use this option for shared hosting or servers without Git or Composer. Download `lamb-<version>.tar.gz` from the [releases page](https://github.com/svandragt/lamb/releases). It includes all dependencies:

```
mkdir lamb && tar -xzf lamb-<version>.tar.gz --strip-components=1 -C lamb
cd lamb
php make-password.php <your-password>
```

Point your web server at the `src/` directory. See [FrankenPHP]({{ site.baseurl }}{% link frankenphp.md %}) or [NGINX]({{ site.baseurl }}{% link nginx.md %}). Those pages also explain how to make `data/` and `src/assets/` writable by the web server user.

### Option 3: Git checkout

This option requires Git and [Composer](https://getcomposer.org):

```
# Check out the project. The release branch is the stable one.
git clone --branch release https://github.com/svandragt/lamb.git
cd lamb
composer install --no-dev
php make-password.php <your-password>
```

A Git checkout also gives you the `bin/upgrade` script for one-command or cron-driven upgrades. See [Upgrading]({{ site.baseurl }}{% link upgrading.md %}).

You can run Lamb locally with the built-in PHP web server or with other tooling.

## Verified setups

The acceptance test suite verifies the well-travelled paths automatically:

- The [Docker image]({{ site.baseurl }}{% link docker.md %}) and [NGINX]({{ site.baseurl }}{% link nginx.md %}), checked before every release by the `release-verify` workflow.
- [FrankenPHP]({{ site.baseurl }}{% link frankenphp.md %}), which uses the same runtime as the Docker image.
- The [built-in PHP web server]({{ site.baseurl }}{% link local-php-setup.md %}), checked on every change.

[Devbox]({{ site.baseurl }}{% link devbox.md %}) wraps the built-in web server and is the maintainer's daily development environment, so it is well-travelled too. [DDEV]({{ site.baseurl }}{% link ddev.md %}) is a convenience wrapper and isn't tested separately.

## Deployment options

Web servers:

1. [FrankenPHP]({{ site.baseurl }}{% link frankenphp.md %})
2. [NGINX]({{ site.baseurl }}{% link nginx.md %})

Containers:

1. [Docker]({{ site.baseurl }}{% link docker.md %})

Development tools, local environments, and sandboxes:

1. [DDEV]({{ site.baseurl }}{% link ddev.md %}), a local-environment wrapper around Docker. Convenient.
2. [Devbox]({{ site.baseurl }}{% link devbox.md %}), portable isolated developer environments. Tidy.
3. [Local PHP setup]({{ site.baseurl }}{% link local-php-setup.md %}), do it yourself. Full control.

## Main topics

* [Cron scheduled tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %})
* [Cross-posting]({{ site.baseurl }}{% link cross-posting.md %})
* [Drafts]({{ site.baseurl }}{% link drafts.md %})
* [Export]({{ site.baseurl }}{% link export.md %})
* [Feeds]({{ site.baseurl }}{% link feeds.md %})
* [Known import]({{ site.baseurl }}{% link known-import.md %})
* [Login security]({{ site.baseurl }}{% link login-security.md %})
* [Media]({{ site.baseurl }}{% link media.md %})
* [Menu items]({{ site.baseurl }}{% link menu-items.md %})
* [Micropub]({{ site.baseurl }}{% link micropub.md %})
* [Post types]({{ site.baseurl }}{% link post-types.md %})
* [Preconnect]({{ site.baseurl }}{% link preconnect.md %})
* [Project goals]({{ site.baseurl }}{% link project-goals.md %})
* [Redirections]({{ site.baseurl }}{% link redirections.md %})
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %})
* [Search]({{ site.baseurl }}{% link search.md %})
* [Search engines]({{ site.baseurl }}{% link search-engines.md %})
* [Site configuration]({{ site.baseurl }}{% link site-configuration.md %})
* [Theme functions]({{ site.baseurl }}{% link theme-functions.md %})
* [Themes]({{ site.baseurl }}{% link themes.md %})
* [Trash]({{ site.baseurl }}{% link trash.md %})
* [Upgrading]({{ site.baseurl }}{% link upgrading.md %})
* [WordPress import]({{ site.baseurl }}{% link wordpress-import.md %})
