# Branch protection rulesets

GitHub branch protection lives in **repository settings**, not in the repo
tree — so it cannot be enabled by a commit. These JSON files are the
exportable form of the rules we want, kept under version control so the
intended configuration is reviewable and reproducible.

## `require-ci.json`

Blocks merging any pull request into `main` (the default branch) or
`release` until the **`ci`** status check is green. `ci` is the single
aggregate gate job in `.github/workflows/ci.yml`; it only succeeds when both
`quality` and every `test` matrix entry succeed.

Why this matters: without a required status check, a PR can be merged the
instant it opens — before CI has finished, or even when it has failed.
That is exactly how red PRs reached `main` (e.g. #309 merged with a failing
`test (8.2)`). The `ci` job is also written with `if: always()` so a failed
dependency makes it report **failure**, not *skipped* — GitHub counts a
skipped required check as passing, so a plain aggregate job would not block
anything.

The ruleset uses `required_approving_review_count: 0`, so the solo
maintainer can still self-merge — but only once CI passes. `bypass_actors`
is empty, so the rule applies to everyone, including admins ("this must not
be possible" — including by accident).

### Apply it (one time, ~1 minute)

UI:

1. Repo → **Settings → Rules → Rulesets → New ruleset → Import a ruleset**.
2. Choose `require-ci.json`.
3. Confirm enforcement is **Active** and click **Create**.

Or via the GitHub CLI / API:

```bash
gh api -X POST repos/svandragt/lamb/rulesets \
  --input .github/rulesets/require-ci.json
```

### Verify

Open a throwaway PR that deliberately fails a test. The **Merge** button
should be disabled with "Required statuses must pass before merging", and
`ci` should show a red ✗ (not a grey "skipped").

### Adjust later

- Require code review too: raise `required_approving_review_count`.
- Require branches to be up to date before merging: set
  `strict_required_status_checks_policy` to `true`.
- Allow a specific automation/admin to bypass: add entries to
  `bypass_actors`.

> Note: the `playwright` workflow is intentionally **not** required — it is
> path-filtered (`src/**` etc.), so it does not run on every PR and cannot
> serve as a universal gate. It remains an advisory check.
