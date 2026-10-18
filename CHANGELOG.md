# Changelog

Notable changes of this fork of **AtomX CMS 2.8**, maintained by
Александр Скобялко ([Kotyara12345](https://github.com/Kotyara12345/AtomXCMS-2)).

## [Unreleased]

### Added
- PHP 8.5 compatibility for the core, modules, admin panel and installer.
- Bilingual README (English / Russian) with fork notes, requirements and
  installation instructions.
- Password hashing helpers: `atm_password_hash()`, `atm_password_verify()`,
  `atm_password_needs_rehash()` in `sys/inc/helpers.lib.php`.

### Changed
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

### Security
- `sys/settings/config.php` (database credentials) and the generated
  `sys/img/counter.png` are no longer tracked by git; credentials were
  removed from the repository history.

## [2.8 Beta]

Upstream base by Andrey Brykin (Drunyacoder).
