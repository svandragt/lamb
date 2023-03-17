Literally Another Micro Blog.

Barrier free super simple blogging, self-hosted.

- SQLite based portable single author blog
- Twitter like interface
- Friction free Markdown entry
- Generates a discoverable Atom feed (/feed)
- Hashtags support, by just typing them.
- 404 fallback url feature (redirects 404's relative urls to another site).

# Getting started

Setup requirements:
[PHP 8.1](https://www.php.net/manual/en/install.php),
[composer](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-macos).

```shell
sudo apt install php8.1 php8.1-gettext php8.1-mbstring php8.1-sqlite3 php8.1-xml
composer install
```

Run:

```shell
LAMB_LOGIN_PASSWORD=hackme composer serve
open http://localhost:8747/
```

Support for Caddy (untested) is also provided.

# Configuration (optional)

Place a `config.ini` file in the project root with the following contents and update any of the following lines after
uncommenting them:

```ini
;author_email = joe.sheeple@example.com
;author_name = Joe Sheeple
;site_title = Bleats
;404_fallback = https://my.oldsite.com
```

See also [reference nginx configuration](.nginx/readme.md).

# TODO

- attach an image
- docs
- full text search for improved tag and search pages.
- integration into 'flock' (wip)

# Screenshot

![image](https://i.imgur.com/rwk2VmV.png)
