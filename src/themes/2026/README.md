# 2026 — Notes

A Lamb theme designed for a single-author, attention-respecting personal microblog.

## Brief

The site is "Sander van Dragt's Notes": a maker of positive digital experiences, a Senior Web Engineer at Human Made (enterprise WordPress, Altis DXP), WordPress core contributor (5.5 sitemaps). Lamb itself is "bespoke microblogging software that doesn't demand your attention. Frictionless and fast."

That last phrase is the design constraint. Personality has to be quiet, and it has to come from craft, not from chrome or color.

## What it replaces

The starting point was a token-cleanup of the original `2024` theme. That cleanup fixed inconsistencies (duplicate color tokens, mixed spacing units, hardcoded colors) but kept the visual shape: centered reading column with a left rule, yellow nav band, gray-on-white. A faintly 2000s blog template. The brief asked for a real visual modernization on top of that base.

## Register and reflex-checks

Brand register, not product: a personal site where design IS the experience.

First-order reflex for "personal blog" is editorial-typographic (display serif, italic drop caps, broadsheet grid). Rejected: the content is mostly one-line status updates with occasional 400-600 word essays on Linux tooling and AI-assisted development. Magazine grammar is the wrong register.

Second-order reflex for "developer who blogs" is monospace-everything or dark terminal mode. Rejected as costume by default, but partially adopted with intent: the author is genuinely technical, so mono headings read as voice, not costume. The body stays in a humanist sans so reading isn't fatiguing.

## Direction: workshop / worklog

A worklog feel. Time-stamped entries with a calm visual rhythm. The reader should feel they're looking at a journal, not a publication.

Three concrete moves carry the voice:

1. A hanging timestamp in the left margin of every post.
2. Mono headings, humanist sans body.
3. Almost no rules or borders. Whitespace and typography do the dividing work.

## Theme: light

Physical scene: someone reading short notes on a 13-inch laptop or phone, mixed daytime indoor light, on a break from other work; the mood is low-pressure, low-stakes browsing.

That scene forced light, not dark. Dark would have been a category reflex (developer blog) without a forcing reason behind it.

## Color: restrained, warm-tinted, deep amber accent

Strategy: Restrained. Tinted neutrals plus one accent that shows up on under 10% of the surface.

All neutrals are in OKLCH, tinted ~40-70° on the hue wheel (warm, away from blue and toward sand/amber). No raw `#fff` or `#000`. The paper surface is `oklch(98% 0.006 70)`: a near-white with a faint warm tint that reads as paper, not screen.

Accent: deep amber `oklch(56% 0.135 65)`. The first attempt was a brick-red at higher chroma (`oklch(52% 0.17 28)`), which was rejected as too aggressive for a site whose tagline is "doesn't demand your attention." Amber at lower chroma keeps presence without volume.

The accent appears only on: timestamps, link color, focus rings, the current nav indicator, hashtag-chip hover, blockquote curly quotes. No accent backgrounds. No accent panels.

## Typography: Geist Mono and Public Sans

Brand-voice words used to pick fonts: technical, warm, opinionated. Three reflex picks (Inter, Söhne, JetBrains Mono) were checked against the reflex-reject list. Inter is on the list; rejected. Geist Mono and Public Sans were chosen instead.

- Geist Mono for all headings, timestamps, nav, form labels. The "voice" font. Tight letter-spacing on display sizes.
- Public Sans for body. Calm, neutral, humanist enough to read for several hundred words without fatigue.

Atkinson Hyperlegible was briefly considered for body (designed by the Braille Institute, thematic alignment with the "positive digital experiences" framing). It was dropped when feedback confirmed the existing fonts were working.

Type scale uses a 1.25 ratio with a `clamp()`-fluid headline at the top.

## Layout: 2-column grid, hanging timestamp

Each post is a 2-column CSS grid:

- Column 1 (6.5rem): timestamp, right-aligned, mono, amber.
- Column 2: title, paragraphs, images, blockquotes, code, hashtags, logged-in actions.

`align-items: baseline` aligns the timestamp baseline with the first content row. For a titled post, the timestamp baselines with the title. For a status post (no title), it baselines with the first paragraph. The visual balance is the same either way, which solved an earlier problem where the meta felt heavy beside short status posts.

All body content (text, images, blockquotes, code) starts at the same left edge in column 2. An earlier float-based version had the first paragraph wrapping around the floated timestamp; that broke the vertical left edge the rest of the post lined up to. The grid fixes it.

Reading column is capped at 44rem (~65ch body measure) and centered. Generous outer padding via `clamp()`.

On viewports under 600px the grid collapses to a single column; timestamps stack above their post.

## Lines: only two on the whole page

Below the nav, above the footer. That's it.

Removed in this overhaul: per-post border-bottom, dashed `<small>` divider, h1 underline, entry-form panel border, related-posts top border, pagination current-page border, flash background and border, search input border.

The reasoning: each rule the eye crosses is a small attention demand. The brief explicitly asks the design not to demand attention. Vertical rhythm and typographic hierarchy do the dividing work that borders used to.

## Author hidden from per-post meta

Lamb is a single-author engine. Showing the author's name on every post is repetition that adds noise. The `<strong itemprop="author">` markup stays in the HTML for schema.org/BlogPosting, but is moved to `.screen-reader-text` so it doesn't render visually. Crawlers still see it; readers don't.

## Personality details that don't shout

Small, deliberate touches that reward attention but don't ask for it:

- Hashtags get a soft `paper-raised` pill background, mono font. Reads as a tag, not as a link.
- Blockquote opens and closes with accent-colored curly quotes via `::before` / `::after`.
- `<hr>` renders as a `* * *` asterism in mono, letter-spaced. Old-typography pause-mark instead of a flat line.
- Menu items in the nav are separated by a faint `·` glyph rather than padding alone.
- Current nav item gets a 2px amber underline that sits just under the text, not a background fill.
- Post titles get a hover underline that animates in via `background-size` (allowed: not animating a layout property).

## Files

- `html.php`: overrides the default to add Geist Mono + Public Sans via Google Fonts and a slightly tightened nav.
- `styles/styles.css`: full visual definition.
- `parts/_items.php`: minimal override to move the author into `screen-reader-text`.

Everything else falls back to `themes/base/`.

## Activate

Edit the INI config at `/settings` and set:

```ini
theme = 2026
```

## Trade-offs and known limits

- **Google Fonts dependency.** `html.php` loads Geist Mono and Public Sans from `fonts.googleapis.com`. That's an external request and a privacy consideration for a self-hosted blog. Drop the link from `html.php` and the design falls back to `ui-monospace` and `system-ui`, which works but loses the typographic personality. Self-hosting the woff2 files in `themes/2026/styles/fonts/` is the better long-term fix.
- **`display: contents` on `<header>`.** Required to make grid placement work without changing the markup. Modern browsers handle this correctly; older screen readers might lose the `<header>` landmark, but in this template the header only contains the title and timestamp, so the impact is low.
- **Long human-readable timestamps.** The 6.5rem time column comfortably fits "Monday at 2:15 pm" but will wrap on anything longer. Acceptable; longer values are uncommon.
- **Single-post pages** (`.status`, `.post`) intentionally bypass the grid. The `<h1>` already carries the title, and a one-article page doesn't need a gutter to balance against. The CSS sets `display: block` on `.status article` and `.post article`.
