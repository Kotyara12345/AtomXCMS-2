<?php
/*-----------------------------------------------\
|  Author:       Andrey Brykin (Drunya)          |
|  Version:      1.5.2                           |
|  Project:      CMS                             |
|  package       CMS AtomX                       |
|  subpackage    Admin Panel module              |
|  copyright     ©Andrey Brykin 2010-2012        |
|  Last mod.     2012/07/08                      |
\-----------------------------------------------*/

/*-----------------------------------------------\
|  any partial or not partial extension          |
|  CMS AtomX, without the consent of the        |
|  author, is illegal                            |
|------------------------------------------------|
|  Любое распространение                         |
|  CMS AtomX или ее частей,                      |
|  без согласия автора, является незаконным      |
\-----------------------------------------------*/

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$pageTitle = __('Plugins');

$action = $_GET['ac'] ?? 'index';
$actions = ['index', 'off', 'on', 'edit'];

if (!in_array($action, $actions)) {
    $action = 'index';
}

switch ($action) {
    case 'index':
        $content = index($pageTitle);
        break;
    case 'edit':
        $content = editPlugin($pageTitle);
        break;
    case 'off':
        $content = offPlugin();
        break;
    case 'on':
        $content = onPlugin();
        break;
    default:
        $content = index($pageTitle);
}

$pageNav = $pageTitle;
$pageNavr = '<a href="plugins.php">' . __('Plugins list') . '</a>';

include_once ROOT . '/admin/template/header.php';
echo $content;
include_once ROOT . '/admin/template/footer.php';

function index(string &$page_title): string {
    global $FpsDB;
    $content = '';

    $plugs = glob(ROOT . '/sys/plugins/*', GLOB_ONLYDIR);
    
    if (empty($plugs)) {
        return '<div class="list"><div class="head">
                <div class="title">' . __('Not found available plugins') . '</div></div></div>';
    }

    $content .= "<div class=\"list\">
        <div class='title'>" . $page_title . "</div>
        <div class='level1'>
            <div class='head'>
                <div class='title'>" . __('Title') . "</div>
                <div class='title' style='width:220%;'>" . __('Path') . "</div>
                <div class='title'>" . __('Description') . "</div>
                <div class='title' style='width:30%;'>HOOK</div>
                <div class='title'>" . __('Directory') . "</div>
                <div class='title'>" . __('Action') .  "</div>
            </div>
            <div class='items'>";

    foreach ($plugs as $result) {
        $dir = basename($result);
        
        $params = file_exists($result . '/config.dat') ? json_decode(file_get_contents($result . '/config.dat'), true) : [];
        
        $name = h($params['title'] ?? 'Unknown');
        $descr = h($params['description'] ?? '');
        
        $hook = str_starts_with($dir, 'before_view') ? 'before_view' : 'Unknown';
        
        $actionLink = !empty($params['active']) 
            ? "<a class=\"off\" href='plugins.php?ac=off&dir={$dir}'></a>" 
            : "<a class=\"on\" href='plugins.php?ac=on&dir={$dir}'></a>";

        $content .= "
            <div class='level2'>
                <div class='title2' style='width:10%;'>
                    <a href='plugins.php?ac=edit&dir={$dir}'>{$name}</a>
                </div>    
                <div class='title2' style='width:30%;'>{$result}</div>
                <div class='title2' style='width:15%;'>{$descr}</div>
                <div class='title2'><span class='unknown' style=\"color:#\">{$hook}</span></div>
                <div class='title2' style='width:12%;'>{$dir}</div>
                <div class='title2-buttons'>
                    <a class=\"edit\" href='plugins.php?ac=edit&dir={$dir}'></a>&nbsp;{$actionLink}
                </div>
            </div>";
    }

    $content .= '</div></div></div>';
    
    return $content;
}

function editPlugin(string &$page_title): string {
    if (empty($_GET['dir'])) {
        redirect('/admin/plugins.php');
    }
    
    $dir = $_GET['dir'];
    if (!preg_match('#^[\w\d_-]+$#i', $dir)) {
        redirect('/admin/plugins.php');
    }
    
    $settigs_file_path = ROOT . '/sys/plugins/' . $dir . '/settings.php';
    if (!file_exists($settigs_file_path)) {
        return '<div class="list"><div class="title"></div><div class="level1"><div class="head">
                <div class="title">No settings for this plugin</div></div></div></div>';
    }
    
    include_once $settigs_file_path;
    $page_title = 'Настройка Плагина';
    return $output ?? '';
}

function onPlugin(): void {
    if (empty($_GET['dir'])) redirect('/');
    
    $dir = $_GET['dir'];
    $conf_path = ROOT . '/sys/plugins/' . $dir . '/config.dat';
    $history = file_exists($conf_path) ? json_decode(file_get_contents($conf_path), true) : [];
    
    $history['active'] = 1;
    file_put_contents($conf_path, json_encode($history));
    redirect('../admin/plugins.php');
}

function offPlugin(): void {
    if (empty($_GET['dir'])) redirect('/');
    
    $dir = $_GET['dir'];
    $conf_path = ROOT . '/sys/plugins/' . $dir . '/config.dat';
    $history = file_exists($conf_path) ? json_decode(file_get_contents($conf_path), true) : [];
    
    $history['active'] = 0;
    file_put_contents($conf_path, json_encode($history));
    redirect('../admin/plugins.php');
}
