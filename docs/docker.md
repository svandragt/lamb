---
title: Docker
---

# Docker

The only requirement in this case is a working Docker setup!

## Prebuilt image (recommended)

Every release publishes a ready-to-run image to GitHub Container Registry. It bundles PHP, the webserver (FrankenPHP/Caddy), and all dependencies in a single container:

```shell
# Generate a password hash on any machine with PHP, or inside a throwaway container:
$ docker run --rm php:8.2-cli php -r "echo base64_encode(password_hash('hackme', PASSWORD_DEFAULT));"

# Run Lamb
$ docker run -d --name lamb -p 80:80 \
    -e LAMB_LOGIN_PASSWORD='<the-hash>' \
    -v lamb-data:/app/data \
    -v lamb-assets:/app/src/assets \
    ghcr.io/svandragt/lamb:latest
```

Your site is now ready at http://localhost

The SQLite database lives in the `lamb-data` volume and uploads in `lamb-assets`, so they survive container upgrades. To upgrade, see [Upgrading]({{ site.baseurl }}{% link upgrading.md %}).

Specific versions are available as tags, e.g. `ghcr.io/svandragt/lamb:0.9.0`.

## Build from source

This is the development setup: the project directory is live-mounted into the containers, so code changes apply immediately.

```shell
$ cd .docker

# Bring up the application
$ docker compose up --build -d

# To enable the admin role, generate a password hash. Replace hackme with your own password
$ echo "LAMB_LOGIN_PASSWORD=$(docker exec -it lamb-app bash -c 'php make-password.php hackme')"
```

Your site is now ready at http://localhost

Uploaded images are stored under `src/assets/` inside the app container.

Errors can be inspected with `docker compose logs -f app`.

### Update

To refresh Docker Compose containers, you can follow these steps:

Build new images (if necessary): Pull the latest changes to the application code or Dockerfile, and rebuild
the Docker images using the docker compose build command.

```bash
$ git pull
$ docker compose up --build -d
```

The `-d` flag is used to start the containers in the background (detached mode).

## Running tests

Codeception runs inside the `lamb-app` container of the build-from-source setup. The whole project is mounted
at `/srv/app`, so the test suites and configuration are available there.

The test runner reads `.env` for its parameters, so make sure you have generated
one with the `make-password.php` step from [Build from source](#build-from-source) before running the
tests.

```shell
# Unit tests (fast, no server required)
$ docker exec -it lamb-app vendor/bin/codecept run Unit

# Full suite, including acceptance tests against the running stack
$ docker exec -it lamb-app vendor/bin/codecept run
```

Acceptance tests use `SITE_URL`, which `make-password.php` automatically sets to
`http://localhost` (FrankenPHP inside the same container) when run inside the
container, so they exercise the live Docker stack.

## Related

- [Installation options]({{ site.baseurl }}{% link index.md %})
- [FrankenPHP]({{ site.baseurl }}{% link frankenphp.md %})
- [Upgrading]({{ site.baseurl }}{% link upgrading.md %})
