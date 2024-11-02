<?php
/*---------------------------------------------\
|											   |
| Author:       Andrey Brykin (Drunya)         |
| Version:      1.1                            |
| Project:      CMS                            |
| package       CMS AtomX                      |
| subpackege    Admin Panel module             |
| copyright     Andrey Brykin 2010-2016        |
|----------------------------------------------| 
|											   |
| any partial or not partial extension         |
| CMS AtomX,without the consent of the         |
| author, is illegal                           |
|----------------------------------------------| 
| Любое распространение                        |
| CMS AtomX или ее частей,                     |
| без согласия автора, является не законным    |
\---------------------------------------------*/

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$pageTitle = __('Users');
$pageNav = $pageTitle;
$pageNavr = '<a href="javascript://" onClick="openPopup(\'Add_group\')">' . __('Add group') . '</a>&nbsp;|&nbsp;<a href="users_rules.php">' . __('Groups rules') . '</a>';

$dp = $Register['DocParser'];
$acl_groups = $Register['ACL']->get_group_info();

$errors = [];
$groups = [];

// Create tmp array with groups and cnt users in them.
if (!empty($acl_groups)) {
    $groups = array_map(function ($value) {
        return [
            'title' => $value['title'],
            'color' => $value['color'],
            'cnt_users' => $FpsDB->select('users', DB_COUNT, ['cond' => ['status' => $key]])
        ];
    }, $acl_groups);
}

// Move users into another group
if ($_GET['ac'] ?? null === 'move') {
    $from = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $to = filter_input(INPUT_POST, 'to', FILTER_VALIDATE_INT);

    if ($from && $to && isset($acl_groups[$to])) {
        $FpsDB->save('users', ['status' => $to], ['status' => $from]);
    }

    redirect('/admin/users_groups.php');

// Edit group
} elseif ($_GET['ac'] ?? null === 'edit') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $title = trim($_POST['title'] ?? '');
    $color = $_POST['color'] ?? '';

    if ($id && isset($acl_groups[$id])) {
        $allowed_colors = ['000000', 'EF1821', '368BEB', '959385', 'FBCA0B', '00AA2B', '9B703F', 'FAAA3C'];

        if (!in_array($color, $allowed_colors, true)) {
            $errors[] = 'Не допустимый цвет';
        }

        if (mb_strlen($title) < 2 || mb_strlen($title) > 100) {
            $errors[] = sprintf(__('Field %s must be between %s-%s chars'), __('Group name'), 2, 100);
        }

        if (!preg_match('#^[\w\d-_a-zа-я0-9 ]+$#ui', $title)) {
            $errors[] = sprintf(__('Wrong chars in "..."'), __('Group name'));
        }

        if (empty($errors)) {
            $acl_groups[$id] = ['title' => h($title), 'color' => h($color)];
            $ACL->save_groups($acl_groups);
        }
    } else {
        $errors[] = sprintf(__('Empty field "%s"'), __('Group name'));
    }

    redirect('/admin/users_groups.php');

// Delete group
} elseif ($_GET['ac'] ?? null === 'delete') {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if ($id && $id !== 1 && $id !== 0 && isset($groups[$id]) && $groups[$id]['cnt_users'] > 0) {
        $errors[] = __('Group not empty. First replace users');
    } elseif ($id) {
        unset($acl_groups[$id]);
        $ACL->save_groups($acl_groups);
    }

    redirect('/admin/users_groups.php');

// Add group	
} elseif ($_GET['ac'] ?? null === 'add') {
    $title = trim($_POST['title'] ?? '');
    $color = $_POST['color'] ?? '';

    if ($title && $color) {
        $allowed_colors = ['000000', 'EF1821', '368BEB', '959385', 'FBCA0B', '00AA2B', '9B703F', 'FAAA3C'];

        if (!in_array($color, $allowed_colors, true)) {
            $errors[] = 'Не допустимый цвет';
        }

        if (mb_strlen($title) < 2 || mb_strlen($title) > 100) {
            $errors[] = sprintf(__('Field %s must be between %s-%s chars'), __('Group name'), 2, 100);
        }

        if (!preg_match('#^[\w\d-_a-zа-я0-9 ]+$#ui', $title)) {
            $errors[] = sprintf(__('Wrong chars in "..."'), __('Group name'));
        }

        if (empty($errors)) {
            $acl_groups[] = ['title' => h($title), 'color' => h($color)];
            $ACL->save_groups($acl_groups);
        }
    } else {
        $errors[] = sprintf(__('Empty field "%s"'), __('Group name'));
    }

    redirect('/admin/users_groups.php');
}

include_once ROOT . '/admin/template/header.php';
?>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $error): ?>
        <span style="color:red;"><?php echo $error ?></span><br />
    <?php endforeach; ?>
    <?php unset($errors); ?>
<?php endif; ?>

<div class="popup" id="Add_group">
    <div class="top">
        <div class="title">Добавление группы</div>
        <div class="close" onClick="closePopup('Add_group')"></div>
    </div>
    <div class="items">
        <form action="users_groups.php?ac=add" method="POST">
            <div class="item">
                <div class="left">Имя Группы:</div>
                <div class="right"><input type="text" name="title" /></div>
                <div class="clear"></div>
            </div>
            <div class="item">
                <div class="left">Цвет для группы:</div>
                <div class="right">
                    <select name="color">
                        <option style="color:#000000;" value="000000">Черный</option>
                        <option style="color:#EF1821;" value="EF1821">Красный</option>
                        <option style="color:#368BEB;" value="368BEB">Синий</option>
                        <option style="color:#959385;" value="959385">Серый</option>
                        <option style="color:#FBCA0B;" value="FBCA0B">Желтый</option>
                        <option style="color:#00AA2B;" value="00AA2B">Зеленый</option>
                        <option style="color:#9B703F;" value="9B703F">Коричневый</option>
                        <option style="color:#FAAA3C;" value="FAAA3C">Оранж</option>
                    </select>
                </div>
                <div class="clear"></div>
            </div>
            <div class="item submit">
                <div class="left"></div>
                <div class="right" style="float:left;">
                    <input type="submit" value="Сохранить" name="send" class="save-button" />
                </div>
                <div class="clear"></div>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($groups)): ?>
    <?php foreach ($groups as $key => $value): ?>
        <?php if ($key !== 0): ?>
            <div class="popup" id="<?php echo h($key) ?>_Edit">
                <div class="top">
                    <div class="title">Редактирование группы</div>
                    <div class="close" onClick="closePopup('<?php echo h($key) ?>_Edit')"></div>
                </div>
                <div class="items">
                    <form action="users_groups.php?ac=edit" method="POST">
                        <div class="item">
                            <div class="left">Имя Группы:</div>
                            <div class="right">
                                <input type="hidden" name="id" value="<?php echo $key ?>" />
                                <input type="text" name="title" value="<?php echo h($value['title']) ?>" />
                            </div>
                            <div class="clear"></div>
                        </div>
                        <div class="item">
                            <div class="left">Цвет для группы:</div>
                            <div class="right">
                                <select name="color">
                                    <?php foreach ($allowed_colors as $allowed_color): ?>
                                        <option style="color: #<?php echo $allowed_color; ?>" value="<?php echo $allowed_color; ?>" <?php if ($value['color'] === $allowed_color) echo 'selected'; ?>>
                                            <?php echo ucfirst($allowed_color); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="clear"></div>
                        </div>
                        <div class="item submit">
                            <div class="left"></div>
                            <div class="right" style="float:left;">
                                <input type="submit" value="Сохранить" name="send" class="save-button" />
                            </div>
                            <div class="clear"></div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="popup" id="<?php echo h($key) ?>_Move">
                <div class="top">
                    <div class="title">Перенос пользователей</div>
                    <div class="close" onClick="closePopup('<?php echo h($key) ?>_Move')"></div>
                </div>
                <div class="items">
                    <form action="users_groups.php?ac=move" method="POST">
                        <div class="item">
                            <div class="left">Куда перенести:</div>
                            <div class="right">
                                <input type="hidden" name="id" value="<?php echo $key ?>" />
                                <select name="to">
                                    <?php foreach ($groups as $sk => $sv): ?>
                                        <?php if ($sk !== $key): ?>
                                            <option value="<?php echo $sk; ?>"><?php echo h($sv['title']); ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="clear"></div>
                        </div>
                        <div class="item submit">
                            <div class="left"></div>
                            <div class="right" style="float:left;">
                                <input type="submit" value="Сохранить" name="send" class="save-button" />
                            </div>
                            <div class="clear"></div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<div class="list">
    <div class="title"></div>
    <table cellspacing="0" class="grid" style="min-width:100%">
        <tr>
            <th width="5%">ID</th>
            <th>Группа</th>
            <th width="10%">Пользователей</th>
            <th width="15%">Действия</th>
        </tr>

        <?php if (!empty($groups)): ?>
            <?php foreach ($groups as $key => $value): ?>
                <tr>
                    <td><?php echo h($key); ?></td>
                    <td><?php echo h($value['title']); ?></td>
                    <td><?php echo h($value['cnt_users']); ?></td>
                    <td>
                        <?php if ($key !== 0 && $key !== 1 && $key !== 4): ?>
                            <a title="<?php echo __('Delete'); ?>" href="users_groups.php?ac=delete&id=<?php echo h($key); ?>" onClick="return confirm('Are you sure?')" class="delete"></a>
                        <?php endif; ?>
                        <?php if ($key !== 4): ?>
                            <a title="<?php echo __('Move'); ?>" href="javascript://" onClick="openPopup('<?php echo h($key) ?>_Move')" class="move"></a>
                        <?php endif; ?>
                        <a title="<?php echo __('Edit'); ?>" href="javascript://" onClick="openPopup('<?php echo h($key) ?>_Edit')" class="edit"></a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="4">Нет групп</td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<?php include_once ROOT . '/admin/template/footer.php'; ?>
