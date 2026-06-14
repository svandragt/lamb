---
title: Menu Items
---

# Menu Items

Lamb support menu items through the web settings page, in a section called `menu_items`:

```
[menu_items]
Home = /
About me = /about-me
View Source = https://github.com/svandragt/lamb
```

Menu labels are used as key, whilst the value are the links. When a slug (`/about-me`) is used then the matching post will not be loaded in the timeline (see [Page post-types]({{ site.baseurl }}{% link post-types.md %}#page)).

New installs ship with two menu items by default: `Home = /` and `Feed = /feed`. Remove or change them on the settings page if you don't want them.

Links can also point to external resources.

## Related

* [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}): More information on the settings page.
