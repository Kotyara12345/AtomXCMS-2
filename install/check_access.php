<?php
@ini_set('display_errors', '0');

// Устанавливает права доступа к файлам и директориям
function setChMod(string $path, int $mode = 0755, bool $recursive = true): bool {
    clearstatcache();
    $flag = true;

    if (file_exists($path) && is_dir($path) && $recursive) {
        $children = glob($path . '/*');

        if (!empty($children)) {
            // Рекурсивный вызов
            foreach ($children as $child) {
                if ($child !== '.' && $child !== '..') {
                    if (!setChMod($child, $mode, $recursive)) {
                        $flag = false;
                    }
                }
            }
        }
        if (!@chmod($path, $mode) || !$flag) {
            return false;
        }
        return true;
    } elseif (file_exists($path)) {
        return @chmod($path, $mode);
    }
    
    return false;
}

// Проверяет, доступен ли путь для записи
function checkWritablePerms(string $path, bool $recursive = true): bool {
    if (file_exists($path) && is_dir($path) && $recursive) {
        $children = glob($path . '/*');
        $flag = true;

        if (!empty($children)) {
            // Рекурсивный вызов
            foreach ($children as $child) {
                if ($child !== '.' && $child !== '..') {
                    if (!checkWritablePerms($child, $recursive)) {
                        $flag = false;
                    }
                }
            }
        }
        if (!@is_writable($path) || !$flag) {
            return false;
        }
        return true;
    } else {
        return @is_writable($path);
    }
}

$out = '';
$flag = true;

// Проверяем права для нужных директорий и файлов
$pathsToCheck = [
    '../sys/cache/',
    '../sys/avatars/',
    '../sys/files/',
    '../sys/logs/',
    '../sys/tmp/',
    '../sys/settings/',
    '../template/',
    '../sitemap.xml',
    '../sys/plugins/'
];

foreach ($pathsToCheck as $path) {
    if (!setChMod($path) && !checkWritablePerms($path)) {
        $out .= '<span style="color:#FF0000">' . h($path) . '</span> - Права выставлены не верно<br />';
        $flag = false;
    }
}

echo $out;

if (!$flag) {
    echo '<span style="color:#E90E0E">Не удалось выставить права на все необходимые папки и файлы! Сделайте это в ручную.</span><br />';
} else {
    echo '<span style="color:#46B100">Права на все необходимые файлы установлены верно!</span><br />';
}

// Функция для экранирования вывода
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
?>
