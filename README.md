# Singular Markdown

WordPress plugin (theme-agnostic) that:

- Caches a Markdown representation of each **eligible** singular URL under `wp-content/uploads/singular-markdown-cache/`.
- Serves it at the same path as the HTML permalink with `.md` appended (rewrite rules), for example `/about/` → `/about.md`.
- Adds both an HTTP `Link` header and a `<link rel="alternate" type="text/markdown" href="...">` tag in `<head>` on eligible HTML singular/archive responses.
- Queues regeneration on save, trash, delete, and supports background full regeneration from **Settings → Singular Markdown**.

After install or update, open **Settings → Permalinks** and click **Save** once if `.md` URLs return 404 (rewrite flush).

## How Markdown is generated

1. **Fetch HTML** — `GET` the public permalink (timeout: **Settings → Singular Markdown → Markdown generation → HTML fetch timeout**).
2. **Pick main fragment** — first matching selector from **Main content selectors** (plus built-in fallbacks), or fall back to filtered `post_content`.
3. **Strip noise** — remove nodes matching the built-in list plus **Extra strip selectors**, then apply the `singular_markdown_excluded_selectors` filter.
4. **Convert** — page title as `#` heading, duplicate leading `h1` removed when it matches the title, block HTML → Markdown, then the `singular_markdown_output` filter.

Image conversion supports common lazy-loading attributes when the rendered `src` is a placeholder. It checks normal `src`, NitroPack's `nitro-lazy-src` / `data-nitro-lazy-src`, common `data-*` lazy attributes, and srcset-style fallbacks before emitting Markdown image syntax. NitroPack CDN and `nitropack_static` optimized URLs are normalized back to their original `wp-content/uploads` media URLs when the source path is embedded in the optimized URL.

Automatic conversion runs in scheduled background jobs. Public `.md` requests do **not** run HTML fetching or conversion synchronously: if no cache exists yet, the request queues generation and returns `503 Retry-After`; if stale cache exists, stale Markdown is served while regeneration is queued.

Archive/home Markdown (for example `/blog.md` or category archive `.md` URLs) is generated from the archive query: `# Archive Title`, then a list of eligible posts with `## [Post Title](permalink)` and each post excerpt/trimmed content. Archive caches use a short TTL and regenerate in the background.

## Per-post custom Markdown

For each **included** post type (same scope as automatic Markdown), the editor shows a **Singular Markdown** meta box:

- **Automatic (from HTML)** — default; Markdown is built from the public HTML of the permalink.
- **Custom Markdown** — when selected and the textarea is **non-empty**, that body is served at the `.md` URL (after `singular_markdown_output`). The file cache is updated on save so batch jobs stay consistent.

If **Custom** is selected but the textarea is **empty**, the choice is stored as **automatic** (same as HTML conversion).

## Eligibility (indexability)

By default, Markdown is generated only for **published**, **non–password-protected** posts that have a permalink and pass layered checks:

- **Post type**: built-in **`post`** and **`page`** are always candidates when the type is `public` (even if another plugin sets `publicly_queryable` to false for them). Other types match WordPress `public` + `publicly_queryable` and are not blocked internally; you can still exclude types in settings.
- **Excluded post IDs** (settings).
- **Excluded taxonomy terms** (settings): posts assigned any listed term are skipped.
- **SEO noindex** (when meta is present): Yoast SEO, Rank Math, SEOPress, All in One SEO.
- **Canonical mismatch**: if a canonical URL is set in supported SEO meta and does not match the post permalink (or resolve to the same post), the post is skipped.
- **Force-include post IDs** (settings): overrides post type exclusion, term exclusion, SEO noindex, canonical mismatch, and excluded post IDs. Password-protected posts are never included.

Developers can override the final decision with filters (see below).

## Configuration

**Settings → Singular Markdown**

- Exclude entire post types from Markdown generation.
- Exclude specific post IDs (comma-separated).
- Force-include post IDs (comma-separated).
- Exclude by taxonomy term (one per line: `taxonomy:term_id` or `taxonomy:term-slug`).
- **Listing pages**: map normal WordPress pages to a post type when those pages use a template to display posts. Their `.md` URLs are generated as archive-style post lists.
- Optional **main content selectors** (one per line: tag, `#id`, or `.class` only). Tried in order, then built-in fallbacks.
- **Extra strip selectors** (one per line) — removed from the main fragment after the built-in strip list (cookie banners, sidebars, etc.).
- **HTML fetch timeout** (5–120 seconds) for loading the public HTML before conversion.
- **Cache status**: shows cached singular/archive file counts, total cache size, newest cache time, approximate background regeneration progress, and queued post/archive jobs. If progress has advanced but no batch cron is scheduled, the status is shown as paused.
- **Eligibility diagnostics**: enter a post ID or front-end URL to see eligibility, reason code, and message.
- **Purge ineligible cache files**: removes cached `.md` files for posts that no longer qualify (no full regeneration).
- **Purge all Markdown cache and regenerate**: removes every cached `.md` file, including archive/listing cache, then schedules background regeneration. Use after changing strip selectors or generation logic.
- **Save settings** / **Regenerate**: also purges ineligible cache files and schedules a background regeneration.
- **Per-post meta box** (edit screen): choose automatic Markdown from HTML or **custom Markdown** for that entry (included post types only).

## Filters

- `singular_markdown_allowed_post_types` — default included post type names (after merging `post` / `page` when public).
- `singular_markdown_excluded_selectors` — full list of selectors removed from the main fragment (built-in defaults + **Extra strip selectors** from settings are merged before this filter runs).
- `singular_markdown_main_content_selectors` — ordered list of selectors used to find main HTML before Markdown conversion.
- `singular_markdown_output` — final Markdown string.
- `singular_markdown_is_post_eligible` — `(bool $eligible, int $post_id)` after built-in rules; return `false` to exclude or `true` to include.
- `singular_markdown_eligibility` — `(array $result, int $post_id)` with keys `eligible`, `code`, `message`; adjust or replace the full diagnostic result.
- `singular_markdown_fetch_timeout` — `(int $timeout, int $post_id)` seconds for the HTML fetch request.
- `singular_markdown_retry_fetch_without_sslverify` — `(bool $allowed, int $post_id, string $url, WP_Error $error)` allows one local/self-signed HTTPS retry without SSL verification when the initial rendered HTML fetch fails. Defaults to local/development or `.local` hosts only.
- `singular_markdown_image_source_attributes` — `(string[] $attributes, DOMElement $img)` ordered image URL attributes checked before srcset fallbacks. Defaults include `src`, NitroPack `nitro-lazy-src` / `data-nitro-lazy-src`, and common lazy-load `data-*` attributes.
- `singular_markdown_image_srcset_attributes` — `(string[] $attributes, DOMElement $img)` ordered srcset-style attributes checked when no direct image URL attribute is usable. Defaults include `srcset`, NitroPack `nitro-lazy-srcset` / `data-nitro-lazy-srcset`, and common lazy-load `data-*srcset` attributes.
- `singular_markdown_cache_control` — `(string $header, int $post_id)` Cache-Control header for successful `.md` responses.
- `singular_markdown_cache_eligibility` — `(bool $cache, int $post_id)` whether to cache eligibility decisions for five minutes.
- `singular_markdown_listing_posts_per_page` — `(int $posts_per_page, array $mapping)` number of posts in configured listing page Markdown.
- `singular_markdown_archive_image_size` — `(string|int[] $size, WP_Post $post)` featured image size for archive/listing Markdown entries. Default: `medium`.
- `singular_markdown_archive_output` — `(string $markdown, string $slug_path, WP_Query $query)` final archive Markdown.
- `singular_markdown_archive_title` — `(string $title, string $slug_path, WP_Query $query)` archive Markdown heading.
- `singular_markdown_archive_cache_ttl` — `(int $ttl, string $slug_path)` archive cache TTL in seconds.

## Internal query vars

Rewrite uses short internal vars `sing_md` and `sing_md_path` (not part of the public URL).

## Manual verification

1. Publish a normal page: HTML has `Link` header and a `<link rel="alternate" type="text/markdown" href="...">` tag; `/path.md` returns cached Markdown after background generation (a first uncached request may return `503 Retry-After`).
2. Unpublish or trash: `.md` returns 404; cache file removed after save/cron.
3. Add post ID to **Exclude post IDs**: `.md` and `Link` disappear; cache purged on save/regenerate.
4. Mark post **noindex** in Yoast (or another supported SEO plugin): excluded unless force-included.
5. **Force-include** a noindex post ID: `.md` is served again (not password-protected).
6. **Cache status** shows cached file counts and background regeneration state after generation starts.
7. **Diagnostics** with post ID or URL shows expected `code` and message.
8. Open a posts/archive page such as `/blog/`: HTML has the `<head>` alternate tag; `/blog.md` contains the archive title, featured images when available, and post entries after background generation.
9. Configure a **Listing page** mapping such as `News → post`: `/news.md` contains the selected post type listing instead of only the page body.

## Uninstall

Deleting the plugin runs `uninstall.php`, which removes plugin options (current and legacy cache key) and Markdown cache directories.

## Migration

If you previously used an older build of this plugin under a different folder name, activating **Singular Markdown** may copy stored options once into `singular_markdown_options` and clear the previous scheduled cron hook.
