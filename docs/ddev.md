---
title: DDev
---

# DDev

> DDev is a convenience wrapper that runs Lamb under nginx + php-fpm in Docker. The underlying server setups are release-verified (see [Nginx]({{ site.baseurl }}{% link nginx.md %}) and [Docker]({{ site.baseurl }}{% link docker.md %})), but the DDev wrapper itself is not separately tested.

## Setup

* [Install ddev](https://ddev.com/get-started/), if you haven't.

Make sure the tool's installed, then it will install prerequisites:

```shell
ddev start

# Run lamb - Change `hackme` to something more secure, this is the `/login` password!
ddev php make-password.php hackme

# reload the environment
ddev restart
```

## Workflow

- Run `ddev start`. The output will tell you where you can open the project.
- Run `ddev stop` when finished.
