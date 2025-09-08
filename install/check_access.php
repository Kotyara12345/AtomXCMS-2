<?php
// Совместимость с PHP 8.1+
declare(strict_types=1);

// Безопасное отключение отображения ошибок
ini_set('display_errors', '0');
error_reporting(E_ALL);

/**
 * Рекурсивно устанавливает права доступа для пути
 */
function setChMod(string $path, int $mode = 0755, bool $recursive = true): bool {
    clearstatcache();
    
    if (!file_exists($path)) {
        return false;
    }

    if (is_dir($path) && $recursive) {
        $child = glob($path . '/*');
        $flag = true;

        if (!empty($child)) {
            foreach ($child as $row) {
                if ($row !== '.' && $row !== '..') {
                    if (!setChMod($row, $mode, $recursive)) {
                        $flag = false;
                    }
                }
            }
        }
        
        return @chmod($path, $mode) && $flag;
    }

    return @chmod($path, $mode);
}

/**
 * Проверяет права на запись для пути (рекурсивно)
 */
function checkWriteablePerms(string $path, bool $recursive = true): bool {
    if (!file_exists($path)) {
        return false;
    }

    if (is_dir($path) && $recursive) {
        $child = glob($path . '/*');
        $flag = true;

        if (!empty($child)) {
            foreach ($child as $row) {
                if ($row !== '.' && $row !== '..') {
                    if (!checkWriteablePerms($row, $recursive)) {
                        $flag = false;
                    }
                }
            }
        }
        
        return is_writable($path) && $flag;
    }

    return is_writable($path);
}

/**
 * Проверяет и пытается исправить права доступа для пути
 */
function checkAndFixPermissions(string $path, string $displayPath): array {
    $result = [
        'success' => false,
        'message' => '',
        'fixed' => false
    ];

    // Сначала пытаемся установить правильные права
    $setSuccess = setChMod($path, 0755, true);
    
    // Затем проверяем доступность записи
    $isWritable = checkWriteablePerms($path, true);
    
    if ($setSuccess && $isWritable) {
        $result['success'] = true;
        $result['message'] = "<span style='color:#46B100'>$displayPath</span> - Права установлены корректно";
        $result['fixed'] = true;
    } elseif ($isWritable) {
        $result['success'] = true;
        $result['message'] = "<span style='color:#46B100'>$displayPath</span> - Доступ на запись есть";
    } else {
        $result['success'] = false;
        $result['message'] = "<span style='color:#FF0000'>$displayPath</span> - Ошибка прав доступа";
    }

    return $result;
}

// Пути для проверки с соответствующими путями для отображения
$pathsToCheck = [
    '../sys/cache/' => '/sys/cache/',
    '../sys/avatars/' => '/sys/avatars/',
    '../sys/files/' => '/sys/files/',
    '../sys/logs/' => '/sys/logs/',
    '../sys/tmp/' => '/sys/tmp/',
    '../sys/settings/' => '/sys/settings/',
    '../template/' => '/template/',
    '../sitemap.xml' => '/sitemap.xml',
    '../sys/plugins/' => '/sys/plugins/',
];

// Дополнительные проверки для важных файлов
$importantFiles = [
    '../config.php' => '/config.php',
    '../.htaccess' => '/.htaccess',
];

$output = '';
$allSuccess = true;
$fixedCount = 0;

// Проверка основных путей
foreach ($pathsToCheck as $realPath => $displayPath) {
    $result = checkAndFixPermissions($realPath, $displayPath);
    
    if (!$result['success']) {
        $allSuccess = false;
    }
    
    if ($result['fixed']) {
        $fixedCount++;
    }
    
    $output .= $result['message'] . '<br />';
}

// Проверка важных файлов (только проверка, без изменения прав)
foreach ($importantFiles as $realPath => $displayPath) {
    if (file_exists($realPath) && !is_writable($realPath)) {
        $output .= "<span style='color:#FF9900'>$displayPath</span> - Файл доступен только для чтения<br />";
    } elseif (file_exists($realPath)) {
        $output .= "<span style='color:#46B100'>$displayPath</span> - Файл доступен для записи<br />";
    }
}

// Вывод результатов
echo $output;

if (!$allSuccess) {
    echo '<br /><span style="color:#E90E0E">Внимание: Не удалось установить права на все необходимые папки и файлы!</span><br />';
    echo '<span style="color:#E90E0E">Рекомендуется установить права вручную:</span><br />';
    echo '<span style="color:#666">chmod -R 755 ../sys/</span><br />';
    echo '<span style="color:#666">chmod -R 755 ../template/</span><br />';
    echo '<span style="color:#666">chmod 644 ../sitemap.xml</span><br />';
} else {
    if ($fixedCount > 0) {
        echo '<br /><span style="color:#46B100">Права успешно установлены для ' . $fixedCount . ' объектов!</span><br />';
    } else {
        echo '<br /><span style="color:#46B100">Все права доступа установлены корректно!</span><br />';
    }
}

// Дополнительная информация о сервере
echo '<br /><span style="color:#666">Информация о сервере:</span><br />';
echo '<span style="color:#666">PHP: ' . PHP_VERSION . '</span><br />';
echo '<span style="color:#666">Владелец скрипта: ' . (function_exists('posix_getpwuid') ? posix_getpwuid(fileowner(__FILE__))['name'] : 'unknown') . '</span><br />';
echo '<span style="color:#666">Владелец процесса: ' . (function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : 'unknown') . '</span><br />';

// Проверка безопасных режимов
if (ini_get('safe_mode')) {
    echo '<span style="color:#FF0000">Внимание: safe_mode включен</span><br />';
}

if (ini_get('open_basedir')) {
    echo '<span style="color:#FF9900">Внимание: open_basedir ограничивает доступ</span><br />';
}
