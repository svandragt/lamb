# Product

## Register

brand

## Users

A single author publishing short status posts and occasional 400-600 word essays on Linux tooling and AI-assisted development. Readers arrive from feeds, links, and search; their context is mixed daytime reading on a 13" laptop or phone, on a break from other work. Mood is low-pressure, low-stakes browsing.

The site is "Sander van Dragt's Notes": a Senior Web Engineer at Human Made (enterprise WordPress, Altis DXP), WordPress core contributor (5.5 sitemaps), and maker of positive digital experiences.

## Product Purpose

Lamb is bespoke microblogging software that "doesn't demand your attention. Frictionless and fast." The 2026 "Notes" theme is a personal-site instance of that philosophy: a worklog. Time-stamped entries with a calm visual rhythm. The reader should feel they're looking at a journal, not a publication.

Success: the design recedes. The text and timestamps carry the experience. Nothing on the page asks for attention it didn't earn.

## Brand Personality

Three words: **technical, warm, opinionated**.

Voice: quiet craftsmanship. Personality comes from typographic decisions and restraint, not from chrome, color, or motion. The author is genuinely technical; mono headings read as voice, not costume.

Emotional goal: calm presence. Like a well-kept notebook, not a publication or a product.

## Anti-references

- **Editorial-magazine grammar.** Display serif, italic drop caps, broadsheet grids. Wrong register for short status updates.
- **Developer-blog dark mode by default.** Costume, not voice. The scene (daytime, mixed light, break time) doesn't force dark.
- **Inter, Söhne, JetBrains Mono.** First-reflex picks for "technical and modern"; on the saturated list.
- **2000s blog template.** Yellow nav band, centered column with a left rule, gray-on-white. What the previous 2024 theme was. The 2026 overhaul exists to move past this.
- **SaaS chrome.** Card grids, hero-metric layouts, gradient accents, bordered panels, glassmorphism.
- **High-chroma accents that shout.** An earlier brick-red `oklch(52% 0.17 28)` was rejected as too aggressive for a site whose tagline is "doesn't demand your attention."

## Design Principles

1. **Doesn't demand attention.** Every rule, panel, border, or color block must justify itself. The default answer is no.
2. **Personality from craft, not chrome.** Hanging timestamps, asterism `hr`, curly-quote blockquotes, mono headings, separator dots between nav items. Quiet details that reward attention but don't ask for it.
3. **Whitespace and typography do the dividing work.** Two horizontal rules on the entire page (under nav, above footer). That's it.
4. **Warm, not cold.** Tinted neutrals toward sand and amber; never raw `#fff` or `#000`. Light theme because the physical scene forces it, not because light is "safe."
5. **Single-author, single-purpose.** Author name hidden from per-post meta (kept in schema markup for crawlers). Repetition is noise.

## Accessibility & Inclusion

- WCAG AA target. Atkinson Hyperlegible was briefly considered; rejected when feedback confirmed existing fonts were working.
- `prefers-reduced-motion` honored: transitions and animations clamped to 0.01ms.
- `.screen-reader-text` utility for visually hidden but crawlable content (author name, skip links).
- Focus indicators visible (`:focus-visible` with 2px amber outline + offset).
- Single-column collapse under 600px; timestamps stack above their post.
- Known limitation: `display: contents` on `<header>` may lose the landmark role in older screen readers. Accepted because the header only contains title and timestamp in this template.
