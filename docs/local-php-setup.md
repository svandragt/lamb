---
title: Local PHP setup
---

# Local PHP setup

Make sure everything is installed:

```bash
# Install required system packages, for example on Debian Linux derivatives like Ubuntu
sudo apt update
sudo apt install php8.4 php8.4-gettext php8.4-mbstring php8.4-sqlite3 php8.4-xml php8.4-mysql composer
# PHP 8.2–8.5 are supported; replace 8.4 with your preferred version
# php8.4-mysql (pdo_mysql) is required by the database library even though Lamb uses SQLite

# install project packages
composer install

# Run lamb - Change `hackme` to something more secure, this is the `/login` password!
LAMB_LOGIN_PASSWORD=$(php make-password.php hackme) composer serve
```

Uploaded images are stored under `src/assets/`, so if you are serving Lamb through PHP-FPM or another web server user, make sure that directory is writable at runtime.
