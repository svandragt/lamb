---
title: Docker
parent: Installation & hosting
---

# Docker

> **Well-travelled path.** The release image is verified by the automated acceptance suite before every release (the `release-verify` workflow), so this is a supported, regularly-tested way to run Lamb.

You need a working Docker setup and a checkout of the repository. The development setup also needs Docker Compose 2.24 or later.

## Run the release image

`.docker/Dockerfile.release` bundles PHP, the webserver (FrankenPHP/Caddy), and all dependencies into a single production container. Build it from a checkout, then run it. The container reads the hash from `LAMB_LOGIN_PASSWORD`; [Login security]({{ site.baseurl }}{% link login-security.md %}#setting-the-password) explains the hash:

```shell
# Clone the repository
$ git clone --branch release https://github.com/svandragt/lamb && cd lamb

# Make the password hash with make-password.php (see Login security). With no PHP on
# the host, run the script in a throwaway container; -w /tmp keeps the .env it writes there:
$ docker run --rm -v "$PWD/make-password.php:/make-password.php:ro" -w /tmp php:8.5-cli php /make-password.php '<your-password>'

# Build the image (run from the repository root)
$ docker build -f .docker/Dockerfile.release -t lamb .

# Run Lamb
$ docker run -d --name lamb -p 80:80 \
    -e LAMB_LOGIN_PASSWORD='<the-hash>' \
    -v lamb-data:/app/data \
    -v lamb-assets:/app/src/assets \
    lamb
```

Your site is now ready at http://localhost

The SQLite database lives in the `lamb-data` volume and uploads in `lamb-assets`, so they survive container upgrades. To upgrade, see [Upgrading]({{ site.baseurl }}{% link upgrading.md %}).

> **Prebuilt images are discontinued.** Lamb stopped publishing `ghcr.io/svandragt/lamb` after 0.14.0. Older tags still pull but get no further updates — build from the current source instead.

### Run commands in the container

The image includes `bin/lamb`. Run it as `www-data`, the user that owns the database. `bin/lamb` refuses to open a database owned by another user, and `docker exec` runs as root unless you pass `-u`:

```shell
$ docker exec -u www-data lamb php bin/lamb migrate
```

### Environment variables

Pass these with `-e` on `docker run`. Each links to the page that explains it.

- `LAMB_LOGIN_PASSWORD`: the login hash. To change the password, recreate the container with the new hash. See [Login security]({{ site.baseurl }}{% link login-security.md %}#setting-the-password).
- `LAMB_SITE_URL`: the canonical address of your site, which overrides the saved setting. See [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}#site-url).
- `LAMB_DATA_DIR`: where the database lives. It defaults to `/app/data` in the image. If you set another path, mount your volume there, and the entrypoint creates the directory and gives `www-data` ownership of it. See [Writable directories]({{ site.baseurl }}{% link index.md %}#writable-directories).
- `LAMB_MAX_UPLOAD_PIXELS`: the largest image WebP conversion will decode. See [Pixel cap and memory]({{ site.baseurl }}{% link media.md %}#pixel-cap-and-memory).
- `LAMB_MICROPUB_DEBUG`: logs Micropub requests. See [Micropub troubleshooting]({{ site.baseurl }}{% link micropub.md %}#troubleshooting).

### File ownership

The server runs as `www-data`. Each time the container starts, the entrypoint changes the owner of the data directory (`/app/data`, or `LAMB_DATA_DIR`) and of `/app/src/assets` to `www-data`, including every file inside them. Named volumes and bind mounts therefore both work, with no `chown` on the host. A bind mount's files change owner on the host as well, so use named volumes if they must keep their owner.

### HTTPS

The image serves plain HTTP on port 80 and does not request certificates. Choose one of these:

- **Put a TLS proxy in front.** Forward HTTPS from your proxy to the container's port 80. Set `LAMB_SITE_URL=https://example.com`: Lamb sees plain HTTP from the proxy, so the `https://` site URL is how it knows to mark its cookies `Secure` and to build absolute links with `https://`. [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}#site-url) explains how.
- **Let Caddy in the image handle it.** Copy `.docker/Caddyfile.release`, replace `:80` with your domain, and mount the copy over `/etc/frankenphp/Caddyfile`. Publish ports 80 and 443, and keep `/data` and `/config` in volumes so a new container reuses the certificates. [FrankenPHP]({{ site.baseurl }}{% link frankenphp.md %}) explains the automatic certificates.

The image's health check requests `http://localhost/`. With your domain in the Caddyfile, that request gets a redirect, which `curl -f` counts as success, so the container reports healthy whether or not the site works. The recipe replaces the check with one that requests your domain over HTTPS. Replace `example.com` with your domain in both places.

```shell
$ sed 's/^:80 {/example.com {/' .docker/Caddyfile.release > Caddyfile
$ docker run -d --name lamb -p 80:80 -p 443:443 -p 443:443/udp \
    -e LAMB_LOGIN_PASSWORD='<the-hash>' \
    -v lamb-data:/app/data \
    -v lamb-assets:/app/src/assets \
    -v lamb-caddy-data:/data \
    -v lamb-caddy-config:/config \
    -v "$PWD/Caddyfile:/etc/frankenphp/Caddyfile:ro" \
    --health-cmd 'curl -fsS -o /dev/null --resolve example.com:443:127.0.0.1 https://example.com/' \
    lamb
```

### Scheduled tasks

Lamb runs its background tasks when something requests `/_cron`; [Cron Scheduled Tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}) lists them. The image includes `curl`, so schedule a request from the host's crontab, for a user who can run `docker`:

```
*/30 * * * * docker exec lamb curl -fsS http://localhost/_cron > /dev/null
```

With your own Caddyfile for HTTPS, `http://localhost` redirects to HTTPS and no longer reaches the site. Request your public address from the host instead: `curl -fsS https://example.com/_cron`.

## Build from source

This is the development setup: the project directory is live-mounted into the containers, so code changes apply immediately.

```shell
$ cd .docker

# Bring up the application
$ docker compose up --build -d

# To enable the admin role, write a password hash to .env in the project root.
# Replace hackme with your own password. -u runs the command as you, so .env is yours.
$ docker exec -u "$(id -u):$(id -g)" lamb-app php make-password.php hackme

# Compose reads .env only when it creates the container, so recreate the app container
$ docker compose up -d --force-recreate --no-deps app
```

Your site is now ready at http://localhost

The app container reads `.env` from the project root. The container starts without one, but nobody can log in until you have run `make-password.php`. Running as your own user ID keeps `.env` readable by you on the host, also when root owns the checkout, and does not depend on the container having finished starting. To change the password later, run the same command with `--force` and recreate the container again.

Uploaded images and video are stored under `src/assets/` inside the app container.

Both images accept uploads up to 100&nbsp;MB (`upload_max_filesize = 100M`, `post_max_size = 100M`) — see [Media]({{ site.baseurl }}{% link media.md %}). On a memory-constrained host, `LAMB_MAX_UPLOAD_PIXELS` lets you lower how large an image WebP conversion will attempt to decode — see [Pixel cap and memory]({{ site.baseurl }}{% link media.md %}#pixel-cap-and-memory).

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

The test runner reads `.env` for its parameters, and the [Build from source](#build-from-source) steps already wrote it. The acceptance suite also needs your cleartext password to log in. Pass it as `LAMB_TEST_PASSWORD` on the command instead of writing it to `.env`, because Compose copies `.env` into the container's environment each time you recreate it.

The container's environment holds the login hash from `.env`, but three unit tests expect no login password, so the commands also clear it with `-e LAMB_LOGIN_PASSWORD=`. Replace `hackme` with the password you gave `make-password.php`:

```shell
# Unit tests (fast, no server required)
$ docker exec -u "$(id -u):$(id -g)" -e LAMB_LOGIN_PASSWORD= lamb-app vendor/bin/codecept run Unit

# Full suite, including acceptance tests
$ docker exec -u "$(id -u):$(id -g)" -e LAMB_LOGIN_PASSWORD= -e LAMB_TEST_PASSWORD=hackme lamb-app vendor/bin/codecept run
```

The acceptance suite starts its own PHP built-in server inside the container, on
`LAMB_TEST_PORT`, and `SITE_URL` in `.env` points at it. It does not test the
FrankenPHP server that serves your site on port 80.

## Related

- [Installation options]({{ site.baseurl }}{% link index.md %})
- [FrankenPHP]({{ site.baseurl }}{% link frankenphp.md %})
- [Upgrading]({{ site.baseurl }}{% link upgrading.md %})
