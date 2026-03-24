---
title: Themes
---

Lamb comes with two built in themes: `default` and `2024`.

I'm not a designer.

* _Default_ is a traditional blog theme
* _2024_ is a more open modern theme. It's build on top of default.

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

## Theme documentation

* All functions available in theme.php can be reused in the theme.
* A theme does not need to provide every file. Lamb falls back to `src/themes/default/` when a file is missing in the active theme.
* The only file path that is always expected in the active theme is `styles/styles.css`, because `the_styles()` always loads that stylesheet from the selected theme.
* `html.php` is only needed when you want to change the outer page layout.
* `feed.php` is only needed when you want to change the Atom feed output.
* Use the `part($basename)` function to load any other theme includes. This enables a fallback to the default theme's
  files if the file does not exist in the theme. This makes the default theme is a requirement for the 2024 theme.
* CSS stylesheets must be saved in a subfolder of the theme called `styles/` and are loaded using `the_styles()`.
* `the_styles()` takes no arguments and loads `styles/styles.css` from the active theme.
* `the_scripts()` takes no arguments and loads application scripts from `src/scripts/`, not from the active theme directory.
* `the_scripts()` always loads `src/scripts/shorthand.js`.
* Logged-in users additionally get the admin scripts in `src/scripts/logged_in/`.

Have a look at the pre-existing themes for examples of the above.

Any suggestions to improve theming are welcomed.

## Related

* [Site Configuration]({% link site-configuration.md %}): Set the `theme` key in the config to activate a theme.
