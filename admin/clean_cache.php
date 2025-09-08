<?php
/*-----------------------------------------------\
| 												 |
| @Author:       Andrey Brykin (Drunya)          |
| @Email:        drunyacoder@gmail.com           |
| @Site:         http://atomx.net                |
| @Version:      1.4                             |
| @Project:      CMS                             |
| @package       CMS AtomX                       |
| @subpackege    Admin Panel module  			 |
| @copyright     ©Andrey Brykin 2010-2013        |
\-----------------------------------------------*/

/*-----------------------------------------------\
| 												 |
|  any partial or not partial extension          |
|  CMS AtomX,without the consent of the          |
|  author, is illegal                            |
|------------------------------------------------|
|  Любое распространение                         |
|  CMS AtomX или ее частей,                      |
|  без согласия автора, является не законным     |
\-----------------------------------------------*/

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

// Проверка CSRF-токена для POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(__('CSRF token validation failed'));
    }
} else {
    // Для GET-запросов показываем форму подтверждения
    showConfirmationForm();
    exit;
}

// Очистка кэша
$cacheCleared = clearCache();

if ($cacheCleared) {
    $_SESSION['message'] = __('Cache is cleared');
} else {
    $_SESSION['errors'] = __('Failed to clear cache');
}

redirect('/admin');

/**
 * Показывает форму подтверждения очистки кэша
 */
function showConfirmationForm() {
    include_once ROOT . '/admin/template/header.php';
    ?>
    <div class="content">
        <div class="warning">
            <?php echo __('Are you sure you want to clear all cache?') ?>
        </div>
        <form action="clean_cache.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>" />
            <div class="form-actions">
                <input type="submit" value="<?php echo __('Yes, clear cache'); ?>" class="button" />
                <a href="/admin/" class="button"><?php echo __('Cancel'); ?></a>
            </div>
        </form>
    </div>
    <?php
    include_once ROOT . '/admin/template/footer.php';
}

/**
 * Выполняет очистку кэша
 * 
 * @return bool true если очистка прошла успешно, false в противном случае
 */
function clearCache() {
    global $Register, $Snippets;
    
    $success = true;
    
    // Очистка основного кэша
    try {
        $Register['Cache']->clean();
    } catch (Exception $e) {
        error_log('Cache cleaning error: ' . $e->getMessage());
        $success = false;
    }
    
    // Очистка сниппетов
    try {
        $Snippets = new AtmSnippets;
        $Snippets->cleanCahe();
    } catch (Exception $e) {
        error_log('Snippets cache cleaning error: ' . $e->getMessage());
        $success = false;
    }
    
    // Очистка кэша шаблонов
    try {
        $templatesCacheDir = ROOT . '/sys/cache/templates/';
        if (is_dir($templatesCacheDir)) {
            $success = $success && clearDirectory($templatesCacheDir);
        }
    } catch (Exception $e) {
        error_log('Templates cache cleaning error: ' . $e->getMessage());
        $success = false;
    }
    
    return $success;
}

/**
 * Безопасно очищает директорию, сохраняя структуру папок
 * 
 * @param string $directory Путь к директории
 * @return bool true если очистка прошла успешно
 */
function clearDirectory($directory) {
    if (!is_dir($directory)) {
        return false;
    }
    
    $success = true;
    $items = scandir($directory);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $path = $directory . '/' . $item;
        
        if (is_dir($path)) {
            // Рекурсивно очищаем поддиректории, но не удаляем их
            $success = $success && clearDirectory($path);
        } else {
            // Удаляем только файлы, не директории
            if (!unlink($path)) {
                error_log("Failed to delete cache file: $path");
                $success = false;
            }
        }
    }
    
    return $success;
}
