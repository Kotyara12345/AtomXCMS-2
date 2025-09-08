<?php
/*---------------------------------------------\
|											   |
| @Author:       Andrey Brykin (Drunya)        |
| @Version:      1.0                           |
| @Project:      AtomX CMS                     |
| @Package       Admin panel                   |
| @subpackege    Comments list                 |
| @copyright     ©Andrey Brykin 		       |
| @last mod.     2014/03/13                    |
|----------------------------------------------|
|											   |
| any partial or not partial extension         |
| CMS AtomX,without the consent of the         |
| author, is illegal                           |
|----------------------------------------------|
| Любое распространение                        |
| CMS AtomX или ее частей,                     |
| без согласия автора, является не законным    |
\---------------------------------------------*/

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

$Register = Register::getInstance();

$allowed_mods = $Register['ModManager']->getAllowedModules('commentsList');
$allowed_actions = array('edit', 'delete', 'index', 'premoder');

// Валидация модуля
if (empty($_GET['m']) || !in_array($_GET['m'], $allowed_mods)) {
    redirect('/admin/');
}
$module = htmlspecialchars($_GET['m'], ENT_QUOTES, 'UTF-8');

// Валидация действия
$action = (!empty($_GET['ac']) && in_array($_GET['ac'], $allowed_actions)) ? $_GET['ac'] : 'index';

// Проверка CSRF для действий, изменяющих данные
if (in_array($action, ['delete', 'edit', 'premoder']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(__('CSRF token validation failed'));
    }
}

$Controll = new MaterialsList;
list($output, $pages) = $Controll->{$action}($module);

class MaterialsList {
    public $pageTitle;

    public function __construct()
    {
        $this->pageTitle = __('Comments list');
    }

    public function index($module) 
    {
        $output = '';
        $Register = Register::getInstance();
        $model = $Register['ModManager']->getModelInstance('Comments');
        
        // Безопасное формирование условий WHERE
        $where = array();
        if (!empty($_GET['premoder'])) {
            $where['premoder'] = 'nochecked';
        }
        $where['module'] = $module;

        $total = $model->getTotal(array('cond' => $where));
        
        // Безопасное формирование URL для пагинации
        $paginationParams = 'm=' . urlencode($module);
        if (!empty($_GET['order']) && preg_match('/^[a-zA-Z_]+$/', $_GET['order'])) {
            $paginationParams .= '&order=' . urlencode($_GET['order']);
        }
        if (!empty($_GET['asc']) && $_GET['asc'] == 1) {
            $paginationParams .= '&asc=1';
        }
        
        list ($pages, $page) = pagination($total, 20, '/admin/comments_list.php?' . $paginationParams);

        $model->bindModel('author');
        $model->bindModel('parent_entity');
        $materials = $model->getCollection($where, array(
            'page' => $page,
            'limit' => 20,
            'order' => $model->getOrderParam(),
        ));
        
        if (empty($materials)) {
            $output = '<div class="setting-item"><div class="left"><b>' 
            . __('Materials not found') . '</b></div><div class="clear"></div></div>';
            return array($output, $pages);
        }
    
        foreach ($materials as $mat) {
            $output .= '<div class="setting-item"><div class="left">';
            $output .= '<a style="font-weight:bold; margin-bottom:5px;" href="' 
                . get_url('/admin/materials_list.php?m=' . urlencode($module) . '&ac=edit&id=' . (int)$mat->getParent_entity()->getId()) . '">' 
                . h($mat->getParent_entity()->getTitle()) . '</a><br>';
            $output .= __('Author') . ': ';

            if (is_object($mat->getAuthor())) {
                $output .= '<a style="font-weight:bold; margin-bottom:5px;" href="' . get_url('/admin/users_list.php?ac=ank&id=' . (int)$mat->getAuthor()->getId()) . '">';
                $output .= h($mat->getAuthor()->getName());
                $output .= '</a>';
            } else {
                $output .= __('Guest');
            }

            $output .= '</div><div style="width:60%;" class="right">';
            $output .= h(mb_substr($mat->getMessage(), 0, 120));
            $output .= '<br /><span class="comment">' . h(AtmDateTime::getSimpleDate($mat->getDate())) . '</span>';
            
            // Формы с CSRF-токенами для действий
            $csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
            
            if (!empty($_GET['premoder'])) {
                $output .= '</div><div class="unbordered-buttons">';
                $output .= '<form method="POST" action="' . get_url('/admin/comments_list.php?m=' . urlencode($module) . '&ac=premoder&status=rejected&id=' . (int)$mat->getId()) . '" style="display:inline;">';
                $output .= '<input type="hidden" name="csrf_token" value="' . $csrfToken . '" />';
                $output .= '<button type="submit" class="off" title="' . __('Reject') . '" onclick="return confirm(\'' . __('Are you sure?') . '\')"></button>';
                $output .= '</form>';
                $output .= '<form method="POST" action="' . get_url('/admin/comments_list.php?m=' . urlencode($module) . '&ac=premoder&status=confirmed&id=' . (int)$mat->getId()) . '" style="display:inline;">';
                $output .= '<input type="hidden" name="csrf_token" value="' . $csrfToken . '" />';
                $output .= '<button type="submit" class="on" title="' . __('Approve') . '" onclick="return confirm(\'' . __('Are you sure?') . '\')"></button>';
                $output .= '</form>';
                $output .= '</div><div class="clear"></div></div>';
            } else {
                $output .= '</div><div class="unbordered-buttons">';
                $output .= '<form method="POST" action="' . get_url('/admin/comments_list.php?m=' . urlencode($module) . '&ac=delete&id=' . (int)$mat->getId()) . '" style="display:inline;">';
                $output .= '<input type="hidden" name="csrf_token" value="' . $csrfToken . '" />';
                $output .= '<button type="submit" class="delete" title="' . __('Delete') . '" onclick="return confirm(\'' . __('Are you sure?') . '\')"></button>';
                $output .= '</form>';
                $output .= '<a href="' . get_url('/admin/comments_list.php?m=' . urlencode($module) . '&ac=edit&id=' . (int)$mat->getId()) . '" class="edit" title="' . __('Edit') . '"></a>';
                $output .= '</div><div class="clear"></div></div>';
            }
        }
        
        return array($output, $pages);
    }
    
    function premoder($module){
        $Register = Register::getInstance();
        $Model = $Register['ModManager']->getModelInstance('Comments');
        
        // Валидация ID
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            $_SESSION['errors'] = __('Invalid comment ID');
            redirect('/admin/comments_list.php?m=' . urlencode($module) . '&premoder=1');
        }
        
        $id = (int)$_GET['id'];
        $entity = $Model->getById($id);
        
        if (!empty($entity)) {
            // Валидация статуса
            $status = isset($_GET['status']) ? $_GET['status'] : 'nochecked';
            if (!in_array($status, array('rejected', 'confirmed'))) {
                $status = 'nochecked';
            }
            
            $entity->setPremoder($status);
            $entity->save();
            $_SESSION['message'] = __('Saved');
            
            // Очистка кэша
            $Cache = new Cache;
            $Cache->clean(
                CACHE_MATCHING_TAG, 
                array(
                    'module_' . $module,
                    'record_id_' . $entity->getUser_id()));
        } else {
            $_SESSION['errors'] = __('Some error occurred');
        }
        redirect('/admin/comments_list.php?m=' . urlencode($module) . '&premoder=1');
    }
    
    public function delete($module) {
        $Register = Register::getInstance();
        
        // Валидация ID
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            $_SESSION['errors'] = __('Invalid comment ID');
            redirect('/admin/comments_list.php?m=' . urlencode($module));
        }
        
        $model = $Register['ModManager']->getModelInstance('Comments');
        $id = (int)$_GET['id'];
        $entity = $model->getById($id);
        
        if (!empty($entity)) {
            $entity->delete();
            $_SESSION['message'] = __('Material has been delete');
        } else {
            $_SESSION['errors'] = __('Comment not found');
        }
        
        redirect('/admin/comments_list.php?m=' . urlencode($module));
    }
    
    public function edit($module) {
        $this->pageTitle .= ' - ' . __('Comment editing');
    
        $output = '';
        $Register = Register::getInstance();
        $model = $Register['ModManager']->getModelInstance('Comments');

        // Валидация ID
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            $_SESSION['errors'] = __('Invalid comment ID');
            redirect('/admin/comments_list.php?m=' . urlencode($module));
        }
        
        $id = (int)$_GET['id'];
        $entity = $model->getById($id);
        
        if (empty($entity)) {
            $_SESSION['errors'] = __('Comment not found');
            redirect('/admin/comments_list.php?m=' . urlencode($module));
        }
        
        if (!empty($_POST)) {
            // Валидация сообщения
            $message = trim($_POST['message']);
            if (empty($message)) {
                $_SESSION['errors'] = __('Message cannot be empty');
            } else {
                $entity->setMessage($message);
                $entity->save();
                $_SESSION['message'] = __('Operation is successful');
                
                redirect('/admin/comments_list.php?m=' . urlencode($module));
            }
        }
        
        $csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
        
        $output .= '
        <form method="POST" action="" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="' . $csrfToken . '" />
        <div class="setting-item"><div class="left">
            ' . __('Message') . '
        </div><div class="right">
            <textarea style="height:200px;" name="message">'.h($entity->getMessage()).'</textarea>
        </div><div class="clear"></div></div>
        <div class="setting-item">
            <div class="left">
            </div>
            <div class="right">
                <input class="save-button" type="submit" name="send" value="' . __('Save') . '" />
            </div>
            <div class="clear"></div>
        </div>
        </form>';
        
        return array($output, '');
    }
}

$pageTitle = $Controll->pageTitle;
$pageNav = $Controll->pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';
?>

<div class="list">
    <div class="title"><?php echo h($pageNav); ?></div>
    <div class="add-cat-butt">
        <select onChange="window.location.href='/admin/comments_list.php?m=<?php echo urlencode($module); ?>&order='+this.value;">
            <option><?php echo __('Ordering') ?></option>
            <option value="views"><?php echo __('Users') ?></option>
            <option value="date"><?php echo __('Date') ?> (↓)</option>
            <option value="date&asc=1"><?php echo __('Date') ?> (↑)</option>
            <option value="premoder"><?php echo __('Premoderation') ?> (↓)</option>
            <option value="premoder&asc=1"><?php echo __('Premoderation') ?> (↑)</option>
        </select>
    </div>
    <div class="level1">
        <div class="head">
            <div class="title settings"><?php echo __('Name') ?></div>
            <div class="title-r"><?php echo __('Message') ?></div>
            <div class="clear"></div>
        </div>
        <div class="items">
            <?php echo $output; ?>
        </div>
    </div>
</div>
<div class="pagination"><?php echo $pages ?></div>

<?php include_once 'template/footer.php'; ?>
