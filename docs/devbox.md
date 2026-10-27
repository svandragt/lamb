---
title: Devbox
parent: Installation & hosting
---

# Devbox

> **Well-travelled path.** Devbox is the maintainer's daily development environment. It wraps the [local PHP setup]({{ site.baseurl }}{% link local-php-setup.md %}), running the same built-in PHP webserver that the test suite verifies on every change.

Devbox doesn't package [viv](https://github.com/svandragt/vivace), the dependency installer, so install it on the host first and it carries into the shell:

```shell
cargo binstall --git https://github.com/svandragt/vivace vivace
# or download a release binary from https://github.com/svandragt/vivace/releases
```

```shell
devbox shell

# In the shell from now on
viv install

# Set your /login password - change `hackme` to something more secure.
php make-password.php hackme

# Run lamb - the dev server reads .env automatically.
viv run serve

```
