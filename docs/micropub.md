---
title: Micropub
---

# Micropub

Lamb supports the [Micropub](https://micropub.net/) protocol, which lets you publish posts from any Micropub-compatible client app, such as iA Writer, Quill, or Indigenous.

## How Micropub works

Lamb exposes a `/micropub` endpoint. Clients discover it through a `<link rel="micropub">` tag in your home page `<head>`. [IndieAuth](https://indieauth.com/) handles authentication, verifying your identity by checking `rel="me"` links on your site.

## Set up Micropub

### 1. Set your site URL

Add your canonical site URL to your site configuration at `/settings`:

```ini
site_url = https://example.com
```

Lamb compares the identity in an IndieAuth token against this value, to confirm that
the token was issued for *your* site and not someone else's. Until you set it, the
Micropub endpoint refuses every token and logs
`micropub: rejecting token, no site_url configured` to the PHP error log.

Don't derive the value from the request. Avoiding that is exactly why this setting
exists. You can also supply it in the `LAMB_SITE_URL` environment variable, which
takes precedence over the setting.

### 2. Add `rel="me"` identity links

IndieAuth verifies who you are by checking that your site links to your profiles and that those profiles link back. Add a `[me]` section to your site configuration at `/settings`:

```ini
[me]
Github = https://github.com/yourusername
Email = mailto:you@example.com
```

Lamb renders each entry as a `<link rel="me">` tag in the HTML `<head>`, invisible to visitors but readable by IndieAuth. You can add as many entries as you like.

Make sure each linked profile, such as GitHub, has your site URL in its profile page, so IndieAuth can verify the two-way link.

### 3. Configure your Micropub client

Point your client at your site URL. It auto-discovers the endpoints from your home page `<head>`:

| Link tag | Default value |
|---|---|
| `rel="authorization_endpoint"` | `https://indieauth.com/auth` |
| `rel="token_endpoint"` | `https://tokens.indieauth.com/token` |
| `rel="micropub"` | `https://yoursite.com/micropub` |

### Use your own IndieAuth server (optional)

To use a different authorization or token server, add the following to your site configuration at `/settings`:

```ini
authorization_endpoint = https://auth.example.com/auth
token_endpoint = https://token.example.com/token
```

## What Lamb creates

A Micropub `h-entry` with a `content` property creates a status post, with no title and no slug. If a `name` property is also present, Lamb creates a titled post with a slug derived from the title.

## Draft and scheduled post previews

Posts created with `post-status: draft`, or with a future `published` date, aren't publicly visible, so their permalink returns a 404 to anyone who isn't logged in. Because Micropub clients open the post URL right after creating it, Lamb appends a secret preview token to the URL it returns (`?preview=…`). That link shows the unpublished post to anyone who has it, without a login, and expires after 24 hours. The plain permalink, without the token, stays hidden until you publish the post.

## Troubleshooting

If a client can't connect, for example reporting "something went wrong setting up your Micropub endpoint", turn on diagnostic logging to see exactly what the client sent and why Lamb responded as it did.

First, check that `site_url` is set. See [Set your site URL](#1-set-your-site-url). Without it, Lamb refuses every token, with a `no_site_url` reason in the log described below.

Add this to your site configuration at `/settings`:

```ini
micropub_debug = true
```

Reproduce the problem with your client, then read `data/micropub.log`, next to your `lamb.db`. Each line is one event: the incoming request (method, client `User-Agent`, and whether a token was supplied), the token-verification outcome (including a `me_mismatch` reason when the token's identity doesn't match your site URL), and the response status. Lamb never writes the bearer token itself, only a non-reversible fingerprint.

Comparing the log from a client that works against one that fails usually pinpoints the difference. **Turn logging back off with `micropub_debug = false` when you're done**, so the log stops growing.

## POSSE syndication

Lamb can advertise syndication targets to Micropub clients, so you can publish once and syndicate elsewhere (POSSE).

### Configure targets

Add a `[syndicate_to]` section to your site configuration at `/settings`:

```ini
[syndicate_to]
https://bsky.app/profile/yourusername = Bluesky
https://mastodon.social/@yourusername = Mastodon
```

Each entry is a `uid = name` pair. The `uid` is the profile URL of the syndication target, and the `name` is the human-readable label that Micropub clients such as Quill show. Clients discover the list from `GET /micropub?q=config`.

### Syndicate a post

When your Micropub client sends `mp-syndicate-to` on create, Lamb records the selected targets on the post as `syndicated-to`. The `syndicated-to` field is also visible in the `q=source` response, as a `syndication` property.

The status page then shows "Also on: …" links, with the `u-syndication` microformat class, for any recorded targets.

[Bridgy](https://brid.gy/) handles actual delivery to silos, through outbound webmentions. Lamb only configures, records, and surfaces the targets.

## Test your setup

Go to [MicroPub Rocks](https://micropub.rocks/) and enter your site. Lamb's implementation report is at [micropub.rocks/implementation-reports/servers/962](https://micropub.rocks/implementation-reports/servers/962/GYKIHp3O03m9vNil9Qcq).

## Related

* [Media]({{ site.baseurl }}{% link media.md %}): Lamb stores uploaded photos under `src/assets/` and converts JPEG and PNG to WebP.
* [Site configuration]({{ site.baseurl }}{% link site-configuration.md %}): The `[me]`, `authorization_endpoint`, and `token_endpoint` settings.
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): Send a future `published` date or `post-status: scheduled` to schedule a post.
* [Webmentions]({{ site.baseurl }}{% link webmentions.md %}): Receive notifications when other sites link to your posts.
