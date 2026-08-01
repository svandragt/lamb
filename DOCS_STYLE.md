# Documentation style

How to write Lamb's documentation. It follows the
[Google developer documentation style guide](https://developers.google.com/style),
with the project-specific decisions below. When this file and Google disagree,
this file wins. When neither covers a case, follow Google.

`docs/` is the end-user manual and is published to GitHub Pages from the
`release` branch. Contributor and maintainer material lives in root-level files.
See the 2026-05-29 entry in `DECISIONS.md`.

## The short version

Write the way you would explain it to someone competent who hasn't seen this
part of the system before. Say who does what. Keep sentences short enough to
read once.

## Voice and grammar

- **Second person.** "You" is the reader. Don't use "we" for instructions.
- **Active voice, with Lamb as the actor.** "Lamb issues a fresh token", not
  "a fresh token is issued". Naming the actor is the single highest-value habit
  in this guide — passive voice is where documentation goes vague.
- **Present tense.** "The post appears", not "the post will appear".
- **Task-first sentence order.** "To save a post as a draft, add `draft: true`"
  beats "Posts can be saved as drafts by adding `draft: true`". Lead with the
  goal so a scanning reader can stop as soon as they've found theirs.
- **One idea per sentence.** If a sentence has an em dash carrying a second
  clause, it's usually two sentences. This is the most common thing to fix.

## Headings

- **Sentence case**, in the `title:` front matter and every heading.
  "Post types", not "Post Types".
- **Imperative for tasks**, noun phrase for reference. "Set up feeds", not
  "Setting up feeds" or "How to set up feeds". A page describing what something
  *is* keeps a noun phrase: "Available endpoints".
- **Changing a heading changes its anchor.** Before renaming one, grep for the
  old slug. If anything outside the repo might link to it, pin the old anchor
  with kramdown's `{: #old-slug }` rather than breaking the link.

## Words

- **Don't use:** simply, just, easy, easily, please, note that, obviously, of
  course. If a step is easy, the reader finds that out by doing it; if it isn't,
  you've told them the problem is them.
- **Don't use** "e.g.", "i.e.", "etc.", "via", "whilst". Write "for example",
  "that is", "and so on", "through", "while".
- **Prefer** "lets you" over "allows you to", "go to" over "navigate to",
  "turn on" over "enable" in user-facing steps.
- **British spelling** is the house style: behaviour, sanitise, colour,
  travelled. This is a deliberate departure from Google, which specifies US
  English.
- **Product names as their owners write them:** DDEV, NGINX, FrankenPHP,
  Devbox, Docker, Micropub, WebSub, IndieAuth, Xdebug, SQLite, WordPress.
- **Front matter** is two words as a noun.

## Formatting

- **Bold** for UI labels the reader clicks: **Settings → Export**.
- `Code font` for paths, filenames, config keys, commands, and literal values.
- Serial comma.
- Numbered lists for ordered steps, bullets for unordered sets.
- Tables for reference material with a repeating shape. Prose for reasoning.
- Comment code samples in the same voice as the prose.

## What every page needs

- Front matter with `title:` and `nav_order:`. `nav_order` controls the sidebar,
  which is otherwise alphabetical and useless. Keep the ordering grouped by task
  flow: install, writing, publishing, administration, theming.
- A `## Related` section at the end, linking to topically adjacent pages, each
  with a clause saying *why* the reader might follow it. These cross-links are
  the docs' best feature — they carry navigation the sidebar can't. Add both
  directions when you add a page.
- Internal links written as `{% link page.md %}` so a build fails on a bad
  target instead of shipping a dead link.

## Filenames and URLs

`permalink: pretty` derives URLs from filenames, so **renaming a file changes a
live URL**. Don't rename a page in `docs/` without deciding what happens to the
old address.

## Be honest about failure

Document the ways a thing goes wrong, not just the happy path: the silent upload
failure, the footgun in an optional cache config, the memory ceiling that
`memory_limit` doesn't govern. Mark which install paths are verified before
release and which aren't. This honesty is a deliberate feature of these docs and
the reason people trust them.

Don't document unreleased behaviour. Pages build from `release`, so anything
merged to `main` goes live at the next release, not before.

## Check before you commit

```sh
make docs   # builds and serves at http://localhost:4000/lamb/
```

A build failure on a `{% link %}` means a bad target. After renaming headings,
confirm nothing else in `docs/` linked to the old anchor.
