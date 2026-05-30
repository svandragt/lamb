# Workshop development environment

[Canonical Workshop](https://ubuntu.com/workshop/docs/) provides a sandboxed,
reproducible dev environment for lamb — an alternative to Devbox/DDev. The
definition here installs the same toolchain (PHP, Node, Ruby) and bootstraps
the project automatically.

This directory is developer tooling, not end-user documentation (which lives in
`docs/`).

## Prerequisites

The `workshop` CLI (snap, classic confinement):

```sh
sudo snap install workshop --classic
```

## Quick start

```sh
workshop launch dev      # build the environment (first time; pulls base + SDKs)
workshop run dev serve   # start the lamb dev server (foreground)
workshop run dev url      # print the host-reachable URL, e.g. http://10.82.218.6:8747
```

Open that URL in your host browser. Then:

```sh
workshop shell dev                    # interactive shell inside the env
workshop exec dev -- composer lint    # one-off command
workshop exec dev -- vendor/bin/codecept run Unit
workshop refresh dev                  # re-apply after editing the definition / hooks
workshop stop dev / workshop start dev
```

## What's in the definition

`dev.yaml`:

- `node` (store SDK) — Node.js 24, mirrors `devbox.json`'s `nodejs@24`.
- `claude-code` (store SDK) — agent tooling.
- `project-lamb` — an **in-project SDK** (`.workshop/lamb/`) that provides the
  PHP toolchain, because the Workshop Store has no PHP or Ruby SDK.
- `actions:` — `serve` (runs `composer serve`) and `url` (prints the URL).

`lamb/` (the in-project SDK):

| Hook | Runs as | Does |
|------|---------|------|
| `setup-base` | root, once on install | `apt-get install` PHP 8.3 + extensions, Composer, Ruby, openssl, pkg-config |
| `setup-project` | workshop user, every launch/refresh | `composer install` + `pnpm install` (mirrors `devbox.json` `init_hook`) |
| `check-health` | workshop user, after setup | verifies php/composer/node/ruby + required extensions, reports health |

## Accessing the server from the host

The environment is an LXD container on the LXD bridge, directly routable from
the host. The server binds `0.0.0.0:8747`, so:

- `workshop run dev url` prints `http://<container-ip>:8747` (reads the IP from
  inside the container, so it survives restarts that change the address).
- The IP changes when the workshop restarts — re-run `workshop run dev url`.
- Optional stable `localhost` forward (added outside the Workshop definition, so
  a recreate drops it):
  ```sh
  lxc config device add dev-<project-id> lamb proxy \
    listen=tcp:127.0.0.1:8747 connect=tcp:127.0.0.1:8747 --project workshop.sander
  ```

## Gotchas worth knowing

- **Shared project mount.** Workshop bind-mounts your host checkout at
  `/project`, so `composer install` / `pnpm install` write to the *same*
  `vendor/` and `node_modules/` that Devbox uses. They are not isolated; the
  trees are built for slightly different PHP/Node versions (8.2 vs 8.3) but are
  compatible in practice.
- **`ext-pdo_mysql` is required** even though lamb uses SQLite — RedBeanPHP
  references a MySQL PDO constant at load time and fatals without it. It's
  declared in `composer.json`; `setup-base` installs `php8.3-mysql`.
- **`workshopctl set-health`** statuses are lowercase (`okay` / `waiting` /
  `error` / `unknown`).

## Related

- End-user local setup: `docs/devbox.md`, `docs/local-php-setup.md`, `docs/ddev.md`
- Contributor guide: `CONTRIBUTING`
- Workshop docs: <https://ubuntu.com/workshop/docs/>
