---
title: Decision Log
---

# Decision Log

Key architectural and design decisions made during Lamb's development.

## Procedural PHP with namespaces (not MVC)

**Decision:** Use namespaced procedural PHP rather than an MVC framework.

**Rationale:** Lamb targets single-author self-hosting with minimal setup. A framework would add complexity, dependencies, and upgrade friction. Namespaced PHP files give logical organisation without the overhead of a class hierarchy or a DI container. The routing model (`register_route` / `call_route`) is intentionally trivial.

## SQLite via RedBeanPHP (fluid mode)

**Decision:** Use SQLite with RedBeanPHP ORM in fluid mode (automatic schema evolution).

**Rationale:** A single-author microblog rarely needs multi-writer concurrency. SQLite is zero-config, ships as a single file, and is easy to back up. Fluid mode means schema migrations happen automatically and there is no migration runner to maintain. The trade-off is that column types are inferred by RedBeanPHP and can change; this is acceptable for a small personal blog.

## INI text stored in the database (not config files on disk)

**Decision:** Store the site configuration as raw INI text in the `option` table rather than reading a file from disk at runtime.

**Rationale:** Keeping config in the database avoids file-permission issues on shared hosts and simplifies deployment (no writable config directory needed outside of the database file itself). The INI format is kept because it is human-readable and easy to edit in a `<textarea>`, with a clear upgrade path if a structured UI is ever added.

## No automatic reslugging on title changes

**Decision:** Once a page slug is created it is not overwritten when the title is edited.

**Rationale:** "Good URLs don't change." Silently resluggling on edit would break external links. If an author deliberately wants a new slug they must set `slug:` in the front-matter explicitly.  When a slug is changed, a 301 redirect is created automatically so old links remain valid.

## Soft-delete (trash) instead of hard-delete

**Decision:** Deleting a post marks it as deleted (`deleted = 1`) rather than removing the database row.

**Rationale:** Accidental deletion is a common user error. Soft-delete provides a safety net at minimal cost. The `/trash` page is admin-only, so deleted posts are not publicly visible.

## Themes by file fallback, not inheritance

**Decision:** Custom themes only need to provide the files they wish to override. Missing files fall back to the `default` theme automatically.

**Rationale:** This minimises the amount of boilerplate a theme author must maintain. A theme can be a single CSS file if only the styling differs, which lowers the barrier for customisation.

## Feed ingestion saved as drafts by default

**Decision:** Posts ingested from external feeds are saved as drafts unless `feeds_draft = false` is set.

**Rationale:** Lamb is a single-author blog. The author should retain editorial control over cross-posted content; surfacing ingested items as drafts for review respects that. Authors who want automatic publishing can opt in with a config flag.

## Micropub via IndieAuth (no bespoke auth)

**Decision:** The Micropub endpoint delegates authentication entirely to IndieAuth rather than implementing its own token system.

**Rationale:** Implementing token issuance and revocation correctly is non-trivial. Delegating to IndieAuth (indieauth.com or a self-hosted server) keeps Lamb's auth surface small and leverages a well-specified open standard.

## Empty `<title>` for titleless feed entries (micro.blog convention)

**Decision:** In the Atom feed, posts without a title emit an empty `<title></title>` element rather than a date-string fallback.

**Rationale:** [book.micro.blog](https://book.micro.blog/) treats entries without a title as microblog-style posts and renders them inline; entries with a title are rendered as titled articles. A date fallback would force every status post into the titled-article layout in micro.blog and similar readers. The `<title>` element is still emitted (empty) because Atom requires it; this produces a benign validator warning we accept as the cost of correct rendering downstream.
