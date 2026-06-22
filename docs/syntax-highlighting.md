---
title: Syntax Highlighting
---

# Syntax Highlighting

Fenced code blocks with a language hint are syntax-highlighted automatically:

````markdown
```php
echo "Hello world";
```
````

Highlighting happens on the server when the post is saved, so no JavaScript is shipped to visitors and pages without code stay exactly as light as before. Pages render with GitHub-style colours, and the bundled "Notes" (2026) theme switches to a matching dark palette when the visitor prefers dark mode.

Lamb uses [Phiki](https://github.com/phikiphp/phiki), which supports over 200 languages via TextMate grammars — including `html`, `css`, `scss`, `javascript`, `python`, `php`, `shell`, `yaml`, `ini`, and `gdscript`.

Code blocks without a language hint, or with an unrecognised language, are rendered as plain preformatted text.

Posts written before this feature are re-rendered automatically the next time they are viewed.

## Related

* [Post Types]({{ site.baseurl }}{% link post-types.md %}): Posts are written in markdown; fenced code blocks are part of standard markdown.
* [Themes]({{ site.baseurl }}{% link themes.md %}): Custom themes can restyle highlighted blocks via the `.phiki` class.
