---
title: Upgrading stored posts
parent: Installation & hosting
---

# Upgrading stored posts

Lamb stamps every post with the schema version it was last parsed against. When you upgrade Lamb itself, some stored posts can be behind the current version. Lamb already re-parses a post automatically the next time it's rendered, so you never see stale content — but that means a post nobody has viewed since the upgrade stays on its old version indefinitely.

`bin/lamb upgrade-posts` makes that catch-up explicit and immediate, instead of leaving it to the next render:

```bash
# See how many posts are behind, without changing anything
bin/lamb upgrade-posts --dry-run

# Upgrade them
bin/lamb upgrade-posts
```

It processes posts in fixed-size batches so an upgrade run doesn't hold your whole archive in memory at once, however large it is.

Both forms print a summary line, for example:

```
Done. needs_upgrade=42 upgraded=42
```

Under `--dry-run`, `upgraded` stays `0`; nothing is written.

This command doesn't replace the automatic upgrade on render — it's there for when you'd rather bring every post current in one pass, such as right after upgrading Lamb, rather than have posts upgrade one by one as they're viewed.

## Related

- [Upgrading](upgrading.md): Upgrading the Lamb application itself.
