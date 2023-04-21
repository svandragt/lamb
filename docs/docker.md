# Docker

## Setup

```shell
$ cd .docker

# Bring up the application
$ touch secrets.env; docker compose up --build -d

# To enable the admin role, generate a password hash. Replace hackme with your own password
$ echo "LAMB_LOGIN_PASSWORD=$(sudo docker compose exec php bash -c 'php make_password_hash.php hackme')" > secrets.env

# Apple the secret
$ docker compose restart

```

Your site is now ready at https://localhost

Known Issue: Currently the PHP errors are shown on screen.

## Update Lamb

To refresh Docker Compose containers, you can follow these steps:

Stop the running containers: Use the docker compose stop command to stop the running containers.

```bash
$ docker compose stop
```

Remove the stopped containers: Use the docker compose rm command to remove the stopped containers.

```bash
$ docker compose rm
```

Build new images (if necessary): If you have made changes to the application code or Dockerfile, you will need to
rebuild the Docker images using the docker compose build command.

```bash
$ git pull
$ docker compose build
```

Start the containers: Use the docker compose up command to start the containers again.

```bash
$ docker compose up -d
```

The `-d` flag is used to start the containers in the background (detached mode).
