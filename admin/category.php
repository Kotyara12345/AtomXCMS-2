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
| @copyright     ©Andrey Brykin 2010-2014        |
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

// Security headers
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Validate session and permissions
if (!isset($_SESSION['user_id']) || !$Register['ACL']->isAdmin($_SESSION['user_id'])) {
    die(__('Access denied'));
}

// CSRF token validation function
function validateCsrfToken() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            die(__('CSRF token validation failed'));
        }
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Return current module which we editing
 */
function getCurrMod() {
    $ModulesManager = new ModulesManager();
    $allow_mods = $ModulesManager->getAllowedModules('categories');
    
    if (empty($_GET['mod'])) {
        redirect('/admin/category.php?mod=news');
    }
    
    $mod = trim($_GET['mod']);
    // Validate module name format
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $mod) || !in_array($mod, $allow_mods)) {
        redirect('/admin/category.php?mod=news');
    }
    
    return $mod;
}

/**
 * Try find collision
 */
function deleteCatsCollision() {
    global $FpsDB;
    
    $mod = getCurrMod();
    $tableName = $FpsDB->escape($mod . '_categories');
    
    $collision = $FpsDB->select($tableName, DB_ALL, array(
        'joins' => array(
            array(
                'type' => 'LEFT',
                'table' => $tableName,
                'alias' => 'b',
                'cond' => '`b`.`id` = `a`.`parent_id`',
            ),
        ),
        'fields' => array('COUNT(`b`.`id`) as cnt', '`a`.*'),
        'alias' => 'a',
        'group' => '`a`.`parent_id`',
    ));
    
    if (count($collision)) {
        foreach ($collision as $key => $cat) {
            if (!empty($cat['parent_id']) && empty($cat['cnt'])) {
                $FpsDB->save($tableName,
                array(
                    'parent_id' => 0,
                ), 
                array(
                    'id' => (int)$cat['id']
                ));
            }
        }
    }
}

deleteCatsCollision();

$head = file_get_contents('template/header.php');
$page_title = __(ucfirst(getCurrMod()));
$popups = '';

// Validate action parameter
$valid_actions = array('add', 'del', 'index', 'edit', 'off_home', 'on_home');
$action = isset($_GET['ac']) && in_array($_GET['ac'], $valid_actions) ? $_GET['ac'] : 'index';

// Validate CSRF token for actions that modify data
if (in_array($action, ['add', 'del', 'edit', 'off_home', 'on_home'])) {
    validateCsrfToken();
}

switch($action) {
    case 'index':
        $content = index($page_title);
        break;
    case 'del':
        $content = delete();
        break;
    case 'add':
        $content = add();
        break;
    case 'edit':
        $content = edit();
        break;
    case 'on_home':
        $content = on_home();
        break;
    case 'off_home':
        $content = off_home();
        break;
    default:
        $content = index();
}

$pageTitle = $page_title;
$pageNav = $page_title;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';
?>

<div class="warning">
<?php echo __('If you delete a category, all the materials in it will be removed') ?>
</div>
<?php

echo $popups;
echo $content;

function getTreeNode($array, $id = false) {
    $out = array();
    foreach ($array as $key => $val) {
        if ($id === false && empty($val['parent_id'])) {
            $out[$val['id']] = array(
                'category' => $val,
                'subcategories' => getTreeNode($array, $val['id']),
            );
            unset($array[$key]);
        } else {
            if ($val['parent_id'] == $id) {
                $out[$val['id']] = array(
                    'category' => $val,
                    'subcategories' => getTreeNode($array, $val['id']),
                );
                unset($array[$key]);
            }
        }
    }
    return $out;
}

function buildCatsList($catsTree, $catsList, $indent = '') {
    global $popups;

    $Register = Register::getInstance();
    $FpsDB = $Register['DB'];
    $acl_groups = $Register['ACL']->get_group_info();
    $out = '';
    
    foreach ($catsTree as $id => $node) {
        $cat = $node['category'];
        $no_access = ($cat['no_access'] !== '') ? explode(',', $cat['no_access']) : array();

        $_catList = (count($catsList)) ? $catsList : array();
        $cat_selector = '<select name="id_sec" id="cat_secId">';
        if (empty($cat['parent_id'])) {
            $cat_selector .= '<option value="0" selected="selected">&nbsp;</option>';
        } else {
            $cat_selector .= '<option value="0">&nbsp;</option>';
        }
        
        foreach ($_catList as $selector_result) {
            if ($selector_result['id'] == $cat['id']) continue;
            $selected = ($cat['parent_id'] == $selector_result['id']) ? ' selected="selected"' : '';
            $cat_selector .= '<option value="' . (int)$selector_result['id'] . '"' . $selected . '>' 
                . h($selector_result['title']) . '</option>';
        }
        $cat_selector .= '</select>';
        
        $out .= '<div class="level2">
                    <div class="number">' . (int)$cat['id'] . '</div>
                    <div class="title">' . $indent . h($cat['title']) . '</div>
                    <div class="buttons">';
                        
        $out .= '<a title="' . __('Delete') . '" href="?ac=del&id=' . (int)$cat['id'] 
            . '&mod=' . h(getCurrMod()) . '&csrf_token=' . h($_SESSION['csrf_token']) . '" class="delete" onClick="return _confirm();"></a>'
            . '<a href="javascript://" class="edit" title="' . __('Edit') . '" ' 
            . 'onClick="openPopup(\'' . (int)$cat['id'] . '_cat\');"></a>';
            
        if (getCurrMod() != 'foto') {
            if ($cat['view_on_home'] == 1) {
                $out .=  '<a class="off-home" title="' . __('View on home') . '" href="?ac=off_home&id=' 
                    . (int)$cat['id'] . '&mod=' . h(getCurrMod()) . '&csrf_token=' . h($_SESSION['csrf_token']) . '" onClick="return _confirm();"></a>';
            } else {
                $out .=  '<a class="on-home" title="' . __('View on home') . '" href="?ac=on_home&id=' 
                    . (int)$cat['id'] . '&mod=' . h(getCurrMod()) . '&csrf_token=' . h($_SESSION['csrf_token']) . '" onClick="return _confirm();"></a>';
            }
        }

        $out .= '</div><div class="posts">' . (int)$cat['cnt'] . '</div></div>';        
            
        $popups .= '<div id="' . (int)$cat['id'] . '_cat" class="popup">
                <div class="top">
                    <div class="title">' . __('Category editing') . '</div>
                    <div onClick="closePopup(\'' . (int)$cat['id'] . '_cat\');" class="close"></div>
                </div>
                <form action="category.php?mod=' . h(getCurrMod()) . '&ac=edit&id=' . (int)$cat['id'] . '" method="POST">
                <input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_token']) . '" />
                <div class="items">
                    <div class="item">
                        <div class="left">
                            ' . __('Parent section') . ':
                        </div>
                        <div class="right">' . $cat_selector . '</div>
                        <div class="clear"></div>
                    </div>
                    <div class="item">
                        <div class="left">
                            ' . __('Title') . ':
                        </div>
                        <div class="right"><input type="text" name="title" value="' . h($cat['title']) . '" /></div>
                        <div class="clear"></div>
                    </div>
                    <div class="item">
                        <div class="left">
                            ' . __('Access for') . ':
                        </div>
                        <div class="right"><table class="checkbox-collection"><tr>';
                        $n = 0;
                        if ($acl_groups && is_array($acl_groups)) {
                            foreach ($acl_groups as $id => $group) {
                                if (($n % 3) == 0) $popups .= '</tr><tr>';
                                $checked = (in_array($id, $no_access)) ? '' : ' checked="checked"';
                                
                                $inp_id = 'access_' . (int)$cat['id'] . '_' . (int)$id;
                                
                                $popups .= '<td><input id="' . h($inp_id) . '" type="checkbox" name="access[' . (int)$id . ']" value="' . (int)$id 
                                . '"' . $checked . '  /><label for="' . h($inp_id) . '">' . h($group['title']) . '</label></td>';
                                $n++;
                            }
                        }
                        $popups .= '</tr></table></div>
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
            
        if (count($node['subcategories'])) {
            $out .= buildCatsList($node['subcategories'], $catsList, $indent . '<div class="cat-indent">&nbsp;</div>');
        }
    }
    
    return $out;
}

function index(&$page_title) {
    global $popups;

    $Register = Register::getInstance();
    $FpsDB = $Register['DB'];
    $acl_groups = $Register['ACL']->get_group_info();

    $page_title .= ' - ' . __('Sections editor');
    $cat_selector = '<select name="id_sec" id="cat_secId">';
    $cat_selector .= '<option value="0">&nbsp;</option>';
    
    $query_params = array(
        'joins' => array(),
        'fields' => array('a.*', 'COUNT(b.`id`) as cnt'),
        'alias' => 'a',
        'group' => 'a.`id`',
    );

    // count a materials if such model is exists
    try {
        $Register['ModManager']->getModelInstance(getCurrMod());
        $query_params['joins'][] = array(
            'alias' => 'b',
            'type' => 'LEFT',
            'table' => getCurrMod(),
            'cond' => 'a.`id` = b.`category_id`',
        );
    } catch (Exception $e) {
        $query_params['joins'][] = array(
            'alias' => 'b',
            'type' => 'LEFT',
            'table' => '(SELECT NULL as category_id, NULL as id)',
            'cond' => 'a.`id` = b.`category_id`',
        );
    }

    $all_sections = $FpsDB->select(getCurrMod() . '_categories', DB_ALL, $query_params);
    foreach ($all_sections as $result) {
        $cat_selector .= '<option value="' . (int)$result['id'] . '">' . h($result['title']) . '</option>';
    }
    $cat_selector .= '</select>';
    
    $html = '';

    $cats_tree = getTreeNode($all_sections);
    
    $popups .= '<div id="addCat" class="popup">
            <div class="top">
                <div class="title">' . __('Adding category') . '</div>
                <div onClick="closePopup(\'addCat\');" class="close"></div>
            </div>
            <form action="category.php?mod=' . h(getCurrMod()) . '&ac=add" method="POST">
            <input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_token']) . '" />
            <div class="items">
                <div class="item">
                    <div class="left">
                        ' . __('Parent section') . ':
                    </div>
                    <div class="right">' . $cat_selector . '</div>
                    <div class="clear"></div>
                </div>
                <div class="item">
                    <div class="left">
                        ' . __('Title') . ':
                    </div>
                    <div class="right">
                        <input type="hidden" name="type" value="cat" />
                        <input type="text" name="title" /></div>
                    <div class="clear"></div>
                </div>
                <div class="item">
                    <div class="left">
                        ' . __('Access for') . ':
                    </div>
                    <div class="right">
                        <table class="checkbox-collection"><tr>';
                        $n = 0;
                        if ($acl_groups && is_array($acl_groups)) {
                            foreach ($acl_groups as $id => $group) {
                                if (($n % 3) == 0) $popups .= '</tr><tr>';
                                $inp_id = 'new_access_' . (int)$id;
                                $popups .= '<td><input id="' . h($inp_id) . '" type="checkbox" name="access[' . (int)$id . ']" value="' . (int)$id 
                                . '"  checked="checked" /><label for="' . h($inp_id) . '">' . h($group['title']) . '</label></td>';
                                $n++;
                            }
                        }
                        $popups .= '</tr></table>
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
    
    $html .= '<div class="list">
        <div class="title">' . __('Categories management') . '</div>
        <div class="add-cat-butt" onClick="openPopup(\'addCat\');"><div class="add"></div>' . __('Add section') . '</div>
        <div class="level1">
            <div class="head">
                <div class="title">' . __('Category') . '</div>
                <div class="buttons">
                </div>
                <div class="clear"></div>
            </div>
            <div class="items">';
            
    if (count($all_sections) > 0) {
        $html .= buildCatsList($cats_tree, $all_sections);     
    } else {
        $html .= __('Sections not found');
    }
    
    $html .= '</div></div></div>';

    return $html;
}

function edit() {
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        redirect('/admin/category.php?mod=' . getCurrMod());
    }
    
    $id = (int)$_GET['id'];
    if ($id < 1) {
        redirect('/admin/category.php?mod=' . getCurrMod());
    }
    
    if (!isset($_POST['title'])) {
        redirect('/admin/category.php?mod=' . getCurrMod());
    }
    
    global $FpsDB;
    $Register = Register::getInstance();
    $acl_groups = $Register['ACL']->get_group_info();
    $model = $Register['ModManager']->getModelInstance(getCurrMod() . 'Categories');
    
    $error = '';

    if (empty(trim($_POST['title']))) {
        $error .= '<li>' . __('Empty field "title"') . '</li>';
    }

    $parent_id = isset($_POST['id_sec']) ? (int)$_POST['id_sec'] : 0;
    $entity = $model->getById($id);
    
    if (empty($entity)) {
        $error .= '<li>' . __('Edited section not found') . '</li>';
    }

    // Check if parent section exists if specified
    if ($parent_id > 0) {
        $target_section = $model->getById($parent_id);
        if (empty($target_section)) {
            $error .= '<li>' . __('Parent section not found') . '</li>';
        }
        
        // Prevent circular reference
        if ($parent_id == $id) {
            $error .= '<li>' . __('Category cannot be its own parent') . '</li>';
        }
    }
    
    // If errors exist
    if (!empty($error)) {
        $_SESSION['errors'] = $Register['DocParser']->wrapErrors($error);
        redirect('/admin/category.php?mod=' . getCurrMod());
    }
    
    $no_access = array();
    if ($acl_groups && is_array($acl_groups) && isset($_POST['access'])) {
        foreach ($acl_groups as $gid => $group) {
            if (!array_key_exists($gid, $_POST['access'])) {
                $no_access[] = $gid;
            }
        }
    }
    $no_access = (count($no_access)) ? implode(',', $no_access) : '';
    
    // Prepare data to save
    $entity->setTitle(substr(trim($_POST['title']), 0, 100));
    $entity->setNo_access($no_access);
    
    if ($parent_id > 0 && !empty($target_section)) {
        $path = $target_section->getPath();
        $path = (!empty($path)) ? $path . $parent_id . '.' : $parent_id . '.';
        $entity->setParent_id($parent_id);
        $entity->setPath($path);
    } else {
        $entity->setParent_id(0);
        $entity->setPath('');
    }

    $entity->save();
        
    redirect('/admin/category.php?mod=' . getCurrMod());
}

function add() {
    global $FpsDB;
    
    if (!isset($_POST['title'])) {
        redirect('/admin/category.php?mod=' . getCurrMod());
    }
    
    $Register = Register::getInstance();
    $acl_groups = $Register['ACL']->get_group_info();
    $model = $Register['ModManager']->getModelInstance(getCurrMod() . 'Categories');
    
    $error = '';
    $title = trim($_POST['title']);
    $parent_id = isset($_POST['id_sec']) ? (int)$_POST['id_sec'] : 0;
    
    if ($parent_id > 0) {
        $target_section = $model->getById($parent_id);
        if (empty($target_section)) {
            $error .= '<li>' . __('Parent section not found') . '</li>';
        }
    }
    
    if (empty($title)) {
        $error .= '<li>' . __('Empty field "title"') . '</li>';
    }
    
    $no_access = array();
    if ($acl_groups && is_array($acl_groups) && isset($_POST['access'])) {
        foreach ($acl_groups as $id => $group) {
            if (!array_key_exists($id, $_POST['access'])) {
                $no_access[] = $id;
            }
        }
    }
    $no_access = (count($no_access)) ? implode(',', $no_access) : '';
    
    // If errors exist
    if (!empty($error)) {
        $_SESSION['errors'] = $error;
        redirect('/admin/category.php?mod=' . getCurrMod());
    }
    
    $path = '';
    if ($parent_id > 0 && !empty($target_section)) {
        $path = $target_section->getPath();
        $path = (!empty($path)) ? $path . $parent_id . '.' : $parent_id . '.';
    }
    
    $data = array(
        'title' => $title,
        'parent_id' => $parent_id,
        'no_access' => $no_access,
    );
    
    if (!empty($path)) {
        $data['path'] = $path;
    }
    
    $entityName = getCurrMod() . 'CategoriesEntity';
    $entity = new $entityName($data);
    $entity->save();
        
    redirect('/admin/category.php?mod=' . getCurrMod());
}

function delete() {    
    global $Register, $FpsDB;
    
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        redirect('/admin/category.php?mod=' . getCurrMod());
    }
    
    $id = (int)$_GET['id'];
    if ($id < 1) {
        redirect('/admin/category.php?mod=' . getCurrMod());
    }
    
    $model = $Register['ModManager']->getModelInstance(getCurrMod() . 'Categories');
    $total = $model->getTotal();
    
    if ($total <= 1) {
        $_SESSION['errors'] = $Register['DocParser']->wrapErrors(__('You can\'t remove the last category'), true);
        redirect('/admin/category.php?mod=' . getCurrMod());
    }
    
    // Use iterative approach instead of recursion to avoid stack overflow
    $categoriesToDelete = array($id);
    $allCategoriesToDelete = array();
    
    while (!empty($categoriesToDelete)) {
        $currentId = array_shift($categoriesToDelete);
        $allCategoriesToDelete[] = $currentId;
        
        $children = $model->getCollection(array('parent_id' => $currentId));
        if ($children) {
            foreach ($children as $child) {
                $categoriesToDelete[] = $child->getId();
            }
        }
    }
    
    // Delete in reverse order (children first)
    $allCategoriesToDelete = array_reverse($allCategoriesToDelete);
    foreach ($allCategoriesToDelete as $categoryId) {
        delete_category($categoryId);
    }
    
    redirect('/admin/category.php?mod=' . getCurrMod());
}

function delete_category($id) {
    global $Register, $FpsDB;
    
    $attachModel = $Register['ModManager']->getModelInstance(getCurrMod() . 'Attaches');
    $sectionsModel = $Register['ModManager']->getModelInstance(getCurrMod() . 'Categories');
    $model = $Register['ModManager']->getModelInstance(getCurrMod());
    
    $records = $model->getCollection(array('category_id' => $id));
    
    // Delete materials and attaches
    if (is_array($records) && count($records) > 0) {
        foreach ($records as $record) {
            // Delete associated attachments first
            if (method_exists($record, 'getAttaches')) {
                $attaches = $record->getAttaches();
                if ($attaches) {
                    foreach ($attaches as $attach) {
                        $attach->delete();
                    }
                }
            }
            $record->delete();
        }
    }
    
    // Delete category
    $entity = $sectionsModel->getById($id);
    if ($entity) {
        $entity->delete();
    }
    
    return true;
}

function on_home($cid = false) {
    global $FpsDB;
    
    if (getCurrMod() == 'foto') {
        redirect('/admin/category.php?mod=' . getCurrMod());
    }
    
    if ($cid === false) {
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            redirect('/admin/category.php?mod=' . getCurrMod());
        }
        
        $id = (int)$_GET['id'];
        if ($id < 1) {
            redirect('/admin/category.php?mod=' . getCurrMod());
        }
    } else {
        $id = (int)$cid;
    }

    // Use iterative approach instead of recursion
    $categoriesToProcess = array($id);
    $allCategoriesToProcess = array();
    
    while (!empty($categoriesToProcess)) {
        $currentId = array_shift($categoriesToProcess);
        $allCategoriesToProcess[] = $currentId;
        
        $children = $FpsDB->select(getCurrMod() . '_categories', DB_ALL, array(
            'cond' => array('parent_id' => $currentId)
        ));
        
        if (count($children)) {
            foreach ($children as $child) {
                $categoriesToProcess[] = (int)$child['id'];
            }
        }
    }
    
    foreach ($allCategoriesToProcess as $categoryId) {
        $FpsDB->save(getCurrMod() . '_categories', 
            array('view_on_home' => 1), 
            array('id' => $categoryId)
        );
        $FpsDB->save(getCurrMod(), 
            array('view_on_home' => 1), 
            array('category_id' => $categoryId)
        );
    }
        
    if ($cid === false) {
        redirect('/admin/category.php?mod=' . getCurrMod());
    }
}

function off_home($cid = false) {
    global $FpsDB;
    
    if (getCurrMod() == 'foto') {
        redirect('/admin/category.php?mod=' . getCurrMod());
    }
    
    if ($cid === false) {
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            redirect('/admin/category.php?mod=' . getCurrMod());
        }
        
        $id = (int)$_GET['id'];
        if ($id < 1) {
            redirect('/admin/category.php?mod=' . getCurrMod());
        }
    } else {
        $id = (int)$cid;
    }

    // Use iterative approach instead of recursion
    $categoriesToProcess = array($id);
    $allCategoriesToProcess = array();
    
    while (!empty($categoriesToProcess)) {
        $currentId = array_shift($categoriesToProcess);
        $allCategoriesToProcess[] = $currentId;
        
        $children = $FpsDB->select(getCurrMod() . '_categories', DB_ALL, array(
            'cond' => array('parent_id' => $currentId)
        ));
        
        if (count($children)) {
            foreach ($children as $child) {
                $categoriesToProcess[] = (int)$child['id'];
            }
        }
    }
    
    foreach ($allCategoriesToProcess as $categoryId) {
        $FpsDB->save(getCurrMod() . '_categories', 
            array('view_on_home' => 0), 
            array('id' => $categoryId)
        );
        $FpsDB->save(getCurrMod(), 
            array('view_on_home' => 0), 
            array('category_id' => $categoryId)
        );
    }
        
    if ($cid === false) {
        redirect('/admin/category.php?mod=' . getCurrMod());
    }
}

include_once 'template/footer.php';
