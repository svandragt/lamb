---
title: Devbox
---

> **Well-travelled path.** Devbox is the maintainer's daily development environment. It wraps the [local PHP setup]({{ site.baseurl }}{% link local-php-setup.md %}), running the same built-in PHP webserver that the test suite verifies on every change.

```shell
devbox shell

# In the shell from now on
composer install

# Run lamb - Change `hackme` to something more secure, this is the `/login` password!
LAMB_LOGIN_PASSWORD=$(php make-password.php hackme) composer serve

```
