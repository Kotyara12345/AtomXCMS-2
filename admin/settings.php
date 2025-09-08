<?php
##################################################
##                                              ##
## @Author:       Andrey Brykin (Drunya)        ##
## @Version:      1.6.1                         ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Admin Panel module            ##
## @copyright     ©Andrey Brykin 2010-2013      ##
## @last mod.     2013/06/15                    ##
##################################################

// Совместимость с PHP 8.1+
declare(strict_types=1);

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$pageTitle = 'Системные настройки';
$Register = Register::getInstance();
$config = $Register['Config']->read('all');

// Prepare templates select list
$source = glob(ROOT . '/template/*', GLOB_ONLYDIR);
$templates = [];

if (!empty($source) && is_array($source)) {
    foreach ($source as $dir) {
        if (preg_match('#.*/(\w+)$#', $dir, $match)) {
            $templates[] = $match[1];
        }
    }
}

$templateSelect = [];
if (!empty($templates)) {
    foreach ($templates as $value) {
        $templateSelect[$value] = ucfirst($value);
    }
}

// Prepare fonts select list
$fonts = glob(ROOT . '/sys/fonts/*.ttf');
$fontSelect = [];

if (!empty($fonts)) {
    sort($fonts);
    foreach ($fonts as $value) {
        $filename = basename($value);
        $fontSelect[$filename] = $filename;
    }
}

// Prepare smiles select list
$smiles = glob(ROOT . '/sys/img/smiles/*/info.php');
$smilesSelect = [];

if (!empty($smiles)) {
    sort($smiles);
    foreach ($smiles as $value) {
        if (is_file($value)) {
            include_once $value;
            $dirname = basename(dirname($value));
            
            if (isset($smilesInfo) && isset($smilesInfo['name'])) {
                $smilesSelect[$dirname] = $smilesInfo['name'];
            }
            unset($smilesInfo); // Очищаем для следующей итерации
        }
    }
} else {
    $smilesSelect['fapos'] = 'Fapos';
}

/**
 * For show template preview
 */
function getImgPath(string $template): string
{
    $path = ROOT . '/template/' . $template . '/screenshot.png';
    if (file_exists($path)) {
        return get_url('/template/' . $template . '/screenshot.png');
    }
    return get_url('/sys/img/noimage.jpg');
}

// Properties for system settings
include_once ROOT . '/sys/settings/conf_properties.php';

// Get current module
$module = $_GET['m'] ?? 'sys';
$module = is_string($module) ? trim($module) : 'sys';

if (in_array($module, $sysMods)) {
    $settingsInfo = $settingsInfo[$module] ?? [];
    
    $pageTitle = match($module) {
        'rss' => __('RSS settings'),
        'hlu' => __('HLU settings'),
        'sitemap' => __('Sitemap settings'),
        'secure' => __('Security settings'),
        'watermark' => __('Watermark settings'),
        'autotags' => __('Auto tags settings'),
        'links' => __('Links settings'),
        default => $pageTitle
    };
} else {
    $pathToModInfo = ROOT . '/modules/' . $module . '/info.php';
    if (file_exists($pathToModInfo)) {
        include $pathToModInfo;
        $pageTitle = isset($menuInfo['ankor']) 
            ? $menuInfo['ankor'] . ' - ' . __('Settings') 
            : $pageTitle;
    } else {
        $_SESSION['mess'] = "Модуль \"{$module}\" не найден!";
        $module = 'sys';
        $settingsInfo = $settingsInfo[$module] ?? [];
    }
}

// Save settings
if (isset($_POST['send'])) {
    $tmpSet = in_array($module, $noSub) ? $config : ($config[$module] ?? []);
    
    foreach ($settingsInfo as $fname => $params) {
        if (is_string($params)) continue;
        
        if (!empty($params['attr']['disabled'])) continue;
        
        // Save nested elements
        if (!empty($params['fields'])) {
            $fieldName = str_starts_with($fname, 'sub_') ? substr($fname, 4) : $fname;
            
            if (!empty($_POST[$params['fields']][$fieldName])) {
                $tmpSet[$params['fields']][$fieldName] = $_POST[$params['fields']][$fieldName];
            } else {
                if (isset($tmpSet[$params['fields']]) && is_array($tmpSet[$params['fields']])) {
                    unset($tmpSet[$params['fields']][$fieldName]);
                }
            }
            continue;
        }
        
        if (isset($_POST[$fname]) || isset($_FILES[$fname])) {
            if (!empty($params['onsave']['func']) && function_exists($params['onsave']['func'])) {
                $tmpSet = call_user_func($params['onsave']['func'], $tmpSet) ?? $tmpSet;
                continue;
            }
            
            if (isset($_POST[$fname])) {
                $value = trim((string)$_POST[$fname]);
                
                if (!empty($params['onsave']['multiply'])) {
                    $value = (string)round((float)$value * $params['onsave']['multiply']);
                }
                
                if ($params['type'] === 'checkbox') {
                    $tmpSet[$fname] = !empty($value) ? 1 : 0;
                } else {
                    $tmpSet[$fname] = $value;
                }
            }
        }
    }
    
    if (!in_array($module, $noSub)) {
        $config[$module] = $tmpSet;
        $tmpSet = $config;
    }
    
    // Save settings
    Config::write($tmpSet);
    $_SESSION['message'] = __('Saved');
    
    // Clean cache
    $Cache = new Cache();
    $Cache->clean(CACHE_MATCHING_ANY_TAG, ['module_' . $module]);
    redirect('/admin/settings.php?m=' . $module);
}

// Build form
$_config = in_array($module, $noSub) ? $config : ($config[$module] ?? []);
$output = '';

if (!empty($settingsInfo) && is_array($settingsInfo)) {
    foreach ($settingsInfo as $fname => $params) {
        if (is_string($params)) {
            $output .= '</div><div class="head"><div class="title">' . h($params) . '</div></div><div class="items">';
            continue;
        }
        
        $defaultParams = [
            'type' => 'text',
            'title' => '',
            'description' => '',
            'value' => '',
            'help' => '',
            'options' => [],
            'attr' => [],
        ];
        
        $params = array_merge($defaultParams, $params);
        $currValue = $_config[$fname] ?? $params['value'] ?? '';
        
        if (!empty($params['onview']['division'])) {
            $currValue = round((float)$currValue / $params['onview']['division']);
        }
        
        $attr = '';
        foreach ($params['attr'] as $attrk => $attrv) {
            $attr .= ' ' . h($attrk) . '="' . h($attrv) . '"';
        }
        
        switch ($params['type']) {
            case 'text':
                $output_ = '<input type="text" name="' . h($fname) . '" value="' . h($currValue) . '"' . $attr . ' />';
                break;
                
            case 'textarea':
                $output_ = '<textarea name="' . h($fname) . '"' . $attr . '>' . h($currValue) . '</textarea>';
                break;
                
            case 'checkbox':
                $id = uniqid('cb_', true);
                $state = '';
                
                if (!empty($params['fields'])) {
                    $fieldName = str_starts_with($fname, 'sub_') ? substr($fname, 4) : $fname;
                    $subParams = $_config[$params['fields']] ?? [];
                    
                    if (in_array($fieldName, $subParams)) {
                        $state = ' checked="checked"';
                    }
                    
                    $fname = $params['fields'] . '[' . $fieldName . ']';
                } else {
                    if (!empty($params['checked']) && $currValue == $params['checked']) {
                        $state = ' checked="checked"';
                    }
                }
                
                $output_ = '<input id="' . $id . '" type="checkbox" name="' . h($fname) 
                    . '" value="' . ($params['value'] ?? '1') . '" ' . $state . $attr . ' />'
                    . '<label for="' . $id . '"></label>';
                break;
                
            case 'select':
                $options = '';
                foreach ($params['options'] as $value => $visName) {
                    $optionsAttr = [];
                    
                    if (is_array($visName) && !empty($visName['attr'])) {
                        $optionsAttr = $visName['attr'];
                        $visName = $visName['value'];
                    }
                    
                    if (!empty($params['options_attr'])) {
                        $optionsAttr = array_merge($params['options_attr'], $optionsAttr);
                    }
                    
                    $selected = ($_config[$fname] == $value) ? ' selected="selected"' : '';
                    $attrString = '';
                    
                    foreach ($optionsAttr as $k => $v) {
                        $attrString .= ' ' . $k . '="' . h($v) . '"';
                    }
                    
                    $options .= '<option value="' . h($value) . '"' . $selected . $attrString . '>'
                        . h($visName) . '</option>';
                }
                
                $output_ = '<select name="' . h($fname) . '">' . $options . '</select>';
                break;
                
            case 'file':
                $output_ = '<input type="file" name="' . h($fname) . '"' . $attr . ' />';
                break;
                
            default:
                $output_ = '';
        }
        
        $output .= '<div class="setting-item">
            <div class="left">
                ' . h($params['title']) . '
                <span class="comment">' . h($params['description']) . '</span>
            </div>
            <div class="right">' . $output_;
        
        if (!empty($params['input_sufix_func']) && function_exists($params['input_sufix_func'])) {
            $output .= call_user_func($params['input_sufix_func'], $config);
        }
        
        if (!empty($params['input_sufix'])) {
            $output .= h($params['input_sufix']);
        }
        
        if (!empty($params['help'])) {
            $output .= '&nbsp;<span class="comment2">' . h($params['help']) . '</span>';
        }
        
        $output .= '</div><div class="clear"></div></div>';
    }
}

$pageNav = $pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';
?>

<form method="POST" action="settings.php?m=<?= h($module) ?>" enctype="multipart/form-data">
<div class="list">
    <div class="title"><?= h($pageNav) ?></div>
    <div class="level1">
        <div class="head">
            <div class="title settings">Ключ</div>
            <div class="title-r">Значение</div>
            <div class="clear"></div>
        </div>
        <div class="items">
            <?= $output ?>
            <div class="setting-item">
                <div class="left"></div>
                <div class="right">
                    <input class="save-button" type="submit" name="send" value="Сохранить" />
                </div>
                <div class="clear"></div>
            </div>
        </div>
    </div>
</div>
</form>

<?php include_once 'template/footer.php'; ?>
