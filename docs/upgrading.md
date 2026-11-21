---
title: Upgrading
parent: Installation & hosting
---

# Upgrading

How you upgrade depends on how you installed Lamb. There is [more information about branches](https://github.com/svandragt/lamb/blob/main/BRANCHES) to be on — `release` is the stable branch.

## Git install

### Prerequisite: Composer or viv

`bin/upgrade` installs dependencies with [viv](https://github.com/svandragt/vivace) when it is on your `PATH`, and with Composer otherwise. If you already use Composer, you have nothing more to install.

viv is optional and faster. It is a Composer-compatible installer that reads your existing `composer.json`/`composer.lock`. Use version 0.22.0 or later: older versions write vendor files that a web server running as another user can't read, so `bin/upgrade` uses Composer instead. [Local PHP setup]({{ site.baseurl }}{% link local-php-setup.md %}) shows how to install viv. `bin/upgrade` prints which installer it uses. It stops with an error, without touching your checkout, if it finds neither.

Run the bundled upgrade script:

```
bin/upgrade
```

It resets your checkout to the latest version of the branch you are on, installs production dependencies, and — when `SITE_URL` is set in `.env` — checks that the site still responds, trying up to three times a few seconds apart. If the health check still fails, it prints the exact command to roll back to the previous version.

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

One of these steps lists the settings 0.17 removed that your stored settings still hold, without changing them (see [Upgrading to 0.17](#upgrading-to-017)). Another migration adds `draft: true` to the front matter of every [feed draft]({{ site.baseurl }}{% link drafts.md %}) that lacks it. Without the line, editing a feed draft in Lamb publishes it.

`bin/lamb migrate` also brings every stored post up to date. [`bin/lamb upgrade-posts`]({{ site.baseurl }}{% link upgrade-posts.md %}) runs only that part, for when that is all you need. Viewing a post no longer upgrades it as a side effect: a listing, feed or search page always shows a post's content correctly, but a post whose stored `version` is behind keeps that old version until `bin/lamb migrate` (or `upgrade-posts`) runs.

Lamb runs these migrations for you after every upgrade, whichever way you upgrade. The first [`/_cron` request]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}) after the code changes runs them, before the other scheduled tasks and even when those are not due yet. `/_cron` runs as the user that owns the database, so it works where `bin/lamb migrate` cannot, as when `bin/upgrade` runs as a different user. The reply to that request lists each step.

`bin/upgrade` also runs `bin/lamb migrate` itself after installing dependencies and before the health check. A manual run counts too: Lamb notes which version of its code it last migrated your data for, so `/_cron` does not repeat it. If a migration fails, Lamb writes the error to the PHP error log and the next `/_cron` request tries again.

Run `bin/lamb migrate` yourself when you do not schedule `/_cron`, or to see the migrations' output straight away. Run it as the user that owns the database. After you restore an old `lamb.db` backup, the next `/_cron` request brings it current, or run `bin/lamb migrate` to do it at once.

### What `bin/upgrade` does with the result

`bin/lamb migrate` reports one of three outcomes, and `bin/upgrade` reacts differently to each:

- **Ran successfully.** The upgrade continues straight to the health check, as normal.
- **Ran nothing.** `bin/lamb migrate` exits with code 3 when `data/lamb.db` is owned by a different user than the one running `bin/upgrade` — the [ownership guard](#the-deploying-user-must-own-the-checkout) that protects against leaving unwritable WAL sidecar files behind — or when a `/_cron` run is in progress. A different owner is the *normal* shape of a cron deploy: the checkout and database owned by the webserver user, cron running as a human. The code and dependencies are fine, so `bin/upgrade` hands the migrations to your site. When `SITE_URL` is set in `.env`, it requests `/_cron` on your site, which runs the migrations as the database's owner, and prints one line saying so. If that request fails, `bin/upgrade` prints the rollback command and stops before the health check. Without `SITE_URL`, it prints one line saying that the next scheduled `/_cron` request runs the migrations, and finishes the upgrade.
- **Genuinely failed.** Something else went wrong running a migration. `bin/upgrade` prints a rollback command and stops before the health check, the same as a failed dependency install.

When `bin/lamb migrate` ran nothing, you have nothing to run by hand. If cron mails you the failed-request message, check that your site is up, then run `bin/upgrade` again or run the rollback command.

**Upgrade floor:** these migrations shipped in 0.14.0. Restoring a `lamb.db` backup older than 0.14.0, or upgrading a checkout that has been stuck before it, needs a stop at 0.14.0 first — run `bin/lamb migrate` there — before continuing on to a later version.

## Tarball install

Download the latest `lamb-<version>.tar.gz` from the [releases page](https://github.com/svandragt/lamb/releases) and extract it over your existing installation:

```
tar -xzf lamb-<version>.tar.gz --strip-components=1 -C /path/to/lamb
```

Your database (`data/`), uploads (`src/assets/`), and `.env` are preserved — the tarball does not contain them.

The next `/_cron` request runs the [data migrations](#data-migrations). To run them at once, run `bin/lamb migrate` as the user that owns `data/lamb.db`.

## Docker install

Rebuild the image from an updated checkout and recreate the container:

```
git pull
docker build -f .docker/Dockerfile.release -t lamb .
docker stop lamb && docker rm lamb
```

Then start the container again with the `docker run` command in [Docker]({{ site.baseurl }}{% link docker.md %}#run-the-release-image), using the same hash and the same volume names. The database and uploads live in the named volumes and survive the recreate.

Once the container is up, the next `/_cron` request runs the [data migrations](#data-migrations). To run them at once, run them inside the container as `www-data` so the database keeps its owner:

```
docker exec -u www-data lamb php bin/lamb migrate
```

## Upgrading to 0.16

* The settings page is now a form. Site title, site URL and author name must be filled in the first time you save it.
* Five settings are deprecated and stop working in 0.17: `posts_per_page`, `404_fallback`, `[preconnect]`, `micropub_debug` and `experimental_features`. Use `LAMB_MICROPUB_DEBUG` instead of `micropub_debug`, and `bin/lamb import <source> <path> --experimental` instead of `experimental_features`. See [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}#removed-settings).

## Upgrading to 0.17

* Lamb removed the five settings that 0.16 deprecated: `posts_per_page`, `404_fallback`, `[preconnect]`, `micropub_debug` and `experimental_features`. A leftover setting does nothing, and `bin/lamb migrate` lists each one you had set, with what replaces it, and does not change those settings. `bin/upgrade` runs `bin/lamb migrate`, so its output reports them. The reply to a `/_cron` request does not list them, because anyone can read it. When `bin/upgrade` cannot run the migration, run `bin/lamb migrate` as the user that owns the database to get the same report. Saving the settings page removes them. See [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}#removed-settings) for what replaces each one.
* A site with no author name in its settings no longer shows `Joe Sheeple`. Both feeds name the site title as the author (the host of your site URL when there is no title either), and the pages have no `author` meta tag. Open the settings page and fill in the author name.
* The `2024` theme is removed. A site set to `theme = 2024` renders with the `2026` theme, and saving the theme at `/settings` stores `2026`.
* If you upgrade by extracting a tarball over your installation, or by any other method that copies files over the old ones, the old `src/themes/2024` directory stays behind. Delete it after extracting. Lamb no longer uses it.
* If your site uses its own theme named `2024`, rename the theme directory and pick it again in the **Theme** dropdown at `/settings`. Lamb renders `2024` with `2026` even when a `src/themes/2024` directory exists.

## Related

- [Installation options]({{ site.baseurl }}{% link index.md %})
- [Docker]({{ site.baseurl }}{% link docker.md %})
- [Cron Scheduled Tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %})
- [Upgrading stored posts]({{ site.baseurl }}{% link upgrade-posts.md %}): Bring every post's stored data up to date in one pass after upgrading Lamb.
