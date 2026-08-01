---
title: Docker
nav_order: 2
---

# Docker

> **Well-travelled path.** The automated acceptance suite verifies the release image before every release (the `release-verify` workflow), so this is a supported, regularly tested way to run Lamb.

All you need is a working Docker setup.

## Prebuilt image (recommended)

Every release publishes a ready-to-run image to GitHub Container Registry. It bundles PHP, the web server (FrankenPHP and Caddy), and all dependencies in a single container:

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

Your site is now ready at http://localhost.

The SQLite database lives in the `lamb-data` volume and uploads live in `lamb-assets`, so both survive container upgrades. To upgrade, see [Upgrading]({{ site.baseurl }}{% link upgrading.md %}).

Specific versions are available as tags, such as `ghcr.io/svandragt/lamb:0.9.0`.

## Build from source

This is the development setup. Docker live-mounts the project directory into the containers, so your code changes apply immediately.

```shell
$ cd .docker

# Bring up the application
$ docker compose up --build -d

# To enable the admin role, generate a password hash. Replace hackme with your own password.
$ echo "LAMB_LOGIN_PASSWORD=$(docker exec -it lamb-app bash -c 'php make-password.php hackme')"
```

Your site is now ready at http://localhost.

Lamb stores uploaded images and video under `src/assets/` inside the app container.

Both images accept uploads up to 100&nbsp;MB (`upload_max_filesize = 100M`, `post_max_size = 100M`). See [Media]({{ site.baseurl }}{% link media.md %}). On a memory-constrained host, `LAMB_MAX_UPLOAD_PIXELS` lets you lower how large an image WebP conversion attempts to decode. See [Pixel cap and memory]({{ site.baseurl }}{% link media.md %}#pixel-cap-and-memory).

To inspect errors, run `docker compose logs -f app`.

### Update

To refresh Docker Compose containers, pull the latest changes to the application code or Dockerfile, then rebuild the images:

```bash
$ git pull
$ docker compose up --build -d
```

The `-d` flag starts the containers in the background, in detached mode.

## Run tests

Codeception runs inside the `lamb-app` container of the build-from-source setup. Docker mounts the whole project
at `/srv/app`, so the test suites and configuration are available there.

The test runner reads `.env` for its parameters, so generate one with the `make-password.php` step from
[Build from source](#build-from-source) before you run the tests. The acceptance suite also needs the
cleartext `LAMB_TEST_PASSWORD`, which Lamb omits from `.env` by default. When you intend to run acceptance
tests, generate the file with `LAMB_WRITE_TEST_PASSWORD=1` set, for example
`LAMB_WRITE_TEST_PASSWORD=1 php make-password.php hackme`.

```shell
# Unit tests (fast, no server required)
$ docker exec -it lamb-app vendor/bin/codecept run Unit

# Full suite, including acceptance tests against the running stack
$ docker exec -it lamb-app vendor/bin/codecept run
```

Acceptance tests use `SITE_URL`. When you run `make-password.php` inside the container, it sets `SITE_URL` to
`http://localhost`, which is FrankenPHP in the same container, so the tests exercise the live Docker stack.

## Related

- [Installation options]({{ site.baseurl }}{% link index.md %})
- [FrankenPHP]({{ site.baseurl }}{% link frankenphp.md %})
- [Upgrading]({{ site.baseurl }}{% link upgrading.md %})
