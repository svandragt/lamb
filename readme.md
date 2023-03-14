Literally Another Micro Blog.

Barrier free super simple blogging, selfhosted.

- SQLite based portable single author blog
- Twitter like interface
- Friction free Markdown entry
- Generates a discoverable Atom feed (/feed)
- Hashtags support, by just typing them.

# Getting started

Setup requirements: [PHP 8.1](https://www.php.net/manual/en/install.php), [composer](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-macos).
```
sudo apt install php8.1 php8.1-sqlite3
composer install
```

Run:
```
LAMB_LOGIN_PASSWORD=hackme composer serve
open http://localhost:8747/
```

Support for Caddy (untested) is also provided.

# Configuration (optional)

Place a `config.ini` file in the project root with the following contents and update any of the following lines after uncommenting them:
```
;author_email = joe.sheeple@example.com
;author_name = Joe Sheeple
;site_title = Bleats
```


# TODO

- attach an image
- docs
- integration into 'flock' (wip)

# Screenshot
![image](https://user-images.githubusercontent.com/594871/224541914-20ce6cee-24cf-4ebf-8962-0b69ea5bccf0.png)
