<?php
##################################################
##                                                ##
## @Author:       Andrey Brykin (Drunya)        ##
## @Version:      1.6.1                         ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Admin Panel module            ##
## @copyright     ©Andrey Brykin 2010-2013      ##
## @last mod.     2013/06/15                    ##
##################################################

##################################################
##                                                ##
## any partial or not partial extension         ##
## CMS AtomX,without the consent of the         ##
## author, is illegal                           ##
##################################################
## Любое распространение                        ##
## CMS AtomX или ее частей,                     ##
## без согласия автора, является не законным    ##
##################################################

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';
$pageTitle = __('List of materials');
$Register = Register::getInstance();

$allowed_mods = $Register['ModManager']->getAllowedModules('materialsList');
$allowed_actions = ['edit', 'delete', 'index', 'premoder'];

$module = $_GET['m'] ?? null;

if (empty($module) || !in_array($module, $allowed_mods, true)) {
    redirect('/admin/');
}

$action = $_GET['ac'] ?? 'index';

if (!in_array($action, $allowed_actions, true)) {
    $action = 'index';
}

$Controll = new MaterialsList;
list($output, $pages) = $Controll->{$action}($module);

class MaterialsList {
    public string $pageTitle;

    public function __construct() {
        $this->pageTitle = __('List of materials');
    }

    public function index(string $module): array {
        $output = '';
        $Register = Register::getInstance();
        $model = $Register['ModManager']->getModelInstance($module);
        
        $where = !empty($_GET['premoder']) ? ['premoder' => 'nochecked'] : [];

        $total = $model->getTotal(['cond' => $where]);
        list($pages, $page) = pagination($total, 20, '/admin/materials_list.php?m=' . $module
            . (!empty($_GET['order']) ? '&order=' . $_GET['order'] : '')
            . (!empty($_GET['asc']) ? '&asc=1' : ''));

        $model->bindModel('author');
        $materials = $model->getCollection($where, [
            'page' => $page,
            'limit' => 20,
            'order' => $model->getOrderParam(),
        ]);

        if (empty($materials)) {
            $output = '<div class="setting-item"><div class="left"><b>' 
            . __('Materials not found') . '</b></div><div class="clear"></div></div>';
        }

        foreach ($materials as $mat) {
            $output .= '<div class="setting-item"><div class="left">';
            $output .= '<a style="font-weight:bold; margin-bottom:5px;" href="' 
                . get_url('/admin/materials_list.php?m=' . $module . '&ac=edit&id=' 
                . $mat->getId()) . '">' . h($mat->getTitle()) . '</a>';
            $output .= '<br />(' . $mat->getAuthor()->getName() . ')';
            $output .= '</div><div style="width:60%;" class="right">';

            if ($module === 'foto') {
                $AtmFoto = h(preg_replace('#[^\w\d ]+#ui', ' ', $mat->getTitle()));
                $AtmFoto = '<img alt="' . $AtmFoto . '" src="' . WWW_ROOT . '/sys/files/' . $module . '/preview/' 
                    . $mat->getFilename() . '" width="150px" />';
                $AtmFoto = '<a target="_blank" href="' . WWW_ROOT . '/sys/files/' . $module . '/full/' 
                    . $mat->getFilename() . '">' . $AtmFoto . '</a>';
                
                $output .= $AtmFoto;
            } else {
                $output .= h(mb_substr($mat->getMain(), 0, 500));
            }
            $output .= '<br /><span class="comment">' . AtmDateTime::getSimpleDate($mat->getDate()) . '</span>';
            
            if (!empty($_GET['premoder'])) {
                $output .= '</div><div class="unbordered-buttons">
                <a href="' . get_url('/admin/materials_list.php?m=' . $module . '&ac=premoder&status=rejected&id=' . $mat->getId()) . '" class="off"></a>' .
                '<a href="' . get_url('/admin/materials_list.php?m=' . $module . '&ac=premoder&status=confirmed&id=' . $mat->getId()) . '" class="on"></a>
                </div><div class="clear"></div></div>';
            } else {
                $output .= '</div><div class="unbordered-buttons">
                <a href="' . get_url('/admin/materials_list.php?m=' . $module . '&ac=delete&id=' . $mat->getId()) . '" class="delete"></a>' .
                '<a href="' . get_url('/admin/materials_list.php?m=' . $module . '&ac=edit&id=' . $mat->getId()) . '" class="edit"></a>
                </div><div class="clear"></div></div>';
            }
        }
        
        return [$output, $pages];
    }

    public function premoder(string $module): void {
        $Register = Register::getInstance();
        $Model = $Register['ModManager']->getModelInstance($module);
        $entity = $Model->getById((int)$_GET['id']);
        
        if (!empty($entity)) {
            $status = $_GET['status'] ?? 'nochecked';
            if (!in_array($status, ['rejected', 'confirmed'], true)) {
                $status = 'nochecked';
            }
            
            $entity->setPremoder($status);
            $entity->save();
            $_SESSION['message'] = __('Saved');
            
            // Clean cache
            $Cache = new Cache();
            $Cache->clean(CACHE_MATCHING_ANY_TAG, ['module_' . $module]);
        } else {
            $_SESSION['errors'] = __('Some error occurred');
        }
        redirect('/admin/materials_list.php?m=' . $module . '&premoder=1');
    }

    public function delete(string $module): void {
        $Register = Register::getInstance();
        
        $model = $Register['ModManager']->getModelInstance($module);
        $id = (int)$_GET['id'];
        $entity = $model->getById($id);
        
        if (!empty($entity)) {
            if ($module === 'foto') {
                @unlink(ROOT . '/sys/files/' . $module . '/full/' . $entity->getFilename());
                @unlink(ROOT . '/sys/files/' . $module . '/preview/' . $entity->getFilename());
                $entity->delete();
            } else {
                $entity->delete();
            }
            $_SESSION['message'] = __('Material has been deleted');
        }
        
        redirect('/admin/materials_list.php?m=' . $module);
    }

    public function edit(string $module): array {
        $this->pageTitle .= ' - ' . __('Edit');

        $output = '';
        $Register = Register::getInstance();
        $model = $Register['ModManager']->getModelInstance($module);

        $id = (int)$_GET['id'];
        $entity = $model->getById($id);
        
        if (!empty($_POST)) {
            $entity->setTitle($_POST['title']);
            $entity->setMain($_POST['main']);
            $entity->setSourse_email($_POST['email']);
            $entity->save();
            $_SESSION['message'] = __('Saved');
            redirect('/admin/materials_list.php?m=' . $module);
        }

        $output .= '
        <div class="setting-item"><div class="left">' . __('Title') . '</div><div class="right">
            <input type="text" name="title" value="' . h($entity->getTitle()) . '" />
        </div><div class="clear"></div></div>
        <div class="setting-item"><div class="left">' . __('Text of material') . '</div><div class="right">
            <textarea style="height:200px;" name="main">' . h($entity->getMain()) . '</textarea>
        </div><div class="clear"></div></div>
        <div class="setting-item"><div class="left">' . __('Email') . '</div><div class="right">
            <input type="text" name="email" value="' . h($entity->getSourse_email()) . '" />
        </div><div class="clear"></div></div>
        <div class="setting-item">
            <div class="left"></div>
            <div class="right">
                <input class="save-button" type="submit" name="send" value="' . __('Save') . '" />
            </div>
            <div class="clear"></div>
        </div>';

        return [$output, ''];
    }
}

// Output HTML
include_once ROOT . '/admin/template/header.php';
echo '<h1>' . h($pageTitle) . '</h1>';
?>
<form method="post" action="">
    <?= $output ?>
</form>
<?php include_once ROOT . '/admin/template/footer.php'; ?>
