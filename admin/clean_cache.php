<?php
/*-----------------------------------------------\
|  Author:       Andrey Brykin (Drunya)          |
|  Email:        drunyacoder@gmail.com           |
|  Site:         http://atomx.net                |
|  Version:      1.4                             |
|  Project:      CMS                             |
|  package       CMS AtomX                       |
|  subpackage    Admin Panel module              |
|  copyright     ©Andrey Brykin 2010-2013        |
\-----------------------------------------------*/

/*-----------------------------------------------\
|  any partial or not partial extension          |
|  CMS AtomX, without the consent of the        |
|  author, is illegal                            |
|------------------------------------------------|
|  Любое распространение                         |
|  CMS AtomX или ее частей,                      |
|  без согласия автора, является незаконным      |
\-----------------------------------------------*/

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Очищаем кеш
$_SESSION['message'] = __('Cache is cleared');
$Register['Cache']->clean();

$snippets = new AtmSnippets();
$snippets->cleanCache();

// Удаляем кешированные шаблоны
$cachePath = ROOT . '/sys/cache/templates/';
if (is_dir($cachePath)) {
    array_map('unlink', glob("$cachePath/*.*"));
    rmdir($cachePath);
}

redirect('/admin');
