---
title: Themes
---

# Themes

Lamb comes with three built-in themes: `base`, `2024`, and `2026`.

I'm not a designer.

* _2026_ is a worklog-style theme: light, warm-tinted, deep-amber accent, mono headings on a humanist sans body. It's designed for a calm, attention-respecting personal microblog. **New installs use this theme by default.**
* _Base_ is a traditional blog theme. It also acts as the fallback theme: Lamb loads any file the active theme doesn't provide from here.
* _2024_ is a more open modern theme, built on top of base.

Existing sites keep whatever theme they already use. Only fresh installs start on `2026`.

To switch between themes, set the `theme` key in the site configuration at `/settings`:

```ini
theme = 2024
```

Lamb supports user themes in the same way. Create your own theme directory and assign it to the `theme` key.
Version-control your theme as its own Git repo, so that you can update Lamb and your theme
separately.

## Screenshots

Default:
![theme-default](https://github.com/user-attachments/assets/3d80d860-b54c-4d64-ad7b-7c548157e610)


---

2024:
![theme-2024](https://github.com/user-attachments/assets/b9f55c5c-9d48-4357-a41f-ed71d21c0b0c)

---

2026:
![theme-2026]({{ site.baseurl }}/2026-theme.png)

## Write a theme

* You can reuse every function in theme.php in your theme.
* A theme doesn't need to provide every file. Lamb falls back to `src/themes/base/` when the active theme is missing a file.
* The only file path the active theme always needs is `styles/styles.css`, because `the_styles()` always loads that stylesheet from the selected theme.
* You only need `html.php` when you want to change the outer page layout.
* You only need `feed.php` when you want to change the Atom feed output.
* Use the `part($basename)` function to load any other theme includes. This falls back to the base theme's
  files when the file doesn't exist in your theme, which makes the base theme a requirement for the 2024 theme.
* Save CSS stylesheets in a theme subfolder called `styles/`. `the_styles()` loads them.
* `the_styles()` takes no arguments and loads `styles/styles.css` from the active theme.
* `the_scripts()` takes no arguments and loads application scripts from `src/scripts/`, not from the active theme directory.
* `the_scripts()` always loads `src/scripts/shorthand.js`.
* Logged-in users also get the admin scripts in `src/scripts/logged_in/`.
* Lamb stores post bodies at the author's literal heading levels, so `#` becomes `<h1>`, `##` becomes `<h2>`, and so on. A theme decides where those headings sit in its own outline at render time with `anchor_headings($bean->transformed, $top)`. That function shifts the body so its highest heading lands at level `$top`, keeping the rest relative and clamped at `<h6>`, so the outline stays in order for screen readers. The shift is signed: it pulls up a body written deeper than `$top` just as it pushes down a shallower one, so the level the author typed is immaterial. The built-in themes render the post title at `<h2>` and pass `$top = 3`, so the body's first heading always becomes `<h3>`. A theme that titles posts differently passes a different level, and one that wants the literal levels can echo `$bean->transformed` without anchoring.

* Images inside `$bean->transformed` carry intrinsic `width` and `height` attributes (see [Media]({{ site.baseurl }}{% link media.md %})), so a theme's stylesheet **must** leave the aspect ratio free. If you constrain image width with the usual `img { max-width: 100% }`, pair it with `height: auto`. Otherwise the browser squashes images wider than their container to the attribute's height. All three built-in themes set both.

For examples of all of this, look at the existing themes.

Suggestions to improve theming are welcome.

## Related

* [Theme functions]({{ site.baseurl }}{% link theme-functions.md %}): Reference for the helper functions theme parts call.
* [Media]({{ site.baseurl }}{% link media.md %}): Rendered images carry intrinsic dimensions that a theme's CSS has to accommodate.
* [Site configuration]({{ site.baseurl }}{% link site-configuration.md %}): Set the `theme` key in the config to activate a theme.
