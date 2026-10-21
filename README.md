## AtomX CMS

[English](#english) | [Русский](#русский)

### English

AtomX is a fast, modern and sterling Content Management System.

This fork keeps the classic AtomX code base, ported to PHP 8.5 and moved
to a current front-end stack (jQuery 3, HTML5 markup, responsive layout)
instead of the bundles the original shipped with.

#### System Requirements

* Operating system: Unix, Linux, Windows, Mac OS
* Middleware: PHP 8.5 and MySQL 5.7+ / MariaDB 10.4+ (PDO MySQL driver).
  Developed and tested with PHP 8.5 and MariaDB 11.4.
* PHP extensions: pdo_mysql, mbstring, gd, exif
* Webserver: Apache with Mod Rewrite or Nginx with Rewrite Module.

#### Front-end Stack

* HTML5 markup and UTF-8 encoding on every page - front-end, admin panel
  and installation wizard.
* jQuery 3.7.1, jQuery UI 1.14.1
* jQuery Validate 1.21, jQuery datetimepicker 2.5, jQuery.cookie shim
* Fancybox 3.5.7 galleries
* jsTree 3.3.16 (page tree, menu editor)
* Chart.js 4 on the statistics page (replaces jqplot)
* CodeMirror 5.65 in the template and menu editors
* WysiBB editor (patched for jQuery 3)

#### Steps to Install

* Download the version you need.
* Unzip the archive to a target www(public_html) folder on your server.
* You may also need to recursively CHMOD the folder(files) /sitemap.xml/, /sys/tmp/, /sys/logs/, /sys/cache/, /sys/avatars/, /sys/files/, /sys/settings/, /sys/plugins/ and /template/ to 755(or 777) if your host doesn't set it implicitly.
* Type http://yourdomain.org/install/ in the browser.
* After the installation rename or delete install directory.

#### Nginx

The bundled `.htaccess` carries the pretty-URL rewrite rules and works
on Apache only. On Nginx, add the rule below to the site's `server`
block - inside an existing `location /`, or as a new block if the
configuration has none:

```nginx
location / {
    if (!-e $request_filename) {
        rewrite ^/(.*)$ /index.php?url=$1 last;
    }
}
```

Without it Nginx answers sections like `/news/` or `/foto/` with its own
404 page, never reaching PHP. On OSPanel/OpenServer, put the same lines
into the engine template (`userdata/config/Nginx_*_vhost.conf`) so the
panel keeps them after regenerating the configuration.

#### What Changed in This Fork

* The database layer is rewritten on top of PDO; the legacy mysql_* API
  is gone.
* PHP 8.5 compatibility fixes across the core, admin panel, plugins and
  the installation wizard.
* Sessions use HttpOnly and SameSite cookies, the admin sign-in form is
  protected by a CSRF token and the session id is regenerated on login.
* Passwords are stored with `password_hash()` (bcrypt). Hashes created
  by older versions (md5) keep working and are transparently re-hashed
  on the next successful sign-in.
* Legacy front-end libraries (jqplot, jsTree 1.x, jQuery UI 1.8,
  Fancybox 1.x, old CodeMirror builds) were removed and replaced with the
  stack listed above.
* The default front-end theme is responsive: a mobile viewport meta tag,
  fluid width instead of the fixed 1000-px layout and phone breakpoints
  that stack the menu, search, sidebar, forms and forum tables.
* The admin panel controls HTTP/2 and HTTP/3 for the site (System
  settings → "Server protocols"). HTTP/2 adds an `H2Upgrade` rule to the
  site root `.htaccess`; HTTP/3 advertises `Alt-Svc` via PHP and
  `.htaccess`. Both switches are safe on hostings without support - the
  site simply keeps working over the lower protocol version. Note that
  the protocols themselves are served by the web server: for HTTP/2 it
  must negotiate `h2` over TLS (the `http2` parameter of the `listen`
  directive in Nginx, `Protocols h2 http/1.1` in Apache), and HTTP/3
  requires a server or CDN with QUIC support - configure this in the
  hosting control panel.
* Uploaded images can be converted to WebP (System settings → "Convert
  uploaded images to WebP"). The original JPEG/PNG/GIF file is kept and
  served automatically to browsers without WebP support; animated GIFs
  are left untouched.
* Canonical URLs are configured in SEO settings separately for regular
  pages, pagination pages and search pages. Pagination can point to the
  first page or to the current page; search results can use a query-free
  canonical URL or no canonical tag at all.

#### Contributing

* Help on the [Forum](http://atomx.net/forum/).
* Donate to keep [AtomX](http://atomx.net) free.
	* WebMoney:
		* Z320956365350
		* U237030902343
		* R405239497933
* Develop a new plugin.
* Create a new theme.
* Find and report issues.
* Link back to AtomX CMS.

#### Links

* [Site](http://atomx.net)
* [Forum](http://atomx.net/forum/)
* [Documentation](http://atomx.net/stat/category/27/)
* [Github Repository](https://github.com/Kotyara12345/AtomXCMS-2/)

Copyright (C) 2026 Alexander Skobyalko / Kotyara12345
Project have been developing with support by the hosting provider QWARTA.RU.

---

### Русский

AtomX - быстрая, современная и надежная система управления сайтом.

Этот форк сохраняет классическую кодовую базу AtomX, портированную на
PHP 8.5 и переведенную на актуальный фронтенд-стек (jQuery 3,
HTML5-разметка, адаптивная вёрстка) вместо библиотек, которые
поставлялись в оригинале.

#### Системные требования

* Операционная система: Unix, Linux, Windows, Mac OS
* Серверное ПО: PHP 8.5 и MySQL 5.7+ / MariaDB 10.4+ (драйвер PDO MySQL).
  Разработка и тестирование выполнены на PHP 8.5 и MariaDB 11.4.
* Расширения PHP: pdo_mysql, mbstring, gd, exif
* Веб-сервер: Apache с Mod Rewrite или Nginx с модулем перезаписи.

#### Фронтенд-стек

* HTML5-разметка и кодировка UTF-8 на каждой странице - фронтенд,
  админ-панель и мастер установки.
* jQuery 3.7.1, jQuery UI 1.14.1
* jQuery Validate 1.21, jQuery datetimepicker 2.5, шим jQuery.cookie
* Галереи Fancybox 3.5.7
* jsTree 3.3.16 (дерево страниц, редактор меню)
* Chart.js 4 на странице статистики (вместо jqplot)
* CodeMirror 5.65 в редакторах шаблонов и меню
* Редактор WysiBB (пропатчен под jQuery 3)

#### Шаги установки

* Скачайте нужную версию.
* Распакуйте архив в целевую папку www(public_html) на сервере.
* Возможно, потребуется рекурсивно выставить права (CHMOD) 755 (или 777) на /sitemap.xml/, /sys/tmp/, /sys/logs/, /sys/cache/, /sys/avatars/, /sys/files/, /sys/settings/, /sys/plugins/ и /template/, если хостинг не выставляет их автоматически.
* Откройте в браузере http://yourdomain.org/install/.
* После установки переименуйте или удалите каталог install.

#### Работа под Nginx

Файл `.htaccess` с правилами «красивых» URL работает только на Apache.
На Nginx добавьте правило ниже в `server`-блок сайта - внутрь
существующего `location /` либо отдельным блоком, если его нет:

```nginx
location / {
    if (!-e $request_filename) {
        rewrite ^/(.*)$ /index.php?url=$1 last;
    }
}
```

Без этого Nginx на разделы вида `/news/` или `/foto/` отвечает своей
страницей 404, не доходя до PHP. В OSPanel/OpenServer пропишите те же
строки в шаблоне движка (`userdata/config/Nginx_*_vhost.conf`), чтобы
панель сохранила их после перегенерации конфигурации.

#### Что изменилось в этом форке

* Слой работы с базой данных переписан на PDO; устаревший API mysql_*
  больше не используется.
* Исправления совместимости с PHP 8.5 по ядру, админ-панели, плагинам и
  мастеру установки.
* Сессии используют cookie с флагами HttpOnly и SameSite, форма входа в
  админ-панель защищена CSRF-токеном, идентификатор сессии
  перегенерируется при входе.
* Пароли хранятся в виде хешей `password_hash()` (bcrypt). Хеши прежних
  версий (md5) продолжают работать и прозрачно обновляются на bcrypt
  при следующем успешном входе.
* Устаревшие фронтенд-библиотеки (jqplot, jsTree 1.x, jQuery UI 1.8,
  Fancybox 1.x, старые сборки CodeMirror) удалены и заменены стеком,
  указанным выше.
* Тема оформления по умолчанию стала адаптивной: мета-тег viewport,
  «резиновая» ширина вместо фиксированных 1000 px и мобильные
  брейкпоинты — меню, поиск, боковая панель, формы и таблицы форума
  складываются в одну колонку на телефонах.
* В админ-панели появилось управление протоколами HTTP/2 и HTTP/3
  (Системные настройки → «Серверные протоколы») с предупреждением о
  поддержке хостинга и подсказкой о назначении. HTTP/2 добавляет в
  корневой `.htaccess` правило `H2Upgrade`; HTTP/3 анонсируется
  заголовком `Alt-Svc` (из PHP и через правила `.htaccess`). Оба
  переключателя безопасны на хостингах без поддержки — сайт просто
  продолжит работать по старшей доступной версии протокола. Сам
  протокол включает веб-сервер: для HTTP/2 он должен согласовывать
  `h2` по TLS (параметр `http2` у директивы `listen` в Nginx,
  `Protocols h2 http/1.1` в Apache), а для HTTP/3 нужен сервер или CDN
  с поддержкой QUIC — это настраивается в панели хостинга.
* Загружаемые изображения можно конвертировать в WebP (Системные
  настройки → «Конвертировать загружаемые изображения в WebP»).
  Исходный файл JPEG/PNG/GIF сохраняется и автоматически отдаётся
  браузерам без поддержки WebP; анимированные GIF не трогаются.
* Canonical настраивается в разделе SEO отдельно для обычных страниц,
  страниц пагинации и страниц поиска. Для пагинации можно указать
  первую страницу или текущую; для поиска — URL без запроса или
  отключить вывод canonical.

#### Участие в проекте

* Помощь на [форуме](http://atomx.net/forum/).
* Пожертвования для поддержки [AtomX](http://atomx.net):
	* WebMoney:
		* Z320956365350
		* U237030902343
		* R405239497933
* Разработайте новый плагин.
* Создайте новую тему оформления.
* Находите и сообщайте об ошибках.
* Разместите обратную ссылку на AtomX CMS.

#### Ссылки

* [Сайт](http://atomx.net)
* [Форум](http://atomx.net/forum/)
* [Документация](http://atomx.net/stat/category/27/)
* [Репозиторий на GitHub](https://github.com/Kotyara12345/AtomXCMS-2/)

Copyright (C) 2026 Александр Скобялко / Kotyara12345
Проект разрабатывался при поддержке хостинг-провайдера QWARTA.RU.
