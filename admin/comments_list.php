<?php
/*---------------------------------------------\
|                                             |
| @Author:       Andrey Brykin (Drunya)      |
| @Version:      1.0                         |
| @Project:      AtomX CMS                   |
| @Package       Admin panel                 |
| @subpackege    Comments list               |
| @copyright     ©Andrey Brykin              |
| @last mod.     2014/03/13                  |
|----------------------------------------------|
|                                             |
| Any partial or not partial extension       |
| CMS AtomX, without the consent of the      |
| author, is illegal                         |
|----------------------------------------------|
| Любое распространение                      |
| CMS AtomX или ее частей,                   |
| без согласия автора, является не законным  |
\---------------------------------------------*/

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$Register = Register::getInstance();
$allowed_mods = $Register['ModManager']->getAllowedModules('commentsList');
$allowed_actions = ['edit', 'delete', 'index', 'premoder'];

$module = $_GET['m'] ?? null;
if (empty($module) || !in_array($module, $allowed_mods)) {
    redirect('/admin/');
}

$action = $_GET['ac'] ?? 'index';
if (!in_array($action, $allowed_actions)) {
    $action = 'index';
}

$controller = new MaterialsList();
list($output, $pages) = $controller->{$action}($module);

class MaterialsList {
    public $pageTitle;

    public function __construct() {
        $this->pageTitle = __('Comments list');
    }

    public function index($module) {
        $output = '';
        $Register = Register::getInstance();
        $model = $Register['ModManager']->getModelInstance('Comments');

        $where = (!empty($_GET['premoder'])) ? ['premoder' => 'nochecked'] : [];
        $where[] = "`module` = '" . $module . "'";

        $total = $model->getTotal(['cond' => $where]);
        list($pages, $page) = pagination($total, 20, '/admin/comments_list.php?m=' . $module
            . (!empty($_GET['order']) ? '&order=' . $_GET['order'] : '')
            . (!empty($_GET['asc']) ? '&asc=1' : ''));

        $model->bindModel('author');
        $model->bindModel('parent_entity');
        $materials = $model->getCollection($where, [
            'page' => $page,
            'limit' => 20,
            'order' => $model->getOrderParam(),
        ]);

        if (empty($materials)) {
            return ['<div class="setting-item"><div class="left"><b>' . __('Materials not found') . '</b></div><div class="clear"></div></div>', $pages];
        }

        foreach ($materials as $mat) {
            $output .= '<div class="setting-item"><div class="left">';
            $output .= '<a style="font-weight:bold; margin-bottom:5px;" href="' 
                . get_url('/admin/materials_list.php?m=' . $module . '&ac=edit&id=' . $mat->getParent_entity()->getId()) . '">' 
                . h($mat->getParent_entity()->getTitle()) . '</a><br>';
            $output .= __('Author') . ': ';

            if (is_object($mat->getAuthor())) {
                $output .= '<a style="font-weight:bold; margin-bottom:5px;" href="' . get_url('/admin/users_list.php?ac=ank&id=' . $mat->getAuthor()->getId()) . '">'
                    . h($mat->getAuthor()->getName()) . '</a>';
            } else {
                $output .= __('Guest');
            }

            $output .= '</div><div style="width:60%;" class="right">';
            $output .= h(mb_substr($mat->getMessage(), 0, 120));
            $output .= '<br /><span class="comment">' . AtmDateTime::getSimpleDate($mat->getDate()) . '</span>';
            
            if (!empty($_GET['premoder'])) {
                $output .= '</div><div class="unbordered-buttons">
                <a href="' . get_url('/admin/comments_list.php?m=' . $module . '&ac=premoder&status=rejected&id=' . $mat->getId()) . '" class="off"></a>
                <a href="' . get_url('/admin/comments_list.php?m=' . $module . '&ac=premoder&status=confirmed&id=' . $mat->getId()) . '" class="on"></a>
                </div><div class="clear"></div></div>';
            } else {
                $output .= '</div><div class="unbordered-buttons">
                <a href="' . get_url('/admin/comments_list.php?m=' . $module . '&ac=delete&id=' . $mat->getId()) . '" class="delete"></a>
                <a href="' . get_url('/admin/comments_list.php?m=' . $module . '&ac=edit&id=' . $mat->getId()) . '" class="edit"></a>
                </div><div class="clear"></div></div>';
            }
        }

        return [$output, $pages];
    }

    public function premoder($module) {
        $Register = Register::getInstance();
        $Model = $Register['ModManager']->getModelInstance('Comments');
        $entity = $Model->getById((int)$_GET['id']);

        if ($entity) {
            $status = $_GET['status'] ?? 'nochecked';
            if (!in_array($status, ['rejected', 'confirmed'])) {
                $status = 'nochecked';
            }

            $entity->setPremoder($status);
            $entity->save();
            $_SESSION['message'] = __('Saved');

            // Clean cache
            $Cache = new Cache();
            $Cache->clean(CACHE_MATCHING_TAG, [
                'module_' . $module,
                'record_id_' . $entity->getUser_id(),
            ]);
        } else {
            $_SESSION['errors'] = __('Some error occurred');
        }
        redirect('/admin/comments_list.php?m=' . $module . '&premoder=1&id=' . $entity->getUser_id());
    }

    public function delete($module) {
        $Register = Register::getInstance();
        $model = $Register['ModManager']->getModelInstance('Comments');
        $id = (int)$_GET['id'];
        $entity = $model->getById($id);

        if ($entity) {
            $entity->delete();
            $_SESSION['message'] = __('Material has been deleted');
        }

        redirect('/admin/comments_list.php?m=' . $module);
    }

    public function edit($module) {
        $this->pageTitle .= ' - ' . __('Comment editing');

        $output = '';
        $Register = Register::getInstance();
        $model = $Register['ModManager']->getModelInstance('Comments');

        $id = (int)$_GET['id'];
        $entity = $model->getById($id);

        if ($_POST) {
            $entity->setMessage($_POST['message']);
            $entity->save();
            $_SESSION['message'] = __('Operation is successful');
            redirect('/admin/comments_list.php?m=' . $module);
        }

        $output .= '
        <div class="setting-item"><div class="left">' . __('Message') . '</div>
        <div class="right"><textarea style="height:200px;" name="message">' . h($entity->getMessage()) . '</textarea></div>
        <div class="clear"></div></div>
        <div class="setting-item"><div class="left"></div>
        <div class="right"><input class="save-button" type="submit" name="send" value="' . __('Save') . '" /></div>
        <div class="clear"></div></div>';

        return [$output, ''];
    }
}

$pageTitle = $controller->pageTitle;
$pageNav = $controller->pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';
?>

<form method="POST" action="" enctype="multipart/form-data">
<div class="list">
    <div class="title"><?php echo $pageNav; ?></div>
    <div class="add-cat-butt">
        <select onChange="window.location.href='/admin/comments_list.php?m=<?php echo $module ?>&order=' + this.value;">
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
<div class="pagination"><?php echo $pages; ?></div>
</form>

<?php include_once 'template/footer.php'; ?>
