<?php
##################################################
##												##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      1.1                            ##
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
header('Content-Type: application/json; charset=utf-8');

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Проверка прав администратора
if (!isset($_SESSION['user_id']) || !$Register['ACL']->isAdmin($_SESSION['user_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Access denied']));
}

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

// Валидация и фильтрация входных данных
if (empty($_GET['name'])) {
    die(json_encode([]));
}

$name = trim($_GET['name']);

// Дополнительная валидация имени
if (!preg_match('/^[a-zA-Zа-яА-Я0-9_\-\s]{1,50}$/u', $name)) {
    die(json_encode(['error' => 'Invalid name format']));
}

// Ограничение длины запроса
if (mb_strlen($name) < 2) {
    die(json_encode([]));
}

if (mb_strlen($name) > 50) {
    $name = mb_substr($name, 0, 50);
}

try {
    $usersModel = $Register['ModManager']->getModelInstance('users');
    
    // Безопасный поиск с использованием параметризованных запросов
    $users = $usersModel->getCollection(
        ["name LIKE ?"], 
        [
            'limit' => 20,
            'params' => [$name . '%']
        ]
    );

    $result = [];
    if (!empty($users)) {
        foreach ($users as $user) {
            // Возвращаем только необходимые поля
            $result[] = [
                'id' => (int)$user->getId(),
                'name' => h($user->getName()),
                'email' => h($user->getEmail()),
                'group_id' => (int)$user->getGroup_id()
            ];
        }
    }
    
    die(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    
} catch (Exception $e) {
    // Логирование ошибки без раскрытия деталей пользователю
    error_log('User search error: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => 'Internal server error']));
}
