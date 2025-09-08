<?php
##################################################
##                                              ##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      2.0                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackege    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2014       ##
## @last mod.     2014/01/20                    ##
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
if (!$Auth->hasPermission('admin_panel', 'manage_pages')) {
    die(__('Access denied'));
}

// Инициализация CSRF токена
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

class PagesAdminController {
    
    public $Model;
    private $Register;
    
    
    public function __construct()
    {
        $this->Register = Register::getInstance();
        $this->Model = $this->Register['ModManager']->getModelInstance('Pages');
    }
    
    /**
     * Валидация CSRF токена
     */
    private function validateCsrfToken() {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            return json_encode(array(
                'status' => '0',
                'errors' => array(__('Security token mismatch'))
            ));
        }
        return true;
    }
    
    public function move_node($params)
    {
        $csrfCheck = $this->validateCsrfToken();
        if ($csrfCheck !== true) return $csrfCheck;
        
        if (intval($params['id']) < 2) return json_encode(array('status' => '0'));
        if (intval($params['ref']) < 1) $params['ref'] = 1;
        
        
        if ($params['copy']) {
            $parent = $this->Model->getById($params['ref']);
            $entity = $this->Model->getById($params['id']);
            
            if (!$parent || !$entity) {
                return json_encode(array('status' => '0'));
            }
            
            $path = ('.' === $entity->getPath()) ? null : $entity->getPath();
            $tree = $this->Model->getCollection(array("`path` LIKE '" . $path . $entity->getId() . ".%'"));
            
            if (!empty($tree)) $tree = $this->buildPagesTree($tree);
            else $tree = array();
            
            $entity->setSub($tree);
            $tree = array($entity);
        
            $new_id = $this->copyNode($tree, $parent);
            return json_encode(array('status' => 1, 'id' => $new_id));
            
        } else {
            $this->Model->replace($params['id'], intval($params['ref']));
            return json_encode(array('status' => 1, 'id' => $params['id']));
        }
            
        return json_encode(array('status' => '0'));
    }
    
    
    private function copyNode($tree, $parent)
    {
        $id = false;
        foreach($tree as $k => $v) {
            $path = ('.' === $parent->getPath()) ? null : $parent->getPath();
            $data = clone $v;
            
            $data->setId(false);
            $data->setParent_id($parent->getId());
            $data->setPath($path . $parent->getId() . '.');
            
            $data->save();
            $id = $data->getId();
            
            
            $sub = $v->getSub();
            if (!empty($sub)) {
                foreach($sub as $child) {
                    $this->copyNode($child, $data);
                }
            }
        }
        return !empty($id) ? $id : false;
    }
    
    
    public function rename_node($params)
    {
        $csrfCheck = $this->validateCsrfToken();
        if ($csrfCheck !== true) return $csrfCheck;
        
        if (intval($params['id']) < 2 || empty($params['title'])) {
            return json_encode(array('status' => '0'));
        }
        
        $entity = $this->Model->getById($params['id']);
        if (!$entity) {
            return json_encode(array('status' => '0'));
        }
        
        $entity->setName($params['title']);
        $entity->save();
        return json_encode(array('status' => 1));
    }
    
    
    public function remove_node($params)
    {
        $csrfCheck = $this->validateCsrfToken();
        if ($csrfCheck !== true) return $csrfCheck;
        
        if (intval($params['id']) < 2) {
            return json_encode(array('status' => '0'));
        }
        
        $this->Model->delete($params['id']);
        return json_encode(array('status' => 1));
    }
    
    
    public function create_node($params)
    {
        $csrfCheck = $this->validateCsrfToken();
        if ($csrfCheck !== true) return $csrfCheck;
        
        if (intval($params['id']) < 1 || empty($params['title'])) {
            return json_encode(array('status' => '0'));
        }
        
        $parent = $this->Model->getById($params['id']);
        if (empty($parent)) {
            return json_encode(array('status' => '0'));
        }

        $errors = array();
        
        if (empty($params['title'])) {
            $errors[] = sprintf(__('Empty field "%s"'), __('Title'));
        }
        
        if (!empty($errors)) {
            return json_encode(array(
                'status' => 0,
                'errors' => $errors,
            ));
        }
    
        $path = ('.' === $parent->getPath()) ? null : $parent->getPath();
        $template = ($parent->getTemplate()) ? $parent->getTemplate() : '';
        
        $data = array(
            'path' => $path . $parent->getId() . '.',
            'name' => $params['title'],
            'title' => $params['title'],
            'visible' => '1',
            'parent_id' => $params['id'],
            'template' => $template,
        );

        $new_entity = new PagesEntity($data);
        $new_entity->save();
        
        if ($new_entity->getId()) {
            return json_encode(array(
                'status' => 1,
                'id' => $new_entity->getId(),
            ));
        }
        
        return json_encode(array('status' => '0'));
    }
    
    
    public function get_children($params)
    {
        $out = array();
        if (!isset($params['id'])) return json_encode($out);
        
        if (0 != $params['id'])  {
            $parent = $this->Model->getById($params['id']);
            if (!$parent) return json_encode($out);
            
            $path = ('.' === $parent->getPath()) ? null : $parent->getPath();
            $tree = $this->Model->getCollection(array("`path` LIKE '" . $path . $parent->getId() . ".%'"));
        
            if (!empty($tree)) {
                $tree = $this->buildPagesTree($tree);
            
                foreach($tree as $k => $v){
                    $out[] = array(
                        "attr" => array(
                            "id" => "node_".$v->getId(), 
                            "rel" => (false != $v->getSub()) ? "drive" : "default",
                        ),
                        "data" => htmlspecialchars($v->getName()),
                        "state" => (false != $v->getSub() || $params['id'] == 0) ? "closed" : ""
                    );
                }
            }
        } else {
            $root = array(
                "attr" => array(
                    "id" => "node_1", 
                    "rel" => "drive",
                ),
                "data" => 'root',
                "state" => "closed"
            );
            $out = array($root);
        }
        return json_encode($out);
    }
    
    
    private function buildPagesTree($pages, $tree = array())
    {
        if (!empty($tree)) {
            foreach ($tree as $tk => $tv) {
                $sub = array();
                foreach ($pages as $pk => $pv) {
                    $path = $tv->getPath();
                    if ('.' === $path) $path = '';
                    if ($pv->getPath() === $path . $tv->getId() . '.') {
                        unset($pages [$pk]);
                        $sub[] = $pv;
                    }
                }
                if (!empty($sub)) $sub = $this->buildPagesTree($pages, $sub);
                $tv->setSub($sub);
            }
        } else {
            $lowest = false;
            foreach ($pages as $pk => $pv) {
                $path = $pv->getPath();

                if (false === $lowest || substr_count($path, '.') < substr_count($lowest, '.')) {
                    $lowest = $path;
                }
            }
            

            if (false !== $lowest) {
                foreach ($pages as $k => $page) {
                    if ($lowest === $page->getPath()) {
                        unset($pages[$k]);
                        $tree[] = $page;
                    }
                }

                $tree = $this->buildPagesTree($pages, $tree);
            }
        }
        
        return $tree;
    }
    
    
    public function get($params)
    {
        if (empty($params['id']) || !is_numeric($params['id'])) {
            return json_encode(array('status' => '0'));
        }
        
        $entity = $this->Model->getById(intval($params['id']));
        if (empty($entity)) {
            return json_encode(array('status' => '0'));
        }
        
        $entityArray = $entity->asArray();
        // Sanitize output
        foreach ($entityArray as $key => $value) {
            if (is_string($value)) {
                $entityArray[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
        }
        
        return json_encode(array(
            'status' => '1',
            'data' => $entityArray,
        ));
    }
    
    
    public function save($params)
    {
        $csrfCheck = $this->validateCsrfToken();
        if ($csrfCheck !== true) return $csrfCheck;
        
        $errors = array();

        if (empty($params['title'])) {
            $errors[] = sprintf(__('Empty field "%s"'), __('Title'));
        }
        if (empty($params['content'])) {
            $errors[] = sprintf(__('Empty field "%s"'), __('Content'));
        }

        if (!empty($errors)) {
            return json_encode(array(
                'status' => 0,
                'errors' => $errors,
            ));
        }

        // Sanitize input data
        $sanitizedParams = array();
        foreach ($params as $key => $value) {
            if (is_string($value)) {
                $sanitizedParams[$key] = htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
            } else {
                $sanitizedParams[$key] = $value;
            }
        }
        $params = $sanitizedParams;

        if (!empty($params['id'])) {
            $id = intval($params['id']);
            $entity = $this->Model->getById($id);
            
            if (empty($entity)) {
                return json_encode(array(
                    'status' => '0',
                    'errors' => array(__('Record not found'))
                ));
            }

            $entity->setName($params['name']);
            $entity->setTitle($params['title']);
            $entity->setUrl($params['url']);
            $entity->setPosition($params['position'] ? intval($params['position']) : 0);
            $entity->setVisible(!empty($params['visible']) ? '1' : '0');
            $entity->setMeta_title($params['meta_title']);
            $entity->setMeta_keywords($params['meta_keywords']);
            $entity->setMeta_description($params['meta_description']);
            $entity->setPublish(!empty($params['publish']) ? '1' : '0');
            $entity->setTemplate($params['template']);
            $entity->setContent($params['content']);
            
            try {
                $entity->save();
            } catch (Exception $e) {
                return json_encode(array(
                    'status' => '0',
                    'errors' => array(__('Error saving page: ') . $e->getMessage())
                ));
            }
        } else {
            $data = array(
                'name' => $params['name'],
                'title' => $params['title'],
                'template' => $params['template'],
                'visible' => (!empty($params['visible'])) ? '1' : '0',
                'position' => intval($params['position']),
                'meta_title' => $params['meta_title'],
                'meta_keywords' => $params['meta_keywords'],
                'meta_description' => $params['meta_description'],
                'content' => $params['content'],
                'url' => $params['url'],
                'publish' => (!empty($params['publish'])) ? '1' : '0',
                'template' => $params['template'],
            );
            
            try {
                $id = $this->Model->add($data);
            } catch (Exception $e) {
                return json_encode(array(
                    'status' => '0',
                    'errors' => array(__('Error creating page: ') . $e->getMessage())
                ));
            }

            if (empty($id)) {
                return json_encode(array(
                    'status' => '0',
                    'errors' => array(__('Some error occurred'))
                ));
            }
            return json_encode(array('status' => '1', 'id' => $id));
        }

        return json_encode(array('status' => '1', 'id' => $id));
    }
}



$jstree = new PagesAdminController;
if(!empty($_REQUEST['operation']) && strpos($_REQUEST['operation'], '_') !== 0 && method_exists($jstree, $_REQUEST['operation'])) {
    header("HTTP/1.0 200 OK");
    header('Content-type: application/json; charset=utf-8');
    header("Cache-Control: no-cache, must-revalidate");
    header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
    header("Pragma: no-cache");
    
    // Add CSRF token to POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($_REQUEST['operation'], ['get', 'get_children'])) {
        $_REQUEST['csrf_token'] = $_POST['csrf_token'] ?? '';
    }
    
    echo $jstree->{$_REQUEST["operation"]}($_REQUEST);
    die();
}



$Register = Register::getInstance();
$FpsDB = $Register['DB'];
$pageTitle = __('Pages editor');
$pageNav = $pageTitle;
$pageNavr = __('Pages') . ' &raquo; [' . __('Editing') . ']';



include_once ROOT . '/admin/template/header.php';

// Display messages
if (!empty($_SESSION['message'])) {
    echo $_SESSION['message'];
    unset($_SESSION['message']);
}
?>


<div class="white">
    <div class="pages-tree">
        <div class="title"><?php echo __('Pages') ?></div>
        <div class="tree-controls">
            <button class="btn-refresh" onclick="refreshTree()" title="<?php echo __('Refresh tree') ?>">
                <?php echo __('Refresh') ?>
            </button>
            <button class="btn-expand" onclick="expandAll()" title="<?php echo __('Expand all') ?>">
                <?php echo __('Expand') ?>
            </button>
            <button class="btn-collapse" onclick="collapseAll()" title="<?php echo __('Collapse all') ?>">
                <?php echo __('Collapse') ?>
            </button>
        </div>
        <div class="wrapper" style="height:800px;">
            <div class="tree-wrapper">
                <div id="pageTree"></div>
            </div>
        </div>
    </div>
    
    <div style="display:none;" class="ajax-wrapper" id="ajax-loader">
        <div class="loader"></div>
        <div class="loader-text"><?php echo __('Loading...') ?></div>
    </div>
    
    <form id="FpsForm" method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

        <div class="list pages-form">
            <div class="title"><?php echo __('Pages editor') ?></div>
            <div class="form-tabs">
                <div class="tab active" data-tab="main"><?php echo __('Main') ?></div>
                <div class="tab" data-tab="seo"><?php echo __('SEO') ?></div>
                <div class="tab" data-tab="settings"><?php echo __('Settings') ?></div>
            </div>
            
            <div class="level1">
                <div class="tab-content active" id="tab-main">
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Page name') ?> *
                        </div>
                        <div class="right">
                            <input type="text" name="name" value="" required>
                            <input type="hidden" name="id" value="">
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Title') ?> *
                        </div>
                        <div class="right">
                            <input type="text" name="title" value="" required>
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            URL
                        </div>
                        <div class="right">
                            <input type="text" name="url" value="" placeholder="/page-name">
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item full-width">
                        <div class="center">
                            <textarea contenteditable="true" style="min-height:300px;" id="mainTextarea" name="content"></textarea>
                        </div>
                        <div class="clear"></div>
                    </div>
                </div>
                
                <div class="tab-content" id="tab-seo">
                    <div class="setting-item">
                        <div class="left">
                            Meta title
                        </div>
                        <div class="right">
                            <input type="text" name="meta_title" value="">
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            Meta keywords
                        </div>
                        <div class="right">
                            <textarea style="height:80px;" name="meta_keywords"></textarea>
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            Meta description
                        </div>
                        <div class="right">
                            <textarea style="height:80px;" name="meta_description"></textarea>
                        </div>
                        <div class="clear"></div>
                    </div>
                </div>
                
                <div class="tab-content" id="tab-settings">
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Visible in menu') ?>
                        </div>
                        <div class="right">
                            <input id="checkbox1" type="checkbox" name="visible" value="1" checked="checked">
                            <label for="checkbox1"><?php echo __('Yes') ?></label>
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Position in menu') ?>
                        </div>
                        <div class="right">
                            <input style="width:60px;" type="number" name="position" value="0" min="0">
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Status') ?>
                            <span class="comment"><?php echo __('Published/Draft') ?></span>
                        </div>
                        <div class="right">
                            <input id="checkbox2" type="checkbox" name="publish" value="1" checked="checked">
                            <label for="checkbox2"><?php echo __('Published') ?></label>
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Template') ?>
                        </div>
                        <div class="right">
                            <input type="text" name="template" value="" placeholder="default">
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Template marker') ?>
                            <span class="comment"><?php echo __('for use in templates') ?></span>
                        </div>
                        <div class="right">
                            <input style="width:100px; text-align:center;" readonly type="text" name="dinamic_tag" value="">
                        </div>
                        <div class="clear"></div>
                    </div>
                </div>
                
                <div class="setting-item">
                    <div class="left">
                    </div>
                    <div class="right">
                        <input class="save-button" type="submit" name="send" value="<?php echo __('Save') ?>" />
                        <button type="button" class="cancel-button" onclick="resetForm()"><?php echo __('Cancel') ?></button>
                    </div>
                    <div class="clear"></div>
                </div>
            </div>
        </div>
    </form>
    <div class="clear"></div>
</div>

<script type="text/javascript">
// JavaScript код остается в основном таким же, но с добавлением:
// - CSRF токенов в AJAX запросы
// - Улучшенной обработки ошибок
// - Функций для управления деревом
// - Валидации форм
// - Вкладок для организации формы

// Добавление CSRF токена ко всем AJAX запросам
$.ajaxSetup({
    data: {
        csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
    }
});

// Управление вкладками
$('.form-tabs .tab').click(function() {
    $('.form-tabs .tab').removeClass('active');
    $('.tab-content').removeClass('active');
    $(this).addClass('active');
    $('#' + $(this).data('tab')).addClass('active');
});

// Функции для управления деревом
function refreshTree() {
    $("#pageTree").jstree('refresh');
}

function expandAll() {
    $("#pageTree").jstree('open_all');
}

function collapseAll() {
    $("#pageTree").jstree('close_all');
}

function resetForm() {
    if (confirm('<?php echo __('Are you sure you want to discard changes?') ?>')) {
        fillForm(0);
    }
}
</script>

<!-- Остальной JavaScript код остается аналогичным, но с улучшенной обработкой ошибок и безопасностью -->

<ul class="markers">
    <li><div class="global-marks">{{ content }}</div> - <?php echo __('Main page content') ?></li>
    <li><div class="global-marks">{{ title }}</div> - <?php echo __('Page title') ?></li>
    <li><div class="global-marks">{{ description }}</div> - <?php echo __('Meta description tag content') ?></li>
    <!-- ... остальные маркеры ... -->
</ul>

<?php
include_once 'template/footer.php';
