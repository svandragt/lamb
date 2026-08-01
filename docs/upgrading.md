---
title: Upgrading
---

# Upgrading

How you upgrade depends on how you installed Lamb. For [more information about the branches](https://github.com/svandragt/lamb/blob/main/BRANCHES) you can track, see the `BRANCHES` file. `release` is the stable branch.

## Git install

Run the bundled upgrade script:

```
bin/upgrade
```

The script resets your checkout to the latest version of the branch you're on and installs production dependencies. When `SITE_URL` is set in `.env`, it also checks that the site still responds. If the health check fails, the script prints the exact command to roll back to the previous version.

The reset discards any local changes to tracked files. It doesn't affect your database (`data/`), uploads (`src/assets/`), or `.env`, because Git doesn't track them.

To upgrade automatically every night, add the script to cron:

```
15 3 * * * /path/to/lamb/bin/upgrade
```

Cron emails you the output if the health check fails, provided your system delivers mail.

## Tarball install

Download the latest `lamb-<version>.tar.gz` from the [releases page](https://github.com/svandragt/lamb/releases) and extract it over your existing installation:

```
tar -xzf lamb-<version>.tar.gz --strip-components=1 -C /path/to/lamb
```

The tarball doesn't contain your database (`data/`), uploads (`src/assets/`), or `.env`, so the upgrade preserves them.

## Docker install

Pull the new image and recreate the container:

```
docker pull ghcr.io/svandragt/lamb:latest
docker stop lamb && docker rm lamb
docker run -d --name lamb -p 80:80 \
  -e LAMB_LOGIN_PASSWORD='<your-hash>' \
  -v lamb-data:/app/data -v lamb-assets:/app/src/assets \
  ghcr.io/svandragt/lamb:latest
```

The database and uploads live in the named volumes and survive the recreate.

## Related

- [Installation options]({{ site.baseurl }}{% link index.md %})
- [Docker]({{ site.baseurl }}{% link docker.md %})
- [Cron scheduled tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %})
