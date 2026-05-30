# Releasing Lamb

Maintainer checklist for cutting a release. Lamb ships from the `release`
branch (see `BRANCHES`); `main` is the active development branch. Versions are
plain SemVer tags (`0.9.0`); pre-releases use a suffix (`0.9.0-rc1`). There is
no version string in the code — **the Git tag is the source of truth**.

## 1. Pre-flight (on `main`)

- [ ] All intended PRs are merged into `main`; nothing release-worthy is still open.
- [ ] Working tree is clean and `main` is up to date: `git checkout main && git pull`.
- [ ] Tests pass: `vendor/bin/codecept run` (Unit + Acceptance).
      Acceptance needs `.env` (`SITE_URL`, `LAMB_TEST_PASSWORD`); it starts its
      own server, so don't have another server on the test port.
- [ ] Static checks pass: `composer lint` && `composer analyse`.
- [ ] Docs are accurate for any user-facing change (`docs/`, `README.md`).

## 2. Choose the version

- [ ] Decide the new version from the change set (SemVer):
      patch = fixes only, minor = new features, major = breaking changes.
- [ ] Confirm it's unused: `git tag | sort -V | tail`.
- [ ] If cutting a pre-release first, use an `-rcN` suffix and mark it
      pre-release in step 6.

## 3. Generate end-user release notes

Notes are for **people running a Lamb blog**, not contributors. Start from the
commit list, then curate.

```sh
# Everything on main since the last release tag (use the previous final tag):
git log --format='- %s' <last-tag>..main
```

- [ ] **Keep** changes an end user would notice: new/changed features, bug
      fixes affecting the blog or admin, new config keys, install/upgrade
      requirements (e.g. a newly required PHP extension), deployment changes
      (Docker/Caddy/Nginx/DDev/Devbox).
- [ ] **Drop** internal-only changes: dev-environment tooling (e.g. Workshop),
      CI, tests, refactors, code-comments/`CLAUDE.md`/`DECISIONS.md`, and
      dependency bumps with no user-visible effect.
- [ ] Rewrite each kept line in plain language (what changed for the user, not
      the PR title). Group under **Added / Changed / Fixed**.
- [ ] Call out anything requiring action on upgrade in an **Upgrade notes**
      section (e.g. "install the `pdo_mysql` PHP extension", config changes).
- [ ] Save the notes to a temp file (e.g. `/tmp/notes.md`) for step 6.

## 4. Merge `main` into `release`

```sh
git checkout release && git pull
git merge --no-ff main -m "Release <version>"
git push origin release
```

- [ ] Resolve any conflicts (rare — `release` should only trail `main`).
- [ ] Re-run `vendor/bin/codecept run` on `release` to confirm green.

## 5. Tag

```sh
git tag -a <version> -m "Lamb <version>"   # e.g. 0.9.0
git push origin <version>
```

- [ ] Tag is created on the `release` branch tip.

## 6. Create the GitHub release

```sh
gh release create <version> \
  --target release \
  --title "Lamb <version>" \
  --notes-file /tmp/notes.md
# add --prerelease for an -rcN tag
# add --latest to mark a final release as the latest
```

- [ ] Final releases: pass `--latest`. Pre-releases: pass `--prerelease` and
      do **not** mark latest.
- [ ] Verify on GitHub: `gh release view <version>`.

## 7. Post-release

- [ ] Announce / update any demo site if applicable.
- [ ] Note that the Docker/Devbox/DDev users pull from `release`; confirm a
      clean checkout of `release` installs and runs.

## Related

- `BRANCHES` — branch roles (`main`, `release`, `next`, pinned).
- `CONTRIBUTING` — contribution workflow.
- `docs/upgrading.md` — what end users run to upgrade.
