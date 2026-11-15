---
title: Devbox
parent: Installation & hosting
---

# Devbox

> **Well-travelled path.** Devbox is the maintainer's daily development environment. It wraps the [local PHP setup]({{ site.baseurl }}{% link local-php-setup.md %}), running the same built-in PHP webserver that the test suite verifies on every change.

Devbox doesn't package viv, the dependency installer, so install it on the host first, as [Local PHP setup]({{ site.baseurl }}{% link local-php-setup.md %}) describes. It carries into the shell.

```shell
devbox shell

# In the shell from now on
viv install

# Set your /login password - change `hackme` to something more secure.
php make-password.php hackme

# Run lamb - the dev server reads .env automatically.
viv run serve
```

[Login security]({{ site.baseurl }}{% link login-security.md %}#setting-the-password) explains the password hash.
