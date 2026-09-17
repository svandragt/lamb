---
title: Upgrading
parent: Installation & hosting
---

# Upgrading

How you upgrade depends on how you installed Lamb. There is [more information about branches](https://github.com/svandragt/lamb/blob/main/BRANCHES) to be on — `release` is the stable branch.

## Git install

### Prerequisite: viv

`bin/upgrade` installs dependencies with [viv](https://github.com/svandragt/vivace), a Composer-compatible dependency installer that reads your existing `composer.json`/`composer.lock`. Install it before your first upgrade:

```
cargo binstall --git https://github.com/svandragt/vivace vivace
```

Or download a release binary from the [releases page](https://github.com/svandragt/vivace/releases) (viv's releases are currently tagged as prereleases, so "latest release" links don't resolve — use the releases page). `bin/upgrade` checks for viv on `PATH` and stops with an error, without touching your checkout, if it isn't installed.

Run the bundled upgrade script:

```
bin/upgrade
```

It resets your checkout to the latest version of the branch you are on, installs production dependencies, and — when `SITE_URL` is set in `.env` — checks that the site still responds. If the health check fails, it prints the exact command to roll back to the previous version.

Note: the reset discards any local changes to tracked files. Your database (`data/`), uploads (`src/assets/`), and `.env` are not tracked, so they are unaffected.

To upgrade automatically every night, add it to cron:

```
15 3 * * * /path/to/lamb/bin/upgrade
```

Cron will email you the output if the health check fails (when your system is set up to deliver mail).

### The deploying user must own the checkout

Run the script as a user that owns the checkout. If the webserver user owns it and your cron job runs as someone else, git refuses to touch the repository at all:

```
fatal: detected dubious ownership in repository at '/var/www/example.com/html'
```

The script stops there and tells you how to fix it. You have two options. Align ownership:

```
sudo chown -R $(id -un) /var/www/example.com/html
```

Or, if the webserver must keep owning the files, mark the checkout as trusted for the deploying user:

```
git config --global --add safe.directory /var/www/example.com/html
```

Run that command as the user the cron job runs as, not as root — `--global` writes to that user's own git config.

Watch for this after moving to a new server, where ownership often differs from the old one. Nothing else reports it: the upgrade simply stops running, and the site keeps serving the version it already has.

## Data migrations

A few one-off changes to stored data — the kind that ship once and never run again — live behind `bin/lamb migrate`:

```bash
# See what would run, without changing anything
bin/lamb migrate --dry-run

# Run it
bin/lamb migrate
```

It's safe to run at any time: each migration checks whether it still has anything to do and does nothing on a database that's already current.

`bin/upgrade` runs it automatically after installing dependencies and before the health check, so a git install with cron scheduled needs no extra step. Any other upgrade path — a tarball extract, a Docker rebuild, or a git install that skips `bin/upgrade` — needs it run by hand, once, right after switching to the new code.

**Upgrade floor:** these migrations shipped in 0.14.0. Restoring a `lamb.db` backup older than 0.14.0, or upgrading a checkout that has been stuck before it, needs a stop at 0.14.0 first — run `bin/lamb migrate` there — before continuing on to a later version.

## Tarball install

Download the latest `lamb-<version>.tar.gz` from the [releases page](https://github.com/svandragt/lamb/releases) and extract it over your existing installation:

```
tar -xzf lamb-<version>.tar.gz --strip-components=1 -C /path/to/lamb
```

Your database (`data/`), uploads (`src/assets/`), and `.env` are preserved — the tarball does not contain them.

## Docker install

Rebuild the image from an updated checkout and recreate the container:

```
git pull
docker build -f .docker/Dockerfile.release -t lamb .
docker stop lamb && docker rm lamb
docker run -d --name lamb -p 80:80 \
  -e LAMB_LOGIN_PASSWORD='<your-hash>' \
  -v lamb-data:/app/data -v lamb-assets:/app/src/assets \
  lamb
```

The database and uploads live in the named volumes and survive the recreate.

## Related

- [Installation options]({{ site.baseurl }}{% link index.md %})
- [Docker]({{ site.baseurl }}{% link docker.md %})
- [Cron Scheduled Tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %})
- [Upgrading stored posts]({{ site.baseurl }}{% link upgrade-posts.md %}): Bring every post's stored data up to date in one pass after upgrading Lamb.

`bin/lamb migrate` and `bin/lamb upgrade-posts` are different: `migrate` runs a fixed, one-off set of data changes shipped in a specific version; `upgrade-posts` re-runs the post upgrade every render already does, for every post, on demand.
