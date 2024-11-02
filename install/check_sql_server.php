<?php
@ini_set('display_errors', '0');

// Установка типа по умолчанию, если не передан
$type = $_GET['type'] ?? true;

$host = $_GET['host'] ?? '';
$user = $_GET['user'] ?? '';
$pass = $_GET['pass'] ?? '';
$base = $_GET['base'] ?? '';

// Проверка соединения с базой данных
if ($type === 'base') {
    $connection = @mysqli_connect($host, $user, $pass);

    if ($connection && @mysqli_select_db($connection, $base)) {
        echo '<span style="color:#46B100">База найдена</span>';
    } else {
        echo '<span style="color:#FF0000">Не удалось найти базу</span>';
    }

    // Закрытие соединения
    @mysqli_close($connection);
} else {
    $connection = @mysqli_connect($host, $user, $pass);

    if ($connection) {
        echo '<span style="color:#46B100">Подключились</span>';
    } else {
        echo '<span style="color:#FF0000">Не удалось подключиться</span>';
    }

    // Закрытие соединения
    @mysqli_close($connection);
}
?>
