---
title: Menu Items
parent: Site customisation
---

# Menu Items

Lamb supports menu items through the **Menu links** box on the settings page, one `label = value` entry per line:

```
Home = /
About me = /about-me
View Source = https://github.com/svandragt/lamb
```

The label is the text of the link, and the value is where it goes. When a slug (`/about-me`) is used then the matching post will not be loaded in the timeline (see [Page post-types]({{ site.baseurl }}{% link post-types.md %}#page)).

New installs ship with two menu items by default: `Home = /` and `Feed = /feed`. Remove or change them on the settings page if you don't want them.

Links can also point to external resources.

## Footer items

The 2026 theme also supports footer links for secondary navigation, rendered in the page footer. Add them to the **Footer links** box in the same format as menu links, but footer links do **not** hide matching posts from the timeline.

```
Privacy = /privacy
Source = https://github.com/svandragt/lamb
```

## Related

* [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}): More information on the settings page.
