<?php
/*-----------------------------------------------\
| 												 |
|  @Author:       Andrey Brykin (Drunya)         |
|  @Email:        drunyacoder@gmail.com          |
|  @Site:         http://atomx.net               |
|  @Version:      1.5.4                          |
|  @Project:      CMS                            |
|  @package       CMS AtomX                      |
|  @subpackege    Template redactor              |
|  @copyright     ©Andrey Brykin 2010-2013       |
|  @last mod.     2013/06/13                     |
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

$pageTitle = __('Design - templates');
$pageNav = $pageTitle;
$pageNavr = '<a href="set_default_dis.php" onClick="return confirm(\'' . __('System will be restore the last saved template version. Are you sure?') . '\')">' . __('Return to default template') . '</a>&nbsp;|&nbsp;<a href="backup_dis.php" onClick="return confirm(\'' . __('System will be make a backup copy of template. Are you sure?') . '\')">' . __('Save current state of template') . '</a>';

$allowedFiles = array(
    'news' => array(
        'addform',
        'main',
        'editform',
        'material',
        'list',
    ),
    'stat' => array(
        'addform',
        'main',
        'editform',
        'material',
        'list',
    ),
    'loads' => array(
        'addform',
        'main',
        'editform',
        'material',
        'list',
    ),
    'foto' => array(
        'addform',
        'main',
        'editform',
        'material',
        'list',
    ),
    'chat' => array(
        'addform',
        'main',
        'list',
    ),
    'search' => array(
        'search_form',
        'search_row',
    ),
    'users' => array(
        'addnewuserform',
        'main',
        'edituserform',
        'loginform',
        'baned',
        'showuserinfo',
        'pm_send_form',
        'pm_view',
        'pm',
    ),
    'forum' => array(
        'addthemeform',
        'editthemeform',
        'main',
        'replyform',
        'editpostform',
        'get_stat',
        'posts_list',
        'themes_list',
    ),
    'default' => array(
        'main',
        'infomessagegrand',
    ),
    'custom' => array(),
);

$entities = array(
    'addform'           => __('Add form'),
    'main'              => __('Layout'),
    'editform'          => __('Edit form'),
    'material'          => __('Material view'),
    'list'              => __('List of materials'),
    'addnewuserform'    => __('Add form'),
    'edituserform'      => __('Edit form'),
    'loginform'         => __('Login form'),
    'baned'             => __('Ban page'),
    'showuserinfo'      => __('Profile info'),
    'style'             => __('Style(CSS)'),
    'addthemeform'      => __('Add theme form'),
    'editthemeform'     => __('Edit theme form'),
    'replyform'         => __('Reply form'),
    'editpostform'      => __('Edit post form'),
    'get_stat'          => __('Statistic'),
    'posts_list'        => __('Posts list'),
    'themes_list'       => __('Themes list'),
    'search_form'       => __('Search form'),
    'search_row'        => __('Search results'),
    'infomessagegrand'  => 'Страница ошибки',
    'pm_send_form'      => __('Send PM message form'),
    'pm_view'           => __('View one dialog'),
    'pm'                => __('List of dialogs'),
);

// Обработка добавления нового шаблона
if (!empty($_GET['ac']) && $_GET['ac'] === 'add_template') {
    // Проверка CSRF-токена
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(__('CSRF token validation failed'));
    }
    
    $title = preg_replace('#[^a-z0-9_\-]#', '', (!empty($_POST['title'])) ? $_POST['title'] : '');
    $code = (!empty($_POST['code'])) ? $_POST['code'] : '';
    
    if (empty($title) || empty($code)) {
        redirect('/admin/design.php?m=default&t=main', false);
    }
    
    // Безопасное создание пути
    $templateDir = ROOT . '/template/' . basename($Register['Config']->read('template'));
    $path = $templateDir . '/html/' . basename($title);
    $path2 = $path . '/main.html';
    
    if (file_exists($path) && is_dir($path)) {
        $_SESSION['errors'] = __('Same template already exists');
    } else {
        // Создание директории с безопасными правами
        if (mkdir($path, 0755, true)) {
            if (file_put_contents($path2, $code) !== false) {
                $_SESSION['message'] = __('Template is created');
            } else {
                $_SESSION['errors'] = __('Failed to create template file');
                rmdir($path); // Удаляем пустую директорию при ошибке
            }
        } else {
            $_SESSION['errors'] = __('Failed to create template directory');
        }
    }   
}

// Валидация параметров
$module = isset($_GET['m']) && is_string($_GET['m']) ? trim($_GET['m']) : 'default';
$tmp_file = isset($_GET['t']) && is_string($_GET['t']) ? trim($_GET['t']) : 'main';
$type = isset($_GET['d']) && is_string($_GET['d']) ? trim($_GET['d']) : 'default';

// Получение списка модулей
$modules = $Register['ModManager']->getModulesList();
foreach ($modules as $module_) {
    if (!array_key_exists($module_, $allowedFiles) && $Register['ModManager']->isInstall($module_)) {
        $extentionParams = $Register['ModManager']->getTemplateParts($module_);
        if (!empty($extentionParams)) {
            $allowedFiles[$module_] = $extentionParams;
        }
    }
}

// Пользовательские шаблоны
$custom_tpl = array();
$templateBaseDir = ROOT . '/template/' . basename($Register['Config']->read('template')) . '/html/';
if (is_dir($templateBaseDir)) {
    $pathes = glob($templateBaseDir . '*', GLOB_ONLYDIR);
    if (!empty($pathes)) {
        foreach ($pathes as $path) {
            $name = basename($path);
            if (!array_key_exists($name, $allowedFiles)) {
                $custom_tpl[] = $name;
            }
        }
    }
}

// Валидация параметров файла
$module = (array_key_exists($module, $allowedFiles)) ? $module : 'default';
$type = (in_array($type, array('css', 'default'))) ? $type : 'default';

if ($type === 'css') {
    $filename = (in_array('css.' . $tmp_file, $allowedFiles[$module])) ? $tmp_file : 'style';
} else {
    $filename = (in_array($tmp_file, $allowedFiles[$module])) ? $tmp_file : 'main';
}

if ($module === 'custom') {
    $module = $tmp_file;
    $filename = 'main';
}

// Обработка сохранения шаблона
if (isset($_POST['send']) && isset($_POST['templ'])) {
    // Проверка CSRF-токена
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(__('CSRF token validation failed'));
    }
    
    $templateContent = $_POST['templ'];
    
    if ($type == 'css') {
        $template_file = ROOT . '/template/' . basename($Register['Config']->read('template')) . '/css/' . basename($filename) . '.css';
        if (!is_file($template_file . '.stand')) {
            copy($template_file, $template_file . '.stand');
        }
    } else {
        $template_file = ROOT . '/template/' . basename($Register['Config']->read('template')) . '/html/' . basename($module) . '/' . basename($filename) . '.html';
        if (!file_exists($template_file . '.stand') && file_exists($template_file)) {
            copy($template_file, $template_file . '.stand');
        }
    }
    
    // Безопасное сохранение файла
    if (file_put_contents($template_file, $templateContent) !== false) {
        $_SESSION['message'] = __('Template is saved');
    } else {
        $_SESSION['errors'] = __('Template is not saved');
    }
}

// Получение содержимого шаблона
if ($type == 'css') {
    $path = ROOT . '/template/' . basename(Config::read('template')) . '/css/' . basename($filename) . '.css';
} else {
    clearstatcache();
    $path = ROOT . '/template/' . basename(Config::read('template')) . '/html/' . basename($module) . '/' . basename($filename) . '.html';
    if (!file_exists($path)) {
        $path = ROOT . '/template/' . basename(Config::read('template')) . '/html/default/' . basename($filename) . '.html';
        if (!file_exists($path)) {
            $_SESSION['message'] = __('Requested file is not found');
            redirect('/admin/design.php');
        }
    }
}

// Безопасное чтение файла
if (is_file($path) && is_readable($path)) {
    $template = file_get_contents($path);
} else {
    $_SESSION['errors'] = __('Cannot read template file');
    $template = '';
}

include_once ROOT . '/admin/template/header.php';

$csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
echo '<form action="' . htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8') . '" method="POST">';
echo '<input type="hidden" name="csrf_token" value="' . $csrfToken . '" />';
?>

<div class="warning">
    <?php echo __('Change template and save') ?>
</div>

<div class="white">
    <div class="pages-tree">
        <div class="title"><?php echo __('Pages') ?></div>
        <div class="wrapper">
            <div class="tbn"><?php echo __('Your templates') ?></div>
                <?php foreach ($custom_tpl as $file): ?>
                <div class="tba1">
                <a href="design.php?d=default&t=<?php echo urlencode($file); ?>&m=custom"><?php echo h($file) . '.html'; ?></a>
                </div>
                <?php endforeach; ?>

            <?php unset($allowedFiles['custom']); ?>
            <?php foreach ($allowedFiles as $mod => $files):
                $title = ('default' == $mod)
                    ? __('Default')
                    : __(ucfirst($mod));

                if (!empty($title)):
            ?>

                <div class="tbn"><?php echo h($title); ?></div>
                    <?php foreach ($files as $file): ?>
                    <div class="tba1">
                        <?php if (substr($file, 0, 4) === 'css.'): ?>
                            <a href="design.php?d=css&t=<?php echo urlencode(substr($file, 4)); ?>&m=<?php echo urlencode($mod); ?>">
                                <?php echo (!empty($entities[$file])) ? h($entities[$file]) : h(substr($file, 4)) . '.css'; ?>
                            </a>
                        <?php else: ?>
                            <a href="design.php?d=default&t=<?php echo urlencode($file); ?>&m=<?php echo urlencode($mod); ?>">
                                <?php echo (!empty($entities[$file])) ? h($entities[$file]) : h($file) . '.html'; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if ('default' == $mod): ?>
                        <div class="tba1">
                        <a href="design.php?d=css&t=style"><?php echo __('Style(CSS)') ?></a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div style="width:100%;">&nbsp;</div>
    </div>
    
    <div class="list pages-form">
        <div class="title"><?php echo __('Template editing') ?></div>
        <div class="add-cat-butt" onClick="openPopup('sec');"><div class="add"></div><?php echo __('Add template') ?></div>
        
        <div class="level1">
            <div class="items">
                <div class="setting-item">
                    <div class="center">
                        <textarea title="Код шаблона" style="width:99%; height:380px; display:none;" wrap="off" name="templ" id="tmpl"><?php print h($template); ?></textarea>
                    </div>
                    <div class="clear"></div>
                </div>
                <div class="setting-item">
                    <div class="left">
                    </div>
                    <div class="right">
                        <input class="save-button" type="submit" name="send" value="<?php echo __('Save') ?>" />
                    </div>
                    <div class="clear"></div>
                </div>
            </div>
        </div>
    </div>
    </form>
    <div class="clear"></div>
</div>

<div id="sec" class="popup">
<div class="top">
    <div class="title"><?php echo __('Adding template') ?></div>
    <div onClick="closePopup('sec');" class="close"></div>
</div>
<div class="items">
    <form action="design.php?ac=add_template" method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>" />
        <div class="item">
            <div class="left">
                <?php echo __('Name') ?>
            </div>
            <div class="right"><input type="text" name="title" value="" pattern="[a-zA-Z0-9_\-]+" title="<?php echo __('Only letters, numbers, underscores and hyphens are allowed'); ?>" /></div>
            <div class="clear"></div>
        </div>
        <div class="item">
            <div class="left">
                HTML
            </div>
            <div class="right">
                <textarea name="code" style="height:150px; overflow:auto;"></textarea>
            </div>
            <div class="clear"></div>
        </div>
        <div class="item submit">
            <div class="left"></div>
            <div class="right" style="float:left;">
                <input type="submit" value="<?php echo __('Save') ?>" name="send" class="save-button" />
            </div>
            <div class="clear"></div>
        </div>
    </form>
</div>
</div>

<script type="text/javascript" src="js/codemirror/codemirror.js"></script>
<script type="text/javascript" src="js/codemirror/mode/htmlmixed/htmlmixed.js"></script>
<script type="text/javascript" src="js/codemirror/mode/vbscript/vbscript.js"></script>
<script type="text/javascript" src="js/codemirror/mode/css/css.js"></script>
<link rel="StyleSheet" type="text/css" href="js/codemirror/codemirror.css" />
<link rel="StyleSheet" type="text/css" href="js/codemirror/theme/solarized.css" />
<script type="text/javascript">
$(document).ready(function(){
    var wd = parseInt($('.list.pages-form').css('width'), 10) - 20;
    var editor = CodeMirror.fromTextArea(document.getElementById("tmpl"), {
        theme: "solarized",
        mode: "<?php echo ($type === 'css') ? 'css' : 'vbscript'; ?>"
    });
    
    editor.setSize(wd, 450);
    $('.CodeMirror').css({
        'margin-top': '-20px',
        'margin-bottom': '-20px'
    });
});
</script>

<script type="text/javascript">
$('#listener').hover(function(){
    $(this).stop().animate({height:'200px', overflow:'auto'}, 400);
    $(this).css('overflow','auto');
},
function(){
    $(this).stop().animate({height:'90px', opacity:'0.3'}, 300, function(){
        $(this).css('opacity','1');
    });
    $(this).css('overflow','auto');
});
</script>

<ul class="markers">
    <h2>Глобальные метки</h2>
    <li><div class="global-marks">{{ content }}</div> - Основной контент страницы</li>
    <li><div class="global-marks">{{ meta_title }}</div> - <?php echo __('Page title') ?></li>
    <li><div class="global-marks">{{ meta_keywords }}</div> - <?php echo __('Keywords') ?></li>
    <li><div class="global-marks">{{ meta_description }}</div> - Содержание Мета-тега description</li>
    <li><div class="global-marks">{{ fps_wday }}</div> - День кратко</li>
    <li><div class="global-marks">{{ fps_date }}</div> - Дата</li>
    <li><div class="global-marks">{{ fps_time }}</div> - Время</li>
    <li><div class="global-marks">{{ headmenu }}</div> - Верхнее меню</li>
    <li><div class="global-marks">{{ fps_user_name }}</div> - Ник текущего пользователя (Для не авторизованного - Гость)</li>
    <li><div class="global-marks">{{ fps_user_group }}</div> - Группа текущего пользователя (Для не авторизованного - Гости)</li>
    <li><div class="global-marks">{{ categories }}</div> - Список категорий раздела</li>
    <li><div class="global-marks">{{ counter }}</div> - Встроенный счетчик посещаемости AtomX</li>
    <li><div class="global-marks">{{ fps_year }}</div> - Год</li>
    <li><div class="global-marks">{{ powered_by }}</div> - AtomX CMS</li>
    <li><div class="global-marks">{{ comments }}</div> - Комментарии к материалу и форма добавления комментариев <b>(если предусмотренно)</b></li>
    <li><div class="global-marks">{{ personal_page_link }}</div> - URL на свою персональную страницу или на страницу регистрации, если не авторизован</li>
</ul>

<?php include_once 'template/footer.php'; ?>
