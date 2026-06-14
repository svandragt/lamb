---
title: Theme Functions
---

Lamb ships a small library of helper functions that theme parts call to render
posts, titles, navigation, and the page `<head>`. This page is the reference for
theme authors. For how themes are structured and selected, see
[Themes]({{ site.baseurl }}{% link themes.md %}).

## Using the helpers

The helpers live in the `Lamb\Theme` namespace. Import each one with a
`use function` statement at the top of the part before you call it:

```php
<?php
use function Lamb\Theme\site_title;
use function Lamb\Theme\part;
use function Lamb\Theme\escape;
?>
<?= site_title() ?>
```

The built-in themes (`base`, `2024`, `2026`) are the best worked examples — copy
the `use function` lines from the part you are overriding.

## Globals available in every part

Every part is included from `html.php`, so these variables are always in scope:

| Variable | Type | Contents |
|----------|------|----------|
| `$config` | `array` | Site config: `site_title`, `author_name`, `author_email`, `menu_items`, `feeds`, `theme`, … |
| `$data` | `array` | Route-specific data, including `$data['posts']` (an array of post beans), `$data['pagination']`, `$data['title']`, and `$data['intro']` |
| `$template` | `string` | Current template name (`home`, `status`, `tag`, `search`, `404`, …) |

Each entry in `$data['posts']` is a post bean. The properties you will use most:
`$bean->title`, `$bean->transformed` (pre-rendered HTML — render this, never
re-render `body`), `$bean->description`, `$bean->created`, `$bean->updated`,
`$bean->slug`, and `$bean->id`.

## Titles and intro

| Function | Returns | Description |
|----------|---------|-------------|
| `site_title($type = 'html')` | `string` | The site title. Wrapped in `<h1>` for HTML output, or plain text when `$type` is not `'html'`. |
| `page_title($type = 'html')` | `string` | The current page title (`$data['title']`), falling back to the site title. |
| `site_or_page_title($type = 'html')` | `string` | The page title when one is set, otherwise the site title. |
| `page_intro()` | `string` | A `<p>` wrapping `$data['intro']` (used on tag and search pages), or `''`. |

## Posts and content

| Function | Returns | Description |
|----------|---------|-------------|
| `date_created($bean)` | `string` | An `<a>` wrapping a `<time>` element, linking to the post permalink with a human-readable timestamp. `''` when the bean has no created date. |
| `title_link($bean)` | `string` | An `<a>` linking the post title to its permalink, or `''` when the post has no title. |
| `link_source($bean)` | `string` | A "Via …" attribution link for feed-ingested posts (uses `source_url`, falling back to the configured feed URL), or `''` for ordinary posts. |
| `the_reply_context($bean)` | `string` | A "In reply to …" line with the `u-in-reply-to` microformats class when the post replies to another URL, or `''`. |
| `anchor_headings($html, $top)` | `string` | Shifts the heading levels in a rendered body so its highest heading sits at level `$top`, keeping the rest relative (clamped at `<h6>`). The built-in themes title posts at `<h2>` and pass `$top = 3`. |
| `related_posts($body, $exclude_id = 0)` | `array` | `['posts' => OODBBean[]]` — posts that share a hashtag with `$body`, excluding `$exclude_id`. |
| `human_time($timestamp)` | `string` | A relative time string ("3 hours ago", "Yesterday at 2:15 pm", or an absolute date for older posts). Takes a Unix timestamp. |

## Navigation

| Function | Returns | Description |
|----------|---------|-------------|
| `li_menu_items()` | `string` | `<li><a>` markup for each entry in `$config['menu_items']`, or `''` when none are configured. |

## Admin actions (logged-in only)

These return `''` for anonymous visitors, so they are safe to call
unconditionally in a part — the admin controls only appear when you are
logged in.

| Function | Returns | Description |
|----------|---------|-------------|
| `action_edit($bean)` | `string` | An "Edit" button for the post. |
| `action_delete($bean)` | `string` | A delete `<form>` (with CSRF token) for the post. |
| `action_restore($bean)` | `string` | A "Restore post" `<form>` — used on the Trash view. |
| `action_preview($bean)` | `string` | A shareable "Preview" link for an unpublished (draft or scheduled) post that has a valid preview token. |
| `the_entry_form()` | `void` | Echoes the quick-post `<form>` used to create posts. |
| `csrf_token()` | `string` | The current session CSRF token, creating one if needed. Include it in any `<form>` that POSTs. |

## Assets and the `<head>`

| Function | Returns | Description |
|----------|---------|-------------|
| `the_styles()` | `void` | Emits the stylesheet. It always loads `styles/styles.css` from the active theme — small stylesheets are inlined, larger ones linked with a cache-busting hash. |
| `the_scripts()` | `void` | Emits the application `<script>` tags from `src/scripts/`; logged-in users also get the admin scripts. It does **not** load scripts from the theme directory. |
| `the_opengraph()` | `void` | Emits OpenGraph/Twitter `<meta>` tags (status pages only). |
| `the_preconnect()` | `void` | Emits `<link rel="preconnect">` tags for the origins in `$config['preconnect']`. |
| `the_reply_context($bean)` | `string` | See "Posts and content" above. |

## Parts and includes

| Function | Returns | Description |
|----------|---------|-------------|
| `part($name, $dir = 'parts')` | `void` | Includes a theme part, falling back to the base theme when the active theme does not provide it. Pass `$dir = ''` for top-level files such as `html`. `$name` and `$dir` are sanitised to `[a-zA-Z0-9-_]`. |

```php
<?php use function Lamb\Theme\part; ?>
<?php part('_items'); ?>          <!-- parts/_items.php -->
<?php part('html', ''); ?>        <!-- html.php (top level) -->
```

## Utility

| Function | Returns | Description |
|----------|---------|-------------|
| `escape($str)` | `string` | `htmlspecialchars` for HTML5 output. Call it on every user-supplied value you print. |
| `redirect_to()` | `string` | The sanitised `?redirect_to=` query value (used by the login form). |
| `preload_text()` | `string` | The escaped `?text=` query value, used to pre-fill the entry form. |

## Related

* [Themes]({{ site.baseurl }}{% link themes.md %}): how themes are structured, the fallback to `base`, and the part resolution rules.
* [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}): the `theme` key and the config values exposed in `$config`.
* [Post Types]({{ site.baseurl }}{% link post-types.md %}): how status posts and page-style posts differ, which affects `$bean->slug` and permalinks.
