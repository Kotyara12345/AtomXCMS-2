## AtomX CMS

[English](#english) | [Русский](#русский)

### English

AtomX is a fast, modern and sterling Content Management System.

This fork keeps the classic AtomX code base, ported to PHP 8.5 and moved
to a current front-end stack (jQuery 3, HTML5 markup) instead of the
bundles the original shipped with.

#### System Requirements

* Operating system: Unix, Linux, Windows, Mac OS
* Middleware: PHP 8.5 and MySQL 5.7+ / MariaDB 10.4+ (PDO MySQL driver).
  Developed and tested with PHP 8.5 and MariaDB 11.4.
* PHP extensions: pdo_mysql, mbstring, gd, exif
* Webserver: Apache with Mod Rewrite or Nginx with Rewrite Module.

#### Front-end Stack

* HTML5 markup and UTF-8 encoding on every page - front-end, admin panel
  and installation wizard.
* jQuery 3.7.1 + jQuery Migrate 3.4.1, jQuery UI 1.14.1
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

#### What Changed in This Fork

* The database layer is rewritten on top of PDO; the legacy mysql_* API
  is gone.
* PHP 8.5 compatibility fixes across the core, admin panel, plugins and
  the installation wizard.
* Sessions use HttpOnly and SameSite cookies, the admin sign-in form is
  protected by a CSRF token and the session id is regenerated on login.
* User passwords are still stored as md5 hashes - kept on purpose so
  databases created by earlier versions keep working.
* Legacy front-end libraries (jqplot, jsTree 1.x, jQuery UI 1.8,
  Fancybox 1.x, old CodeMirror builds) were removed and replaced with the
  stack listed above.

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
HTML5-разметка) вместо библиотек, которые поставлялись в оригинале.

#### Системные требования

* Операционная система: Unix, Linux, Windows, Mac OS
* Серверное ПО: PHP 8.5 и MySQL 5.7+ / MariaDB 10.4+ (драйвер PDO MySQL).
  Разработка и тестирование выполнены на PHP 8.5 и MariaDB 11.4.
* Расширения PHP: pdo_mysql, mbstring, gd, exif
* Веб-сервер: Apache с Mod Rewrite или Nginx с модулем перезаписи.

#### Фронтенд-стек

* HTML5-разметка и кодировка UTF-8 на каждой странице - фронтенд,
  админ-панель и мастер установки.
* jQuery 3.7.1 + jQuery Migrate 3.4.1, jQuery UI 1.14.1
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

#### Что изменилось в этом форке

* Слой работы с базой данных переписан на PDO; устаревший API mysql_*
  больше не используется.
* Исправления совместимости с PHP 8.5 по ядру, админ-панели, плагинам и
  мастеру установки.
* Сессии используют cookie с флагами HttpOnly и SameSite, форма входа в
  админ-панель защищена CSRF-токеном, идентификатор сессии
  перегенерируется при входе.
* Пароли пользователей по-прежнему хранятся в виде md5-хешей - это
  сделано намеренно, чтобы базы данных прежних версий продолжали
  работать.
* Устаревшие фронтенд-библиотеки (jqplot, jsTree 1.x, jQuery UI 1.8,
  Fancybox 1.x, старые сборки CodeMirror) удалены и заменены стеком,
  указанным выше.

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
