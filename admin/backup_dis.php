<?php
declare(strict_types=1);

/**
 * @author    Andrey Brykin (Drunya)
 * @email     drunyacoder@gmail.com
 * @site      http://atomx.net
 * @version   1.2
 * @project   CMS AtomX
 * @package   Admin Panel Module
 * @copyright ©Andrey Brykin 2010-2013
 *
 * Любое распространение CMS AtomX или ее частей
 * без согласия автора является незаконным.
 */

require_once '../sys/boot.php';
require_once ROOT . '/admin/inc/adm_boot.php';

// Установка заголовков страницы
$pageTitle = '';
$pageNav = '';
$pageNavr = '';

// Подключение шапки
include_once ROOT . '/admin/template/header.php';

// Удаление стандартных файлов шаблона
$templateStandartFiles = array_merge(
    glob(ROOT . '/template/' . Config::read('template') . '/css/*.stand'),
    glob(ROOT . '/template/' . Config::read('template') . '/html/*/*.stand')
);

if (is_array($templateStandartFiles)) {
    foreach ($templateStandartFiles as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

// Установка сообщения об успешном завершении
$_SESSION['message'] = __('Backup complete');

// Перенаправление на главную страницу админки
redirect('/admin');

// Подключение подвала
include_once 'template/footer.php';
