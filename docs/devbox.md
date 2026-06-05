---
title: Devbox
---

> Devbox is a convenience wrapper around the [local PHP setup]({{ site.baseurl }}{% link local-php-setup.md %}) — it runs the same built-in PHP webserver that the test suite verifies, but the wrapper itself is not separately tested.

```shell
devbox shell

# In the shell from now on
composer install

# Run lamb - Change `hackme` to something more secure, this is the `/login` password!
LAMB_LOGIN_PASSWORD=$(php make-password.php hackme) composer serve

```
