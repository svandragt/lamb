![Lamb made out of circuitry](src/images/og-image-lamb.webp)

Lamb — Literally Another Micro Blog.

Barrier free super simple blogging, self-hosted.

- Drag or paste an image and Lamb converts it to WebP automatically, no upload step or asset library to manage.
- Tag posts by typing `#hashtag` inline; no taxonomy UI, the tags just appear.
- Full-text search included, no plugin needed and nothing to rebuild.
- Runs on SQLite, so your entire blog is one file – easy to back up, easy to move.
- Drafts and scheduled posts built in, with one-click trash and restore.
- A [Micropub](https://indieweb.org/Micropub) endpoint lets you post from iA Writer, Ulysses, or any IndieWeb-compatible app.

# Getting started

[![Deploy to DO](https://www.deploytodo.com/do-btn-blue.svg)](https://cloud.digitalocean.com/apps/new?repo=https://github.com/svandragt/lamb/tree/release&refcode=5e6e347c4e08)

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
