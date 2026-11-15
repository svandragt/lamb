---
title: Local PHP setup
parent: Installation & hosting
---

# Local PHP setup

> **Well-travelled path.** The built-in PHP webserver is exercised by the full test suite on every change, across the supported PHP versions (the `ci` workflow).

Make sure everything in [Requirements]({{ site.baseurl }}{% link index.md %}#requirements) is installed:

```bash
# Install required system packages, for example on Debian Linux derivatives like Ubuntu
sudo apt update
sudo apt install php8.5 php8.5-gettext php8.5-mbstring php8.5-sqlite3 php8.5-mysql php8.5-xml php8.5-gd
# replace 8.5 with your preferred supported PHP version
# php8.5-sqlite3 ships pdo_sqlite, php8.5-mysql ships pdo_mysql and php8.5-gd is the gd extension

# Install viv, the dependency installer, version 0.22.0 or later (no apt package; there is no PHP toolchain to conflict with)
cargo binstall --git https://github.com/svandragt/vivace vivace
# or download a release binary from https://github.com/svandragt/vivace/releases
# (viv's releases are tagged as prereleases, so use the releases page, not a "latest release" link)

# install project packages
viv install

# Set your /login password - change `hackme` to something more secure.
# This writes the hashed password to .env.
php make-password.php hackme

# Run lamb - the dev server reads .env automatically.
viv run serve
```

[Login security]({{ site.baseurl }}{% link login-security.md %}#setting-the-password) explains the password hash. If you are serving Lamb through PHP-FPM or another web server user, make [`data/` and `src/assets/` writable]({{ site.baseurl }}{% link index.md %}#writable-directories) for that user.
