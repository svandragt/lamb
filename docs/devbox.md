---
title: Devbox
---

# Devbox

> **Well-travelled path.** Devbox is the maintainer's daily development environment. It wraps the [local PHP setup]({{ site.baseurl }}{% link local-php-setup.md %}), running the same built-in PHP web server that the test suite verifies on every change.

```shell
devbox shell

# Run the remaining commands inside that shell.
composer install

# Set your /login password. Change `hackme` to something more secure.
php make-password.php hackme

# Run Lamb. The dev server reads .env automatically.
composer serve

```
