# Releasing Lamb

Maintainer checklist for cutting a release. Lamb ships from the `release`
branch (see `BRANCHES`); `main` is the active development branch. Versions are
plain SemVer tags (`0.9.0`); pre-releases use a suffix (`0.9.0-rc1`). There is
no version string in the code — **the Git tag is the source of truth**.

> **Run everything below from Lamb's devbox shell** (`devbox shell` in the repo)
> or via `devbox run -- …`. The pre-push hook runs the test suite, which needs
> PHP — a bare shell (or the *global* devbox) won't have it.

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
- [ ] Save the notes to a temp file (e.g. `/tmp/notes.md`) for step 5.

## 4. Promote `main` to `release` via PR

Direct pushes to `release` are rejected by the branch ruleset (`GH013` —
changes must go through a pull request), so promotion happens as a PR merged
with a **merge commit** (not squash/rebase, to keep histories connected):

```sh
gh pr create --base release --head main \
  --title "Release <version>" \
  --body "Promote main to release for <version>."
gh pr merge --merge --subject "Release <version>"
```

- [ ] If the PR reports `BEHIND`, `release` has commits not on `main` (e.g.
      old release merges). Sync first: branch from `main`, `git merge
      origin/release` (a merge commit, no content changes expected), PR that
      into `main`, then re-check the release PR.
- [ ] Resolve any conflicts (uncommon — `main` is the source of truth, though
      `release` may also carry the occasional release-only commit).
- [ ] Re-run `vendor/bin/codecept run` on `release` to confirm green.

## 5. Tag and create the GitHub release

`gh release create` creates the tag itself at `--target release` (the merged
branch tip) and publishes the release in one step — no separate `git tag` needed.

```sh
gh release create <version> \
  --target release \
  --title "Lamb <version>" \
  --notes-file /tmp/notes.md
# add --prerelease for an -rcN tag
# add --latest to mark a final release as the latest
```

- [ ] Merge the release PR (step 4) first, so `--target release` tags the
      intended commit. If the tag landed on the wrong commit, move it — the
      release object and its notes follow the tag:
      `git tag -f -m "<version>" <version> origin/release && git push -f origin <version>`.
      The `release: published` event will **not** re-fire for a moved tag; use
      the `workflow_dispatch` re-run from step 6 instead.
- [ ] Final releases: pass `--latest`. Pre-releases: pass `--prerelease` and
      do **not** mark latest.
- [ ] Verify: `gh release view <version>`, and `git fetch --tags` to pull the
      tag `gh` created.

## 6. Post-release

- [ ] Publishing the release triggers the `Release artifacts` workflow. Verify it
      attached `lamb-<version>.tar.gz` to the release (`gh release view <version>`)
      and pushed `ghcr.io/svandragt/lamb:<version>` (plus `:latest` for finals).
      Re-run via `gh workflow run release-artifacts.yml -f tag=<version>` if needed.
- [ ] Announce / update any demo site if applicable.
- [ ] Note that the Docker/Devbox/DDev users pull from `release`; confirm a
      clean checkout of `release` installs and runs.

## Related

- `BRANCHES` — branch roles (`main`, `release`, `next`, pinned).
- `CONTRIBUTING` — contribution workflow.
- `docs/upgrading.md` — what end users run to upgrade.
