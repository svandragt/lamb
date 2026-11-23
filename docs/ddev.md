---
title: DDEV
parent: Installation & hosting
---

# DDEV

> DDEV is a convenience wrapper that runs Lamb under nginx + php-fpm in Docker. The underlying server setups are release-verified (see [Nginx]({{ site.baseurl }}{% link nginx.md %}) and [Docker]({{ site.baseurl }}{% link docker.md %})), but the DDEV wrapper itself is not separately tested.

## Setup

* [Install DDEV](https://ddev.com/get-started/) 1.24.0 or later, if you haven't. Earlier versions have no `ddev dotenv` command.

Start the project, then set the `/login` password. DDEV runs Lamb under nginx and php-fpm, which do not read the `.env` file that `make-password.php` may write, so ignore its "set it yourself" message. Give the hash to the web container as an environment variable instead.

```shell
ddev start

# Change `hackme` to something more secure, this is the `/login` password!
ddev dotenv set .ddev/.env.web --lamb-login-password="$(ddev php make-password.php hackme)"

# reload the environment
ddev restart
```

DDEV keeps the hash in `.ddev/.env.web`, which is not part of the repository. Do not commit it.

[Login security]({{ site.baseurl }}{% link login-security.md %}#setting-the-password) explains the password hash.

## Workflow

- Run `ddev start`. The output will tell you where you can open the project.
- Run `ddev stop` when finished.
