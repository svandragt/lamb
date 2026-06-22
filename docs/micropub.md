---
title: Micropub
---

# Micropub

Lamb supports the [Micropub](https://micropub.net/) protocol, allowing you to publish posts from any Micropub-compatible client app (e.g. iA Writer, Quill, or Indigenous).

## How it works

Lamb exposes a `/micropub` endpoint. Clients discover it via a `<link rel="micropub">` tag in your home page `<head>`. Authentication is handled via [IndieAuth](https://indieauth.com/), which verifies your identity by checking `rel="me"` links on your site.

## Setup

### 1. Add `rel="me"` identity links

IndieAuth verifies who you are by checking that your site links to your profiles and those profiles link back. Add a `[me]` section to your site configuration at `/settings`:

```ini
[me]
Github = https://github.com/yourusername
Email = mailto:you@example.com
```

Each entry is rendered as a `<link rel="me">` tag in the HTML `<head>` — invisible to visitors but readable by IndieAuth. You can add as many entries as you like.

Make sure each linked profile (e.g. GitHub) has your site URL in its profile page so IndieAuth can verify the two-way link.

### 2. Configure your Micropub client

Point your client at your site URL. It will auto-discover the endpoints from your home page `<head>`:

| Link tag | Default value |
|---|---|
| `rel="authorization_endpoint"` | `https://indieauth.com/auth` |
| `rel="token_endpoint"` | `https://tokens.indieauth.com/token` |
| `rel="micropub"` | `https://yoursite.com/micropub` |

### Using your own IndieAuth server (optional)

To use a different authorization or token server, add the following to your site configuration at `/settings`:

```ini
authorization_endpoint = https://auth.example.com/auth
token_endpoint = https://token.example.com/token
```

## What gets created

A Micropub `h-entry` with a `content` property creates a status post (no title, no slug). If a `name` property is also present, it creates a titled post with a slug derived from the title.

## Draft and scheduled post previews

Posts created with `post-status: draft` or a future `published` date are not publicly visible, so their permalink returns a 404 to anyone who isn't logged in. Because Micropub clients open the post URL right after creating it, Lamb appends a secret preview token to the URL it returns (`?preview=…`). That link shows the unpublished post to anyone who has it — without logging in — and expires after 24 hours. The plain permalink (without the token) stays hidden until the post is published.

## Troubleshooting

If a client can't connect — for example it reports "something went wrong setting up your Micropub endpoint" — you can turn on diagnostic logging to see exactly what the client sent and why Lamb responded as it did.

Add this to your site configuration at `/settings`:

```ini
micropub_debug = true
```

Reproduce the problem with your client, then read `data/micropub.log` (next to your `lamb.db`). Each line is one event: the incoming request (method, client `User-Agent`, whether a token was supplied), the token-verification outcome (including a `me_mismatch` reason when the token's identity doesn't match your site URL), and the response status. The bearer token itself is never written — only a non-reversible fingerprint.

Comparing the log from a client that works against one that fails usually pinpoints the difference. **Turn it back off (`micropub_debug = false`) when you're done** so the log stops growing.

## POSSE syndication

Lamb supports advertising syndication targets to Micropub clients so you can publish once and syndicate elsewhere (POSSE).

### Configure targets

Add a `[syndicate_to]` section to your site configuration at `/settings`:

```ini
[syndicate_to]
https://bsky.app/profile/yourusername = Bluesky
https://mastodon.social/@yourusername = Mastodon
```

Each entry is a `uid = name` pair. The `uid` is the profile URL of the syndication target; the `name` is the human-readable label shown in Micropub clients (e.g. Quill). Clients discover the list from `GET /micropub?q=config`.

### Syndicating a post

When your Micropub client sends `mp-syndicate-to` on create, Lamb records the selected targets on the post as `syndicated-to`. The `syndicated-to` field is also visible in the `q=source` response as a `syndication` property.

The status page then shows "Also on: …" links (with `u-syndication` microformat class) for any recorded targets.

Actual delivery to silos is handled by [Bridgy](https://brid.gy/) via outbound webmentions — Lamb only configures, records, and surfaces the targets.

## How to test

Visit [MicroPub Rocks](https://micropub.rocks/) and enter your site. Lamb's implementation report is available at [micropub.rocks/implementation-reports/servers/962](https://micropub.rocks/implementation-reports/servers/962/GYKIHp3O03m9vNil9Qcq).

## Related

* [Media]({{ site.baseurl }}{% link media.md %}): Uploaded photos are stored under `src/assets/` and JPEG/PNG are converted to WebP.
* [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}): The `[me]`, `authorization_endpoint`, and `token_endpoint` settings.
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): Send a future `published` date or `post-status: scheduled` to schedule a post.
* [Webmentions]({{ site.baseurl }}{% link webmentions.md %}): Receive notifications when other sites link to your posts.
