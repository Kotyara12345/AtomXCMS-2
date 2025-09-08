<?php
/*-----------------------------------------------\
| 												 |
| @Author:       Andrey Brykin (Drunya)          |
| @Email:        drunyacoder@gmail.com           |
| @Site:         http://atomx.net                |
| @Version:      1.0                             |
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

// Генерация CSRF-токена
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = __('Admin panel - DB dump');
$pageNav = $pageTitle;
$pageNavr = '';

// Обработка действий с проверкой CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(__('CSRF token validation failed'));
    }
}

if (!empty($_GET['ac']) && $_GET['ac'] == 'make_dump') {
    // Создание резервной копии БД
    createDatabaseBackup();
    
} else if (!empty($_GET['ac']) && $_GET['ac'] == 'delete' && !empty($_GET['id'])) {
    // Удаление резервной копии
    deleteBackup($_GET['id']);
    
} else if (!empty($_GET['ac']) && $_GET['ac'] == 'restore' && !empty($_GET['id'])) {
    // Восстановление БД из резервной копии
    restoreDatabase($_GET['id']);
}

// Функция создания резервной копии БД
function createDatabaseBackup() {
    global $FpsDB, $Register;
    
    $res = $FpsDB->query("SHOW TABLES");
    if (!empty($res)) {
        $backupDir = ROOT . '/sys/logs/db_backups/';
        
        // Создание директории с безопасными правами
        if (!file_exists($backupDir)) {
            if (!mkdir($backupDir, 0755, true)) {
                $_SESSION['errors'] = __('Failed to create backup directory');
                redirect('/admin/dump.php');
                return;
            }
        }
        
        // Проверка возможности записи в директорию
        if (!is_writable($backupDir)) {
            $_SESSION['errors'] = __('Backup directory is not writable');
            redirect('/admin/dump.php');
            return;
        }
        
        $backupFile = $backupDir . date("Y-m-d-H-i") . ".sql";
        $fp = fopen($backupFile, "w");
        
        if ($fp) {
            // Добавление заголовка с информацией о резервной копии
            fwrite($fp, "-- AtomX CMS Database Backup\n");
            fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($fp, "-- Version: " . ($Register['Config']->read('version') ?? 'Unknown') . "\n\n");
            
            foreach ($res as $table) {
                $tableName = current($table);
                
                // Пропускаем системные таблицы, если необходимо
                if (shouldSkipTable($tableName)) {
                    continue;
                }
                
                // Создание структуры таблицы
                createTableStructure($fp, $tableName);
                
                // Дамп данных таблицы
                dumpTableData($fp, $tableName);
            }
            
            fclose($fp);
            
            // Установка безопасных прав на файл
            chmod($backupFile, 0644);
            
            $_SESSION['message'] = __('DB backup complete');
        } else {
            $_SESSION['errors'] = __('Failed to create backup file');
        }
    } else {
        $_SESSION['errors'] = __('No tables found in database');
    }
    
    redirect('/admin/dump.php');
}

// Функция проверки, нужно ли пропускать таблицу
function shouldSkipTable($tableName) {
    // Здесь можно добавить логику для пропуска системных таблиц
    // return preg_match('/^_|^cache_|^temp_/i', $tableName);
    return false;
}

// Функция создания структуры таблицы
function createTableStructure($fp, $tableName) {
    global $FpsDB;
    
    fwrite($fp, "\n\n-- --------------------------------------------------------\n\n");
    fwrite($fp, "--\n-- Table structure for table `$tableName`\n--\n\n");
    
    $query_fields = $FpsDB->query("SHOW CREATE TABLE `$tableName`");
    if (!empty($query_fields)) {
        $createTable = $query_fields[0]['Create Table'];
        fwrite($fp, $createTable . ";\n\n");
    }
}

// Функция дампа данных таблицы
function dumpTableData($fp, $tableName) {
    global $FpsDB;
    
    fwrite($fp, "--\n-- Dumping data for table `$tableName`\n--\n\n");
    
    $data = $FpsDB->query("SELECT * FROM `$tableName`");
    if (!empty($data)) {
        foreach ($data as $row) {
            $columns = array();
            $values = array();
            
            foreach ($row as $column => $value) {
                $columns[] = "`$column`";
                if (is_null($value)) {
                    $values[] = "NULL";
                } else {
                    $values[] = "'" . $FpsDB->escape($value) . "'";
                }
            }
            
            $query = "INSERT INTO `$tableName` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
            fwrite($fp, $query);
        }
    }
    
    fwrite($fp, "\n");
}

// Функция удаления резервной копии
function deleteBackup($backupId) {
    // Валидация имени файла
    $backupId = basename($backupId);
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{2}-[0-9]{2}\.sql$/', $backupId)) {
        $_SESSION['errors'] = __('Invalid backup file name');
        redirect('/admin/dump.php');
        return;
    }
    
    $backupFile = ROOT . '/sys/logs/db_backups/' . $backupId;
    
    // Проверка существования файла и его расположения
    if (!file_exists($backupFile) || !is_file($backupFile)) {
        $_SESSION['errors'] = __('Backup file not found');
        redirect('/admin/dump.php');
        return;
    }
    
    // Дополнительная проверка пути
    $realBackupPath = realpath($backupFile);
    $realBackupDir = realpath(ROOT . '/sys/logs/db_backups/');
    
    if (strpos($realBackupPath, $realBackupDir) !== 0) {
        $_SESSION['errors'] = __('Invalid backup file path');
        redirect('/admin/dump.php');
        return;
    }
    
    if (@unlink($backupFile)) {
        $_SESSION['message'] = __('Backup file is removed');
    } else {
        $_SESSION['errors'] = __('Failed to remove backup file');
    }
    
    redirect('/admin/dump.php');
}

// Функция восстановления БД из резервной копии
function restoreDatabase($backupId) {
    global $FpsDB;
    
    // Валидация имени файла
    $backupId = basename($backupId);
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{2}-[0-9]{2}\.sql$/', $backupId)) {
        $_SESSION['errors'] = __('Invalid backup file name');
        redirect('/admin/dump.php');
        return;
    }
    
    $backupFile = ROOT . '/sys/logs/db_backups/' . $backupId;
    
    // Проверка существования файла и его расположения
    if (!file_exists($backupFile) || !is_file($backupFile)) {
        $_SESSION['errors'] = __('Backup file not found');
        redirect('/admin/dump.php');
        return;
    }
    
    // Дополнительная проверка пути
    $realBackupPath = realpath($backupFile);
    $realBackupDir = realpath(ROOT . '/sys/logs/db_backups/');
    
    if (strpos($realBackupPath, $realBackupDir) !== 0) {
        $_SESSION['errors'] = __('Invalid backup file path');
        redirect('/admin/dump.php');
        return;
    }
    
    // Чтение и выполнение SQL из файла
    $sqlContent = file_get_contents($backupFile);
    if ($sqlContent === false) {
        $_SESSION['errors'] = __('Failed to read backup file');
        redirect('/admin/dump.php');
        return;
    }
    
    // Разделение SQL на отдельные запросы
    $queries = parseSqlQueries($sqlContent);
    
    // Выполнение запросов в транзакции
    try {
        $FpsDB->beginTransaction();
        
        foreach ($queries as $query) {
            if (!empty(trim($query))) {
                $FpsDB->query($query);
            }
        }
        
        $FpsDB->commit();
        $_SESSION['message'] = __('Database is restored');
        
    } catch (Exception $e) {
        $FpsDB->rollBack();
        error_log('Database restore error: ' . $e->getMessage());
        $_SESSION['errors'] = __('Failed to restore database');
    }
    
    redirect('/admin/dump.php');
}

// Функция парсинга SQL запросов из файла
function parseSqlQueries($sqlContent) {
    // Удаление комментариев
    $sqlContent = preg_replace('/--.*$/m', '', $sqlContent);
    $sqlContent = preg_replace('/\/\*.*?\*\//s', '', $sqlContent);
    
    // Разделение на отдельные запросы
    $queries = array();
    $currentQuery = '';
    $inString = false;
    $stringChar = '';
    
    $tokens = token_get_all('<?php ' . $sqlContent);
    foreach ($tokens as $token) {
        if (is_array($token)) {
            $token = $token[1];
        }
        
        if ($inString) {
            $currentQuery .= $token;
            if ($token === $stringChar) {
                $inString = false;
            }
        } else {
            if ($token === "'" || $token === '"') {
                $inString = true;
                $stringChar = $token;
                $currentQuery .= $token;
            } elseif ($token === ';') {
                $currentQuery .= $token;
                $queries[] = trim($currentQuery);
                $currentQuery = '';
            } else {
                $currentQuery .= $token;
            }
        }
    }
    
    // Добавление последнего запроса, если он есть
    if (!empty(trim($currentQuery))) {
        $queries[] = trim($currentQuery);
    }
    
    return $queries;
}

// Получение списка резервных копий
$backupDir = ROOT . '/sys/logs/db_backups/';
$current_dumps = array();

if (file_exists($backupDir) && is_dir($backupDir)) {
    $files = glob($backupDir . '*.sql');
    if ($files !== false) {
        foreach ($files as $file) {
            if (is_file($file)) {
                $current_dumps[] = $file;
            }
        }
    }
    
    // Сортировка по дате (новые сначала)
    usort($current_dumps, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
}

include_once ROOT . '/admin/template/header.php';

$csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
?>

<div class="warning">
<?php echo __('DB backup is cool') ?>
</div>

<div class="list">
    <div class="title"></div>
    <form action="dump.php?ac=make_dump" method="POST" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>" />
        <button type="submit" class="add-cat-butt" style="border:none; background:none; cursor:pointer;">
            <div class="add"></div><?php echo __('Create DB backup') ?>
        </button>
    </form>
    <div class="level1">
        <div class="items">
        
        <?php if (!empty($current_dumps)): 
            foreach ($current_dumps as $dump): 
                $fileName = basename($dump);
                $fileSize = round((filesize($dump) / 1024), 1);
                $fileDate = date('Y-m-d H:i:s', filemtime($dump));
        ?>    
        
            <div class="level2">
                <div class="number"><?php echo $fileSize ?> Kb</div>
                <div class="title" title="<?php echo h($fileDate) ?>">
                    <?php echo h($fileName) ?>
                </div>
                <div class="buttons">
                    <form action="dump.php?ac=delete&id=<?php echo urlencode($fileName); ?>" method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>" />
                        <button type="submit" class="delete" title="<?php echo __('Delete') ?>" onclick="return confirm('<?php echo __('Are you sure?') ?>')"></button>
                    </form>
                    <form action="dump.php?ac=restore&id=<?php echo urlencode($fileName); ?>" method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>" />
                        <button type="submit" class="undo" title="<?php echo __('Restore') ?>" onclick="return confirm('<?php echo __('Are you sure? This will overwrite current database.') ?>')"></button>
                    </form>
                </div>
            </div>
        
        <?php endforeach; 
        else: ?> 
        
            <div class="level2">
                <div class="number"></div>
                <div class="title"><?php echo __('DB backups not found') ?></div>
            </div>
        
        <?php endif; ?>
        </div>
    </div>
</div>

<?php include_once 'template/footer.php';
