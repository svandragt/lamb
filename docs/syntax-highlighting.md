---
title: Syntax highlighting
---

# Syntax highlighting

Lamb highlights fenced code blocks that carry a language hint:

````markdown
```php
echo "Hello world";
```
````

Highlighting happens on the server when you save the post, so Lamb ships no JavaScript to visitors and pages without code stay exactly as light as before. Pages render with GitHub-style colours, and the bundled "Notes" (2026) theme switches to a matching dark palette when the visitor prefers dark mode.

Lamb uses [Phiki](https://github.com/phikiphp/phiki), which supports over 200 languages through TextMate grammars, including `html`, `css`, `scss`, `javascript`, `python`, `php`, `shell`, `yaml`, `ini`, and `gdscript`.

Lamb renders code blocks without a language hint, or with an unrecognised language, as plain preformatted text.

Posts you wrote before this feature existed are re-rendered automatically the next time someone views them.

## Related

* [Post types]({{ site.baseurl }}{% link post-types.md %}): You write posts in Markdown, and fenced code blocks are part of standard Markdown.
* [Themes]({{ site.baseurl }}{% link themes.md %}): Custom themes can restyle highlighted blocks through the `.phiki` class.
