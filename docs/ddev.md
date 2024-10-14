# DDev

## Setup

0. [Install ddev](https://ddev.com/get-started/), if you haven't.
1. Note the output of `ddev php make_password_hash.php hackme` (where `hackme` is a unique password)
2. Create the file `.ddev/.env` with the content `LAMB_LOGIN_PASSWORD='hash output'` (where `hash output` is the output
   of the previous step.

## Worfklow

- Run `ddev start`. The output will tell you where you can open the project.
- Runn `ddev stop` when finished.

## Known Issues

Note that the URL is hardcoded in the Codeception configuration so these tests will not
run. [Track this GitHub Issue](https://github.com/svandragt/lamb/issues/71)
