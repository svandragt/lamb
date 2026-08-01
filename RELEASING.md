# Releasing Lamb

Maintainer checklist for cutting a release. Lamb ships from the `release`
branch (see `BRANCHES`), and `main` is the active development branch. Versions
are plain SemVer tags such as `0.9.0`, and pre-releases use a suffix such as
`0.9.0-rc1`. There's no version string in the code — **the Git tag is the
source of truth**.

> **Run everything below from Lamb's devbox shell** (`devbox shell` in the repo)
> or through `devbox run -- …`. The pre-push hook runs the test suite, which
> needs PHP, and a bare shell or the *global* devbox won't have it.

## 1. Pre-flight (on `main`)

- [ ] All intended PRs are merged into `main`, and nothing release-worthy is still open.
- [ ] The working tree is clean and `main` is up to date: `git checkout main && git pull`.
- [ ] Tests pass: `vendor/bin/codecept run` (unit and acceptance).
      Acceptance needs `.env` with `SITE_URL` and `LAMB_TEST_PASSWORD`. Generate it with
      `LAMB_WRITE_TEST_PASSWORD=1 php make-password.php <pw>` so the cleartext
      `LAMB_TEST_PASSWORD` is written, because it's omitted by default. Acceptance starts
      its own server, so don't leave another server on the test port.
- [ ] Static checks pass: `composer lint` && `composer analyse`.
- [ ] Docs are accurate for any user-facing change (`docs/`, `README.md`).

## 2. Choose the version

- [ ] Decide the new version from the change set, using SemVer:
      patch for fixes only, minor for new features, major for breaking changes.
- [ ] Confirm it's unused: `git tag | sort -V | tail`.
- [ ] To cut a pre-release first, use an `-rcN` suffix and mark it
      pre-release in step 6.

## 3. Generate end-user release notes

Notes are for **people running a Lamb blog**, not for contributors. Start from
the commit list, then curate.

```sh
# Everything on main since the last release tag (use the previous final tag):
git log --format='- %s' <last-tag>..main
```

- [ ] **Keep** changes an end user would notice: new and changed features, bug
      fixes affecting the blog or admin, new config keys, install or upgrade
      requirements such as a newly required PHP extension, and deployment
      changes to Docker, FrankenPHP, NGINX, DDEV, or Devbox.
- [ ] **Drop** internal-only changes: dev-environment tooling such as Workshop,
      CI, tests, refactors, code comments, `CLAUDE.md` and `DECISIONS.md`, and
      dependency bumps with no user-visible effect.
- [ ] Rewrite each kept line in plain language, describing what changed for the
      user rather than repeating the PR title. Group the lines under
      **Added**, **Changed**, and **Fixed**.
- [ ] Call out anything requiring action on upgrade in an **Upgrade notes**
      section, such as "install the `pdo_mysql` PHP extension" or config changes.
- [ ] Save the notes to a temp file such as `/tmp/notes.md` for step 5.

## 4. Promote `main` to `release` through a PR

The branch ruleset rejects direct pushes to `release` (`GH013` — changes must go
through a pull request), so promotion happens as a PR merged with a **merge
commit**, not a squash or rebase, to keep the histories connected:

```sh
gh pr create --base release --head main \
  --title "Release <version>" \
  --body "Promote main to release for <version>."
gh pr merge --merge --subject "Release <version>"
```

- [ ] Confirm the **release-verify** check is green on the PR before merging.
      It runs the acceptance suite against the Docker and FrankenPHP release image
      and an NGINX plus PHP-FPM install — the well-travelled production paths —
      in addition to `ci`'s built-in-server run.
- [ ] If the PR reports `BEHIND`, `release` has commits that `main` doesn't, such
      as old release merges. Sync first: branch from `main`, run `git merge
      origin/release` (a merge commit, with no content changes expected), open a
      PR for that into `main`, then re-check the release PR.
- [ ] Resolve any conflicts. These are uncommon, because `main` is the source of
      truth, though `release` may also carry the occasional release-only commit.
- [ ] Re-run `vendor/bin/codecept run` on `release` to confirm it's green.

## 5. Tag and create the GitHub release

`gh release create` creates the tag itself at `--target release`, which is the
merged branch tip, and publishes the release in one step. You don't need a
separate `git tag`.

```sh
gh release create <version> \
  --target release \
  --title "Lamb <version>" \
  --notes-file /tmp/notes.md
# add --prerelease for an -rcN tag
# add --latest to mark a final release as the latest
```

**No local `gh`?** The `Cut release` workflow (`.github/workflows/cut-release.yml`)
runs the same `gh release create --target release` server-side from a
`workflow_dispatch`, so you can drive it without a local checkout or token, for
example from an automation session that can trigger workflows but not create
releases:

```sh
gh workflow run cut-release.yml \
  -f version=<version> \
  -f prerelease=true \
  -f notes="$(cat /tmp/notes.md)"
# omit prerelease (or set =false) for a final; add -f latest=true to mark it latest
```

It tags the current `release` tip, so promote main to release (step 4) first.

- [ ] Merge the release PR from step 4 first, so `--target release` tags the
      intended commit. If the tag landed on the wrong commit, move it. The
      release object and its notes follow the tag:
      `git tag -f -m "<version>" <version> origin/release && git push -f origin <version>`.
      The `release: published` event does **not** re-fire for a moved tag, so use
      the `workflow_dispatch` re-run from step 6 instead.
- [ ] For final releases, pass `--latest`. For pre-releases, pass `--prerelease`
      and do **not** mark them latest.
- [ ] Verify with `gh release view <version>`, and run `git fetch --tags` to pull
      the tag that `gh` created.

## 6. Post-release

- [ ] Publishing the release triggers the `Release artifacts` workflow. Verify it
      attached `lamb-<version>.tar.gz` to the release (`gh release view <version>`)
      and pushed `ghcr.io/svandragt/lamb:<version>`, plus `:latest` for finals.
      Re-run it with `gh workflow run release-artifacts.yml -f tag=<version>` if needed.
- [ ] Announce the release and update any demo site, if applicable.
- [ ] Docker, Devbox, and DDEV users pull from `release`, so confirm that a
      clean checkout of `release` installs and runs.
- [ ] If a `next` branch exists, holding work parked for the next version (see
      `BRANCHES`), open a PR to merge it into `main` now that the release is
      out:

      ```sh
      git fetch origin
      git show-ref --verify --quiet refs/remotes/origin/next && \
        gh pr create --base main --head next \
          --title "Merge next into main" \
          --body "Bring parked next-version work into main after the <version> release."
      ```

## Related

- `BRANCHES`: branch roles (`main`, `release`, `next`, pinned).
- `CONTRIBUTING`: contribution workflow.
- `docs/upgrading.md`: what end users run to upgrade.
