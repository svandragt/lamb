![Lamb made out of circuitry](src/images/og-image-lamb.webp)

Lamb — Literally Another Micro Blog.

Barrier free super simple blogging, self-hosted.

- SQLite based portable single author blog.
- Friction
  free [Markdown](https://docs.github.com/en/get-started/writing-on-github/getting-started-with-writing-and-formatting-on-github/basic-writing-and-formatting-syntax)
  entry with server-side syntax highlighting; drag and drop or paste images, automatically converted to WebP and downscaled.
- Hashtags by just typing them `#ahyeah`, plus full text search and configurable menu items.
- Drafts, scheduled posts, and one-click trash with restore.
- Discoverable Atom and JSON feeds (`/feed` and `/feed.json`, plus feeds per tag), with WebSub for instant updates.
- IndieWeb friendly: send and receive webmentions, write reply posts (`in-reply-to`), and publish from other apps through a Micropub endpoint.
- Pull external content into the blog by subscribing to feeds; ingested posts land as drafts by default.
- 404 fallback redirection to your old site, plus automatic 301s when a post's slug changes.
- Friendly user theming, if you don't like my retro themes. ;)

# Getting started

[Read the documentation](https://svandragt.github.io/lamb) to get started. It is published from the `release`
branch, so it always matches the latest released version.

To preview the in-development docs on `main` locally, run `make docs` and open http://localhost:4000/lamb/.

# Screenshots

An example blog running the 2026 theme at [vandragt.com](https://vandragt.com):
![Demo Lamb instance](docs/demo-vandragt.webp "Sander van Dragt's Notes, running Lamb with the 2026 theme")

Dropping images into a post ala GitHub:
![Drag and drop image demo](https://vandragt.com/assets/2023/12/6c5e64336afdd939f9c9768ac07b35551de8043b.gif "Creating a post with an image")

Friction free post deletion:
[Friction free post deletion (video)](https://github.com/svandragt/lamb/assets/594871/d0178b48-9a62-4e5d-bab7-b8168485be1e)

# Philosophy

- Simple over complex.
- Opinionated defaults over settings.
- Assume success, communicate failure.

[![Built with Devbox](https://www.jetify.com/img/devbox/shield_moon.svg)](https://www.jetify.com/docs/devbox/)
