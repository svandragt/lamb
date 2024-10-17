# Docker

The only requirement in this case is a working Docker setup!

## Setup

```shell
$ cd .docker

# Bring up the application
$ touch ../.ddev/.env; docker compose up --build -d

# To enable the admin role, generate a password hash. Replace hackme with your own password
$ echo "LAMB_LOGIN_PASSWORD=$(docker exec -it lamb-app bash -c 'php setup.php hackme')"

# Test the previous command. If output is "err(1)" please review your secrets file
$ test "$(wc -c < ../.ddev/.env)" -ne 21 && echo "ok($?)" || echo 'err($?)'

# Apple the secret
$ docker compose up --build -d

```

Your site is now ready at https://localhost

Errors can be inspected with `docker composer logs -f php`.

## Update Lamb

To refresh Docker Compose containers, you can follow these steps:

Build new images (if necessary): Pull the latest changes to the application code or Dockerfile, and rebuild
the Docker images using the docker compose build command.

```bash
$ git pull
$ docker compose up --build -d
```

The `-d` flag is used to start the containers in the background (detached mode).
