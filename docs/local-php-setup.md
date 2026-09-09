---
title: Local PHP setup
parent: Installation & hosting
---

# Local PHP setup

> **Well-travelled path.** The built-in PHP webserver is exercised by the full test suite on every change, across PHP 8.4–8.5 (the `ci` workflow).

Make sure everything is installed:

```bash
# Install required system packages, for example on Debian Linux derivatives like Ubuntu
sudo apt update
sudo apt install php8.4 php8.4-gettext php8.4-mbstring php8.4-sqlite3 php8.4-mysql php8.4-xml php8.4-gd composer
# PHP 8.4–8.5 are supported; replace 8.4 with your preferred version
# php8.4-sqlite3 ships pdo_sqlite, the driver Lamb actually connects through
# php8.4-mysql ships pdo_mysql, required because RedBeanPHP references a MySQL PDO constant at load time and fatals without it (Lamb never connects to MySQL)
# php8.4-gd converts image uploads to WebP; without it originals are stored as-is

# install project packages
composer install

# Set your /login password - change `hackme` to something more secure.
# This writes the hashed password to .env.
php make-password.php hackme

# Run lamb - the dev server reads .env automatically.
composer serve
```

Uploaded images are stored under `src/assets/`, so if you are serving Lamb through PHP-FPM or another web server user, make sure that directory is writable at runtime.
