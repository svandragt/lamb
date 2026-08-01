---
title: Menu items
nav_order: 23
---

# Menu items

Lamb supports menu items through the web settings page, in a section called `menu_items`:

```
[menu_items]
Home = /
About me = /about-me
View Source = https://github.com/svandragt/lamb
```

Menu labels are the keys, and the values are the links. When you use a slug such as `/about-me`, Lamb doesn't load the matching post in the timeline. See [Page post types]({{ site.baseurl }}{% link post-types.md %}#page).

New installs ship with two menu items: `Home = /` and `Feed = /feed`. Remove or change them on the settings page if you don't want them.

Links can also point to external resources.

## Footer items

The 2026 theme also supports a `[footer_items]` section for secondary navigation links, which it renders in the page footer. It uses the same format as `[menu_items]`, but footer links don't hide matching posts from the timeline.

```
[footer_items]
Privacy = /privacy
Source = https://github.com/svandragt/lamb
```

## Related

* [Site configuration]({{ site.baseurl }}{% link site-configuration.md %}): More information about the settings page.
