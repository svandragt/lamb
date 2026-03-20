---
title: Local PHP setup
---

# Local PHP setup

Make sure everything is installed:

```bash
# Install required system packages, for example on Debian Linux derivatives like Ubuntu
sudo apt update
sudo apt install php8.2 php8.2-gettext php8.2-mbstring php8.2-sqlite3 php8.2 php8.2-xml composer

# install project packages
composer install

# Run lamb - Change `hackme` to something more secure, this is the `/login` password!
LAMB_LOGIN_PASSWORD=$(php make-password.php hackme) composer serve
```

Uploaded images are stored under `src/assets/`, so if you are serving Lamb through PHP-FPM or another web server user, make sure that directory is writable at runtime.

**Contributors:** To facilitate debugging using XDebug, it's best to open the site as [http://localhost:8747/](http://localhost:8747/)
