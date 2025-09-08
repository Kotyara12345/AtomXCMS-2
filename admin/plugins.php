<?php
/*-----------------------------------------------\
|                                                |
|  Author:       Andrey Brykin (Drunya)          |
|  Version:      2.0                             |
|  Project:      CMS                             |
|  package       CMS AtomX                       |
|  subpackege    Admin Panel module              |
|  copyright     ©Andrey Brykin 2010-2014        |
|  Last mod.     2014/01/15                      |
\-----------------------------------------------*/

/*-----------------------------------------------\
|                                                |
|  any partial or not partial extension          |
|  CMS AtomX,without the consent of the          |
|  author, is illegal                            |
|------------------------------------------------|
|  Любое распространение                         |
|  CMS AtomX или ее частей,                      |
|  без согласия автора, является не законным     |
\-----------------------------------------------*/

// Проверка безопасности - предотвращение прямого доступа
if (!defined('IN_ADMIN') || !defined('IN_SCRIPT')) {
    die('Access denied');
}

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Проверка прав доступа
if (!$Auth->hasPermission('admin_panel', 'manage_plugins')) {
    die(__('Access denied'));
}

$pageTitle = __('Plugins');
$Register = Register::getInstance();

// Инициализация CSRF токена
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Валидация действий
$allowed_actions = array('index', 'off', 'on', 'edit', 'delete', 'install');
$action = isset($_GET['ac']) && in_array($_GET['ac'], $allowed_actions) ? $_GET['ac'] : 'index';

// Валидация CSRF токена для POST действий
function validateCsrfToken() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = '<div class="warning error">' . __('Security token mismatch') . '</div>';
        redirect('/admin/plugins.php');
    }
}

// Обработка действий
switch ($action) {
    case 'index':
        $content = indexAction($pageTitle);
        break;
    case 'edit':
        validateCsrfToken();
        $content = editPluginAction($pageTitle);
        break;
    case 'off':
        validateCsrfToken();
        $content = togglePluginAction(false);
        break;
    case 'on':
        validateCsrfToken();
        $content = togglePluginAction(true);
        break;
    case 'delete':
        validateCsrfToken();
        $content = deletePluginAction();
        break;
    case 'install':
        validateCsrfToken();
        $content = installPluginAction();
        break;
    default:
        $content = indexAction($pageTitle);
}

$pageNav = $pageTitle;
$pageNavr = '<a href="plugins.php">' . __('Plugins list') . '</a>';

include_once ROOT . '/admin/template/header.php';

// Отображение сообщений
if (!empty($_SESSION['message'])) {
    echo $_SESSION['message'];
    unset($_SESSION['message']);
}

echo $content;
include_once ROOT . '/admin/template/footer.php';

/**
 * Главная страница управления плагинами
 */
function indexAction(&$page_title) {
    $content = '';
    $plugins = getPluginsList();

    if (empty($plugins)) {
        return '<div class="list">
            <div class="title">' . __('Plugins') . '</div>
            <div class="level1">
                <div class="no-plugins">
                    ' . __('No available plugins found') . '
                    <div class="install-hint">
                        <a href="get_plugins.php" class="install-link">' . __('Install plugins') . '</a>
                    </div>
                </div>
            </div>
        </div>';
    }

    $content .= "<div class=\"list\">
        <div class='title'>" . $pageTitle . "</div>
        <div class='action-panel'>
            <a href='get_plugins.php' class='add-cat-butt'>
                <div class='add'></div>" . __('Install plugin') . "
            </a>
        </div>
        <div class='level1'>
            <div class='head'>
                <div class='title' style='width:15%;'>" . __('Title') . "</div>
                <div class='title' style='width:20%;'>" . __('Description') . "</div>
                <div class='title' style='width:10%;'>" . __('Version') . "</div>
                <div class='title' style='width:10%;'>" . __('Author') . "</div>
                <div class='title' style='width:10%;'>" . __('Hook') . "</div>
                <div class='title' style='width:10%;'>" . __('Status') . "</div>
                <div class='title' style='width:15%;'>" . __('Directory') . "</div>
                <div class='title' style='width:10%;'>" . __('Actions') . "</div>
            </div>
            <div class='items'>";

    foreach ($plugins as $plugin) {
        $status = $plugin['active'] ? 
            '<span class="status-active">' . __('Active') . '</span>' : 
            '<span class="status-inactive">' . __('Inactive') . '</span>';
        
        $actions = '';
        if ($plugin['has_settings']) {
            $actions .= "<a class=\"edit\" href='plugins.php?ac=edit&dir=" . urlencode($plugin['dir']) . "&csrf_token=" . $_SESSION['csrf_token'] . "' title='" . __('Settings') . "'></a>";
        }
        
        if ($plugin['active']) {
            $actions .= "&nbsp;<a class=\"off\" href='plugins.php?ac=off&dir=" . urlencode($plugin['dir']) . "&csrf_token=" . $_SESSION['csrf_token'] . "' title='" . __('Deactivate') . "'></a>";
        } else {
            $actions .= "&nbsp;<a class=\"on\" href='plugins.php?ac=on&dir=" . urlencode($plugin['dir']) . "&csrf_token=" . $_SESSION['csrf_token'] . "' title='" . __('Activate') . "'></a>";
        }
        
        $actions .= "&nbsp;<a class=\"delete\" href='plugins.php?ac=delete&dir=" . urlencode($plugin['dir']) . "&csrf_token=" . $_SESSION['csrf_token'] . "' onclick='return confirm(\"" . __('Are you sure you want to delete this plugin?') . "\")' title='" . __('Delete') . "'></a>";

        $content .= "
            <div class='level2'>
                <div class='title2' style='width:15%;'>
                    <strong>" . htmlspecialchars($plugin['title']) . "</strong>
                </div>  
                <div class='title2' style='width:20%;'>
                    " . htmlspecialchars($plugin['description']) . "
                </div>
                <div class='title2' style='width:10%;'>
                    " . htmlspecialchars($plugin['version'] ?? '1.0') . "
                </div>
                <div class='title2' style='width:10%;'>
                    " . htmlspecialchars($plugin['author'] ?? 'Unknown') . "
                </div>
                <div class='title2' style='width:10%;'>
                    <span class='hook-type'>" . htmlspecialchars($plugin['hook']) . "</span>
                </div>
                <div class='title2' style='width:10%;'>
                    " . $status . "
                </div>
                <div class='title2' style='width:15%;'>
                    <code>" . htmlspecialchars($plugin['dir']) . "</code>
                </div>
                <div class='title2-buttons' style='width:10%;'>
                    " . $actions . "
                </div>
            </div>";
    }
    
    $content .= '</div></div></div>';

    return $content;
}

/**
 * Получение списка плагинов
 */
function getPluginsList() {
    $plugins = array();
    $plugin_dirs = glob(ROOT . '/sys/plugins/*', GLOB_ONLYDIR);
    
    if (!empty($plugin_dirs)) {
        foreach ($plugin_dirs as $plugin_dir) {
            $dir_name = basename($plugin_dir);
            
            // Пропускаем системные директории
            if (in_array($dir_name, array('.', '..', '.htaccess'))) {
                continue;
            }
            
            $config_file = $plugin_dir . '/config.dat';
            $settings_file = $plugin_dir . '/settings.php';
            
            $plugin_data = array(
                'dir' => $dir_name,
                'path' => $plugin_dir,
                'has_settings' => file_exists($settings_file),
                'active' => false,
                'title' => $dir_name,
                'description' => '',
                'hook' => 'Unknown'
            );
            
            if (file_exists($config_file)) {
                $config = json_decode(file_get_contents($config_file), true);
                if ($config && is_array($config)) {
                    $plugin_data = array_merge($plugin_data, $config);
                    $plugin_data['active'] = !empty($config['active']);
                }
            }
            
            // Определение типа хука
            $hooks = array('before_view', 'after_view', 'before_content', 'after_content', 'admin_menu');
            foreach ($hooks as $hook) {
                if (strpos($dir_name, $hook) === 0) {
                    $plugin_data['hook'] = $hook;
                    break;
                }
            }
            
            $plugins[] = $plugin_data;
        }
    }
    
    // Сортировка плагинов по названию
    usort($plugins, function($a, $b) {
        return strcmp($a['title'], $b['title']);
    });
    
    return $plugins;
}

/**
 * Редактирование настроек плагина
 */
function editPluginAction(&$page_title) {
    if (empty($_GET['dir'])) {
        redirect('/admin/plugins.php');
    }
    
    $dir = $_GET['dir'];
    if (!preg_match('#^[\w\d_-]+$#i', $dir)) {
        $_SESSION['message'] = '<div class="warning error">' . __('Invalid plugin directory') . '</div>';
        redirect('/admin/plugins.php');
    }
    
    $settings_file_path = ROOT . '/sys/plugins/' . $dir . '/settings.php';
    if (!file_exists($settings_file_path)) {
        return '<div class="list">
            <div class="title">' . __('Plugin settings') . '</div>
            <div class="level1">
                <div class="no-settings">
                    ' . __('No settings available for this plugin') . '
                    <div class="back-link">
                        <a href="plugins.php">' . __('Back to plugins list') . '</a>
                    </div>
                </div>
            </div>
        </div>';
    }
    
    // Загрузка конфигурации плагина
    $config_file = ROOT . '/sys/plugins/' . $dir . '/config.dat';
    $plugin_config = array();
    if (file_exists($config_file)) {
        $plugin_config = json_decode(file_get_contents($config_file), true);
    }
    
    $page_title = __('Plugin settings') . ' - ' . ($plugin_config['title'] ?? $dir);
    
    // Включение файла настроек с буферизацией вывода
    ob_start();
    try {
        include_once $settings_file_path;
        $output = ob_get_clean();
    } catch (Exception $e) {
        ob_end_clean();
        $output = '<div class="warning error">' . __('Error loading plugin settings: ') . htmlspecialchars($e->getMessage()) . '</div>';
    }
    
    // Добавление CSRF токена к форме, если она существует
    if (strpos($output, '<form') !== false && strpos($output, 'csrf_token') === false) {
        $output = preg_replace('/(<form[^>]*>)/i', '$1<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">', $output);
    }
    
    return '<div class="list">
        <div class="title">' . $page_title . '</div>
        <div class="level1">
            <div class="back-link" style="padding:10px;">
                <a href="plugins.php">← ' . __('Back to plugins list') . '</a>
            </div>
            ' . $output . '
        </div>
    </div>';
}

/**
 * Включение/выключение плагина
 */
function togglePluginAction($activate) {
    if (empty($_GET['dir'])) {
        redirect('/admin/plugins.php');
    }
    
    $dir = $_GET['dir'];
    if (!preg_match('#^[\w\d_-]+$#i', $dir)) {
        $_SESSION['message'] = '<div class="warning error">' . __('Invalid plugin directory') . '</div>';
        redirect('/admin/plugins.php');
    }
    
    $plugin_path = ROOT . '/sys/plugins/' . $dir;
    $config_path = $plugin_path . '/config.dat';
    
    if (!is_dir($plugin_path)) {
        $_SESSION['message'] = '<div class="warning error">' . __('Plugin not found') . '</div>';
        redirect('/admin/plugins.php');
    }
    
    $config = array();
    if (file_exists($config_path)) {
        $config = json_decode(file_get_contents($config_path), true);
    }
    
    $config['active'] = (bool)$activate;
    
    if (file_put_contents($config_path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        $action = $activate ? __('activated') : __('deactivated');
        $_SESSION['message'] = '<div class="warning ok">' . sprintf(__('Plugin %s successfully'), $action) . '</div>';
        
        // Очистка кэша после изменения статуса плагина
        $Cache = new Cache;
        $Cache->clean();
    } else {
        $_SESSION['message'] = '<div class="warning error">' . __('Error saving plugin configuration') . '</div>';
    }
    
    redirect('/admin/plugins.php');
}

/**
 * Удаление плагина
 */
function deletePluginAction() {
    if (empty($_GET['dir'])) {
        redirect('/admin/plugins.php');
    }
    
    $dir = $_GET['dir'];
    if (!preg_match('#^[\w\d_-]+$#i', $dir)) {
        $_SESSION['message'] = '<div class="warning error">' . __('Invalid plugin directory') . '</div>';
        redirect('/admin/plugins.php');
    }
    
    $plugin_path = ROOT . '/sys/plugins/' . $dir;
    
    if (!is_dir($plugin_path)) {
        $_SESSION['message'] = '<div class="warning error">' . __('Plugin not found') . '</div>';
        redirect('/admin/plugins.php');
    }
    
    // Дополнительное подтверждение для важных плагинов
    $config_path = $plugin_path . '/config.dat';
    $is_important = false;
    if (file_exists($config_path)) {
        $config = json_decode(file_get_contents($config_path), true);
        $is_important = !empty($config['important']);
    }
    
    if ($is_important && empty($_POST['confirm_delete'])) {
        return '<div class="list">
            <div class="title">' . __('Confirm deletion') . '</div>
            <div class="level1">
                <div class="warning important">
                    <h3>' . __('Important plugin') . '</h3>
                    <p>' . __('This plugin is marked as important. Are you sure you want to delete it?') . '</p>
                    <form method="POST" action="plugins.php?ac=delete&dir=' . urlencode($dir) . '">
                        <input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">
                        <input type="hidden" name="confirm_delete" value="1">
                        <button type="submit" class="save-button">' . __('Yes, delete') . '</button>
                        <a href="plugins.php" class="cancel-button">' . __('Cancel') . '</a>
                    </form>
                </div>
            </div>
        </div>';
    }
    
    // Удаление плагина
    if (deleteDirectory($plugin_path)) {
        $_SESSION['message'] = '<div class="warning ok">' . __('Plugin deleted successfully') . '</div>';
        
        // Очистка кэша после удаления плагина
        $Cache = new Cache;
        $Cache->clean();
    } else {
        $_SESSION['message'] = '<div class="warning error">' . __('Error deleting plugin') . '</div>';
    }
    
    redirect('/admin/plugins.php');
}

/**
 * Рекурсивное удаление директории
 */
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}

/**
 * Установка плагина
 */
function installPluginAction() {
    // Эта функция может быть расширена для обработки загрузки
    // и установки плагинов из ZIP-архивов
    $_SESSION['message'] = '<div class="warning error">' . __('Installation functionality not implemented') . '</div>';
    redirect('/admin/plugins.php');
}
