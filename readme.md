Literally Another Micro Blog.

Barrier free super simple blogging, selfhosted.

- SQLite based portable single author blog
- Twitter like interface
- Friction free Markdown entry
- Generates Atom feed

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


# TODO

- attach an image
- integration into 'flock' (wip)

# Screenshot
![image](https://user-images.githubusercontent.com/594871/224541914-20ce6cee-24cf-4ebf-8962-0b69ea5bccf0.png)
