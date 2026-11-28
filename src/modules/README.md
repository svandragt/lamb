# Modules

Optional features that plug into core through the hook API (`src/hooks.php`).
Core fires hooks; a module only registers callbacks, so deleting a module's
directory removes the feature without touching core.

## Shape

```
src/modules/<name>/module.php
```

`module.php` returns a callable that registers the module's hooks.
`Hooks\load_modules()` runs it once per request, after the core subscribers,
in directory-name order.

```php
<?php

namespace Lamb\Modules\Example;

use Lamb\Hooks;
use Lamb\Route;

return static function (): void {
    Hooks\add_action('routes.register', static function (): void {
        Route\register_route('hello', __NAMESPACE__ . '\\respond_hello');
    });
    Hooks\add_filter('http.link_headers', static fn(array $links): array =>
        [...$links, '<https://example.com/hub>; rel="hub"']);
    Hooks\add_action('post.published', static function ($bean): void {
        // Side effect for a newly published post.
    });
};
```

The hooks core fires are listed in the `src/hooks.php` docblock.
