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
if (!$Auth->hasPermission('admin_panel', 'manage_menu')) {
    die(__('Access denied'));
}

$pageTitle = __('Menu editor');
$pageNav = $pageTitle;
$pageNavR = '';
$popups = '';

$menu_conf_file = ROOT . '/sys/settings/menu.dat';
$menu_backup_dir = ROOT . '/sys/settings/backups/';

// Создание директории для бэкапов
if (!is_dir($menu_backup_dir)) {
    @mkdir($menu_backup_dir, 0755, true);
}

// Инициализация CSRF токена
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Функция валидации CSRF
function validateCsrfToken() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = '<div class="warning error">' . __('Security token mismatch') . '</div>';
        redirect('/admin/menu_editor.php');
    }
}

// Создание бэкапа меню
function createMenuBackup($menu) {
    global $menu_backup_dir;
    $backup_file = $menu_backup_dir . 'menu_backup_' . date('Y-m-d_H-i-s') . '.dat';
    file_put_contents($backup_file, serialize($menu));
    return $backup_file;
}

// Валидация данных меню
function validateMenuData($data) {
    $errors = array();
    
    if (empty($data['title'])) {
        $errors[] = __('Visible text is required');
    }
    
    if (empty($data['url'])) {
        $errors[] = __('URL is required');
    } elseif (!filter_var($data['url'], FILTER_VALIDATE_URL) && $data['url'] !== '#' && !preg_match('#^/[^/]#', $data['url'])) {
        $errors[] = __('Invalid URL format');
    }
    
    if (!empty($data['id']) && !is_numeric($data['id'])) {
        $errors[] = __('Invalid menu item ID');
    }
    
    return $errors;
}

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    
    if (!empty($_GET['ac']) && $_GET['ac'] === 'add') {
        $data = array(
            'title' => trim($_POST['ankor'] ?? ''),
            'url' => trim($_POST['url'] ?? ''),
            'prefix' => trim($_POST['prefix'] ?? ''),
            'sufix' => trim($_POST['sufix'] ?? ''),
            'newwin' => !empty($_POST['newwin']) ? 1 : 0
        );
        
        $errors = validateMenuData($data);
        
        if (empty($errors)) {
            if (file_exists($menu_conf_file)) {
                $menu = unserialize(file_get_contents($menu_conf_file));
                // Создание бэкапа перед изменением
                createMenuBackup($menu);
            } else {
                $menu = array();
            }
            
            $data['id'] = getMenuPointId($menu) + 1;
            $menu[] = $data;
            
            if (file_put_contents($menu_conf_file, serialize($menu)) {
                $_SESSION['message'] = '<div class="warning ok">' . __('Menu item added successfully') . '</div>';
            } else {
                $_SESSION['message'] = '<div class="warning error">' . __('Error saving menu') . '</div>';
            }
            
            redirect('/admin/menu_editor.php');
        } else {
            $_SESSION['message'] = '<div class="warning error"><ul><li>' . implode('</li><li>', $errors) . '</li></ul></div>';
        }
        
    } elseif (!empty($_GET['ac']) && $_GET['ac'] === 'edit' && !empty($_GET['id'])) {
        $id = intval($_GET['id']);
        if ($id < 1) redirect('/admin/menu_editor.php');
        
        $data = array(
            'id' => $id,
            'title' => trim($_POST['ankor'] ?? ''),
            'url' => trim($_POST['url'] ?? ''),
            'prefix' => trim($_POST['prefix'] ?? ''),
            'sufix' => trim($_POST['sufix'] ?? ''),
            'newwin' => !empty($_POST['newwin']) ? 1 : 0
        );
        
        $errors = validateMenuData($data);
        
        if (empty($errors)) {
            $menu = unserialize(file_get_contents($menu_conf_file));
            // Создание бэкапа перед изменением
            createMenuBackup($menu);
            
            $menu = saveMenu($id, $data, $menu);
            
            if (file_put_contents($menu_conf_file, serialize($menu))) {
                $_SESSION['message'] = '<div class="warning ok">' . __('Menu item updated successfully') . '</div>';
            } else {
                $_SESSION['message'] = '<div class="warning error">' . __('Error saving menu') . '</div>';
            }
            
            redirect('/admin/menu_editor.php');
        } else {
            $_SESSION['message'] = '<div class="warning error"><ul><li>' . implode('</li><li>', $errors) . '</li></ul></div>';
        }
        
    } elseif (isset($_POST['data']) && is_array($_POST['data'])) {
        // Сохранение порядка меню
        $array_menu = parseNode($_POST['data']);
        
        if (is_array($array_menu)) {
            $menu = unserialize(file_get_contents($menu_conf_file));
            // Создание бэкапа перед изменением
            createMenuBackup($menu);
            
            if (file_put_contents($menu_conf_file, serialize($array_menu))) {
                echo json_encode(array('success' => true, 'message' => __('Menu order saved successfully')));
            } else {
                echo json_encode(array('success' => false, 'message' => __('Error saving menu order')));
            }
        } else {
            echo json_encode(array('success' => false, 'message' => __('Invalid menu data')));
        }
        exit;
    }
}

// Функции работы с меню (остаются в основном без изменений, но с улучшенной обработкой ошибок)
function saveMenu($id, $data, $menu) {
    if (!empty($menu) && is_array($menu)) {
        foreach ($menu as $key => $value) {
            if (!empty($value['id']) && $value['id'] == $id) {
                $menu[$key] = $data;
                if (isset($value['sub'])) $menu[$key]['sub'] = $value['sub'];
                break;
            }
            
            if (!empty($value['sub']) && is_array($value['sub'])) {
                $menu[$key]['sub'] = saveMenu($id, $data, $value['sub']);
            }
        }
    }
    return $menu;
}    

function getMenuPointId($menu) {
    $n = 0;
    if (empty($menu) || !is_array($menu)) return 0;
    
    foreach ($menu as $k => $v) {
        if (empty($v['id'])) continue;
        if ($n < $v['id']) $n = $v['id'];
        if (!empty($v['sub']) && is_array($v['sub'])) {
            $ns = getMenuPointId($v['sub']);
            if ($n < $ns) $n = $ns;
        }
    }
    return $n;
}

function parseNode($data) {
    $output = array();
    $n = 0;
    
    if (!empty($data) && is_array($data)) {    
        foreach ($data as $key => $value) {
            if (empty($value['url']) || empty($value['title']) || empty($value['id'])) {
                continue;
            }
            
            $output[$n] = array(
                'id' => intval($value['id']),
                'url' => trim($value['url']),
                'title' => trim($value['title']),
                'prefix' => !empty($value['prefix']) ? trim($value['prefix']) : '',
                'sufix' => !empty($value['sufix']) ? trim($value['sufix']) : '',
                'newwin' => !empty($value['newwin']) ? intval($value['newwin']) : 0,
            );
            
            if (!empty($value['sub']) && is_array($value['sub'])) {
                $output[$n]['sub'] = parseNode($value['sub']);
            }
            
            $n++;
        }
    }
    
    return $output;
}

function buildMenu($node) {
    $out = '';
    global $popups;
    
    if (!empty($node) && is_array($node)) {    
        foreach ($node as $key => $value) {
            if (empty($value['url']) || empty($value['title']) || empty($value['id'])) continue;
            
            $out .= '<li data-id="' . $value['id'] . '">' . "\n";
            $out .= '<div class="menu-item">' . htmlspecialchars($value['title'])
                . '<input type="hidden" name="id" value="' . $value['id'] . '" />' . "\n" 
                . '<input type="hidden" name="url" value="' . htmlspecialchars($value['url']) . '" />' . "\n" 
                . '<input type="hidden" name="ankor" value="' . htmlspecialchars($value['title']) . '" />' . "\n"
                . '<input type="hidden" name="prefix" value="' . htmlspecialchars($value['prefix']) . '" />' . "\n"
                . '<input type="hidden" name="sufix" value="' . htmlspecialchars($value['sufix']) . '" />' . "\n" 
                . '<input type="hidden" name="newwin" value="' . $value['newwin'] . '" />' . "\n" 
                . '<div class="menu-actions">'
                . '<a class="delete" title="' . __('Delete') . '" '
                . 'onClick="if(confirm(\'' . __('Are you sure you want to delete this menu item?') . '\'))deletePoint(this);"></a>'
                . '<a class="edit" title="' . __('Edit') . '" ' 
                . 'onClick="openPopup(\'edit' . $value['id'] . '\');"></a>' . "\n"
                . '</div>' . "\n"
                . '</div>' . "\n";
                
            $checked = (!empty($value['newwin'])) ? 'checked="checked"' : '';
            
            $popups .= '<div id="edit' . $value['id'] . '" class="popup">
                <div class="top">
                    <div class="title">' . __('Edit menu item') . '</div>
                    <div onClick="closePopup(\'edit' . $value['id'] . '\')" class="close"></div>
                </div>
                <form action="menu_editor.php?ac=edit&id=' . $value['id'] . '" method="POST">
                <input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">
                <div class="items">
                    <div class="item">
                        <div class="left">
                            ' . __('Visible text') . ':
                        </div>
                        <div class="right">
                            <input type="text" name="ankor" value="' . htmlspecialchars($value['title']) . '" required />
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="item">
                        <div class="left">
                            URL:
                        </div>
                        <div class="right">
                            <input type="text" name="url" value="' . htmlspecialchars($value['url']) . '" required />
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="item">
                        <div class="left">
                            ' . __('Prefix') . ':
                        </div>
                        <div class="right">
                            <textarea name="prefix">' . htmlspecialchars($value['prefix']) . '</textarea>
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="item">
                        <div class="left">
                            ' . __('Suffix') . ':
                        </div>
                        <div class="right">
                            <textarea name="sufix">' . htmlspecialchars($value['sufix']) . '</textarea>
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="item">
                        <div class="left">
                            ' . __('Open in new window') . ':
                        </div>
                        <div class="right">
                            <input id="newwin' . $value['id'] . '" type="checkbox" value="1" name="newwin" ' . $checked . ' />
                            <label for="newwin' . $value['id'] . '">' . __('Yes') . '</label>
                        </div>
                        <div class="clear"></div>
                    </div>
                    
                    <div class="item submit">
                        <div class="left"></div>
                        <div class="right" style="float:left;">
                            <input type="submit" value="' . __('Save') . '" name="send" class="save-button" />
                        </div>
                        <div class="clear"></div>
                    </div>
                </div>
                </form>
            </div>';
            
            $out .= '<ul class="submenu">' . "\n";    
            if (!empty($value['sub']) && is_array($value['sub'])) {
                $out .= buildMenu($value['sub']) . "\n";
            }
            $out .= '</ul>' . "\n";
            
            $out .= '</li>';
        }
    }
    
    return $out;
}

// Загрузка меню
$menu = array();
if (file_exists($menu_conf_file)) {
    $menu_data = file_get_contents($menu_conf_file);
    if (!empty($menu_data)) {
        $menu = unserialize($menu_data);
        if ($menu === false) {
            $_SESSION['message'] = '<div class="warning error">' . __('Error loading menu data') . '</div>';
            $menu = array();
        }
    }
}    

include_once ROOT . '/admin/template/header.php';

// Отображение сообщений
if (!empty($_SESSION['message'])) {
    echo $_SESSION['message'];
    unset($_SESSION['message']);
}
?>

<div class="warning">
    * <?php echo __('Create any menu items and sort them by simple drag and drop.') ?><br />
    * <?php echo __('Delete and edit items by clicking buttons on the right side.') ?><br />
    * <?php echo __('Don\'t forget to save changes by clicking the "Save" button.') ?><br />
    * <?php echo __('Use special marker to display menu on the site.') ?><br />
    * <?php echo __('Menu items will be in this format') ?>: 
    <span class="comment">[prefix]&lt;a href="[url]" target="[newwin]"&gt;[title]&lt;/a&gt;[sufix]</span>.<br />
</div>

<script type="text/javascript">
$(function(){
    $('#sort').sortable({
        items: "li",
        placeholder: "sortable-placeholder",
        tolerance: "pointer",
        opacity: 0.8,
        update: function(event, ui) {
            // Автосохранение при изменении порядка
            // saveMenuOrder();
        }
    });
    
    $('#sort').disableSelection();
});

function deletePoint(obj) {
    if (confirm('<?php echo __('Are you sure you want to delete this menu item?') ?>')) {
        var $item = $(obj).closest('li');
        $item.remove();
        saveMenuOrder();
    }
    return false;
}

function saveMenuOrder() {
    var menuData = getMenuData($('#sort'));
    
    $('#sendButton').prop('disabled', true).css('opacity', '0.7');
    
    $.ajax({
        url: 'menu_editor.php',
        type: 'POST',
        data: {
            data: menuData,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        },
        success: function(response) {
            try {
                var result = JSON.parse(response);
                if (result.success) {
                    showMessage(result.message, 'success');
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (e) {
                showMessage('<?php echo __('Error processing response') ?>', 'error');
            }
        },
        error: function() {
            showMessage('<?php echo __('Server error') ?>', 'error');
        },
        complete: function() {
            $('#sendButton').prop('disabled', false).css('opacity', '1');
        }
    });
}

function getMenuData($element) {
    var data = [];
    
    $element.children('li').each(function() {
        var $li = $(this);
        var itemData = {
            id: $li.find('input[name="id"]').val(),
            title: $li.find('input[name="ankor"]').val(),
            url: $li.find('input[name="url"]').val(),
            prefix: $li.find('input[name="prefix"]').val(),
            sufix: $li.find('input[name="sufix"]').val(),
            newwin: $li.find('input[name="newwin"]').val(),
            sub: getMenuData($li.find('> ul.submenu'))
        };
        data.push(itemData);
    });
    
    return data;
}

function showMessage(message, type) {
    var $message = $('<div class="warning ' + type + '">' + message + '</div>');
    $('.warning:first').before($message);
    setTimeout(function() {
        $message.fadeOut(1000, function() {
            $(this).remove();
        });
    }, 3000);
}

// Валидация формы добавления
function validateAddForm(form) {
    var title = form.ankor.value.trim();
    var url = form.url.value.trim();
    
    if (!title) {
        alert('<?php echo __('Please enter visible text') ?>');
        return false;
    }
    
    if (!url) {
        alert('<?php echo __('Please enter URL') ?>');
        return false;
    }
    
    return true;
}
</script>

<div class="list">
    <div class="title"><?php echo __('Menu editor') ?></div>
    
    <div class="action-panel">
        <div onClick="openPopup('addCat');" class="add-cat-butt">
            <div class="add"></div><?php echo __('Add menu item') ?>
        </div>
        
        <button class="save-button" onClick="saveMenuOrder();" id="sendButton">
            <?php echo __('Save order') ?>
        </button>
        
        <div class="menu-marker">
            <?php echo __('Template marker') ?>: 
            <input type="text" value="{MAINMENU}" readonly onClick="this.select();" />
        </div>
    </div>
    
    <div class="level1">
        <div class="items">
            <ul id="sort" class="menu-sortable">
                <?php echo buildMenu($menu); ?>
            </ul>
        </div>
    </div>
</div>

<?php echo $popups; ?>

<div id="addCat" class="popup">
    <div class="top">
        <div class="title"><?php echo __('Add menu item') ?></div>
        <div onClick="closePopup('addCat')" class="close"></div>
    </div>
    <form action="menu_editor.php?ac=add" method="POST" onsubmit="return validateAddForm(this);">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <div class="items">
        <div class="item">
            <div class="left">
                <?php echo __('Visible text') ?>: *
            </div>
            <div class="right">
                <input type="text" name="ankor" value="" required />
            </div>
            <div class="clear"></div>
        </div>
        <div class="item">
            <div class="left">
                URL: *
            </div>
            <div class="right">
                <input type="text" name="url" placeholder="/page or http://example.com" required />
            </div>
            <div class="clear"></div>
        </div>
        <div class="item">
            <div class="left">
                <?php echo __('Prefix') ?>:
            </div>
            <div class="right">
                <textarea name="prefix" placeholder="&lt;li&gt;"></textarea>
            </div>
            <div class="clear"></div>
        </div>
        <div class="item">
            <div class="left">
                <?php echo __('Suffix') ?>:
            </div>
            <div class="right">
                <textarea name="sufix" placeholder="&lt;/li&gt;"></textarea>
            </div>
            <div class="clear"></div>
        </div>
        <div class="item">
            <div class="left">
                <?php echo __('Open in new window') ?>:
            </div>
            <div class="right">
                <input id="newwin" type="checkbox" value="1" name="newwin" />
                <label for="newwin"><?php echo __('Yes') ?></label>
            </div>
            <div class="clear"></div>
        </div>
        
        <div class="item submit">
            <div class="left"></div>
            <div class="right" style="float:left;">
                <input type="submit" value="<?php echo __('Add') ?>" name="send" class="save-button" />
            </div>
            <div class="clear"></div>
        </div>
    </div>
    </form>
</div>

<?php include_once 'template/footer.php'; ?>
