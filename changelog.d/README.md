# Changelog fragments

Each user-visible change adds one fragment file to this directory, in the same
commit as the code. Fragments replace edits to `CHANGELOG.md`, so parallel pull
requests no longer conflict on it. Internal-only changes (tests, tooling,
refactors) need no fragment.

## Name a fragment

Name the file `<name>.<type>.md`.

- `<type>` is one of `added`, `changed`, `deprecated`, `removed`, `fixed` or
  `security`, as in [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
- `<name>` is the issue number (`924`), the issue number and a slug
  (`917-preconnect`) when one issue has several pull requests, or a short
  kebab-case slug (`feed-draft`) when there is no issue.

For example, `changelog.d/924.fixed.md`.

## Write a fragment

A fragment holds one or more Markdown bullets, written as they appear in
`CHANGELOG.md`. Start each bullet with `- ` and indent any wrapped line. Say
what the user sees, in plain language, and link the issue as `([#N](url))`.

```md
- A feed post saved as a draft no longer goes live when the feed updates it ([#921](https://github.com/svandragt/lamb/issues/921)).
```

Headings and paragraphs are not allowed, because the assembly adds the headings
itself.

## Check a pull request

The `changelog` job in `.github/workflows/ci.yml` fails a pull request that
changes `src/` or `bin/` without adding or changing a fragment. Add the
`no-changelog` label to the pull request when it needs none, then re-run the
failed jobs.

## Assemble at release

From the repository root, run:

```sh
scripts/changelog-assemble.sh <version> [<date>] [--dry-run]
```

The script inserts `## [<version>] - <date>` below `## [Unreleased]` in
`CHANGELOG.md`, updates the compare links at the bottom, and deletes the
fragments it used. `README.md` is not a fragment. The date defaults to today.

- Sections follow the Keep a Changelog order: Added, Changed, Deprecated,
  Removed, Fixed, Security.
- Within a section, bullets follow the fragment names: by issue number, then by
  the rest of the name, then the slug-only names.
- `--dry-run` prints the section and changes nothing.
- The script stops, and changes nothing, on an unknown type, an empty fragment,
  a line that does not start or continue a bullet, or entries left under
  `## [Unreleased]`.
