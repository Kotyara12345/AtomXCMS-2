<?php

##################################################
##                                              ##
## Author:       Andrey Brykin (Drunya)        ##
## Version:      1.0                           ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackage    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2014      ##
##################################################

##################################################
##                                              ##
## any partial or not partial extension         ##
## CMS AtomX, without the consent of the       ##
## author, is illegal                          ##
##################################################
## Любое распространение                        ##
## CMS AtomX или ее частей,                     ##
## без согласия автора, является не законным    ##
##################################################

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$Register = Register::getInstance();
$FpsDB = $Register['DB'];

$pageTitle = __('Admin Panel');
$pageNav = $pageTitle . __(' - General information');
$pageNavr = '';

$url = $_GET['url'] ?? throw new Exception('Невозможно обработать динамический URL. Страница не найдена.');
$url_params = explode('/', $url);
$module = array_shift($url_params);
$action = array_shift($url_params);

if (empty($module) || empty($action)) {
    throw new Exception('Невозможно обработать динамический URL. Страница не найдена.');
}

$controller_path = $Register['ModManager']->getSettingsControllerPath($module);
$class_name = $Register['ModManager']->getSettingsControllerClassName($module);

if (!$Register['ModManager']->moduleExists($module)) {
    throw new Exception("Модуль \"$module\" не найден.");
}

if (!file_exists($controller_path)) {
    throw new Exception("Контроллер динамических страниц для модуля \"$module\" не найден.");
}

include_once $controller_path;

if (!class_exists($class_name)) {
    throw new Exception("Контроллер динамических страниц для модуля \"$module\" не найден.");
}

$controller = new $class_name();
$controller->pageTitle = &$pageTitle;
$controller->pageNav = &$pageNav;
$controller->pageNavr = &$pageNavr;

if (!is_callable([$controller, $action])) {
    throw new Exception("Метод \"$action\" не найден в модуле \"$module\".");
}

$content = call_user_func_array([$controller, $action], $url_params);

include_once ROOT . '/admin/template/header.php';
echo $content;

include_once 'template/footer.php';
