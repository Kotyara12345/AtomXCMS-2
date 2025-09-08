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
if (!$Auth->hasPermission('admin_panel', 'manage_bans')) {
    die(__('Access denied'));
}

$pageTitle = __('Bans by IP');
$banFile = ROOT . '/sys/logs/ip_ban/baned.dat';
$banDir = ROOT . '/sys/logs/ip_ban/';

// Создание директории если не существует
if (!is_dir($banDir)) {
    @mkdir($banDir, 0755, true);
    @chmod($banDir, 0755);
}

// Валидация и обработка действий
$allowed_actions = array('index', 'del', 'add', 'multi_delete');
$action = isset($_GET['ac']) && in_array($_GET['ac'], $allowed_actions) ? $_GET['ac'] : 'index';

// Обработка CSRF токена
function validateCsrfToken() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = '<div class="warning error">' . __('Security token mismatch') . '</div>';
        redirect('/admin/ip_ban.php');
    }
}

// Генерация CSRF токена
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

switch ($action) {
    case 'index':
        $content = indexAction($pageTitle);
        break;
    case 'add':
        validateCsrfToken();
        $content = addAction();
        break;
    case 'del':
        validateCsrfToken();
        $content = deleteAction();
        break;
    case 'multi_delete':
        validateCsrfToken();
        $content = multiDeleteAction();
        break;
    default:
        $content = indexAction($pageTitle);
}

$pageNav = $pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';

echo $content;

include_once ROOT . '/admin/template/footer.php';

/**
 * Главная страница управления банами
 */
function indexAction($page_title) {
    global $banFile;
    
    $content = '';
    $banned_ips = array();
    
    // Чтение забаненных IP-адресов
    if (file_exists($banFile) && is_readable($banFile)) {
        $data = file($banFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!empty($data)) {
            foreach($data as $key => $row) {
                if (!empty($row) && filter_var(trim($row), FILTER_VALIDATE_IP)) {
                    $banned_ips[$key] = trim($row);
                }
            }
        }
    }
    
    // Формирование таблицы IP-адресов
    if (!empty($banned_ips)) {
        $table_content = '';
        foreach($banned_ips as $key => $ip) {
            $table_content .= '<tr>
                <td><input type="checkbox" name="ip_ids[]" value="' . $key . '" class="ip-checkbox"></td>
                <td>' . htmlspecialchars($ip) . '</td>
                <td>' . getIpInfo($ip) . '</td>
                <td width="80px">
                    <a class="delete" onClick="return confirm(\'' . __('Are you sure you want to delete this IP?') . '\');" 
                       href="ip_ban.php?ac=del&id=' . $key . '&csrf_token=' . $_SESSION['csrf_token'] . '"></a>
                </td>
            </tr>';
        }
        
        $content = '<div class="list">
            <div class="title">' . __('Bans by IP') . ' (' . count($banned_ips) . ')</div>
            <div class="action-buttons">
                <div class="add-cat-butt" onClick="openPopup(\'addBan\');">
                    <div class="add"></div>' . __('Add IP') . '
                </div>
                <div class="delete-cat-butt" onClick="deleteSelectedIps();" style="margin-left:10px;">
                    <div class="delete"></div>' . __('Delete selected') . '
                </div>
            </div>
            <form id="multiDeleteForm" action="ip_ban.php?ac=multi_delete" method="POST">
                <input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">
                <table cellspacing="0" class="grid">
                    <thead>
                        <tr>
                            <th width="30px"><input type="checkbox" id="selectAll"></th>
                            <th>' . __('IP Address') . '</th>
                            <th>' . __('Information') . '</th>
                            <th>' . __('Actions') . '</th>
                        </tr>
                    </thead>
                    <tbody>' . $table_content . '</tbody>
                </table>
            </form>
        </div>';
    } else {
        $content = '<div class="list">
            <div class="title">' . __('Bans by IP') . '</div>
            <div class="add-cat-butt" onClick="openPopup(\'addBan\');">
                <div class="add"></div>' . __('Add IP') . '
            </div>
            <div class="no-records">' . __('No banned IP addresses found') . '</div>
        </div>';
    }
    
    // Форма добавления IP
    $content .= '<div id="addBan" class="popup">
        <div class="top">
            <div class="title">' . __('Add IP to ban list') . '</div>
            <div onClick="closePopup(\'addBan\');" class="close"></div>
        </div>
        <form action="ip_ban.php?ac=add" method="POST" onsubmit="return validateIpForm(this);">
            <input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">
            <div class="items">
                <div class="item">
                    <div class="left">
                        ' . __('IP Address') . ':
                        <span class="comment">' . __('Enter IP address or range (e.g., 192.168.1.1 or 192.168.1.0/24)') . '</span>
                    </div>
                    <div class="right">
                        <input type="text" name="ip" placeholder="192.168.1.1" required />
                    </div>
                    <div class="clear"></div>
                </div>
                
                <div class="item">
                    <div class="left">
                        ' . __('Reason') . ':
                        <span class="comment">' . __('Optional reason for banning') . '</span>
                    </div>
                    <div class="right">
                        <textarea name="reason" placeholder="' . __('Reason for ban') . '" rows="3"></textarea>
                    </div>
                    <div class="clear"></div>
                </div>
                
                <div class="item">
                    <div class="left">
                        ' . __('Ban duration') . ':
                    </div>
                    <div class="right">
                        <select name="duration">
                            <option value="permanent">' . __('Permanent') . '</option>
                            <option value="1 hour">' . __('1 hour') . '</option>
                            <option value="1 day">' . __('1 day') . '</option>
                            <option value="1 week">' . __('1 week') . '</option>
                            <option value="1 month">' . __('1 month') . '</option>
                        </select>
                    </div>
                    <div class="clear"></div>
                </div>
                
                <div class="item submit">
                    <div class="left"></div>
                    <div class="right" style="float:left;">
                        <input type="submit" value="' . __('Add to ban list') . '" name="send" class="save-button" />
                    </div>
                    <div class="clear"></div>
                </div>
            </div>
        </form>
    </div>';

    // JavaScript для управления
    $content .= '
    <script type="text/javascript">
    function validateIpForm(form) {
        var ip = form.ip.value.trim();
        if (!ip) {
            alert("' . __('Please enter an IP address') . '");
            return false;
        }
        
        // Basic IP validation
        if (!isValidIp(ip) && !isValidCidr(ip)) {
            alert("' . __('Please enter a valid IP address or CIDR range') . '");
            return false;
        }
        
        return true;
    }
    
    function isValidIp(ip) {
        var pattern = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/;
        if (!pattern.test(ip)) return false;
        
        var parts = ip.split(\'.\');
        for (var i = 0; i < 4; i++) {
            if (parts[i] > 255) return false;
        }
        
        return true;
    }
    
    function isValidCidr(cidr) {
        var pattern = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\/(\d{1,2})$/;
        if (!pattern.test(cidr)) return false;
        
        var parts = cidr.split(\'/\');
        if (!isValidIp(parts[0])) return false;
        
        var mask = parseInt(parts[1]);
        return mask >= 0 && mask <= 32;
    }
    
    function deleteSelectedIps() {
        if (confirm("' . __('Are you sure you want to delete selected IP addresses?') . '")) {
            document.getElementById(\'multiDeleteForm\').submit();
        }
    }
    
    document.getElementById(\'selectAll\').addEventListener(\'change\', function() {
        var checkboxes = document.querySelectorAll(\'.ip-checkbox\');
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = this.checked;
        }
    });
    </script>';
    
    // Показать сообщения об ошибках/успехе
    if (isset($_SESSION['message'])) {
        $content = $_SESSION['message'] . $content;
        unset($_SESSION['message']);
    }
    
    return $content;
}

/**
 * Добавление IP в бан-лист
 */
function addAction() {
    global $banFile;
    
    if (empty($_POST['ip'])) {
        $_SESSION['message'] = '<div class="warning error">' . __('IP address is required') . '</div>';
        redirect('/admin/ip_ban.php');
    }
    
    $ip = trim($_POST['ip']);
    $errors = array();
    
    // Валидация IP адреса или CIDR диапазона
    if (!filter_var($ip, FILTER_VALIDATE_IP) && !isValidCidr($ip)) {
        $errors[] = __('Invalid IP address or CIDR format');
    }
    
    // Проверка на дубликат
    if (file_exists($banFile)) {
        $existing_ips = file($banFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (in_array($ip, $existing_ips)) {
            $errors[] = __('This IP address is already in the ban list');
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['message'] = '<div class="warning error"><ul><li>' . implode('</li><li>', $errors) . '</li></ul></div>';
        redirect('/admin/ip_ban.php');
    }
    
    // Добавление IP в файл
    if (file_put_contents($banFile, $ip . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        $_SESSION['message'] = '<div class="warning error">' . __('Error saving IP address') . '</div>';
    } else {
        // Логирование действия
        logAction('IP ban added: ' . $ip . ' - Reason: ' . ($_POST['reason'] ?? 'Not specified'));
        $_SESSION['message'] = '<div class="warning ok">' . __('IP address added to ban list') . '</div>';
    }
    
    redirect('/admin/ip_ban.php');
}

/**
 * Удаление IP из бан-листа
 */
function deleteAction() {
    global $banFile;
    
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        $_SESSION['message'] = '<div class="warning error">' . __('Invalid IP identifier') . '</div>';
        redirect('/admin/ip_ban.php');
    }
    
    $id = (int)$_GET['id'];
    
    if (!file_exists($banFile)) {
        $_SESSION['message'] = '<div class="warning error">' . __('Ban list file not found') . '</div>';
        redirect('/admin/ip_ban.php');
    }
    
    $data = file($banFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($data) || !isset($data[$id])) {
        $_SESSION['message'] = '<div class="warning error">' . __('IP address not found in ban list') . '</div>';
        redirect('/admin/ip_ban.php');
    }
    
    $deleted_ip = $data[$id];
    unset($data[$id]);
    
    // Перезапись файла без удаленного IP
    if (file_put_contents($banFile, implode(PHP_EOL, $data) . PHP_EOL, LOCK_EX) === false) {
        $_SESSION['message'] = '<div class="warning error">' . __('Error deleting IP address') . '</div>';
    } else {
        logAction('IP ban removed: ' . $deleted_ip);
        $_SESSION['message'] = '<div class="warning ok">' . __('IP address removed from ban list') . '</div>';
    }
    
    redirect('/admin/ip_ban.php');
}

/**
 * Массовое удаление IP-адресов
 */
function multiDeleteAction() {
    global $banFile;
    
    if (empty($_POST['ip_ids']) || !is_array($_POST['ip_ids'])) {
        $_SESSION['message'] = '<div class="warning error">' . __('No IP addresses selected') . '</div>';
        redirect('/admin/ip_ban.php');
    }
    
    if (!file_exists($banFile)) {
        $_SESSION['message'] = '<div class="warning error">' . __('Ban list file not found') . '</div>';
        redirect('/admin/ip_ban.php');
    }
    
    $data = file($banFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $deleted_ips = array();
    
    // Удаление выбранных IP
    foreach ($_POST['ip_ids'] as $id) {
        $id = (int)$id;
        if (isset($data[$id])) {
            $deleted_ips[] = $data[$id];
            unset($data[$id]);
        }
    }
    
    // Перезапись файла
    if (file_put_contents($banFile, implode(PHP_EOL, $data) . PHP_EOL, LOCK_EX) === false) {
        $_SESSION['message'] = '<div class="warning error">' . __('Error deleting IP addresses') . '</div>';
    } else {
        logAction('Multiple IP bans removed: ' . implode(', ', $deleted_ips));
        $_SESSION['message'] = '<div class="warning ok">' . sprintf(__('%d IP addresses removed from ban list'), count($deleted_ips)) . '</div>';
    }
    
    redirect('/admin/ip_ban.php');
}

/**
 * Валидация CIDR диапазона
 */
function isValidCidr($cidr) {
    $parts = explode('/', $cidr);
    if (count($parts) != 2) return false;
    
    $ip = $parts[0];
    $mask = (int)$parts[1];
    
    return filter_var($ip, FILTER_VALIDATE_IP) && $mask >= 0 && $mask <= 32;
}

/**
 * Получение информации об IP
 */
function getIpInfo($ip) {
    // Здесь можно добавить интеграцию с сервисами типа ipapi.com
    // Пока возвращаем базовую информацию
    if (strpos($ip, '/') !== false) {
        return __('CIDR Range');
    }
    
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return __('IPv4 Address');
    }
    
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return __('IPv6 Address');
    }
    
    return __('Unknown format');
}

/**
 * Логирование действий
 */
function logAction($message) {
    $logFile = ROOT . '/sys/logs/ip_ban/actions.log';
    $logMessage = date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL;
    @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

?>
