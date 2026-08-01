---
title: Preconnect
nav_order: 26
---

# Preconnect

Lamb supports preconnect hints through the web settings page, in a section called `preconnect`.

Preconnect hints tell the browser to open a TCP connection to an external origin before it needs one, which reduces latency when a page first requests those resources. Lamb also emits a [`dns-prefetch`](https://developer.mozilla.org/en-US/docs/Web/Performance/dns-prefetch) fallback for browsers that don't support preconnect.

Preconnect hints help most when your theme or content loads resources from external origins, such as a font provider.

```
[preconnect]
google-fonts = https://fonts.googleapis.com
google-fonts-static = https://fonts.gstatic.com
```

Labels are the keys, and the values are the origins: a scheme and host, with no trailing slash.

The preceding configuration emits the following in the HTML `<head>`:

```
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="dns-prefetch" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com">
<link rel="dns-prefetch" href="https://fonts.gstatic.com">
```

## Related

* [Site configuration]({{ site.baseurl }}{% link site-configuration.md %}): More information about the settings page.
