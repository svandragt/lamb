# Code Coverage Tracking

<!-- src-hash: 573e7f76745b69004ba2a7a631b9863f -->

Coverage is measured by `composer coverage` using pcov (CI) on files matching `src/*.php` (excludes `src/index.php` and all `src/response/*.php`, `src/theme/*.php` subdirectory files).

Current threshold: **70%**

## Keeping this document up to date

The `<!-- src-hash: ... -->` comment above is a fingerprint of all `src/*.php` files (excluding `src/index.php`). Run `scripts/check-coverage-doc.sh` to detect drift:

```bash
scripts/check-coverage-doc.sh
```

If the hash has changed (new file added, existing file modified), the script exits non-zero and prints the new hash to embed. Update this doc and the table below, then commit both together.

The check is wired into `composer lint` so CI will catch it automatically.

## Covered files (`src/*.php`)

| File | Key functions | Coverage status |
|------|--------------|-----------------|
| `src/LambDown.php` | `LambDown` class | ✅ LambDownTest.php |
| `src/bootstrap.php` | `bootstrap_db`, `ensure_post_columns` | ✅ ResponseHandlersTest.php; `bootstrap_session` untested (session_start side-effect) |
| `src/config.php` | `load`, `get_ini_text`, `save_ini_text`, `validate_ini`, `get_menu_slugs`, `is_menu_item`, `get_default_ini_text` | ✅ ConfigLoadTest, ConfigValidateTest, ConfigTest |
| `src/constants.php` | constant definitions | ✅ loaded on every test run |
| `src/http.php` | `get_request_uri` | ✅ HttpTest.php |
| `src/lamb.php` | `get_tags`, `parse_tags`, `permalink`, `parse_bean`, `get_option`, `set_option`, `post_has_slug`, `delete_redirect_for_slug`, `find_redirect` | ✅ LambTest, LambHelpersTest, RedirectTest |
| `src/micropub.php` | `LambMicropubAdapter` class methods | ✅ MicropubAdapterTest.php; `respond_micropub`, `respond_micropub_media` untested (exit) |
| `src/network.php` | `get_feeds`, `purge_deleted_posts`, `get_structured_content`, `attributed_content`, `prepare_item`, `create_item`, `update_item` | ✅ NetworkTest, NetworkFeedTest; `process_feeds` untested (exit) |
| `src/post.php` | `populate_bean`, `parse_matter`, `slugify`, `get_tag_search_conditions`, `posts_by_tag` | ✅ PostTest, PopulateBeanTest |
| `src/response.php` | `get_cookie_options`, `build_exclude_slugs_clause`, `build_pagination_meta`, `respond_404`, `upgrade_posts`, `paginate_array`, `paginate_db`, `paginate_posts` | ✅ ResponseTest, ResponseHandlersTest; `redirect_404` untested (die) |
| `src/routes.php` | `register_route`, `call_route`, `is_reserved_route` | ✅ RouteTest.php |
| `src/security.php` | `get_login_url`, `require_login`, `require_csrf` | ✅ SecurityTest.php |
| `src/theme.php` | all public functions | ✅ ThemeTest, ThemeBeanTest, ThemePageTest, ThemeExtendedTest, ThemePreloadTest |

## Not included in coverage measurement (subdirectory files)

These files are tested but **not counted** in the coverage report because `src/*.php` does not recurse:

- `src/response/auth.php` — tested by ResponseAuthTest, ResponseHandlersTest
- `src/response/feeds.php` — tested by ResponseFeedTest, ResponseHandlersTest
- `src/response/posts.php` — tested by ResponsePostsTest, ResponseHandlersTest
- `src/response/upload.php` — tested by UploadTest
- `src/theme/assets.php` — tested by ThemeAssetsTest
- `src/theme/formatting.php` — tested by ThemeTest
- `src/theme/meta.php` — tested by ThemeMetaTest

## Known untestable functions (die/exit/session_start)

| Function | Reason |
|----------|--------|
| `respond_micropub()` | calls `exit` |
| `respond_micropub_media()` | calls `exit` |
| `process_feeds()` | calls `exit` |
| `redirect_404()` | calls `die` |
| `redirect_uri()` | calls `die` |
| `bootstrap_session()` | calls `session_start` — side-effect |

## Opportunities to increase coverage further

1. **Extend `codeception.yml` include to `src/**/*.php`** — would add all subdirectory files to coverage measurement and likely push coverage above 80%
2. **`bootstrap_session()`** — could be tested with process isolation (`@runInSeparateProcess`) at the cost of slower tests
3. **`respond_micropub()`** — the body before `exit` could be tested with output buffering + die replacement, but high effort/low value
