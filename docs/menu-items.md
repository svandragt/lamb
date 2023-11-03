# Menu Items

Lamb support menu items through the `src/config.ini` configuration file, in a section called `menu_items`:

```ini
# Example src/config.ini
[menu_items]
Home = ?home
About me = about-me
View Source = https://github.com/svandragt/lamb
```
The keys are the menu labels and the value are the links. When a slug (`about-me`) is used then the matching post will not be loaded in the timeline.
