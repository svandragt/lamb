---
title: DDEV
---

# DDEV

> DDEV is a convenience wrapper that runs Lamb under NGINX and PHP-FPM in Docker. The underlying server setups are release-verified. See [NGINX]({{ site.baseurl }}{% link nginx.md %}) and [Docker]({{ site.baseurl }}{% link docker.md %}). The DDEV wrapper itself isn't tested separately.

## Set up DDEV

1. [Install DDEV](https://ddev.com/get-started/), if you haven't already.
2. Start the environment. DDEV installs the prerequisites for you:

   ```shell
   ddev start

   # Set the /login password. Change `hackme` to something more secure.
   ddev php make-password.php hackme

   # Reload the environment.
   ddev restart
   ```

## Daily workflow

- Run `ddev start`. The output tells you where to open the project.
- Run `ddev stop` when you finish.
