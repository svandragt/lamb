---
title: Backup and restore
nav_order: 30
---

# Backup and restore

Lamb keeps your whole blog in two places on disk. Back up both and you can rebuild the site anywhere.

| What | Where | Holds |
|------|-------|-------|
| Database | `data/lamb.db` | Posts, drafts, trash, site configuration, received webmentions, stored redirects, feed status |
| Uploads | `src/assets/` | Every image and video you added to a post |
| Password | `.env` | The hashed login password, and `SITE_URL` |

Everything else — the code, the themes, the dependencies — you can reinstall.

## An export is not a full backup

The [export archive]({{ site.baseurl }}{% link export.md %}) is a portable copy of your *writing*: posts, their media, and a manifest. It's the right thing for offsite storage and for moving to another tool, because you can read it in any text editor years from now.

It doesn't contain your site configuration, your received webmentions, or the redirects Lamb created when you changed a slug. Those live only in the database. Treat an export as a content archive, and the database file as the actual backup.

## Back up a Git or tarball install

Stop nothing and copy two paths:

```bash
sqlite3 data/lamb.db ".backup '/backups/lamb-$(date +%F).db'"
tar -czf /backups/lamb-assets-$(date +%F).tar.gz src/assets/
cp .env /backups/lamb-env-$(date +%F)
```

Use `sqlite3 .backup` rather than `cp` on the database. A plain copy of a live SQLite file can catch a write in progress and produce an archive that won't open. If you don't have the `sqlite3` CLI, `VACUUM INTO` does the same job from PHP:

```bash
php -r "(new PDO('sqlite:data/lamb.db'))->exec(\"VACUUM INTO '/backups/lamb.db'\");"
```

To back up nightly, add it to cron:

```
30 3 * * * cd /path/to/lamb && sqlite3 data/lamb.db ".backup '/backups/lamb-$(date +\%F).db'"
```

Cron treats `%` as a line separator, so escape it as `\%` inside a crontab.

## Back up a Docker install

The database and uploads live in named volumes, so back them up from a throwaway container that mounts both:

```bash
docker run --rm \
  -v lamb-data:/data -v lamb-assets:/assets \
  -v "$PWD":/backup alpine \
  tar -czf /backup/lamb-backup-$(date +%F).tar.gz /data /assets
```

Your `LAMB_LOGIN_PASSWORD` isn't in a volume — it's the environment variable you passed to `docker run`. Keep a copy of it wherever you keep the rest of your secrets.

## Restore

Restoring is the same operation backwards. Put the files back, then start the site.

For a Git or tarball install:

```bash
cp /backups/lamb-2026-08-01.db data/lamb.db
tar -xzf /backups/lamb-assets-2026-08-01.tar.gz
cp /backups/lamb-env-2026-08-01 .env
```

For Docker, restore into the volumes before starting the container:

```bash
docker run --rm \
  -v lamb-data:/data -v lamb-assets:/assets \
  -v "$PWD":/backup alpine \
  tar -xzf /backup/lamb-backup-2026-08-01.tar.gz -C /
```

Make sure the restored paths are still writable by the user your web server runs as. See [NGINX]({{ site.baseurl }}{% link nginx.md %}) or [FrankenPHP]({{ site.baseurl }}{% link frankenphp.md %}).

## Check that a backup actually works

An untested backup isn't a backup. Restore one into a scratch copy and open it:

```bash
mkdir /tmp/lamb-check && cd /tmp/lamb-check
git clone --branch release https://github.com/svandragt/lamb.git . && composer install --no-dev
mkdir -p data && cp /backups/lamb-2026-08-01.db data/lamb.db
php make-password.php <a-throwaway-password>
composer serve
```

Open the site and confirm your posts, drafts, and images are there. Do this once when you set backups up, and again after any change to how you host Lamb.

## What upgrading does and doesn't touch

Upgrading never writes to `data/`, `src/assets/`, or `.env`. The `bin/upgrade` script resets tracked files only, the tarball doesn't contain your data, and Docker keeps it in volumes. See [Upgrading]({{ site.baseurl }}{% link upgrading.md %}).

Take a backup before upgrading anyway. The upgrade won't delete your data, but a schema change can't be undone by rolling the code back.

## Related

* [Export]({{ site.baseurl }}{% link export.md %}): A portable Markdown archive of your posts and media, for offsite copies and for moving to another tool.
* [Upgrading]({{ site.baseurl }}{% link upgrading.md %}): What each install method preserves.
* [Docker]({{ site.baseurl }}{% link docker.md %}): Where the named volumes live.
* [Trash]({{ site.baseurl }}{% link trash.md %}): Recovering a single deleted post, which doesn't need a backup.
* [Troubleshooting]({{ site.baseurl }}{% link troubleshooting.md %}): Common problems and where they're documented.
