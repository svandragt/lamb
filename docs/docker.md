# Docker

## Setup

```shell
$ cd .docker

# Bring up the application
$ touch secrets.env; docker compose up --build -d

# To enable the admin role, generate a password hash. Replace hackme with your own password
$ echo "LAMB_LOGIN_PASSWORD=$(sudo docker compose exec lamb-app bash -c 'php make_password_hash.php hackme')" > secrets.env

# Apple the secret
$ docker compose restart

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
