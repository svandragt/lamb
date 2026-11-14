---
title: Site Configuration
parent: Site customisation
---

# Site Configuration

Lamb runs without any configuration. To change it, log in and open `/settings`. The Configuration tab is a form with a field for each setting.

## Fieldsets

Fields marked "(required)" must be filled before the form saves. After a successful save the form reloads with your values and shows no message. Lamb only reports a problem, such as a removed setting that the save dropped.

| Fieldset | Holds |
|---|---|
| Site | Site title (required), site URL (required), description, timezone and theme. |
| Author | Author name (required) and author email. |
| Navigation | Menu links and footer links. |
| Identity links | Profile links for IndieAuth, and the following list. |
| Feeds | Feeds to publish, whether they save as drafts, and WebSub hubs. |
| IndieWeb | Authorization endpoint, token endpoint and syndication targets. |
| Redirections | Old addresses that forward to new ones. |

## List boxes

Each box takes one entry per line, written as `label = value`:

| Box | Format | Example |
|---|---|---|
| Menu links, footer links | `label = path or URL` | `About = about` |
| Feeds to publish | `name = feed URL` | `releases = https://example.com/releases.atom` |
| Profile links | `label = URL` | `Mastodon = https://example.social/@you` |
| Syndication targets | `profile URL = name` | `https://mastodon.social/@you = Mastodon` |
| Redirections | `old-slug = destination` | `old-post = /new-post` |

A path or slug can be a post slug, a path on your site or a full web address.

## Reset to defaults

The Reset to defaults button replaces every setting with the built-in defaults. Lamb asks you to confirm first. You cannot undo it.

## How Lamb stores the settings

Lamb stores the settings as text in the database. Saving the form rewrites that text. Comments in it are not kept, and keys the form has no field for, such as ones you added by hand, stay as they are.

## Removed settings

Lamb 0.17 removed these settings. A site that still has one in its configuration keeps working: Lamb ignores the setting. Saving the form removes the ones still in your configuration, and tells you which, once.

| Setting | Replacement |
|---|---|
| `posts_per_page` | None. Lamb uses 10 posts per page. |
| `404_fallback` | Add a `[redirections]` entry for each old address you still want to forward. |
| `[preconnect]` | None. Lamb adds preconnect hints for the sites your posts load images and video from. |
| `micropub_debug` | Set the `LAMB_MICROPUB_DEBUG` environment variable. |
| `experimental_features` | Pass `--experimental` to `bin/lamb import <source> <path>`. |

## Site URL

`site_url` is the canonical address of your site, e.g. `https://example.com`. The
settings form requires it before it saves, and a site that never saved the form
runs without it. Setting it matters because:

* **Micropub needs it.** IndieAuth tokens are issued for an identity, and Lamb has
  to know its own identity to tell whether a token belongs to you. Without
  `site_url` the Micropub endpoint refuses every token, and logs
  `micropub: rejecting token, no site_url configured` via PHP's error log.
* **It pins absolute URLs.** Feeds, social embed tags, and the endpoint discovery
  links otherwise use whatever host the incoming request asked for. Setting
  `site_url` keeps them on your real domain even if a request arrives with a
  different `Host` header.

You can also set it outside the settings form with the `LAMB_SITE_URL` environment
variable, which takes precedence over the saved value — handy when the same
configuration is deployed to more than one hostname.

### Serve from the root of a domain

Give `site_url` the domain on its own, with no path. Lamb does not support an
install under a subdirectory, so `https://example.com/blog` cannot work: Lamb
reads the domain and drops the path, while your IndieAuth identity is the whole
address. Micropub then refuses every token, because the identity it compares
against is not the one your tokens were issued for.

The settings page warns you if you save a `site_url` with a path. To run Lamb at
`example.com/blog` today, give it its own subdomain — `blog.example.com` — and
point `site_url` at that.

## Related

* [Setting up Cross-Posting]({{ site.baseurl }}{% link cross-posting.md %}#setup) requires site configuration changes.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): The `feeds_draft` setting controls whether ingested posts are published or saved as drafts.
* [Feeds]({{ site.baseurl }}{% link feeds.md %}): The `websub_hubs` setting enables real-time push to feed subscribers.
* [Menu Items]({{ site.baseurl }}{% link menu-items.md %})
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): The `[me]`, `authorization_endpoint`, `token_endpoint`, and `[syndicate_to]` settings enable Micropub publishing and POSSE syndication.
* [Redirections]({{ site.baseurl }}{% link redirections.md %})
* [SocialSpec]({{ site.baseurl }}{% link socialspec.md %}): `author_email`, `author_name`, `following_url` and `[me]` feed the `/.well-known/` profile files.
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): The `timezone` setting determines when scheduled posts go live.
* [Themes]({{ site.baseurl }}{% link themes.md %}): The `theme` key selects the active theme.
