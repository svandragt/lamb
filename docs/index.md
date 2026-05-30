---
title: Lamb
---

# Lamb — Literally Another Micro Blog.

<img src="https://github.com/svandragt/lamb/blob/main/src/images/og-image-lamb.jpg?raw=true" alt="Lamb made out of circuitry" />

Barrier free super simple blogging, self-hosted. [Read about the features](https://github.com/svandragt/lamb/blob/main/README.md).

## Requirements

- PHP 8.2 – 8.5
- SQLite3, gettext, simplexml, mbstring, pdo_mysql extensions (pdo_mysql is required by the database library even though Lamb uses SQLite)

## Getting started

```
# Checkout project - release branch is stable
git clone --branch release https://github.com/svandragt/lamb.git
cd lamb
```

Lamb can be run locally with the builtin PHP webserver, or with other tooling.

## Deployment options

Webservers:

1. [Caddy]({{ site.baseurl }}{% link caddy.md %})
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
* [Menu Items]({{ site.baseurl }}{% link menu-items.md %})
* [Micropub]({{ site.baseurl }}{% link micropub.md %})
* [Post Types]({{ site.baseurl }}{% link post-types.md %})
* [Preconnect]({{ site.baseurl }}{% link preconnect.md %})
* [Redirections]({{ site.baseurl }}{% link redirections.md %})
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %})
* [Search]({{ site.baseurl }}{% link search.md %})
* [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %})
* [Themes]({{ site.baseurl }}{% link themes.md %})
* [Trash]({{ site.baseurl }}{% link trash.md %})
* [Upgrading]({{ site.baseurl }}{% link upgrading.md %})
