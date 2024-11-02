<?php
include_once 'sys/boot.php';

// Проверка доступа. Этот скрипт должен быть доступен только из панели администратора
session_start(); // Убедитесь, что сессия инициализирована

if (empty($_SESSION['adm_panel_authorize']) ||
    $_SESSION['adm_panel_authorize'] < time() ||
    empty($_SESSION['user'])
) {
    die('Access denied');
} else {
    $_SESSION['adm_panel_authorize'] = time() + Config::read('session_time', 'secure');
}

// Папка для хранения файлов
$dir = ROOT . '/sys/files/pages/';
if (!file_exists($dir)) {
    mkdir($dir, 0777, true);
}

// Проверка типа файла
$allowed_types = ['image/png', 'image/jpg', 'image/jpeg', 'image/gif', 'image/pjpeg'];
$file_type = strtolower($_FILES['file']['type']);

if (in_array($file_type, $allowed_types)) {
    // Установка уникального имени файла
    $filename = md5(date('YmdHis') . uniqid('', true)) . '.jpg';
    $file = $dir . $filename;

    // Копирование файла
    if (move_uploaded_file($_FILES['file']['tmp_name'], $file)) {
        // Отображение ссылки на файл
        $response = [
            'filelink' => WWW_ROOT . '/sys/files/pages/' . $filename,
        ];
        echo json_encode($response);
    } else {
        echo json_encode(['error' => 'File upload failed']);
    }
} else {
    echo json_encode(['error' => 'Invalid file type']);
}
