# Docker

## Running it

```shell
$ cd .docker
$ echo "LAMB_LOGIN_PASSWORD=$(php ../make_password_hash.php hackme)" > secrets.env
$ docker-compose up
```

Known Issue: Currently the PHP errors are shown on screen.
