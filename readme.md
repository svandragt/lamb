Literally Another Micro Blog.

Barrier free super simple blogging, selfhosted.

- sqlite based
- twitter like interface
- register to a flock, index of bleats


# Getting started

Setup requirements
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

- Login
- Security tightening
- attach an image
- feeds
- integration into 'flock' (wip)

# Screenshot
![image](https://user-images.githubusercontent.com/594871/224541914-20ce6cee-24cf-4ebf-8962-0b69ea5bccf0.png)
