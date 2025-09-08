<?php
declare(strict_types=1);

/**
 * SQL Server Connection Check Script with PDO
 * 
 * Проверяет подключение к MySQL/MariaDB серверу с использованием PDO
 * Оптимизирован для PHP 8.1+
 */

// Заголовки безопасности
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// Конфигурация ошибок
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Проверяем метод запроса
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Функция для безопасного вывода
function escapeHtml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Функция для валидации параметров
function validateParameters(array $params): array
{
    $errors = [];
    
    // Обязательные параметры
    $required = ['host', 'user'];
    foreach ($required as $param) {
        if (empty($params[$param])) {
            $errors[] = "Отсутствует обязательный параметр: $param";
        }
    }
    
    // Валидация host (разрешаем IP адреса и доменные имена)
    if (!empty($params['host'])) {
        $host = $params['host'];
        
        // Проверяем, является ли host IP адресом
        $isIp = filter_var($host, FILTER_VALIDATE_IP);
        
        // Проверяем, является ли host доменным именем
        $isDomain = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
        
        if (!$isIp && !$isDomain) {
            $errors[] = "Некорректный формат host: " . escapeHtml($host);
        }
    }
    
    // Валидация порта (если указан)
    if (!empty($params['port']) && !filter_var($params['port'], FILTER_VALIDATE_INT, 
        ['options' => ['min_range' => 1, 'max_range' => 65535]])) {
        $errors[] = "Некорректный порт: " . escapeHtml($params['port']);
    }
    
    return $errors;
}

// Функция проверки подключения с PDO
function checkDatabaseConnectionPdo(
    string $host, 
    string $user, 
    string $password = '', 
    string $database = null,
    int $port = 3306,
    string $charset = 'utf8mb4'
): array {
    $result = [
        'success' => false, 
        'message' => '', 
        'error' => '',
        'details' => []
    ];
    
    try {
        // Формируем DSN
        $dsn = "mysql:host=" . $host . ";port=" . $port . ";charset=" . $charset;
        
        if ($database !== null && $database !== '') {
            $dsn .= ";dbname=" . $database;
        }
        
        // Параметры подключения PDO
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5, // Таймаут 5 секунд
            PDO::ATTR_PERSISTENT         => false // Не использовать постоянные соединения
        ];
        
        // Создаем подключение
        $pdo = new PDO($dsn, $user, $password, $options);
        
        // Проверяем подключение
        $stmt = $pdo->query('SELECT 1 as connection_test');
        $testResult = $stmt->fetch();
        
        if ($testResult && $testResult['connection_test'] == 1) {
            if ($database !== null && $database !== '') {
                $result['message'] = 'База данных найдена и доступна';
                
                // Дополнительная информация о базе данных
                try {
                    $versionStmt = $pdo->query('SELECT VERSION() as mysql_version');
                    $versionResult = $versionStmt->fetch();
                    $result['details']['mysql_version'] = $versionResult['mysql_version'] ?? 'неизвестно';
                    
                    // Информация о текущей базе данных
                    $dbStmt = $pdo->query('SELECT DATABASE() as current_database');
                    $dbResult = $dbStmt->fetch();
                    $result['details']['current_database'] = $dbResult['current_database'] ?? 'не выбрана';
                    
                } catch (PDOException $e) {
                    // Игнорируем ошибки дополнительных запросов
                }
            } else {
                $result['message'] = 'Подключение к серверу успешно';
                
                // Получаем список баз данных (если не указана конкретная база)
                try {
                    $databasesStmt = $pdo->query('SHOW DATABASES');
                    $databases = $databasesStmt->fetchAll(PDO::FETCH_COLUMN);
                    $result['details']['available_databases'] = count($databases);
                    $result['details']['databases_sample'] = array_slice($databases, 0, 5); // Первые 5 баз
                } catch (PDOException $e) {
                    // Игнорируем ошибку, если нет прав на просмотр баз
                }
            }
            
            $result['success'] = true;
            $result['details']['connection_type'] = 'PDO';
            $result['details']['host'] = $host;
            $result['details']['port'] = $port;
        }
        
    } catch (PDOException $e) {
        // Анализируем код ошибки для более точного сообщения
        $errorCode = $e->getCode();
        
        switch ($errorCode) {
            case 1044: // Access denied for database
                $result['message'] = 'Доступ к базе данных запрещен';
                break;
            case 1045: // Access denied for user
                $result['message'] = 'Неверное имя пользователя или пароль';
                break;
            case 1049: // Unknown database
                $result['message'] = 'База данных не найдена';
                break;
            case 2002: // Connection timeout
                $result['message'] = 'Таймаут подключения к серверу';
                break;
            case 2005: // Unknown MySQL server host
                $result['message'] = 'Сервер MySQL не найден';
                break;
            default:
                $result['message'] = $database !== null ? 'Не удалось найти базу' : 'Не удалось подключиться';
        }
        
        $result['error'] = $e->getMessage();
        $result['details']['error_code'] = $errorCode;
        
    } catch (Exception $e) {
        $result['message'] = 'Ошибка подключения';
        $result['error'] = $e->getMessage();
    }
    
    return $result;
}

// Основная логика
try {
    // Получаем и фильтруем параметры
    $type = $_GET['type'] ?? 'base';
    $host = $_GET['host'] ?? '';
    $user = $_GET['user'] ?? '';
    $pass = $_GET['pass'] ?? '';
    $base = $_GET['base'] ?? '';
    $port = isset($_GET['port']) ? (int)$_GET['port'] : 3306;
    
    // Валидация параметров
    $validationErrors = validateParameters([
        'host' => $host, 
        'user' => $user, 
        'port' => $port
    ]);
    
    if (!empty($validationErrors)) {
        http_response_code(400);
        echo '<div style="color:#FF0000; margin-bottom: 10px;">Ошибки валидации:</div>';
        echo '<ul style="color:#FF0000; margin: 0;">';
        foreach ($validationErrors as $error) {
            echo '<li>' . escapeHtml($error) . '</li>';
        }
        echo '</ul>';
        exit;
    }
    
    // Проверяем подключение
    if ($type === 'base') {
        $result = checkDatabaseConnectionPdo($host, $user, $pass, $base, $port);
    } else {
        $result = checkDatabaseConnectionPdo($host, $user, $pass, null, $port);
    }
    
    // Вывод результата
    $color = $result['success'] ? '#46B100' : '#FF0000';
    $message = escapeHtml($result['message']);
    
    echo "<div style=\"color:{$color}; font-weight: bold; margin-bottom: 10px;\">{$message}</div>";
    
    // Вывод дополнительной информации при успехе
    if ($result['success'] && !empty($result['details'])) {
        echo '<div style="background: #f8f8f8; padding: 10px; border-radius: 5px; margin: 10px 0;">';
        echo '<div style="font-size: 0.9em; color: #666;">Дополнительная информация:</div>';
        echo '<ul style="font-size: 0.9em; margin: 5px 0 0 0;">';
        
        foreach ($result['details'] as $key => $value) {
            if (is_array($value)) {
                echo '<li>' . escapeHtml($key) . ': ' . escapeHtml(implode(', ', $value)) . '...</li>';
            } else {
                echo '<li>' . escapeHtml($key) . ': ' . escapeHtml((string)$value) . '</li>';
            }
        }
        
        echo '</ul>';
        echo '</div>';
    }
    
    // Вывод ошибки при неудаче
    if (!$result['success'] && !empty($result['error'])) {
        echo '<div style="color:#888; font-size: 0.9em; margin-top: 5px;">';
        echo 'Ошибка: ' . escapeHtml($result['error']);
        echo '</div>';
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo '<span style="color:#FF0000">Внутренняя ошибка сервера</span>';
    error_log('PDO SQL check error: ' . $e->getMessage());
}
?>
