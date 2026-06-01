# Design

Visual system for the Lamb 2026 "Notes" theme. Captures the tokens, type, layout, and component grammar so future variants stay on-brand.

## Theme

**Light by default; dark via `prefers-color-scheme`.** Light is forced by the physical scene: someone reading short notes on a 13" laptop or phone in mixed daytime indoor light, on a break from other work. Mood is low-pressure browsing. Dark wasn't picked as the category reflex (developer blog) but is provided as an opt-in OS-level preference. Dark-mode neutrals stay warm-tinted (60° hue) so the variant reads as evening, not cold.

## Color

**Strategy: Restrained.** Tinted warm neutrals plus one accent that appears on under 10% of the surface. No accent backgrounds, no bordered panels, no color blocks.

All values in OKLCH, tinted 50-70° on the hue wheel (warm, away from blue, toward sand and amber).

### Tokens (light mode)

| Token | Value | Role |
|---|---|---|
| `--paper` | `oklch(98% 0.006 70)` | Page background. Near-white with faint warm tint, reads as paper not screen. |
| `--paper-raised` | `oklch(95% 0.010 70)` | Subtle elevation: code, inputs, hashtag chips. |
| `--ink` | `oklch(22% 0.02 50)` | Primary text. Not pure black. |
| `--ink-soft` | `oklch(50% 0.025 55)` | Secondary text, nav, blockquote, h4-h6, related excerpt. |
| `--ink-faint` | `oklch(58% 0.025 55)` | Tertiary text: footer, edit/delete row, related h6 label, nav `·` separator. |
| `--rule` | `oklch(88% 0.012 70)` | The two horizontal lines on the page. |
| `--accent` | `oklch(56% 0.135 65)` | Graphical only: focus ring, current-nav 2px underline, 1px title-link underline, `--accent-tint` pair. |
| `--accent-link` | `oklch(46% 0.140 60)` | Text-bearing: `a`, timestamps, blockquote quotes, flash, hashtag-hover, related-time, post `.meta`, every hover text color. |
| `--accent-hover` | `oklch(38% 0.140 58)` | Link hover, primary button hover. |
| `--accent-tint` | `oklch(94% 0.045 70)` | Hashtag-chip hover background only. |
| `--selection` | `oklch(89% 0.090 75)` | Text selection. |

Accent rule: any amber that *carries text* uses `--accent-link`; any amber that is a graphical object (focus ring, 2px nav underline, 1px title underline) uses `--accent`. No accent backgrounds. No accent panels.

### Tokens (dark mode)

Engaged via `@media (prefers-color-scheme: dark)`. Same hue family, inverted lightness, amber kicks up in brightness for legibility on dark.

| Token | Value |
|---|---|
| `--paper` | `oklch(20% 0.012 60)` |
| `--paper-raised` | `oklch(25% 0.014 60)` |
| `--ink` | `oklch(92% 0.012 70)` |
| `--ink-soft` | `oklch(75% 0.020 65)` |
| `--ink-faint` | `oklch(60% 0.020 65)` |
| `--rule` | `oklch(32% 0.015 60)` |
| `--accent` | `oklch(74% 0.140 70)` |
| `--accent-link` | `oklch(82% 0.130 72)` |
| `--accent-hover` | `oklch(88% 0.115 75)` |
| `--accent-tint` | `oklch(28% 0.040 70)` |
| `--selection` | `oklch(38% 0.110 75)` |

### Earlier rejection

`oklch(52% 0.17 28)` (brick-red, higher chroma) was tried and rejected as too aggressive for a "doesn't demand your attention" brief. Amber at lower chroma keeps presence without volume.

## Typography

Brand voice: technical, warm, opinionated.

| Family | Stack | Use |
|---|---|---|
| Geist Mono | `"Geist Mono", "JetBrains Mono", ui-monospace, "SF Mono", Menlo, monospace` | All headings, timestamps, nav, form labels, flash, footer, hashtags. The "voice" font. |
| Public Sans | `"Public Sans", "Source Sans 3", system-ui, sans-serif` | Body. Calm, neutral, humanist enough to read for several hundred words. |

**Self-hosted woff2** under `themes/2026/styles/fonts/`. Subsets cover Latin + Latin-Extended; `font-display: swap` on every face. No third-party fetch; stack falls back to `ui-monospace` and `system-ui` if the woff2 files are missing.

### Scale

1.25 ratio, fluid at the top:

| Token | Value |
|---|---|
| `--text-xs` | `0.75rem` |
| `--text-sm` | `0.875rem` |
| `--text-base` | `1.0625rem` |
| `--text-md` | `1.1875rem` |
| `--text-lg` | `1.5rem` |
| `--text-xl` | `clamp(1.6rem, 1.3rem + 1vw, 2rem)` |

Body: `--leading: 1.65`. Headings: `--leading-tight: 1.2`.

Heading letter-spacing: `-0.01em` (subheads), `-0.02em` (h1, tighter on display sizes). Small caps-feel via `letter-spacing: 0.02em` + `--ink-soft` on h4-h6.

## Spacing

Scale (rem-based, eyeballed to a roughly geometric progression):

| Token | Value |
|---|---|
| `--s-1` | `0.25rem` |
| `--s-2` | `0.5rem` |
| `--s-3` | `0.75rem` |
| `--s-4` | `1rem` |
| `--s-5` | `1.5rem` |
| `--s-6` | `2rem` |
| `--s-7` | `3rem` |
| `--s-8` | `4.5rem` |

Vary spacing for rhythm. Larger steps (`--s-7`, `--s-8`) separate sections (post-to-post, related-posts top, footer top). Smaller steps tighten within an entry.

## Layout

### Reading column

- `main` max-width: `44rem` (≈65ch body measure).
- Outer padding: `clamp(var(--s-4), 5vw, var(--s-7))`.
- Centered, no container wrapper outside `main`.

### Post grid (signature pattern)

Each post is a 2-column CSS grid:

| Column | Width | Content |
|---|---|---|
| 1 | `--time-col` (`6.5rem`) | Timestamp. Right-aligned. Mono. Amber. |
| 2 | `minmax(0, 1fr)` | Title, paragraphs, images, blockquotes, code, hashtags, logged-in actions. |

Gap: `--time-gap` (= `--s-5`).

`align-items: baseline` aligns the timestamp baseline with the first content row. Works identically whether or not a title is present, which solved the "meta feels heavy beside short status posts" problem.

`article > header { display: contents; }` lets the header's children participate directly in the grid without changing the markup.

### Single-post bypass

`.status article` and `.post article` use `display: block`. The `<h1>` already carries the title; a one-article page doesn't need a gutter.

### Narrow viewports (≤599px)

Grid collapses to a single column. Timestamps stack above their post, left-aligned.

### Two lines on the entire page

`border-bottom` on `nav`, `border-top` on `footer`. That's it. No per-post dividers, no panel borders, no h1 underline, no entry-form border, no flash border, no search-input border. Whitespace and type carry the dividing work.

## Radii

| Token | Value | Role |
|---|---|---|
| `--radius` | `3px` | Code blocks, inputs, primary buttons. |
| `--radius-sm` | `2px` | Hashtag chips, focus-ring rounding. |

## Components

### Nav

- Bottom border `1px solid var(--rule)`, mono, small.
- Items separated by a faint `·` glyph (`::before` on non-first children), not by padding alone.
- Current item: `--ink` color + a 2px amber underline absolute-positioned under the text (not a background fill).
- Search input on the right; `flex: 1; justify-content: flex-end`. Wraps to full width under 600px.

### Article header

- Mono `<h2>` title; `font-weight: 600`, `letter-spacing: -0.01em`.
- Title hover: `background-image` linear-gradient amber underline animating `background-size: 0% 1px → 100% 1px` over 260ms ease-out-quart. Allowed motion: not a layout property.
- `.meta` timestamp in grid column 1, mono, `--text-xs`, amber, right-aligned, `letter-spacing: 0.01em`.

### Body content

- Paragraphs: `margin: 0.85em 0`.
- Blockquote: italic, `--ink-soft`, no left border. Opens/closes with amber `"` `"` via `::before` / `::after` on `<p>`.
- `<code>`: mono `0.9em/1.4`, `--paper-raised` background, `0.1em 0.35em` padding, `3px` radius.
- `<pre>`: same background, `--s-4` padding, `--s-5` vertical margin, overflow-x auto.
- `<hr>`: no border. Renders an asterism `* * *` in mono, `letter-spacing: 0.6em`, `--ink-faint`, `--s-7` vertical margin.
- Lists: `padding-left: var(--s-5)`, `li { margin: var(--s-2) 0 }`.

### Hashtag chip

- Mono, `0.85em`, `--ink-soft`, `--paper-raised` background, `0.1em 0.4em` padding, `2px` radius.
- Hover: `--accent` color, `--accent-tint` background.

### Logged-in action row

- `<small>` block, mono, `--text-xs`, `--ink-faint`.
- Inline forms; buttons styled as link-like text (no background, `--ink-faint`, hover `--accent`).

### Related posts

- Top border `1px solid var(--rule)` (the only per-post divider, scoped to this block).
- `h6` label: mono, xs, uppercase, `letter-spacing: 0.08em`, `--ink-faint`.
- Items reuse the post grid: amber timestamp on the left, title + single-line excerpt on the right.
- Titles and excerpts clamped to one line with ellipsis on desktop; two-line clamp on narrow viewports.

### Entry form (logged-in only)

- No panel, no border around the section.
- `<textarea>`: mono `--text-sm`, `--paper-raised` background, transparent 1px border, focus changes border to `--accent` and background to `--paper`.
- Primary `<button>` / `input[type="submit"]`: mono 500, `--paper` text on `--ink` background, `3px` radius, `--s-3 --s-4` padding. Hover: background → `--accent-hover`.

### Pagination

- Centered, `--s-7` top margin, no background, no border.
- Separator dots from nav suppressed inside `nav.pagination`.
- Current page: bold, `--ink`; underline removed.

### Flash

- Mono `--text-sm`, `--accent-hover` text, no background, no border, no padding-box.

### Footer

- Mono `--text-xs`, `--ink-faint`, centered.
- Top border `1px solid var(--rule)` (the second of the two page lines).

## Motion

Single easing curve across the system: `--ease-out-quart` = `cubic-bezier(0.22, 1, 0.36, 1)`.

| Token | Value | Where |
|---|---|---|
| `--t-fast` | `140ms` | Color, background, border transitions on links, buttons, inputs, nav items, hashtag chips. |
| `--t-slow` | `260ms` | `background-size` on the title-link underline reveal. |

No layout-property animations. No bounce, no elastic. `prefers-reduced-motion: reduce` clamps all durations to `0.01ms`.

## Accessibility

- WCAG AA target.
- `:focus-visible`: `2px solid var(--accent)` outline, `2px` offset, `2px` border-radius on generic elements.
- `.screen-reader-text` utility for visually hidden but crawlable content (author name kept for schema.org/BlogPosting; skip-link support on `:focus`).
- Single-column collapse at 599px; timestamps stack, related-link items expand to two-line clamp.
- Known limitation: `display: contents` on `<header>` may suppress the landmark role in older screen readers. Accepted; the header only contains title + timestamp in this template.

## File map

- `themes/2026/html.php` — HTML shell, Google Fonts link, tightened nav markup.
- `themes/2026/styles/styles.css` — full visual definition.
- `themes/2026/parts/_items.php` — moves `<strong itemprop="author">` into `.screen-reader-text`.
- Everything else falls back to `themes/default/`.

## Activation

```ini
theme = 2026
```

In the INI config at `/settings`.
