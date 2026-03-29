---
title: Preconnect
---

Lamb supports preconnect hints through the web settings page, in a section called `preconnect`.

Preconnect hints tell the browser to establish a TCP connection to an external origin before it is needed, reducing latency when those resources are first requested. A [`dns-prefetch`](https://developer.mozilla.org/en-US/docs/Web/Performance/dns-prefetch) fallback is also emitted for browsers that do not support preconnect.

This is most useful when your theme or content loads resources from external origins such as a font provider.

```
[preconnect]
google-fonts = https://fonts.googleapis.com
google-fonts-static = https://fonts.gstatic.com
```

Labels are used as keys, while the values are the origins (scheme + host, no trailing slash).

The above configuration emits the following in the HTML `<head>`:

```
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="dns-prefetch" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com">
<link rel="dns-prefetch" href="https://fonts.gstatic.com">
```

## Related

* [Site Configuration]({% link site-configuration.md %}): More information on `config.ini`.
