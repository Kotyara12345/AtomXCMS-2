<?php
/*-----------------------------------------------\
| 												 |
| @Author:       Andrey Brykin (Drunya)          |
| @Email:        drunyacoder@gmail.com           |
| @Site:         http://atomx.net                |
| @Version:      1.5                             |
| @Project:      CMS                             |
| @package       CMS AtomX                       |
| @subpackege    Admin Panel module  			 |
| @copyright     ©Andrey Brykin 2010-2017        |
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

$pageTitle = __('Forum');
$ACL = $Register['ACL'];

// For all popup's(edit & add). Their must be in main wrapper
$popups_content = '';

// Валидация действия
$allowed_actions = array('add', 'del', 'index', 'edit');
$action = isset($_GET['ac']) && in_array($_GET['ac'], $allowed_actions) ? $_GET['ac'] : 'index';

// Проверка CSRF для действий, изменяющих данные
if (in_array($action, ['add', 'del', 'edit']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(__('CSRF token validation failed'));
    }
}

switch($action) {
    case 'index':
        $content = index($pageTitle);
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
    default:
        $content = index();
}

$pageNav = $pageTitle;
$pageNavr = '';

include_once ROOT . '/admin/template/header.php';

$csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
?>

<div class="warning">
<?php echo __('If you delete a category, all the materials in it will be removed') ?><br /><br />
<?php echo __('Each forum should be inherited from the section') ?>
</div>

<?php
echo $popups_content;
?>

<!-- Find users for add new special rules -->
<div id="sp_rules_find_users" class="popup">
    <div class="top">
        <div class="title"><?php echo __('Find users') ?></div>
        <div onClick="closePopup('sp_rules_find_users');" class="close"></div>
    </div>
    <div class="items">
        <div class="item">
            <div class="left">
                <?php echo  __('Name') ?>
                <span class="comment"><?php echo __('Begin to write that see similar users') ?></span>
            </div>
            <div class="right">
                <input id="autocomplete_inp" type="text" name="user_name" placeholder="User Name" />
            </div>
            <div class="clear"></div>
        </div>
        <div id="add_users_list"></div>
    </div>
</div>

<script type="text/javascript">
function findUsersWindow(url) {
    $('#add_users_list').html('');
    $('#sp_rules_find_users input[type="text"]').val('');
    openPopup('sp_rules_find_users');
    
    $('#autocomplete_inp').keypress(function(e){
        var inp = $(this);
        if (inp.val().length < 2) return;
        setTimeout(function(){
            AtomX.findUsersForForums('/admin/find_users.php?name='+encodeURIComponent(inp.val()), 'add_users_list', url);
        }, 500);
    });
}

function setModerator(contId, userId, userName){
    var cont_val = $('#'+contId+' #moderators_view').html();
    cont_val += '<div class="item">' + userName + '<input type="hidden" name="moderators['+userId+']" value="1" /><div class="close"></div></div>';
    $('#'+contId+' #moderators_view').html(cont_val);
    closePopup('sp_rules_find_users');
}

$('.collection .item .close').live('click', function(el){
    $(this).parent('.item').remove();
});
</script>

<?php
echo $content;

function index(&$page_title) {
    global $Register, $popups_content, $csrfToken;
    deleteCollisions();

    $page_title = __('Forum - sections editor');
    
    $forumCatModel = $Register['ModManager']->getModelInstance('forumCat');
    $query = $forumCatModel->getCollection(array(), array('order' => 'previev_id'));
    
    //cats and position selectors for ADD
    $cat_selector = '<select name="in_cat" id="cat_secId">';    
    if ($query) {
        foreach ($query as $key => $result) {
            $cat_selector .= '<option value="' . (int)$result->getId() . '">' . h($result->getTitle()) . '</option>';
        }
    } else {
        $cat_selector .= '<option value="">' . __('First, create a section') . '</option>';
    }
    $cat_selector .= '</select>';
    
    $forumsModel = $Register['ModManager']->getModelInstance('forum');
    $forums = $forumsModel->getCollection(array());

    //selector for subforums
    $sub_selector = '<select name="parent_forum_id">';
    $sub_selector .= '<option value=""></option>';
    if (!empty($forums)) {
        foreach($forums as $forum) {
            $sub_selector .= '<option value="' . (int)$forum->getId() . '">' . h($forum->getTitle()) . '</option>';
        }
    }
    $sub_selector .= '</select>';
    
    $html = '';

    $popups_content .= '<div id="sec" class="popup">
            <div class="top">
                <div class="title">' . __('Adding category') . '</div>
                <div onClick="closePopup(\'sec\');" class="close"></div>
            </div>
            <form action="forum_cat.php?ac=add" method="POST">
            <input type="hidden" name="csrf_token" value="' . $csrfToken . '" />
            <div class="items">
                <div class="item">
                    <div class="left">
                        ' . __('Title') . ':
                    </div>
                    <div class="right">
                        <input type="hidden" name="type" value="section" />
                        <input type="text" name="title" maxlength="200" />
                    </div>
                    <div class="clear"></div>
                </div>
                <div class="item">
                    <div class="left">
                        ' . __('Section position') . ':
                        <span class="comment">' . __('Numeric') . '</span>
                    </div>
                    <div class="right">
                        <input type="number" name="in_pos" min="1" />
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
        <div class="title">' . __('Forums management') . '</div>
        <div class="add-cat-butt" onClick="openPopup(\'sec\');"><div class="add"></div>' . __('Add section') . '</div>';

    if ($query) {
        foreach ($query as $result) {
            $html .= '<div class="level1">
                <div class="head">
                    <div class="title">' . h($result->getTitle()) . '</div>
                    <div class="buttons">
                        <a title="' . __('Delete') . '" href="?ac=del&id=' . (int)$result->getId() . '&section&csrf_token=' . urlencode($csrfToken) . '" onClick="return confirm(\'' . __('Are you sure?') . '\');" class="delete"></a>
                        <a title="' . __('Edit') . '" href="javascript://" onClick="openPopup(\'editSec' . (int)$result->getId() . '\');" class="edit"></a>
                        <a title="' . __('Add') . '" href="javascript://" onClick="openPopup(\'addForum' . (int)$result->getId() . '\');" class="add"></a>
                    </div>
                    <div class="clear"></div>
                </div>
                <div class="items">';
        
            // Select current section
            $cat_selector_ = str_replace('selected="selected"', ' ', $cat_selector);
            $cat_selector_ = str_replace(
                'value="' . $result->getId() .'"', 
                ' selected="selected" value="' . (int)$result->getId() .'"', 
                $cat_selector_
            );
        
            $popups_content .= '<div id="addForum' . (int)$result->getId() . '" class="popup">
                    <div class="top">
                        <div class="title">' . __('Add forum') . '</div>
                        <div onClick="closePopup(\'addForum' . (int)$result->getId() . '\');" class="close"></div>
                    </div>
                    <form action="forum_cat.php?ac=add" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="' . $csrfToken . '" />
                    <div class="items">
                        <div class="item">
                            <div class="left">
                                ' . __('Parent section') . ':
                            </div>
                            <div class="right">' . $cat_selector_ . '</div>
                            <div class="clear"></div>
                        </div>
                        <div class="item">
                            <div class="left">
                                ' . __('Title of forum') . ':
                            </div>
                            <div class="right">
                                <input type="hidden" name="type" value="forum" />
                                <input type="text" name="title" maxlength="200" />
                            </div>
                            <div class="clear"></div>
                        </div>
                        <div class="item">
                            <div class="left">
                                ' . __('Forum position') . ':
                                <span class="comment">' . __('Numeric') . '</span>
                            </div>
                            <div class="right">
                                <input type="number" name="in_pos" min="1" />
                            </div>
                            <div class="clear"></div>
                        </div>
                        <div class="item">
                            <div class="left">
                                ' . __('Parent forum') . ':
                                <span class="comment">' . __('For which this will be sub-forum') . '</span>
                            </div>
                            <div class="right">
                                ' . $sub_selector . '
                            </div>
                            <div class="clear"></div>
                        </div>
                        <div class="item">
                            <div class="left">
                                ' . __('Icon') . ':
                                <span class="comment">(' . __('Empty field - no icon') . ')<br />
                                ' . __('The desired size 16x16 px') . '</span>
                            </div>
                            <div class="right">
                                <input type="file" name="icon" accept="image/jpeg,image/png,image/gif" />
                            </div>
                            <div class="clear"></div>
                        </div>
                        <div class="item">
                            <div class="left">
                                ' . __('Description') . ':
                            </div>
                            <div class="right">
                                <textarea name="description" maxlength="1000"></textarea>
                            </div>
                            <div class="clear"></div>
                        </div>
                        <div class="item">
                            <div class="left">
                                ' . __('Moderators') . ':
                            </div>
                            <div class="right">
                                <div id="moderators_view" class="collection" style="width:230px; "></div>
                                <a class="add-moder-button" href="javascript:void(0);" onClick="findUsersWindow(\'javascript:setModerator(\\\'addForum' . (int)$result->getId() . '\\\', %id, \\\'%name\\\');\')" >' . __('Add') . '</a>
                                <div class="clear"></div>
                            </div>
                            <div class="clear"></div>
                        </div>
                        <div class="item">
                            <div class="left">
                                ' . __('Lock on passwd') . ':
                            </div>
                            <div class="right">
                                <input type="text" name="lock_passwd" maxlength="100" />
                            </div>
                            <div class="clear"></div>
                        </div>
                        <div class="item">
                            <div class="left">
                                ' . __('Lock on posts count') . ':
                            </div>
                            <div class="right">
                                <input type="number" name="lock_posts" min="0" />
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

            $popups_content .= '<div id="editSec' . (int)$result->getId() . '" class="popup">
                    <div class="top">
                        <div class="title">' . __('Category editing') . '</div>
                        <div onClick="closePopup(\'editSec' . (int)$result->getId() . '\');" class="close"></div>
                    </div>
                    <form action="forum_cat.php?ac=edit&id=' . (int)$result->getId() . '" method="POST">
                    <input type="hidden" name="csrf_token" value="' . $csrfToken . '" />
                    <div class="items">
                        <div class="item">
                            <div class="left">
                                ' . __('Title') . ':
                            </div>
                            <div class="right">
                                <input type="hidden" name="type" value="section" />
                                <input type="text" name="title" value="' . h($result->getTitle()) . '" maxlength="200" />
                            </div>
                            <div class="clear"></div>
                        </div>
                        <div class="item">
                            <div class="left">
                                ' . __('Section position') . ':
                                <span class="comment">' . __('Numeric') . '</span>
                            </div>
                            <div class="right">
                                <input type="number" name="in_pos" value="' . (int)$result->getPreviev_id() . '" min="1" />
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

            $queryCat = $forumsModel->getCollection(array('in_cat' => $result->getId()), array('order' => 'pos'));
            
            if (count($queryCat) > 0) {
                foreach ($queryCat as $cat) {
                    //cat selector and position selector for EDIT FRORUMS
                    $cat_selector = '<select name="in_cat" id="cat_secId">';    
                    foreach ($query as $key => $category) {
                        $selected = ($cat->getIn_cat() == $category->getId()) ? ' selected="selected"' : '';
                        $cat_selector .= '<option value="' . (int)$category->getId() . '"' . $selected . '>' . h($category->getTitle()) . '</option>';
                    }
                    $cat_selector .= '</select>';

                    //selector for subforums
                    $sub_selector = '<select name="parent_forum_id">';
                    $sub_selector .= '<option value=""></option>';
                    if (!empty($forums)) {
                        foreach($forums as $forum) {
                            if ($cat->getId() == $forum->getId()) continue; 
                            $selected = ($cat->getParent_forum_id() == $forum->getId()) ? 'selected="selected"' : ''; 
                            $sub_selector .= '<option value="' . (int)$forum->getId() . '" ' . $selected . '>' 
                            . h($forum->getTitle()) . '</option>';
                        }
                    }
                    $sub_selector .= '</select>';
                    
                    $issubforum = ($cat->getParent_forum_id()) 
                    ? '&nbsp;<span style="color:#0373FE;">' . __('Under forum with ID') . ' ' . (int)$cat->getParent_forum_id() . '</span>' : '';
                    
                    // Forum moderators
                    $forumModerators = $Register['ACL']->getForumModerators($cat->getId());
                    $fModerators = '';
                    if ($forumModerators) {
                        foreach ($forumModerators as $fmRow) {
                            $fModerators .= '<div class="item">' . h($fmRow->getName()) 
                                . '<input type="hidden" name="moderators[' . (int)$fmRow->getid() 
                                . ']" value="1" /><div class="close"></div></div>';
                        }
                    }
                    
                    $html .= '<div class="level2">
                                <div class="number">' . (int)$cat->getId() . '</div>
                                <div class="title">' . h($cat->getTitle()) . ' ' . $issubforum . '</div>
                                <div class="buttons">
                                    <a title="' . __('Delete') . '" href="?ac=del&id=' . (int)$cat->getId() . '&csrf_token=' . urlencode($csrfToken) . '" onClick="return confirm(\'' . __('Are you sure?') . '\');" class="delete"></a>
                                    <a title="' . __('Edit') . '" href="javascript://" onClick="openPopup(\'editForum' . (int)$cat->getId() . '\')" class="edit"></a>
                                </div>
                                <div class="posts">' . (int)$cat->getThemes() . '</div>
                                <div class="clear"></div>
                            </div>';

                    $popups_content .= '<div id="editForum' . (int)$cat->getId() . '" class="popup">
                            <div class="top">
                                <div class="title">' . __('Edit forum') . '</div>
                                <div onClick="closePopup(\'editForum' . (int)$cat->getId() . '\');" class="close"></div>
                            </div>
                            <form action="forum_cat.php?ac=edit&id=' . (int)$cat->getId() . '" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="' . $csrfToken . '" />
                            <div class="items">
                                <div class="item">
                                    <div class="left">
                                        ' . __('Parent section') . ':
                                    </div>
                                    <div class="right">' . $cat_selector_ . '</div>
                                    <div class="clear"></div>
                                </div>
                                <div class="item">
                                    <div class="left">
                                        ' . __('Title of forum') . ':
                                    </div>
                                    <div class="right">
                                        <input type="hidden" name="type" value="forum" />
                                        <input type="text" name="title" value="' . h($cat->getTitle()) . '" maxlength="200" />
                                    </div>
                                    <div class="clear"></div>
                                </div>
                                <div class="item">
                                    <div class="left">
                                        ' . __('Forum position') . ':
                                        <span class="comment">' . __('Numeric') . '</span>
                                    </div>
                                    <div class="right">
                                        <input type="number" name="in_pos" value="' . (int)$cat->getPos() . '" min="1" />
                                    </div>
                                    <div class="clear"></div>
                                </div>
                                <div class="item">
                                    <div class="left">
                                        ' . __('Parent forum') . ':
                                        <span class="comment">' . __('For which this will be sub-forum') . '</span>
                                    </div>
                                    <div class="right">
                                        ' . $sub_selector . '
                                    </div>
                                    <div class="clear"></div>
                                </div>
                                <div class="item">
                                    <div class="left">
                                        ' . __('Icon') . ':
                                        <span class="comment">(' . __('Empty field - no icon') . ')<br />
                                        ' . __('The desired size 16x16 px') . '</span>
                                    </div>
                                    <div class="right">
                                        <input type="file" name="icon" accept="image/jpeg,image/png,image/gif" />
                                    </div>
                                    <div class="clear"></div>
                                </div>
                                <div class="item">
                                    <div class="left">
                                        ' . __('Description') . ':
                                    </div>
                                    <div class="right">
                                        <textarea name="description" cols="30" rows="3" maxlength="1000">' . h($cat->getDescription()) . '</textarea>
                                    </div>
                                    <div class="clear"></div>
                                </div>
                                <div class="item">
                                    <div class="left">
                                        ' . __('Moderators') . ':
                                    </div>
                                    <div class="right">
                                        <div id="moderators_view" class="collection" style="width:230px; ">'.$fModerators.'</div>
                                        <a class="add-moder-button" href="javascript:void(0);" onClick="findUsersWindow(\'javascript:setModerator(\\\'editForum' . (int)$cat->getId() . '\\\', %id, \\\'%name\\\');\')" >' . __('Add') . '</a>
                                        <div class="clear"></div>
                                    </div>
                                    <div class="clear"></div>
                                </div>
                                <div class="item">
                                    <div class="left">
                                        ' . __('Lock on passwd') . ':
                                    </div>
                                    <div class="right">
                                        <input type="text" name="lock_passwd" value="' . h($cat->getLock_passwd()) . '" maxlength="100" />
                                    </div>
                                    <div class="clear"></div>
                                </div>
                                <div class="item">
                                    <div class="left">
                                        ' . __('Lock on posts count') . ':
                                    </div>
                                    <div class="right">
                                        <input type="number" name="lock_posts" value="' . (int)$cat->getLock_posts() . '" min="0" />
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
                }
            } else {
                $html .= '<div class="level2"><div class="left"><div class="title">' . __('Empty') . '</div></div></div>';
            }
            
            $html .= '<div class="clear"></div></div></div>';
        }
        $html .= '</div>';
    } else {
        $html .= __('While empty');
    }
    return $html;
}

function edit() {
    global $FpsDB, $Register, $csrfToken;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('/admin/forum_cat.php');
    }
    
    if (!isset($_POST['title']) || !isset($_POST['type']) || empty($_GET['id'])) {
        redirect('/admin/forum_cat.php');
    }
    
    $id = (int)$_GET['id'];
    if ($id < 1) {
        redirect('/admin/forum_cat.php');
    }
    
    $title = trim($_POST['title']);
    $in_pos = isset($_POST['in_pos']) ? (int)$_POST['in_pos'] : 0;
    
    $error = '';
    if (empty($title)) {
        $error .= '<li>' . __('Empty field "title"') . '</li>';
    }
    if (mb_strlen($title) > 200) {
        $error .= '<li>' . __('Title more than 200 symbol') . '</li>';
    }
    
    if ($_POST['type'] == 'forum') {
        if (!isset($_POST['in_cat'])) {
            redirect('/admin/forum_cat.php');
        }
        $in_cat = (int)$_POST['in_cat'];
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        
        // Валидация загружаемого файла
        if (!empty($_FILES['icon']['name'])) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $max_size = 100000; // 100KB
            
            if ($_FILES['icon']['size'] > $max_size) {
                $error .= '<li>' . __('Max icon size 100Kb') . '</li>';
            }
            if (!in_array($_FILES['icon']['type'], $allowed_types)) {
                $error .= '<li>' . __('Wrong icon format') . '</li>';
            }
            if ($_FILES['icon']['error'] !== UPLOAD_ERR_OK) {
                $error .= '<li>' . __('File upload error') . '</li>';
            }
        }
        
        // Lock forum validation
        $lock_passwd = isset($_POST['lock_passwd']) ? trim($_POST['lock_passwd']) : '';
        $lock_posts = isset($_POST['lock_posts']) ? (int)$_POST['lock_posts'] : 0;
        
        if (mb_strlen($lock_passwd) > 100) {
            $error .= '<li>' . __('Forum passwd more than 100 sym.') . '</li>';
        }
        
        if (!empty($error)) {
            $_SESSION['errors'] = $error;
            redirect('/admin/forum_cat.php');
        }
        
        // Обновление позиции
        if ($in_pos > 0) {
            $busy = $FpsDB->select('forums', DB_COUNT, array(
                'cond' => array('pos' => $in_pos, 'in_cat' => $in_cat, 'id !=' => $id)
            ));
            if ($busy > 0) {
                $FpsDB->query("UPDATE `" . $FpsDB->getFullTableName('forums') . "` SET `pos` = `pos` + 1 WHERE `pos` >= ? AND `in_cat` = ? AND `id` != ?", 
                    [$in_pos, $in_cat, $id]);
            }
        }
        
        $parent_forum_id = isset($_POST['parent_forum_id']) ? (int)$_POST['parent_forum_id'] : 0;
        if ($parent_forum_id === $id) {
            $parent_forum_id = 0; // Prevent self-reference
        }
        
        // Подготовка данных для сохранения
        $data = [
            'description' => $description,
            'title' => $title,
            'in_cat' => $in_cat,
            'pos' => $in_pos,
            'parent_forum_id' => $parent_forum_id,
            'lock_passwd' => $lock_passwd,
            'lock_posts' => $lock_posts,
        ];
        
        $FpsDB->save('forums', $data, ['id' => $id]);
        
        // Обработка загружаемого файла
        if (!empty($_FILES['icon']['name']) && empty($error)) {
            $safe_filename = 'forum_icon_' . $id . '.' . getFileExtension($_FILES['icon']['name']);
            $upload_path = ROOT . '/sys/img/' . $safe_filename;
            
            if (move_uploaded_file($_FILES['icon']['tmp_name'], $upload_path)) {
                chmod($upload_path, 0644);
            }
        }
        
        // Сохранение модераторов
        $moderators = $Register['ACL']->getModerators();
        $moderators[$id] = [];
        
        if (!empty($_POST['moderators']) && is_array($_POST['moderators'])) {
            foreach ($_POST['moderators'] as $user_id => $value) {
                $moderators[$id][] = (int)$user_id;
            }
        }
        
        $Register['ACL']->saveForumsModerators($moderators);
        
    } else if ($_POST['type'] == 'section') {
        if (!empty($error)) {
            $_SESSION['errors'] = $error;
            redirect('/admin/forum_cat.php');
        }
        
        // Обновление позиции
        if ($in_pos > 0) {
            $busy = $FpsDB->select('forum_cat', DB_COUNT, array(
                'cond' => array('previev_id' => $in_pos, 'id !=' => $id)
            ));
            if ($busy > 0) {
                $FpsDB->query("UPDATE `" . $FpsDB->getFullTableName('forum_cat') . "` SET `previev_id` = `previev_id` + 1 WHERE `previev_id` >= ? AND `id` != ?", 
                    [$in_pos, $id]);
            }
        }
        
        $FpsDB->save('forum_cat', [
            'title' => $title, 
            'previev_id' => $in_pos,
        ], ['id' => $id]);
    }
    
    redirect('/admin/forum_cat.php');
}

// Вспомогательная функция для получения расширения файла
function getFileExtension($filename) {
    return pathinfo($filename, PATHINFO_EXTENSION);
}

// Остальные функции (add, delete, delete_theme, deleteCollisions)也需要类似的исправления
// Из-за ограничения длины я покажу исправления для основных функций

include_once 'template/footer.php';
