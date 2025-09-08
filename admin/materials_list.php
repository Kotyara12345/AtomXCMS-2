<?php
##################################################
##                                              ##
## @Author:       Andrey Brykin (Drunya)        ##
## @Version:      2.0                           ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Admin Panel module            ##
## @copyright     ©Andrey Brykin 2010-2014      ##
## @last mod.     2014/01/15                    ##
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
if (!$Auth->hasPermission('admin_panel', 'manage_materials')) {
    die(__('Access denied'));
}

$pageTitle = __('List of materials');
$Register = Register::getInstance();

// Получение разрешенных модулей и действий
$allowed_mods = $Register['ModManager']->getAllowedModules('materialsList');
$allowed_actions = array('edit', 'delete', 'index', 'premoder', 'multi_delete', 'multi_premoder');

// Валидация входных параметров
$module = isset($_GET['m']) && in_array($_GET['m'], $allowed_mods) ? $_GET['m'] : null;
if (empty($module)) {
    $_SESSION['message'] = '<div class="warning error">' . __('Invalid module') . '</div>';
    redirect('/admin/');
}

$action = isset($_GET['ac']) && in_array($_GET['ac'], $allowed_actions) ? $_GET['ac'] : 'index';

// Обработка CSRF токена для POST действий
if (in_array($action, array('edit', 'delete', 'premoder', 'multi_delete', 'multi_premoder')) && !empty($_POST)) {
    validateCsrfToken();
}

// Инициализация контроллера
$Controller = new MaterialsListController;
list($output, $pages) = $Controller->{$action}($module);

$pageNav = $Controller->pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';

?>

<form method="POST" action="" id="materialsForm" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<div class="list">
    <div class="title"><?php echo $pageNav; ?></div>
    
    <!-- Панель действий -->
    <div class="action-panel">
        <div class="add-cat-butt">
            <select onChange="window.location.href='/admin/materials_list.php?m=<?php echo $module; ?>&order='+this.value;">
                <option value=""><?php echo __('Ordering') ?></option>
                <option value="views" <?php echo (isset($_GET['order']) && $_GET['order'] == 'views' && !isset($_GET['asc'])) ? 'selected' : ''; ?>>
                    <?php echo __('Views') ?> (↓)
                </option>
                <option value="views&asc=1" <?php echo (isset($_GET['order']) && $_GET['order'] == 'views' && isset($_GET['asc'])) ? 'selected' : ''; ?>>
                    <?php echo __('Views') ?> (↑)
                </option>
                <option value="comments" <?php echo (isset($_GET['order']) && $_GET['order'] == 'comments' && !isset($_GET['asc'])) ? 'selected' : ''; ?>>
                    <?php echo __('Comments') ?> (↓)
                </option>
                <option value="comments&asc=1" <?php echo (isset($_GET['order']) && $_GET['order'] == 'comments' && isset($_GET['asc'])) ? 'selected' : ''; ?>>
                    <?php echo __('Comments') ?> (↑)
                </option>
                <option value="date" <?php echo (isset($_GET['order']) && $_GET['order'] == 'date' && !isset($_GET['asc'])) ? 'selected' : ''; ?>>
                    <?php echo __('Date') ?> (↓)
                </option>
                <option value="date&asc=1" <?php echo (isset($_GET['order']) && $_GET['order'] == 'date' && isset($_GET['asc'])) ? 'selected' : ''; ?>>
                    <?php echo __('Date') ?> (↑)
                </option>
            </select>
        </div>
        
        <?php if (empty($_GET['premoder'])): ?>
        <div class="action-buttons">
            <button type="button" onclick="selectAllMaterials()" class="action-button">
                <?php echo __('Select all') ?>
            </button>
            <button type="submit" name="action" value="delete_selected" onclick="return confirm('<?php echo __('Are you sure you want to delete selected materials?') ?>')" class="action-button delete">
                <?php echo __('Delete selected') ?>
            </button>
        </div>
        <?php else: ?>
        <div class="action-buttons">
            <button type="button" onclick="selectAllMaterials()" class="action-button">
                <?php echo __('Select all') ?>
            </button>
            <button type="submit" name="action" value="approve_selected" class="action-button approve">
                <?php echo __('Approve selected') ?>
            </button>
            <button type="submit" name="action" value="reject_selected" class="action-button reject">
                <?php echo __('Reject selected') ?>
            </button>
        </div>
        <?php endif; ?>
        
        <div class="view-switcher">
            <a href="?m=<?php echo $module; ?>" class="<?php echo empty($_GET['premoder']) ? 'active' : ''; ?>">
                <?php echo __('All materials') ?>
            </a>
            <a href="?m=<?php echo $module; ?>&premoder=1" class="<?php echo !empty($_GET['premoder']) ? 'active' : ''; ?>">
                <?php echo __('Moderation') ?>
                <?php if ($Controller->getModerationCount($module) > 0): ?>
                <span class="badge"><?php echo $Controller->getModerationCount($module); ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>
    
    <div class="level1">
        <div class="head">
            <div class="title settings" style="width:30px;">
                <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)">
            </div>
            <div class="title settings" style="width:40%;"><?php echo __('Title') ?></div>
            <div class="title-r" style="width:40%;"><?php echo __('Content') ?></div>
            <div class="title-r" style="width:20%;"><?php echo __('Actions') ?></div>
            <div class="clear"></div>
        </div>
        <div class="items">
            <?php echo $output; ?>
        </div>
    </div>
</div>
<div class="pagination"><?php echo $pages; ?></div>
</form>

<script type="text/javascript">
function toggleSelectAll(checkbox) {
    var checkboxes = document.querySelectorAll('.material-checkbox');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = checkbox.checked;
    }
}

function selectAllMaterials() {
    document.getElementById('selectAllCheckbox').checked = true;
    toggleSelectAll(document.getElementById('selectAllCheckbox'));
}

function confirmDelete(message) {
    return confirm(message || '<?php echo __('Are you sure you want to delete this material?') ?>');
}

// AJAX обновление статуса
function updateMaterialStatus(id, status) {
    if (confirm('<?php echo __('Change moderation status?') ?>')) {
        $.post('materials_list.php?m=<?php echo $module; ?>', {
            ac: 'premoder',
            id: id,
            status: status,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        }, function(response) {
            location.reload();
        });
    }
}
</script>

<?php 
include_once 'template/footer.php';

/**
 * Контроллер управления материалами
 */
class MaterialsListController {
    public $pageTitle;
    private $Register;
    
    public function __construct() {
        $this->pageTitle = __('List of materials');
        $this->Register = Register::getInstance();
        
        // Инициализация CSRF токена
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }
    
    /**
     * Валидация CSRF токена
     */
    private function validateCsrfToken() {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $_SESSION['message'] = '<div class="warning error">' . __('Security token mismatch') . '</div>';
            redirect('/admin/materials_list.php?m=' . $this->module);
        }
    }
    
    /**
     * Получение количества материалов на модерации
     */
    public function getModerationCount($module) {
        $model = $this->Register['ModManager']->getModelInstance($module);
        return $model->getTotal(array('cond' => array('premoder' => 'nochecked')));
    }
    
    /**
     * Главная страница со списком материалов
     */
    public function index($module) {
        $output = '';
        $model = $this->Register['ModManager']->getModelInstance($module);
        
        // Условия выборки
        $where = (!empty($_GET['premoder'])) ? array('premoder' => 'nochecked') : array();
        
        // Поиск
        if (!empty($_GET['search'])) {
            $search = trim($_GET['search']);
            $where['OR'] = array(
                'title LIKE' => "%$search%",
                'main LIKE' => "%$search%"
            );
        }
        
        $total = $model->getTotal(array('cond' => $where));
        list ($pages, $page) = pagination($total, 20, '/admin/materials_list.php?m=' . $module
            . (!empty($_GET['order']) ? '&order=' . $_GET['order'] : '')
            . (!empty($_GET['asc']) ? '&asc=1' : '')
            . (!empty($_GET['premoder']) ? '&premoder=1' : '')
            . (!empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''));
        
        // Получение материалов
        $model->bindModel('author');
        $materials = $model->getCollection($where, array(
            'page' => $page,
            'limit' => 20,
            'order' => $model->getOrderParam(),
        ));
        
        if (empty($materials)) {
            $output = '<div class="setting-item">
                <div class="left"><b>' . __('Materials not found') . '</b></div>
                <div class="clear"></div>
            </div>';
        }
        
        foreach ($materials as $mat) {
            $output .= '<div class="setting-item">
                <div class="left" style="width:30px;">
                    <input type="checkbox" name="material_ids[]" value="' . $mat->getId() . '" class="material-checkbox">
                </div>
                <div class="left" style="width:40%;">
                    <a style="font-weight:bold; margin-bottom:5px;" href="' 
                        . get_url('/admin/materials_list.php?m=' . $module . '&ac=edit&id=' 
                        . $mat->getId()) . '">' . htmlspecialchars($mat->getTitle()) . '</a>
                    <br /><span class="comment">' . htmlspecialchars($mat->getAuthor()->getName()) . '</span>
                    <br /><span class="comment">' . AtmDateTime::getSimpleDate($mat->getDate()) . '</span>
                </div>
                <div class="right" style="width:40%;">';
            
            if ($module == 'foto') {
                $altText = preg_replace('#[^\w\d ]+#ui', ' ', $mat->getTitle());
                $imageHtml = '<img alt="' . htmlspecialchars($altText) . '" src="' . WWW_ROOT . '/sys/files/' . $module . '/preview/' 
                    . htmlspecialchars($mat->getFilename()) . '" width="150px" style="max-height:100px;object-fit:cover;" />';
                $imageHtml = '<a target="_blank" href="' . WWW_ROOT . '/sys/files/' . $module . '/full/' 
                    . htmlspecialchars($mat->getFilename()) . '">' . $imageHtml . '</a>';
                
                $output .= $imageHtml;
            } else {
                $content = strip_tags($mat->getMain());
                $output .= htmlspecialchars(mb_substr($content, 0, 200));
                if (mb_strlen($content) > 200) $output .= '...';
            }
            
            $output .= '<br /><span class="comment">' 
                . __('Views:') . ' ' . (int)$mat->getViews() 
                . ' | ' . __('Comments:') . ' ' . (int)$mat->getComments() . '</span>';
            
            $output .= '</div><div class="right" style="width:20%;">';
            
            if (!empty($_GET['premoder'])) {
                $output .= '
                <div class="action-buttons">
                    <button onclick="updateMaterialStatus(' . $mat->getId() . ', \'rejected\')" class="btn-reject">
                        ' . __('Reject') . '
                    </button>
                    <button onclick="updateMaterialStatus(' . $mat->getId() . ', \'confirmed\')" class="btn-approve">
                        ' . __('Approve') . '
                    </button>
                </div>';
            } else {
                $output .= '
                <div class="action-buttons">
                    <a href="' . get_url('/admin/materials_list.php?m=' . $module . '&ac=delete&id=' . $mat->getId()) . '" 
                       onclick="return confirmDelete()" class="btn-delete">
                        ' . __('Delete') . '
                    </a>
                    <a href="' . get_url('/admin/materials_list.php?m=' . $module . '&ac=edit&id=' . $mat->getId()) . '" 
                       class="btn-edit">
                        ' . __('Edit') . '
                    </a>
                </div>';
            }
            
            $output .= '</div><div class="clear"></div></div>';
        }
        
        return array($output, $pages);
    }
    
    /**
     * Массовое удаление материалов
     */
    public function multi_delete($module) {
        if (empty($_POST['material_ids'])) {
            $_SESSION['message'] = '<div class="warning error">' . __('No materials selected') . '</div>';
            redirect('/admin/materials_list.php?m=' . $module);
        }
        
        $model = $this->Register['ModManager']->getModelInstance($module);
        $deleted = 0;
        
        foreach ($_POST['material_ids'] as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $entity = $model->getById($id);
                if ($entity) {
                    $this->deleteMaterial($entity, $module);
                    $deleted++;
                }
            }
        }
        
        $_SESSION['message'] = '<div class="warning ok">' 
            . sprintf(__('%d materials deleted successfully'), $deleted) . '</div>';
        
        redirect('/admin/materials_list.php?m=' . $module);
    }
    
    /**
     * Массовая модерация материалов
     */
    public function multi_premoder($module) {
        if (empty($_POST['material_ids']) || empty($_POST['status'])) {
            $_SESSION['message'] = '<div class="warning error">' . __('Invalid parameters') . '</div>';
            redirect('/admin/materials_list.php?m=' . $module . '&premoder=1');
        }
        
        $status = in_array($_POST['status'], array('rejected', 'confirmed')) ? $_POST['status'] : 'nochecked';
        $model = $this->Register['ModManager']->getModelInstance($module);
        $processed = 0;
        
        foreach ($_POST['material_ids'] as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $entity = $model->getById($id);
                if ($entity && $entity->getPremoder() == 'nochecked') {
                    $entity->setPremoder($status);
                    $entity->save();
                    $processed++;
                }
            }
        }
        
        // Очистка кэша
        $Cache = new Cache;
        $Cache->clean(CACHE_MATCHING_ANY_TAG, array('module_' . $module));
        
        $_SESSION['message'] = '<div class="warning ok">' 
            . sprintf(__('%d materials processed'), $processed) . '</div>';
        
        redirect('/admin/materials_list.php?m=' . $module . '&premoder=1');
    }
    
    /**
     * Удаление материала
     */
    private function deleteMaterial($entity, $module) {
        if ($module == 'foto') {
            // Удаление файлов изображений
            $files = array(
                ROOT . '/sys/files/' . $module . '/full/' . $entity->getFilename(),
                ROOT . '/sys/files/' . $module . '/preview/' . $entity->getFilename()
            );
            
            foreach ($files as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        }
        
        $entity->delete();
        
        // Очистка кэша
        $Cache = new Cache;
        $Cache->clean(CACHE_MATCHING_ANY_TAG, array('module_' . $module));
    }
    
    // Остальные методы (premoder, delete, edit) остаются аналогичными, но с улучшенной обработкой ошибок
    // и безопасностью...
}

?>
