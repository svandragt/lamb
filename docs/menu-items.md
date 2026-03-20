# Menu Items

Lamb support menu items through the web settings page, in a section called `menu_items`:

```
[menu_items]
Home = /
About me = /about-me
View Source = https://github.com/svandragt/lamb
```

Menu labels are used as key, whilst the value are the links. When a slug (`/about-me`) is used then the matching post will not be loaded in the timeline (see [Page post-types](./post-types.md#page)).

Links can also point to external resources.

## Related

* [Site Configuration](./site-configuration.md): More information on `config.ini`.
