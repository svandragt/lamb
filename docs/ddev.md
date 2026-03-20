---
title: DDev
---

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

## Worfklow

- Run `ddev start`. The output will tell you where you can open the project.
- Runn `ddev stop` when finished.
