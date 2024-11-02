<?php

declare(strict_types=1);

##################################################
##                                              ##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      0.8                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackage    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2012       ##
##################################################

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$pageTitle = __('Menu editor');
$pageNav = $pageTitle;
$pageNavR = '';
$popups = '';

$menu_conf_file = ROOT . '/sys/settings/menu.dat';

if ($_GET['ac'] ?? '' === 'add') {
    $data = [
        'title' => trim($_POST['ankor'] ?? ''),
        'url' => trim($_POST['url'] ?? ''),
        'prefix' => trim($_POST['prefix'] ?? ''),
        'sufix' => trim($_POST['sufix'] ?? ''),
        'newwin' => trim($_POST['newwin'] ?? ''),
    ];

    if (!empty($data['title']) && !empty($data['url'])) {
        $menu = file_exists($menu_conf_file) ? unserialize(file_get_contents($menu_conf_file)) : [];
        $data['id'] = getMenuPointId($menu) + 1;
        $menu[] = $data;
        file_put_contents($menu_conf_file, serialize($menu));
        redirect('/admin/menu_editor.php');
    }
} elseif ($_GET['ac'] ?? '' === 'edit' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id < 1) redirect('/admin/menu_editor.php');

    $data = [
        'id' => $id,
        'title' => trim($_POST['ankor'] ?? ''),
        'url' => trim($_POST['url'] ?? ''),
        'prefix' => trim($_POST['prefix'] ?? ''),
        'sufix' => trim($_POST['sufix'] ?? ''),
        'newwin' => trim($_POST['newwin'] ?? ''),
    ];

    if (!empty($data['title']) && !empty($data['url']) && !empty($data['id'])) {
        $menu = unserialize(file_get_contents($menu_conf_file));
        $menu = saveMenu($id, $data, $menu);
        file_put_contents($menu_conf_file, serialize($menu));
        redirect('/admin/menu_editor.php');
    }
}

function saveMenu(int $id, array $data, array $menu): array
{
    foreach ($menu as $key => &$value) {
        if (($value['id'] ?? null) === $id) {
            $menu[$key] = $data;
            if (isset($value['sub'])) $menu[$key]['sub'] = $value['sub'];
            break;
        }
        
        if (!empty($value['sub']) && is_array($value['sub'])) {
            $menu[$key]['sub'] = saveMenu($id, $data, $value['sub']);
        }
    }
    return $menu;
}

function getMenuPointId(array $menu): int
{
    $n = 0;
    foreach ($menu as $v) {
        if (($v['id'] ?? 0) > $n) $n = $v['id'];
        if (!empty($v['sub']) && is_array($v['sub'])) {
            $n = max($n, getMenuPointId($v['sub']));
        }
    }
    return $n;
}

function parseNode(array $data): array
{
    $output = [];
    foreach ($data as $value) {
        if (empty($value['url']) || empty($value['title']) || empty($value['id'])) continue;

        $node = [
            'id' => trim($value['id']),
            'url' => trim($value['url']),
            'title' => trim($value['title']),
            'prefix' => trim($value['prefix'] ?? ''),
            'sufix' => trim($value['sufix'] ?? ''),
            'newwin' => trim($value['newwin'] ?? ''),
        ];

        if (!empty($value['sub']) && is_array($value['sub'])) {
            $node['sub'] = parseNode($value['sub']);
        }
        
        $output[] = $node;
    }
    return $output;
}

function buildMenu(array $node): string
{
    $out = '';
    global $popups;
    
    foreach ($node as $value) {
        if (empty($value['url']) || empty($value['title']) || empty($value['id'])) continue;

        $value['prefix'] = trim($value['prefix'] ?? '');
        $value['sufix'] = trim($value['sufix'] ?? '');
        $value['newwin'] = !empty($value['newwin']) ? 'selected="selected"' : '';

        $out .= "<li>\n<div class=\"item\">" . htmlspecialchars($value['title']) . "
            <input type=\"hidden\" name=\"id\" value=\"{$value['id']}\" />
            <input type=\"hidden\" name=\"url\" value=\"" . htmlspecialchars($value['url']) . "\" />
            <input type=\"hidden\" name=\"ankor\" value=\"" . htmlspecialchars($value['title']) . "\" />
            <input type=\"hidden\" name=\"prefix\" value=\"" . htmlspecialchars($value['prefix']) . "\" />
            <input type=\"hidden\" name=\"sufix\" value=\"" . htmlspecialchars($value['sufix']) . "\" />
            <input type=\"hidden\" name=\"newwin\" value=\"" . htmlspecialchars($value['newwin']) . "\" />
            <div style=\"float:right;\">
                <a class=\"delete\" title=\"Delete\" onClick=\"if(confirm('Are you sure?'))deletePoint(this);\"></a>
                <a class=\"edit\" title=\"Edit\" onClick=\"openPopup('edit{$value['id']}');\"></a>
                <div style=\"clear:both;\"></div>
            </div>
        </div>\n";

        $popups .= "<div id=\"edit{$value['id']}\" class=\"popup\">
            <div class=\"top\">
                <div class=\"title\">" . __('Add an item') . "</div>
                <div onClick=\"closePopup('edit{$value['id']}')\" class=\"close\"></div>
            </div>
            <form action=\"menu_editor.php?ac=edit&id={$value['id']}\" method=\"POST\">
            <!-- form fields here -->
            </form>
        </div>";

        if (!empty($value['sub']) && is_array($value['sub'])) {
            $out .= '<ul>' . buildMenu($value['sub']) . '</ul>';
        }

        $out .= '</li>';
    }
    return $out;
}

if (isset($_POST['data']) && is_array($_POST['data'])) {
    $array_menu = parseNode($_POST['data']);
    file_put_contents($menu_conf_file, serialize($array_menu));
    exit();
}

$menu = file_exists($menu_conf_file) ? unserialize(file_get_contents($menu_conf_file)) : [];

include_once ROOT . '/admin/template/header.php';
?>
<!-- HTML for the menu editor goes here -->
<?php include_once 'template/footer.php'; ?>
