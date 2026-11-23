---
title: DDev
parent: Installation & hosting
---

# DDev

> DDev is a convenience wrapper that runs Lamb under nginx + php-fpm in Docker. The underlying server setups are release-verified (see [Nginx]({{ site.baseurl }}{% link nginx.md %}) and [Docker]({{ site.baseurl }}{% link docker.md %})), but the DDev wrapper itself is not separately tested.

## Setup

* [Install ddev](https://ddev.com/get-started/), if you haven't.

Start the project, then set the `/login` password. DDev runs Lamb under nginx and php-fpm, which do not read the `.env` file that `make-password.php` writes. Give the hash to the web container as an environment variable instead.

```shell
ddev start

# Prints the password hash. Change `hackme` to something more secure, this is the `/login` password!
ddev php make-password.php hackme

# Store the printed hash for the web container, then reload the environment.
ddev dotenv set .ddev/.env.web --lamb-login-password='<hash from the previous command>'
ddev restart
```

DDev keeps the hash in `.ddev/.env.web`, which is not part of the repository. Do not commit it.

[Login security]({{ site.baseurl }}{% link login-security.md %}#setting-the-password) explains the password hash.

## Workflow

- Run `ddev start`. The output will tell you where you can open the project.
- Run `ddev stop` when finished.
