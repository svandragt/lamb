# DDev

## Setup

0. [Install ddev](https://ddev.com/get-started/), if you haven't.
1. Update the `LAMB_LOGIN_PASSWORD` environment variable in `.ddev/config.yaml` to the output of
   `ddev php make_password_hash.php hackme` (but change `hackme` to a unique password)

## Worfklow

- Run `ddev start`. The output will tell you where you can open the project.
- Runn `ddev stop` when finished.

## Known Issues

Note that the URL is hardcoded in the Codeception configuration so these tests will not
run. [Track this GitHub Issue](https://github.com/svandragt/lamb/issues/71)
