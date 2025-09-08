<?php
##################################################
##                                              ##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      1.2                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackege    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2014       ##
##################################################

##################################################
##                                              ##
## any partial or not partial extension         ##
## CMS AtomX,without the consent of the         ##
## author, is illegal                           ##
##################################################
## Любое распространение                        ##
## CMS AtomX или ее частей,                     ##
## без согласия автора, является не законным    ##
##################################################

// Проверка безопасности - предотвращение прямого доступа
if (!defined('IN_ADMIN') || !defined('IN_SCRIPT')) {
    die('Access denied');
}

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Проверка прав доступа
if (!$Auth->hasPermission('admin_panel', 'manage_templates')) {
    die(__('Access denied'));
}

// Инициализация CSRF токена
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Валидация CSRF токена
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = '<div class="warning error">' . __('Security token mismatch') . '</div>';
        redirect('/admin/default_dis.php');
    }
}

// Основная логика восстановления шаблона
function restoreTemplateFiles() {
    $currentTemplate = Config::read('template');
    if (empty($currentTemplate)) {
        throw new Exception(__('Template not configured'));
    }
    
    $templatePath = ROOT . '/template/' . $currentTemplate;
    if (!is_dir($templatePath)) {
        throw new Exception(__('Template directory not found'));
    }
    
    // Поиск всех файлов шаблона
    $templateFiles = findTemplateFiles($templatePath);
    $restoredFiles = 0;
    $errors = array();
    
    foreach ($templateFiles as $file) {
        $backupFile = $file . '.stand';
        
        if (file_exists($backupFile)) {
            try {
                if (restoreFile($backupFile, $file)) {
                    $restoredFiles++;
                }
            } catch (Exception $e) {
                $errors[] = sprintf(__('Error restoring file %s: %s'), basename($file), $e->getMessage());
            }
        }
    }
    
    return array(
        'restored' => $restoredFiles,
        'errors' => $errors,
        'total' => count($templateFiles)
    );
}

// Рекурсивный поиск файлов шаблона
function findTemplateFiles($directory) {
    $files = array();
    $allowedExtensions = array('css', 'html', 'htm', 'js', 'tpl', 'php');
    $ignoreDirs = array('.', '..', '.git', '.svn', 'cache', 'backups');
    
    if (!is_dir($directory)) {
        return $files;
    }
    
    $items = scandir($directory);
    foreach ($items as $item) {
        if (in_array($item, $ignoreDirs)) {
            continue;
        }
        
        $path = $directory . '/' . $item;
        
        if (is_dir($path)) {
            $files = array_merge($files, findTemplateFiles($path));
        } else {
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            if (in_array(strtolower($extension), $allowedExtensions)) {
                $files[] = $path;
            }
        }
    }
    
    return $files;
}

// Восстановление файла из резервной копии
function restoreFile($backupFile, $targetFile) {
    // Проверка существования backup файла
    if (!file_exists($backupFile)) {
        return false;
    }
    
    // Проверка прав на запись
    if (file_exists($targetFile) && !is_writable($targetFile)) {
        throw new Exception(__('Target file is not writable'));
    }
    
    // Проверка прав на чтение backup файла
    if (!is_readable($backupFile)) {
        throw new Exception(__('Backup file is not readable'));
    }
    
    // Создание резервной копии текущего файла
    if (file_exists($targetFile)) {
        $backupDir = dirname($targetFile) . '/backups/';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $backupName = $backupDir . basename($targetFile) . '.bak_' . date('Y-m-d_H-i-s');
        if (!copy($targetFile, $backupName)) {
            throw new Exception(__('Could not create backup of current file'));
        }
    }
    
    // Копирование стандартного файла
    if (!copy($backupFile, $targetFile)) {
        throw new Exception(__('Could not restore file'));
    }
    
    // Установка правильных прав
    chmod($targetFile, 0644);
    
    // Удаление backup файла после успешного восстановления
    unlink($backupFile);
    
    return true;
}

// Обработка запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_restore'])) {
    try {
        $result = restoreTemplateFiles();
        
        if (!empty($result['errors'])) {
            $_SESSION['message'] = '<div class="warning error">' .
                sprintf(__('Restored %d of %d files. Errors: %s'), 
                $result['restored'], 
                $result['total'],
                implode('<br>', $result['errors'])) . 
                '</div>';
        } else {
            $_SESSION['message'] = '<div class="warning ok">' .
                sprintf(__('Template successfully restored. Restored %d files'), 
                $result['restored']) . 
                '</div>';
        }
        
        // Очистка кэша после восстановления шаблона
        $Cache = new Cache;
        $Cache->clean();
        
    } catch (Exception $e) {
        $_SESSION['message'] = '<div class="warning error">' . 
            __('Error restoring template: ') . $e->getMessage() . 
            '</div>';
    }
    
    redirect('/admin/default_dis.php');
}

// Если запрос GET, показываем форму подтверждения
$pageTitle = __('Restore Template');
$pageNav = $pageTitle;
$pageNavr = '';

include_once ROOT . '/admin/template/header.php';

// Проверка существования backup файлов
$currentTemplate = Config::read('template');
$templatePath = ROOT . '/template/' . $currentTemplate;
$backupFiles = array();

if (is_dir($templatePath)) {
    $backupFiles = glob($templatePath . '/**/*.stand', GLOB_BRACE);
}

?>

<div class="content-box">
    <div class="box-body">
        <div class="box-header">
            <h2><?php echo $pageTitle; ?></h2>
        </div>
        
        <div class="warning">
            <strong><?php echo __('Warning!'); ?></strong><br>
            <?php echo __('This action will restore all template files to their default state.'); ?><br>
            <?php echo __('Any custom modifications will be lost.'); ?><br>
            <?php echo __('Current backups will be created before restoration.'); ?>
        </div>
        
        <?php if (empty($backupFiles)): ?>
            <div class="warning error">
                <?php echo __('No backup files found for restoration.'); ?><br>
                <?php echo __('Standard template files are already in use or backups were not created.'); ?>
            </div>
        <?php else: ?>
            <div class="warning info">
                <?php echo sprintf(__('Found %d backup files for restoration.'), count($backupFiles)); ?>
                <ul>
                    <?php foreach (array_slice($backupFiles, 0, 5) as $file): ?>
                        <li><?php echo htmlspecialchars(basename($file)); ?></li>
                    <?php endforeach; ?>
                    <?php if (count($backupFiles) > 5): ?>
                        <li>...</li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="confirm_restore" value="1">
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="confirm_backup" value="1" required>
                        <?php echo __('I understand that all custom modifications will be lost.'); ?>
                    </label>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-danger" onclick="return confirm('<?php echo __('Are you sure you want to restore the template? This action cannot be undone.'); ?>')">
                        <?php echo __('Restore Template'); ?>
                    </button>
                    <a href="/admin/default_dis.php" class="btn btn-secondary">
                        <?php echo __('Cancel'); ?>
                    </a>
                </div>
            </form>
        <?php endif; ?>
        
        <div class="box-footer">
            <h3><?php echo __('Template Information'); ?></h3>
            <div class="template-info">
                <p><strong><?php echo __('Current template'); ?>:</strong> <?php echo htmlspecialchars($currentTemplate); ?></p>
                <p><strong><?php echo __('Template path'); ?>:</strong> <?php echo htmlspecialchars($templatePath); ?></p>
                <p><strong><?php echo __('Backup files found'); ?>:</strong> <?php echo count($backupFiles); ?></p>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
// Подтверждение действия
document.addEventListener('DOMContentLoaded', function() {
    var form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            var checkbox = document.querySelector('input[name="confirm_backup"]');
            if (!checkbox || !checkbox.checked) {
                e.preventDefault();
                alert('<?php echo __('Please confirm that you understand the consequences.'); ?>');
                return false;
            }
            
            return confirm('<?php echo __('Are you absolutely sure you want to restore the template? All custom changes will be permanently lost.'); ?>');
        });
    }
});
</script>

<?php
include_once 'template/footer.php';
