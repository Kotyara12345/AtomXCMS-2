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

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$pageTitle = 'Системные настройки';
$Register = Register::getInstance();
$config = $Register['Config']->read('all');

// Подготовка списка шаблонов
$templates = array_map(fn($dir) => basename($dir), glob(ROOT . '/template/*', GLOB_ONLYDIR));

// Подготовка списка шрифтов
$fonts = array_map('basename', glob(ROOT . '/sys/fonts/*.ttf'));
sort($fonts);

// Подготовка списка смайлов
$smiles = glob(ROOT . '/sys/img/smiles/*/info.php');
$smilesSelect = [];

foreach ($smiles as $value) {
    if (is_file($value)) {
        include_once $value;
        $smileName = basename(dirname($value));
        if (isset($smilesInfo['name'])) {
            $smilesSelect[$smileName] = $smilesInfo['name'];
        }
    }
}

// Функция для получения пути к изображению шаблона
function getImgPath(string $template): string {
    $path = ROOT . '/template/' . $template . '/screenshot.png';
    return file_exists($path) ? get_url('/template/' . $template . '/screenshot.png') : get_url('/sys/img/noimage.jpg');
}

// Загружаем свойства системных настроек
include_once ROOT . '/sys/settings/conf_properties.php';

// Получаем текущий модуль (группа настроек)
$module = $_GET['m'] ?? 'sys';
if (in_array($module, $sysMods)) {
    $settingsInfo = $settingsInfo[$module];
    $pageTitle = match ($module) {
        'rss' => __('RSS settings'),
        'hlu' => __('HLU settings'),
        'sitemap' => __('Sitemap settings'),
        'secure' => __('Security settings'),
        'watermark' => __('Watermark settings'),
        'autotags' => __('Auto tags settings'),
        'links' => __('Links settings'),
        default => $pageTitle,
    };
} else {
    $pathToModInfo = ROOT . '/modules/' . $module . '/info.php';
    if (file_exists($pathToModInfo)) {
        include $pathToModInfo;
        $pageTitle = $menuInfo['ankor'] . ' - ' . __('Settings');
    } else {
        $_SESSION['mess'] = "Модуль \"{$module}\" не найден!";
        $module = 'sys';
        $settingsInfo = $settingsInfo[$module];
    }
}

// Сохранение настроек
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
    $tmpSet = in_array($module, $noSub) ? $config : $config[$module];

    foreach ($settingsInfo as $fname => $params) {
        if (!empty($params['attr']['disabled'])) continue;

        // Сохранение вложенных элементов
        if (!empty($params['fields'])) {
            $fname = str_replace('sub_', '', $fname);
            if (!empty($_POST[$params['fields']][$fname])) {
                $tmpSet[$params['fields']][$fname] = $_POST[$params['fields']][$fname];
            } else {
                unset($tmpSet[$params['fields']][$fname]);
            }
            continue;
        }

        if (isset($_POST[$fname]) || isset($_FILES[$fname])) {
            if (!empty($params['onsave']['func']) && function_exists($params['onsave']['func'])) {
                $tmpSet = call_user_func($params['onsave']['func'], $tmpSet);
                continue;
            }

            $value = trim((string)$_POST[$fname]);
            if (!empty($params['onsave']['multiply'])) {
                $value = round($value * $params['onsave']['multiply']);
            }
        }

        $tmpSet[$fname] = empty($value) ? '' : ('checkbox' === $params['type'] ? (!empty($value) ? 1 : 0) : $value);
    }

    if (!in_array($module, $noSub)) {
        $config[$module] = $tmpSet;
    }

    // Сохранение настроек
    Config::write($config);
    $_SESSION['message'] = __('Saved');

    // Очистка кэша
    (new Cache)->clean(CACHE_MATCHING_ANY_TAG, ['module_' . $module]);
    redirect('/admin/settings.php?m=' . $module);
}

// Формирование формы для редактора настроек
$_config = in_array($module, $noSub) ? $config : $config[$module];
$output = '';

foreach ($settingsInfo as $fname => $params) {
    if (is_string($params)) {
        $output .= '</div><div class="head"><div class="title">' . h($params) . '</div></div><div class="items">';
        continue;
    }

    $defParams = [
        'type' => 'text',
        'title' => '',
        'description' => '',
        'value' => '',
        'help' => '',
        'options' => [],
        'attr' => [],
    ];
    $params = array_merge($defParams, $params);
    $currValue = $_config[$fname] ?? false;

    if (!empty($params['onview']['division'])) {
        $currValue = round($currValue / $params['onview']['division']);
    }

    $attr = '';
    if (!empty($params['attr'])) {
        foreach ($params['attr'] as $attrk => $attrv) {
            $attr .= ' ' . h($attrk) . '="' . h($attrv) . '"';
        }
    }

    // Генерация элементов формы
    switch ($params['type']) {
        case 'text':
            $output_ = '<input type="text" name="' . h($fname) . '" value="' . h($currValue) . '"' . $attr . ' />';
            break;
        case 'textarea':
            $output_ = '<textarea name="' . h($fname) . '"' . $attr . '>' . h($currValue) . '</textarea>';
            break;
        case 'checkbox':
            $id = uniqid();
            $state = (!empty($params['checked']) && $currValue == $params['checked']) ? ' checked' : '';
            $output_ = '<input id="' . $id . '" type="checkbox" name="' . h($fname) . '" value="' . h($params['value']) . '"' . $state . $attr . ' /><label for="' . $id . '"></label>';
            break;
        case 'select':
            $options = '';
            foreach ($params['options'] as $value => $visName) {
                $options .= '<option value="' . h($value) . '"' . ($currValue == $value ? ' selected' : '') . '>' . h($visName) . '</option>';
            }
            $output_ = '<select name="' . h($fname) . '">' . $options . '</select>';
            break;
        case 'file':
            $output_ = '<input type="file" name="' . h($fname) . '"' . $attr . ' />';
            break;
        default:
            $output_ = ''; // Обработка других типов, если необходимо
    }

    $output .= '<div class="setting-item">
        <div class="left">' . h($params['title']) . '<span class="comment">' . h($params['description']) . '</span></div>
        <div class="right">' . $output_;
    
    if (!empty($params['input_sufix_func']) && function_exists($params['input_sufix_func'])) {
        $output .= call_user_func($params['input_sufix_func'], $config);
    }
    if (!empty($params['input_sufix'])) {
        $output .= $params['input_sufix'];
    }
    
    if (!empty($params['help'])) {
        $output .= '&nbsp;<span class="comment2">' . h($params['help']) . '</span>';
    }
    $output .= '</div><div class="clear"></div></div>';
}

$pageNav = $pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';
?>

<form method="POST" action="settings.php?m=<?php echo h($module); ?>" enctype="multipart/form-data">
    <div class="list">
        <div class="title"><?php echo h($pageNav); ?></div>
        <div class="level1">
            <div class="head">
                <div class="title settings">Ключ</div>
                <div class="title-r">Значение</div>
                <div class="clear"></div>
            </div>
            <div class="items">
                <?php echo $output; ?>
                <div class="setting-item">
                    <div class="left"></div>
                    <div class="right">
                        <button type="submit" name="send">Сохранить</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php include_once 'template/footer.php'; ?>
