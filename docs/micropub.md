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

## How to test

Visit [MicroPub Rocks](https://micropub.rocks/) and enter your site. Lamb's implementation report is available at [micropub.rocks/implementation-reports/servers/962](https://micropub.rocks/implementation-reports/servers/962/GYKIHp3O03m9vNil9Qcq).

## Related

* [Site Configuration]({% link site-configuration.md %}): The `[me]`, `authorization_endpoint`, and `token_endpoint` settings.
