---
title: Local PHP setup
---

# Local PHP setup

> **Well-travelled path.** The full test suite exercises the built-in PHP web server on every change, across PHP 8.2 to 8.5 (the `ci` workflow).

Install everything you need:

```bash
# Install the required system packages. This example is for Debian Linux
# derivatives such as Ubuntu.
sudo apt update
sudo apt install php8.4 php8.4-gettext php8.4-mbstring php8.4-sqlite3 php8.4-xml php8.4-mysql php8.4-gd composer
# Lamb supports PHP 8.2 to 8.5. Replace 8.4 with your preferred version.
# The database library requires php8.4-mysql (pdo_mysql) even though Lamb uses SQLite.
# php8.4-gd converts image uploads to WebP. Without it, originals are stored unchanged.

# Install the project packages.
composer install

# Set your /login password. Change `hackme` to something more secure.
# This writes the hashed password to .env.
php make-password.php hackme

# Run Lamb. The dev server reads .env automatically.
composer serve
```

Lamb stores uploaded images under `src/assets/`. If you serve Lamb through PHP-FPM or another web server user, make that directory writable at runtime.
