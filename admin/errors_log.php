<?php
##################################################
##												##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      0.9                            ##
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

// Генерация CSRF-токена
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Безопасный путь к логу ошибок
$log_path = ROOT . '/sys/logs/php_errors.log';

// Обработка очистки лога с проверкой CSRF
if (!empty($_GET['del'])) {
    // Проверка CSRF-токена
    if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
        die(__('CSRF token validation failed'));
    }
    
    // Проверка существования и доступности файла
    if (file_exists($log_path) && is_writable($log_path)) {
        // Очистка файла
        if (file_put_contents($log_path, '') !== false) {
            $_SESSION['message'] = __('Operation is successful');
        } else {
            $_SESSION['errors'] = __('Failed to clear log file');
        }
    } else {
        $_SESSION['errors'] = __('Log file is not writable or does not exist');
    }
    
    redirect('/admin/errors_log.php');
}

// Получение информации о файле лога
$file_exists = file_exists($log_path) && is_readable($log_path);
$file_size = $file_exists ? filesize($log_path) : 0;

// Настройки пагинации
$bytes_per_page = 50 * 1024; // 50KB per page
$total_pages = $file_exists ? max(1, ceil($file_size / $bytes_per_page)) : 0;

// Получение текущей страницы
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$current_page = max(1, min($current_page, $total_pages));

// Генерация пагинации
list($pages_html, $page) = pagination($total_pages, 1, '/admin/errors_log.php?');

// Чтение данных лога
$data = '';
if ($file_exists) {
    $offset = max(0, $file_size - ($current_page * $bytes_per_page));
    $length = min($bytes_per_page, $file_size - $offset);
    
    if ($length > 0) {
        $handle = fopen($log_path, 'r');
        if ($handle) {
            if (fseek($handle, $offset) === 0) {
                $data = fread($handle, $length);
                if ($data === false) {
                    $data = '';
                }
            }
            fclose($handle);
        }
    }
}

// Фильтрация sensitive данных из лога
function filterSensitiveData($logContent) {
    // Фильтрация паролей, токенов и другой sensitive информации
    $patterns = [
        // Пароли в POST данных
        '/password[=:][^&\s]*/i' => 'password=***',
        // Токены сессий
        '/[a-f0-9]{32,}/i' => '***', // MD5 и подобные хэши
        // Пути к файловой системе
        '/\/home\/[^\/]+\//' => '/home/***/',
        '/\/var\/www\/[^\/]+\//' => '/var/www/***/',
    ];
    
    foreach ($patterns as $pattern => $replacement) {
        $logContent = preg_replace($pattern, $replacement, $logContent);
    }
    
    return $logContent;
}

// Применение фильтрации
$filtered_data = filterSensitiveData($data);

$pageTitle = __('Errors log');
$pageNav = $pageTitle;
$pageNavr = '';

include_once ROOT . '/admin/template/header.php';

$csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
?>

<div class="list">
    <div class="title">
        <div class="pages"><?php echo $pages_html ?></div>
    </div>
    <a href="<?php echo get_url('/admin/errors_log.php?del=1&csrf_token=' . urlencode($csrfToken)); ?>" 
       onclick="return confirm('<?php echo __('Are you sure you want to clear the error log?'); ?>')">
        <div class="add-cat-butt">
            <div class="add"></div><?php echo __('Clean log') ?>
        </div>
    </a>
    <table class="grid" cellspacing="0" style="width:100%;">
        <?php if(!empty($filtered_data)): ?>
        <tr>
            <td>
                <div style="height:800px; overflow-y:auto; font-family: monospace; font-size: 12px; background: #f8f8f8; padding: 10px; border: 1px solid #ddd;">
                    <?php echo nl2br(htmlspecialchars($filtered_data, ENT_QUOTES, 'UTF-8')) ?>
                </div>
            </td>
        </tr>
        <?php else: ?>
        <tr>
            <td style="text-align: center; padding: 20px;">
                <?php echo __('No entries found') ?>
                <?php if (!$file_exists): ?>
                <br><small><?php echo __('Log file does not exist or is not readable') ?></small>
                <?php endif; ?>
            </td>
        </tr>
        <?php endif; ?>
    </table>
</div>

<?php
include_once 'template/footer.php';
?>
