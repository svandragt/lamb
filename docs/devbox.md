---
title: Devbox
---

```shell
devbox shell

# In the shell from now on
composer install

# Run lamb - Change `hackme` to something more secure, this is the `/login` password!
LAMB_LOGIN_PASSWORD=$(php make-password.php hackme) composer serve

```
