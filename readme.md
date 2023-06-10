![Lamb made out of circuitry](src/images/og-image-lamb.jpg)

Lamb - Literally Another Micro Blog.

Barrier free super simple blogging, self-hosted.

- SQLite based portable single author blog
- Twitter like interface
- Friction free Markdown entry
- Generates a discoverable Atom feed (/feed)
- Hashtags support, by just typing them.
- 404 fallback url feature (redirects 404's relative urls to another site).

# Getting started

You can run this locally with the builtin PHP webserver:

Setup requirements:
[PHP 8.1](https://www.php.net/manual/en/install.php),
[composer](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-macos).

```shell
# Install required system packages
$ sudo apt update
$ sudo apt install php8.1 php8.1-gettext php8.1-mbstring php8.1-sqlite3 php8.1-yaml php8.1-xml composer

# checkout project
$ git clone https://github.com/svandragt/lamb.git
$ cd lamb

# install project packages
$ composer install
```

To Run:

```shell
$ LAMB_LOGIN_PASSWORD=$(php make_password_hash.php hackme) composer serve
$ open http://localhost:8747/
```

Support for [Docker](docs/docker.md), [Caddy](docs/caddy.md) and [NGINX](docs/nginx.md) is also provided.

# Site Configuration (optional)

Add a `src/config.ini` file with the following contents and update any of the following lines after
uncommenting them:

```ini
;author_email = joe.sheeple@example.com
;author_name = Joe Sheeple
;site_title = Bleats
;404_fallback = https://my.oldsite.com
```

# TODO

- attach an image
- docs
- full text search for improved tag and search pages.
- integration into 'flock' (wip)

# Screenshot

![image](https://i.imgur.com/rwk2VmV.png)

# Philosophy

- Simple over complex.
- Opinionated defaults over settings.
- Assume success, communicate failure.
