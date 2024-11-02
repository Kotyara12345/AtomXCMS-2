<?php
/*-----------------------------------------------\
| 												 |
|  Author:       Andrey Brykin (Drunya)          |
|  Version:      1.2.3                           |
|  Project:      CMS                             |
|  package       CMS AtomX                       |
|  subpackege    Admin Panel module              |
|  copyright     ©Andrey Brykin 2010-2011        |
\-----------------------------------------------*/

/*-----------------------------------------------\
| 												 |
|  any partial or not partial extension          |
|  CMS AtomX, without the consent of the         |
|  author, is illegal                            |
|------------------------------------------------|
|  Любое распространение                         |
|  CMS AtomX или ее частей,                      |
|  без согласия автора, является незаконным      |
\-----------------------------------------------*/

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$pageTitle = __('Users');

$action = $_GET['ac'] ?? 'index';
$validActions = ['index', 'ank', 'del', 'save'];

if (!in_array($action, $validActions, true)) {
    $action = 'index';
}

$content = match ($action) {
    'index' => index($pageTitle),
    'ank' => editAnk($pageTitle),
    'save' => saveAnk(),
    default => index($pageTitle),
};

$pageNav = $pageTitle;
$pageNavr = '<a href="users_list.php">' . __('Users list') . '</a>';

$dp = $Register['DocParser'];

include_once ROOT . '/admin/template/header.php';
echo $content;
include_once ROOT . '/admin/template/footer.php';

function index(string &$pageTitle): string {
    $Register = Register::getInstance();
    $FpsDB = $Register['DB'];
    $ACL = $Register['ACL'];
    $pageTitle = __('Users list');
    $order = '';
    $limit = 30;

    $permisionCond = ['name', 'email', 'status', 'posts', 'themes', 'puttime'];
    $cond = $_GET['cond'] ?? 'puttime';
    if (!in_array($cond, $permisionCond, true)) {
        $cond = 'puttime';
    }

    $orderDirection = ($_GET['value'] ?? '') === '1' ? ' DESC' : ' ASC';
    $order = "ORDER BY $cond$orderDirection";

    $strSearch = !empty($_POST['search']) ? "WHERE `name` LIKE '%{$_POST['search']}%'" : '';
    $countResult = $FpsDB->query("SELECT COUNT(*) as cnt FROM `" . $FpsDB->getFullTableName('users') . "` $strSearch $order");
    $total = $countResult[0]['cnt'] ?? 0;
    list($pages, $page) = pagination($total, $limit, '/admin/users_list.php?ac=index');
    $start = ($page - 1) * $limit;

    $sql = "SELECT * FROM `" . $FpsDB->getFullTableName('users') . "` $strSearch $order LIMIT $start, $limit";
    $query = $FpsDB->query($sql);

    $links = [
        'nick' => generateLink($cond, 'name', __('Name')),
        'email' => generateLink($cond, 'email', __('Email')),
        'puttime' => generateLink($cond, 'puttime', __('Registration date')),
        'status' => generateLink($cond, 'status', __('Status')),
        'themes' => generateLink($cond, 'themes', __('Topics')),
        'posts' => generateLink($cond, 'posts', __('Posts')),
    ];

    $pages = '<div class="pagination">' . $pages . '</div>';
    $content = "<div class=\"list\">
        <div class=\"title\">" . __('Users list') . "</div>
        <table cellspacing=\"0\" class=\"grid\"><tr>
            <th width=\"20%\">{$links['nick']}</th>
            <th width=\"25%\">{$links['email']}</th>
            <th width=\"20%\">{$links['puttime']}</th>
            <th width=\"15%\">{$links['status']}</th>
            <th width=\"9%\">{$links['themes']}</th>
            <th width=\"9%\">{$links['posts']}</th>
            <th width=\"20px\" colspan=\"2\">" . __('Action') . "</th></tr>";

    foreach ($query as $result) {
        $statusInfo = $ACL->get_user_group($result['status']);
        $statusText = $statusInfo['title'];
        $color = $statusInfo['color'] ?? '';
        $content .= "<tr>
            <td><a href='users_list.php?ac=ank&id={$result['id']}'>{$result['name']}</a></td>
            <td>{$result['email']}</td>
            <td>{$result['puttime']}</td>
            <td><span style=\"color:#{$color}\">{$statusText}</span></td>
            <td>{$result['themes']}</td>
            <td>{$result['posts']}</td>
            <td colspan=\"2\"><a class=\"edit\" href='users_list.php?ac=ank&id={$result['id']}'></a></td>
        </tr>";
    }
    $content .= '</table></div>';

    $content .= '<form method="POST" action="users_list.php?ac=index"><table class="metatb"><tr><td>
            <input type="text" name="search" />
            <input type="submit" name="send" class="save-button" value="' . __('Search') . '" />
            </td></tr></table></form>';

    $content .= $pages;

    return $content;
}

function generateLink(string $currentCond, string $cond, string $label): string {
    $value = ($currentCond === $cond) ? '0' : '1';
    return '<a href="?cond=' . $cond . '&value=' . $value . '">' . $label . '</a>';
}

// ...

function editAnk(string &$pageTitle): string {
    $Register = Register::getInstance();
    $FpsDB = $Register['DB'];
    $ACL = $Register['ACL'];

    if (!is_numeric($_GET['id'])) redirect('/admin/users_list.php');

    $pageTitle = __('Edit user');
    $content = '';
    $statuses = $ACL->get_group_info();
    $query = $FpsDB->select('users', DB_FIRST, ['cond' => ['id' => $_GET['id']]]);

    if (empty($query)) return '<span style="color:red;">' . __('Cannot find user') . '</span>';

    foreach ($query[0] as $key => $value) {
        $$key = $_SESSION['edit_ank'][$key] ?? $value;
    }

    $telephone = (int)($telephone ?? 0);
    $mpol = ($query[0]['pol'] ?? '') === 'm' ? 'checked' : '';
    $fpol = ($query[0]['pol'] ?? '') === 'f' ? 'checked' : '';

    if (!empty($_SESSION['edit_ank']['errors'])) {
        $content .= '<script type="text/javascript">showHelpWin(\'' . $_SESSION['edit_ank']['errors'] . '\', \'Ошибки\');</script>';
    }
    unset($_SESSION['edit_ank']);
    $_SESSION['adm_form_key'] = md5(rand() . rand());

    $content .= '<form action="users_list.php?ac=save&id=' . $_GET['id'] . '" method="POST"><div class="list">
        <div class="title">' . __('Edit user') . ' (' . h($name) . ')</div>
        <div class="level1">
            <div class="items">';

    $fields = [
        'login' => __('Name'),
        'state' => __('Rank'),
        'email' => __('Email'),
        'url' => __('Site'),
        'icq' => 'ICQ',
        'jabber' => 'Jabber',
        'city' => __('City'),
        'telephone' => __('Telephone'),
        'about' => __('Interests'),
        'signature' => __('Signature')
    ];

    foreach ($fields as $key => $label) {
        $content .= "<div class=\"setting-item\">
            <div class=\"left\">$label</div>
            <div class=\"right\"><input type=\"text\" name=\"$key\" value=\"" . h($$key) . "\" /></div>
            <div class=\"clear\"></div>
        </div>";
    }

    $content .= '<div class="setting-item">
        <div class="left">' . __('Gender') . '</div>
        <div class="right">
            <input type="radio" name="pol" value="m" ' . $mpol . ' id="polm" /><label for="polm">М</label>
            <input type="radio" name="pol" value="f" ' . $fpol . ' id="polj" /><label for="polj">Ж</label>
        </div>
        <div class="clear"></div>
    </div>';

    $content .= '<div class="setting-item">
        <div class="left">' . __('Status') . '</div>
        <div class="right"><select name="status">';
    
    foreach ($statuses as $status) {
        $selected = ($status['id'] === $query[0]['status']) ? 'selected' : '';
        $content .= "<option value=\"{$status['id']}\" $selected>{$status['title']}</option>";
    }
    
    $content .= '</select></div>
        <div class="clear"></div>
    </div>';

    $content .= '<input type="hidden" name="form_key" value="' . $_SESSION['adm_form_key'] . '" />
        <input type="submit" name="send" class="save-button" value="' . __('Save') . '" />
        </div></div></form>';

    return $content;
}

function saveAnk(): string {
    $Register = Register::getInstance();
    $FpsDB = $Register['DB'];

    if ($_POST['form_key'] !== $_SESSION['adm_form_key']) {
        redirect('/admin/users_list.php?ac=ank&id=' . $_GET['id']);
    }

    $id = (int)$_GET['id'];
    $fieldsToUpdate = ['name', 'email', 'state', 'url', 'icq', 'jabber', 'city', 'telephone', 'about', 'signature', 'pol', 'status'];
    $updateData = [];

    foreach ($fieldsToUpdate as $field) {
        if (isset($_POST[$field])) {
            $updateData[$field] = $_POST[$field];
        }
    }

    if (!empty($updateData)) {
        $FpsDB->update('users', $updateData, ['id' => $id]);
        return '<span style="color:green;">' . __('User updated') . '</span>';
    }

    return '<span style="color:red;">' . __('No changes detected') . '</span>';
}

// ...

function redirect(string $url): void {
    header("Location: $url");
    exit;
}
?>
