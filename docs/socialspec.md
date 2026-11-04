---
title: SocialSpec
parent: Sharing & discovery
---

# SocialSpec

[SocialSpec](https://socialspec.org/) lets feed readers and social apps turn
your site into a full profile, without scraping your HTML. Next to your
[feeds]({{ site.baseurl }}{% link feeds.md %}), Lamb serves three small files
under `/.well-known/`. Each one is built from something you already set up, and
stays switched off (`404`) until you do.

| Path | Spec | Built from |
|------|------|------------|
| `/.well-known/about.md` | [AboutSpec](https://aboutspec.org/) | Your page with the slug `about` |
| `/.well-known/contact.vcf` | [ContactSpec](https://contactspec.org/) | `author_name`, `author_email`, your site URL and `[me]` links |
| `/.well-known/following.opml` | [FollowSpec](https://followspec.org/) | A copy of the list at `following_url` |

All three can be read from other sites (CORS), and support conditional
requests, so apps can check for changes cheaply.

## About

Publish a page with `slug: about`:

```markdown
---
title: About me
slug: about
summary: Web engineer writing about Linux tooling.
---
I write short notes about…
```

`about.md` serves the page's Markdown with its title as a heading. The
`summary` comes from the page's `summary` (or `description`), and falls back to
`site_description`. Links and images that start with `/` are made absolute.
Adding the page as a [menu item]({{ site.baseurl }}{% link menu-items.md %})
(`About = about`) keeps it out of your timeline.

## Contact

Set `author_email` in [site configuration]({{ site.baseurl }}{% link site-configuration.md %}).
The vCard lists that address, your `author_name` (or `site_title`), your site
URL, and every `http(s)` link in `[me]`. Without an `author_email`, there's no
contact file.

## Following

Lamb doesn't keep a list of who you follow. Your feed reader does, so Lamb
copies its list instead of making you maintain a second one:

```ini
following_url = https://reader.example/following/your-token.opml
```

The [scheduled tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %})
fetch that URL at most every 30 minutes and serve the copy from your own
domain. If the reader is down, or returns something that isn't OPML, the last
good copy stays. Remove `following_url` and the file goes away.

Use a list your reader publishes on purpose. It will be public on your site.

## Related

* [Feeds]({{ site.baseurl }}{% link feeds.md %}) — the Atom and JSON feeds SocialSpec builds on
* [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}) — `author_email`, `author_name`, `following_url` and `[me]`
* [Menu Items]({{ site.baseurl }}{% link menu-items.md %}) — keep the about page out of the timeline
* [Cron Scheduled Tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}) — where the following list is refreshed
