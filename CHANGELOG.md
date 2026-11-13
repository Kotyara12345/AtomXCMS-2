# Changelog

Notable changes of this fork of **AtomX CMS 2.8**, maintained by
Александр Скобялко ([Kotyara12345](https://github.com/Kotyara12345/AtomXCMS-2)).

## [Unreleased]

### Added
- Request-driven maintenance for newsletters and incremental search indexing,
  with retries and protected queue storage inside the site's writable directory.
  CLI access, cron and operating-system tasks are not required.
- Runtime and database regression checks for remembered login, search visibility,
  concurrent order cancellation, upload validation and background processing.
- PHP 8.5 compatibility for the core, modules, admin panel and installer.
- Bilingual README (English / Russian) with fork notes, requirements and
  installation instructions.
- Shop pages expose Open Graph tags, schema.org `Product`/`AggregateOffer`
  JSON-LD on product pages and `BreadcrumbList` JSON-LD on catalogue pages.
  Paginated catalogue pages print `rel="prev"`/`rel="next"`. Product pages use
  the product image for `og:image` and `product` as `og:type`; internal
  catalogue search is marked `noindex, follow`.
- Shop order lifecycle: unpaid orders are cancelled automatically after the
  configurable `order_ttl_hours` (default 72, `0` disables) and their stock is
  returned to the catalogue. The maintenance runs on requests, like the
  sitemap job, so no system cron is required.
- Shop order status history (`shop_order_status_history`): every status change
  is stored with the comment, the admin name and the time, and shown in the
  order card in the admin panel.
- Admin status changes can add a comment and optionally e-mail the customer
  about the new status. Order e-mails now contain a direct link to the order.
- Checkout rejects phone numbers without 7–15 digits.
- Shop customer retention: product reviews with star ratings and moderation,
  `AggregateRating` JSON-LD, "similar products", "recently viewed", a
  "featured" block on the catalogue home page, and stock-arrival e-mail
  subscriptions for out-of-stock products. Reviews and subscriptions use
  tables created on first use, so an existing installation keeps working
  without a module reinstall; new installs get them from `install_db.php`.
- Shop admin and operations: orders export to CSV with the current filters,
  a printable invoice (`?tab=order&id=N&print=1`), and a tracking number with
  an optional tracking link. The tracking data is shown on the customer order
  page and included in order e-mails. The dashboard now shows revenue for the
  last day, 7 and 30 days, a 14-day sales table, top products and top
  categories (cancelled orders excluded). A `low_stock_threshold` setting
  (default 3, `0` disables) sends one e-mail per low-stock episode from the
  request-driven maintenance. Logged-in customers get their cart saved in
  `shop_carts` and restored on the next visit or login. Existing installations
  get the tracking and low-stock columns on first use.
- Shop sales tools: abandoned-cart reminders and CSV product import.
  Carts untouched for `abandoned_cart_hours` (default 24, `0` disables) get one
  e-mail with the cart contents and a return link; the `reminded_at` column is
  added to existing `shop_carts` tables on first use. The admin product list
  imports products from a CSV file: an existing slug is updated, a new slug
  creates a product with one default variant. Columns: title, slug, category,
  short_description, description, sku, price, stock, active, featured and the
  Meta fields; the delimiter is detected automatically.
- Shop SEO: product JSON-LD now describes every priced variant as a
  `hasVariant` entry with its own `Offer`, SKU, price and availability, while
  the `AggregateOffer` range stays. A `structured_data` setting in the module
  settings (default on) enables or disables the product, offer, rating and
  breadcrumb JSON-LD for installations where another plugin prints markup.
- Promo codes and old prices in the shop. The admin gets a "Промокоды" tab
  with percent or fixed-amount codes, currency, minimum order total, usage
  limit, start/end dates and an on/off switch. The cart and checkout accept a
  code, show the discount row and keep it in the session; the order stores the
  code and the discount, the invoice, order page, e-mails and CSV export print
  it. The cart quote is rechecked inside the order transaction with a row lock,
  so a usage limit cannot be exceeded by two simultaneous orders, and
  cancelling an order gives the redemption back. Percent codes work in every
  currency, fixed codes only in their own. Each variant price now has a
  `compare_price` field ("старая цена" in the admin): the storefront shows it
  struck through with a discount badge on product, catalogue, favourites and
  related-product cards. Tables and columns are added to existing installations
  on first use.
- Manual "customers also buy" picks for the shop: the product form has a
  multi-select of other products, and the product page shows them in a
  "С этим покупают" block above the automatic "Похожие товары". Manual order
  is kept, the section is hidden when empty, and links are cleaned up when a
  product is deleted. The `shop_related_products` table is created on first
  use, so existing installations need no module reinstall.
- Shop settings can turn off the "Похожие товары" and "С этим покупают" blocks
  on the product page (`similar_enabled`, `related_enabled`, both on by
  default), so a site that does not need recommendations skips the queries
  and the markup.
- Password hashing helpers: `atm_password_hash()`, `atm_password_verify()`,
  `atm_password_needs_rehash()` in `sys/inc/helpers.lib.php`.
- "Server protocols" section in the admin panel (System settings) with
  optional HTTP/2 and HTTP/3 switches, including a hosting-support
  warning and a usage hint. HTTP/2 writes an `H2Upgrade` rule into the
  site root `.htaccess`; HTTP/3 advertises the protocol with the
  `Alt-Svc` header (sent by PHP, and by `.htaccess` rules on Apache).
  Both options are safe to enable on hostings without support: the site
  simply keeps working over the lower protocol version.
- News and articles can be attached to several sections at once: a
  material keeps its main section (URLs, breadcrumbs and access rules
  still use it) and is additionally listed in any number of other
  sections. The "Additional sections" checkboxes were added to the
  add/edit forms of the `news` and `stat` modules; the links are stored
  in the shared `categories_bind` table (created by the installer, or
  automatically on the first request of an installation updated from an
  older version). Lists and the material page show every section of a
  material, and it stays visible to visitors while at least one of its
  sections is accessible. Deleting a material or a section removes the
  related bindings.

### Changed
- Search checks current publication, category and forum permissions before returning
  results, including cached results and deleted forum posts. Its cache uses one
  stable key containing the query, filters and access context.
- Uploaded files use an allowlist, server-side MIME/image decoding and size limits;
  filenames are randomized and form validation reports invalid uploads.
- SQL cache entries expire, are written atomically and are invalidated after writes
  and commits. Authentication and locking queries bypass caching.
- Remembered user login uses the current cookie; visit timestamps are throttled
  and unread-topic selection uses a date predicate.
- Order cancellation locks the order and returns stock in the same transaction.
- Passwords are now stored with `password_hash()` (bcrypt) instead of md5.
  Legacy md5 hashes keep working: they are verified and transparently
  re-hashed on the next successful login (front-end login form, admin
  panel login, profile and admin-panel user editing, installer admin
  account, password reset flow).
- Password reset: the temporary activation file now keeps the new
  password hash; the e-mail still carries the plain-text password and the
  activation link.
- Front-end libraries and page skeletons updated (HTML5 markup, modern
  vendor bundles).
- The default front-end theme is responsive now: the page skeletons carry
  a viewport meta tag, the fixed 1000px `min-width` is replaced with a
  fluid layout, the sidebar stacks under the content on tablets and
  phones, and mobile breakpoints (≤700px) stack the menu, search and
  forms for phones.
- The "site is already installed" warning page is styled with the
  installer's look.

### Removed
- jQuery Migrate — the script file and all five includes. Its last
  supported legacy calls (`.size()`, `.load(handler)`) were replaced with
  `.length` and `.on('load', ...)` in WysiBB, jquery.validate and
  Redactor.

### Fixed
- Shop status changes now roll back when the status history row cannot be
  written, instead of silently committing a change without an audit entry.
  The concurrent order test worker now provides the database `save()` method
  used by the history log.
- Profile password change did not work: an empty result of the custom
  password validator was treated as an error, so any profile submit with
  a filled "current password" field was rejected.
- Password reset failed on fresh installations: the `sys/tmp/activate`
  directory was never created.
- Chat message rendering (smiles, BB codes, escaping).
- Various runtime warnings and errors on PHP 8.5 found during a full
  live pass (undefined array keys, dynamic properties, null arguments,
  and more).
- Remaining PHP 8.1+ deprecation warnings caused by `null` arguments:
  the helper `h()`, the WysiBB plugin, the template lexer, GeSHi and
  the extensions API client now pass proper values to
  `htmlspecialchars()`, `preg_replace()`, `preg_match()` and
  `file_get_contents()`.
- Sections returned 404 for visitors when the site is served by Nginx
  (Nginx ignores `.htaccess`): the required rewrite rule is now
  documented in the README. The router also accepts paths with a
  leading slash (e.g. from `try_files $uri /index.php?url=$uri`
  setups) instead of falling back to the wrong page.
- `.htaccess` no longer returns HTTP 500 on Apache builds without the
  compression modules: `AddOutputFilterByType` directives are wrapped in
  `<IfModule mod_filter.c><IfModule mod_deflate.c>` guards, so the file
  parses when either module is missing (e.g. the OSPanel
  "Apache + Nginx" combo ships with mod_deflate disabled).
- README fixes: the front-end stack no longer lists jQuery Migrate
  (removed earlier) and the password description now matches the
  bcrypt storage with transparent md5 upgrade.
- The front-end moderation panel's edit form (news, stat and loads
  modules) returned HTTP 500 on PHP 8 when the material had no
  attachments: `count()` was called on the `null` result of
  `getAttaches()`; it is now guarded with `!empty()`.
- Site search could look case-sensitive: the index was rebuilt only
  once per the configured interval, so new or edited materials were
  not found until the next rebuild, and after moving the site it
  could stay empty while still considered fresh. The index is now
  rebuilt as soon as a searchable table (news, stat, loads, forum
  posts) changes and when it is empty; the keyword highlight in the
  search results no longer depends on the letter case either.
- Forum search did not cover forum section names or topic titles, and
  a hit inside a message was listed with an empty title. The search
  index now also includes forum categories, sections and topic titles
  and descriptions, and a hit inside a message is titled with its
  topic.
- On phones (≤700px) the dark material title bar (news, articles,
  forum topics, search results) collapsed to a few pixels: the bar got
  `height: auto` while both of its children are floated, so the title
  text hung out of the bar and overlapped the info block below. The
  bar now contains the floated title link (`overflow: hidden`) and
  grows around short and multi-line titles.
- Re-installing the CMS into a database with an existing installation
  hung forever on the second installation step: the installer refused
  to run (HTTP 403) instead of dropping and recreating the tables, and
  the page never handled the failed AJAX request, so its loader spun
  endlessly. A deliberate re-installation now works as in the original
  CMS again, a failed request is reported on the page instead of
  hanging, and the database queries are only run when the installation
  was started from the first step (the administrator credentials come
  from the session).
- Settings written with `Config::write()` were not always seen by the
  next request when OPcache was enabled with the default
  `opcache.revalidate_freq` value: the compiled `config.php` could be
  reused for up to two seconds, so the installer's AJAX step could run
  with the previous database settings. Saving the config now
  invalidates its OPcache entry.
- Links in e-mail messages (registration and password-reset activation,
  private-message notification, subscription confirmation, new-material
  announcement and unsubscribe) were hardcoded to `http://`. They now
  use `atm_site_origin()`, which follows the request scheme: an HTTPS
  site sends `https://`, a site without a certificate keeps `http://`.
  The private-message link is also absolute now instead of a
  root-relative path.
- RSS feeds (`/news/rss/`, `/stat/rss/`) lost the slash between the host
  and the section (`https://site.runews/`), hardcoded `http://`, and
  reused one cache entry for every scheme and host. The channel and item
  links are now absolute with the request scheme, `www` is kept, the
  titles and categories are XML-escaped, and the cache is split per site
  origin.
- Forum "link to the message" (`#` next to a post) was built as
  `http://` plus the request host, so it pointed at the insecure scheme
  on an HTTPS site and lost the install subdirectory. It now uses
  `atm_site_origin()`. Returning to the previous page after editing a
  post also failed on HTTPS, because the referer parser accepted only
  `http://`; it now accepts both schemes, compares the host without the
  port and rejects foreign referers.
- Interim "please wait" pages (success and error messages in the forum,
  profile, registration and other modules) redirected to `http://` even
  when the site ran over HTTPS, which dropped the secure session cookie.
  The `Refresh` header now carries a root-relative URL, so the browser
  stays on the current scheme and host.
- `sitemap.xml` always listed URLs with `http://`, even on sites served
  over HTTPS. The scheme and host now come from the request that
  generated the map (including `www`), and installations below the
  document root keep their subdirectory prefix. A new "Sitemap links"
  setting pins the protocol to HTTP or HTTPS for sites reachable over
  both, so the map no longer depends on which request triggered the
  daily regeneration; `robots.txt` follows the same choice.
- `sitemap.xml` marked every URL as `changefreq=daily` and had no
  `lastmod`, so search engines saw user profiles, forum pages and stale
  materials as changing every day. The fake frequency is gone. Materials
  and their section pages now carry `lastmod` from the material date,
  forum themes from the last post and `forum/user_posts` from the user's
  latest post. Pagination pages (`?page=`) are no longer listed. The shop
  was not in the map at all; it now adds the catalog, active categories
  (including subcategories), active products (HLU or id URL) and landing
  pages, dated from `updated_at`/`created_at` and their newest product.
  `<loc>` values are now percent-encoded as the sitemap protocol requires,
  so Cyrillic HLU titles no longer produce invalid XML; existing `%XX`
  escapes are kept as they are and not encoded twice.
- `sitemap.xml` was written without a lock and the result was ignored, so
  a failed write left a stale map and the daily task was still marked as
  done. The write now uses `LOCK_EX` and reports failure, and the
  scheduler retries on the next request instead of waiting another day.
  `robots.txt` is refreshed as soon as the "Sitemap links" setting is
  saved, and the Sitemap settings page has a "Regenerate now" button for
  an immediate rebuild.

### Security
- `sys/settings/config.php` (database credentials) and the generated
  `sys/img/counter.png` are no longer tracked by git; credentials were
  removed from the repository history.
- User "remember me" autologin no longer stores the password hash in a
  cookie. A random token is issued instead; only its sha256 hash is kept
  in the new `user_remember` table (created automatically on demand).
  Legacy `userid`/`password`/`autologin` cookies are cleared on the next
  visit. Changing or resetting a password invalidates all remember
  tokens of the user (front-end and admin panel).
- Fixed SQL injection in the admin user search (`admin/users_list.php`)
  and in the admin user-autocomplete endpoint (`admin/find_users.php`):
  user input is escaped before being placed into LIKE conditions.
- Fixed reflected XSS: the external-redirect page escapes the target URL
  (`template/default/html/default/redirect.html`) and `redirect.php`
  rejects URLs with schemes other than http/https and strips CR/LF;
  `getOrderLink()` escapes the request URI it embeds into sorting links.
- `h()` now escapes single quotes (`ENT_QUOTES`, explicit UTF-8).
- The admin users list escapes user names, e-mails and group data in its
  output (defence against stored XSS through profile fields).
- `img.php` accepts only the known upload directories below `sys/files/`
  and rejects `..` in file names (path traversal hardening).
- The installer runs only when step 1 of the wizard has put the admin
  credentials into the session; calling `install/do_queries.php` directly
  is rejected.
- Front-end login regenerates the session id on success (session fixation
  protection, previously only the admin panel did this).
- Password reset generates the temporary password with `random_int()`
  instead of `rand()`.
- Serialized data read from cache, logs, menus, module settings and
  statistics now passes `allowed_classes => false` to `unserialize()`, so
  a tampered file or database value cannot instantiate arbitrary objects
  and trigger their magic methods. A regression test scans the source for
  unprotected calls.

## [2.8 Beta]

Upstream base by Andrey Brykin (Drunyacoder).
