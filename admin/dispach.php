<?php

##################################################
##												##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      1.0                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackege    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2014       ##
##################################################


##################################################
##												##
## any partial or not partial extension         ##
## CMS AtomX,without the consent of the         ##
## author, is illegal                           ##
##################################################
## Любое распространение                        ##
## CMS AtomX или ее частей,                     ##
## без согласия автора, является не законным    ##
##################################################

// Безопасные заголовки
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Проверка прав администратора
if (!isset($_SESSION['user_id']) || !$Register['ACL']->isAdmin($_SESSION['user_id'])) {
    die(__('Access denied'));
}

$Register = Register::getInstance();
$FpsDB = $Register['DB'];

$pageTitle = __('Admin Panel');
$pageNav = $pageTitle . __(' - General information');
$pageNavr = '';

// Валидация и обработка URL
if (empty($_GET['url'])) {
    throw new Exception(__('Page not found'));
}

// Очистка и валидация URL параметров
$url = filter_var($_GET['url'], FILTER_SANITIZE_URL);
$url_params = explode('/', $url);
$url_params = array_filter($url_params); // Удаляем пустые элементы

// Извлекаем модуль и действие
$module = array_shift($url_params);
$action = array_shift($url_params);

// Валидация модуля и действия
if (empty($module) || empty($action)) {
    throw new Exception(__('Page not found'));
}

// Валидация имени модуля (только буквы, цифры и подчеркивания)
if (!preg_match('/^[a-zA-Z0-9_]+$/', $module)) {
    throw new Exception(__('Invalid module name'));
}

// Валидация имени действия (только буквы, цифры и подчеркивания)
if (!preg_match('/^[a-zA-Z0-9_]+$/', $action)) {
    throw new Exception(__('Invalid action name'));
}

// Проверка существования модуля
if (!$Register['ModManager']->moduleExists($module)) {
    throw new Exception(__('Module not found'));
}

// Получение пути к контроллеру
$controller_path = $Register['ModManager']->getSettingsControllerPath($module);
$class_name = $Register['ModManager']->getSettingsControllerClassName($module);

// Проверка существования файла контроллера
if (!file_exists($controller_path)) {
    throw new Exception(__('Controller not found'));
}

// Безопасное включение файла
$real_controller_path = realpath($controller_path);
if ($real_controller_path === false || strpos($real_controller_path, realpath(ROOT)) !== 0) {
    throw new Exception(__('Invalid controller path'));
}

include_once $real_controller_path;

// Проверка существования класса
if (!class_exists($class_name)) {
    throw new Exception(__('Controller class not found'));
}

// Создание экземпляра контроллера
$controller = new $class_name;

// Проверка, что метод является допустимым и публичным
if (!is_callable(array($controller, $action)) || !method_exists($controller, $action)) {
    throw new Exception(__('Action not found'));
}

// Проверка, что метод является публичным и не начинается с __ (магические методы)
$reflection = new ReflectionMethod($class_name, $action);
if (!$reflection->isPublic() || strpos($action, '__') === 0) {
    throw new Exception(__('Action not accessible'));
}

// Установка свойств страницы
$controller->pageTitle = &$pageTitle;
$controller->pageNav = &$pageNav;
$controller->pageNavr = &$pageNavr;

// Фильтрация оставшихся параметров URL
$filtered_params = array();
foreach ($url_params as $param) {
    $filtered_params[] = filter_var($param, FILTER_SANITIZE_STRING);
}

// Вызов метода контроллера
try {
    $content = call_user_func_array(array($controller, $action), $filtered_params);
} catch (Exception $e) {
    // Логирование ошибки без раскрытия деталей пользователю
    error_log('Dispatcher error: ' . $e->getMessage());
    throw new Exception(__('An error occurred while processing the request'));
}

// Проверка, что возвращено содержимое
if (!is_string($content)) {
    throw new Exception(__('Invalid controller response'));
}

// Вывод содержимого
include_once ROOT . '/admin/template/header.php';
echo $content;

include_once 'template/footer.php';
