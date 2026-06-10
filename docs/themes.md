---
title: Themes
---

Lamb comes with three built-in themes: `base`, `2024`, and `2026`.

I'm not a designer.

* _2026_ is a worklog-style theme: light, warm-tinted, deep-amber accent, mono headings on a humanist sans body. Designed for a calm, attention-respecting personal microblog. **New installs use this theme by default.**
* _Base_ is a traditional blog theme. It also acts as the fallback theme: any file an active theme does not provide is loaded from here.
* _2024_ is a more open modern theme. It's built on top of base.

Existing sites keep whatever theme they already use; only fresh installs start on `2026`.

To switch between themes, set the `theme` key in the site configuration at `/settings`:

```ini
theme = 2024
```

Lamb also supports user themes in the same way. Simply create your own theme directory and assign it to the `theme` key.
It's recommended to version control your theme as it's own git repo. This allows you to update Lamb and your theme
separately using git.

## Screenshots

Default:
![theme-default](https://github.com/user-attachments/assets/3d80d860-b54c-4d64-ad7b-7c548157e610)


---

2024:
![theme-2024](https://github.com/user-attachments/assets/b9f55c5c-9d48-4357-a41f-ed71d21c0b0c)

---

2026:
![theme-2026]({{ site.baseurl }}/2026-theme.png)

## Theme documentation

* All functions available in theme.php can be reused in the theme.
* A theme does not need to provide every file. Lamb falls back to `src/themes/base/` when a file is missing in the active theme.
* The only file path that is always expected in the active theme is `styles/styles.css`, because `the_styles()` always loads that stylesheet from the selected theme.
* `html.php` is only needed when you want to change the outer page layout.
* `feed.php` is only needed when you want to change the Atom feed output.
* Use the `part($basename)` function to load any other theme includes. This enables a fallback to the base theme's
  files if the file does not exist in the theme. This makes the base theme a requirement for the 2024 theme.
* CSS stylesheets must be saved in a subfolder of the theme called `styles/` and are loaded using `the_styles()`.
* `the_styles()` takes no arguments and loads `styles/styles.css` from the active theme.
* `the_scripts()` takes no arguments and loads application scripts from `src/scripts/`, not from the active theme directory.
* `the_scripts()` always loads `src/scripts/shorthand.js`.
* Logged-in users additionally get the admin scripts in `src/scripts/logged_in/`.
* Post bodies are stored at the author's literal heading levels (`#` → `<h1>`, `##` → `<h2>`, …). A theme decides where those headings sit in its own outline at render time with `demote_headings($bean->transformed, $top)`: it shifts the body so its highest heading lands at level `$top`, keeping the rest relative (clamped at `<h6>`) so the outline stays in order for screen readers. The built-in themes render the post title at `<h2>` and pass `$top = 3`, so the body's first heading becomes `<h3>` whether the author wrote `#` or `##`. A theme that titles posts differently passes a different level, and one that wants the literal levels can echo `$bean->transformed` without demoting.

Have a look at the pre-existing themes for examples of the above.

Any suggestions to improve theming are welcomed.

## Related

* [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}): Set the `theme` key in the config to activate a theme.
