# Branch protection rulesets

GitHub branch protection lives in **repository settings**, not in the repo
tree, so you can't enable it with a commit. These JSON files are the
exportable form of the rules we want, kept under version control so the
intended configuration is reviewable and reproducible.

## `require-ci.json`

Blocks merging any pull request into `main`, the default branch, or
`release` until the **`ci`** status check is green. `ci` is the single
aggregate gate job in `.github/workflows/ci.yml`, and it only succeeds when
`quality`, every `test` matrix entry, and the `playwright` browser suite
succeed. The `playwright` job is skipped, and treated as a pass, on PRs that
don't touch e2e-relevant paths such as `src/**`. See the `changes` job.

Why this matters: without a required status check, anyone can merge a PR the
instant it opens, before CI has finished or even when it has failed.
That is exactly how red PRs reached `main`, for example #309, which merged
with a failing `test (8.2)`. The `ci` job also uses `if: always()`, so a failed
dependency makes it report **failure** rather than *skipped*. GitHub counts a
skipped required check as passing, so a plain aggregate job wouldn't block
anything.

The ruleset uses `required_approving_review_count: 0`, so the solo
maintainer can still self-merge, but only once CI passes. `bypass_actors`
is empty, so the rule applies to everyone, including admins ("this must not
be possible" — including by accident).

### Apply it (one time, about 1 minute)

In the UI:

1. Go to the repo, then **Settings → Rules → Rulesets → New ruleset → Import a ruleset**.
2. Choose `require-ci.json`.
3. Confirm that enforcement is **Active**, and click **Create**.

Or use the GitHub CLI or API:

```bash
gh api -X POST repos/svandragt/lamb/rulesets \
  --input .github/rulesets/require-ci.json
```

### Verify

Open a throwaway PR that deliberately fails a test. The **Merge** button
should be disabled, with "Required statuses must pass before merging", and
`ci` should show a red ✗ rather than a grey "skipped".

### Adjust later

- To require code review too, raise `required_approving_review_count`.
- To require branches to be up to date before merging, set
  `strict_required_status_checks_policy` to `true`.
- To let a specific automation or admin bypass the rule, add entries to
  `bypass_actors`.

> Note: the browser (`playwright`) suite is folded into the `ci` gate rather
> than being its own required check. The CI workflow always runs, so the
> required `ci` context is always reported, but the playwright job only
> executes when e2e-relevant files change. A required check from a
> `paths:`-filtered workflow would otherwise hang as "pending" forever on
> PRs that don't trigger it.
