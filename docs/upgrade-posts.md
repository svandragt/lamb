---
title: Upgrading stored posts
parent: Installation & hosting
---

# Upgrading stored posts

Lamb stamps every post with the schema version it was last parsed against. When you upgrade Lamb itself, some stored posts can be behind the current version, and they stay behind until you upgrade them.

`bin/lamb upgrade-posts` brings every post current in one pass:

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

[`bin/lamb migrate`]({{ site.baseurl }}{% link upgrading.md %}#data-migrations) runs this same upgrade alongside Lamb's other one-time data migrations. Run `upgrade-posts` on its own only when you want just the post upgrade.

## Related

- [Upgrading](upgrading.md): Upgrading the Lamb application itself, including `bin/lamb migrate`.
