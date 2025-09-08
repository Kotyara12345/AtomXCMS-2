<?php
/*---------------------------------------------\
|                                               |
| Author:       Andrey Brykin (Drunya)         |
| Version:      1.1                            |
| Project:      CMS                            |
| package       CMS AtomX                      |
| subpackege    Admin Panel module             |
| copyright     Andrey Brykin 2010-2016        |
|----------------------------------------------|
|                                               |
| any partial or not partial extension         |
| CMS AtomX,without the consent of the         |
| author, is illegal                           |
|----------------------------------------------|
| Любое распространение                        |
| CMS AtomX или ее частей,                     |
| без согласия автора, является не законным    |
\---------------------------------------------*/

// Совместимость с PHP 8.1+
declare(strict_types=1);

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$pageTitle = __('Users');
$pageNav = $pageTitle;
$pageNavr = '<a href="javascript://" onClick="openPopup(\'Add_group\')">' . __('Add group') . '</a>&nbsp;|&nbsp;<a href="users_rules.php">' . __('Groups rules') . '</a>';

$dp = $Register['DocParser'] ?? null;
$acl = $Register['ACL'] ?? null;

// Проверка наличия ACL
if (!$acl) {
    die('ACL component is not available');
}

$aclGroups = $acl->get_group_info();
$errors = [];
$groups = [];

// Создание временного массива с группами и количеством пользователей
if (!empty($aclGroups) && is_array($aclGroups)) {
    foreach ($aclGroups as $key => $value) {
        $key = (int)$key;
        $groups[$key] = [
            'title' => $value['title'] ?? '',
            'color' => $value['color'] ?? '000000',
            'cnt_users' => $FpsDB->select('users', DB_COUNT, ['cond' => ['status' => $key]])
        ];
    }
}

// Обработка действий
$action = $_GET['ac'] ?? '';

try {
    switch ($action) {
        case 'move':
            // Перенос пользователей в другую группу
            if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                $from = (int)$_POST['id'];
                if ($from !== 0 && isset($_POST['to']) && is_numeric($_POST['to'])) {
                    $to = (int)$_POST['to'];
                    if (array_key_exists($to, $aclGroups)) {
                        $FpsDB->save('users', ['status' => $to], ['status' => $from]);
                    }
                }
            }
            break;

        case 'edit':
            // Редактирование группы
            if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                $id = (int)$_POST['id'];
                if (!empty($_POST['title'])) {
                    $allowedColors = ['000000', 'EF1821', '368BEB', '959385', 'FBCA0B', '00AA2B', '9B703F', 'FAAA3C'];
                    $title = trim($_POST['title']);
                    $color = $_POST['color'] ?? '000000';

                    if (!in_array($color, $allowedColors)) {
                        $errors[] = 'Не допустимый цвет';
                    }

                    if (mb_strlen($title) > 100 || mb_strlen($title) < 2) {
                        $errors[] = sprintf(__('Field %s must be between %s-%s chars'), __('Group name'), 2, 100);
                    }

                    if (!preg_match('#^[\w\d\-_a-zа-яё0-9 ]+$#ui', $title)) {
                        $errors[] = sprintf(__('Wrong chars in "..."'), __('Group name'));
                    }

                    if (empty($errors) && array_key_exists($id, $aclGroups)) {
                        $aclGroups[$id] = [
                            'title' => h($title),
                            'color' => h($color)
                        ];
                        $acl->save_groups($aclGroups);
                    }
                } else {
                    $errors[] = sprintf(__('Empty field "%s"'), __('Group name'));
                }
            }
            break;

        case 'delete':
            // Удаление группы
            if (isset($_GET['id']) && is_numeric($_GET['id'])) {
                $id = (int)$_GET['id'];
                if ($id !== 0 && $id !== 1) {
                    if (($groups[$id]['cnt_users'] ?? 0) > 0) {
                        $errors[] = __('Group not empty. First replace users');
                    } else {
                        unset($aclGroups[$id]);
                        $acl->save_groups($aclGroups);
                    }
                }
            }
            break;

        case 'add':
            // Добавление группы
            if (!empty($_POST['title']) && !empty($_POST['color'])) {
                $allowedColors = ['000000', 'EF1821', '368BEB', '959385', 'FBCA0B', '00AA2B', '9B703F', 'FAAA3C'];
                $title = trim($_POST['title']);
                $color = $_POST['color'];

                if (!in_array($color, $allowedColors)) {
                    $errors[] = 'Не допустимый цвет';
                }

                if (mb_strlen($title) > 100 || mb_strlen($title) < 2) {
                    $errors[] = sprintf(__('Field %s must be between %s-%s chars'), __('Group name'), 2, 100);
                }

                if (!preg_match('#^[\w\d\-_a-zа-яё0-9 ]+$#ui', $title)) {
                    $errors[] = sprintf(__('Wrong chars in "..."'), __('Group name'));
                }

                if (empty($errors)) {
                    $aclGroups[] = [
                        'title' => h($title),
                        'color' => h($color)
                    ];
                    $acl->save_groups($aclGroups);
                }
            } else {
                $errors[] = sprintf(__('Empty field "%s"'), __('Group name'));
            }
            break;
    }
} catch (Exception $e) {
    $errors[] = 'Произошла ошибка: ' . $e->getMessage();
}

// Перенаправление если нет ошибок
if (empty($errors) && !empty($action)) {
    redirect('/admin/users_groups.php');
}

include_once ROOT . '/admin/template/header.php';
?>

<?php if (!empty($errors)): ?>
<div class="error-messages">
    <?php foreach ($errors as $error): ?>
    <span style="color:red;"><?= h($error) ?></span><br />
    <?php endforeach; ?>
</div>
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
                <div class="right">
                    <input type="text" name="title" required minlength="2" maxlength="100" />
                </div>
                <div class="clear"></div>
            </div>
            <div class="item">
                <div class="left">Цвет для группы:</div>
                <div class="right">
                    <select name="color" required>
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
            <!-- FOR EDIT -->
            <div class="popup" id="<?= h($key) ?>_Edit">
                <div class="top">
                    <div class="title">Редактирование группы</div>
                    <div class="close" onClick="closePopup('<?= h($key) ?>_Edit')"></div>
                </div>
                <div class="items">
                    <form action="users_groups.php?ac=edit" method="POST">
                        <input type="hidden" name="id" value="<?= h($key) ?>" />
                        <div class="item">
                            <div class="left">Имя Группы:</div>
                            <div class="right">
                                <input type="text" name="title" value="<?= h($value['title']) ?>" required minlength="2" maxlength="100" />
                            </div>
                            <div class="clear"></div>
                        </div>
                        <div class="item">
                            <div class="left">Цвет для группы:</div>
                            <div class="right">
                                <select name="color" required>
                                    <?php foreach ([
                                        '000000' => 'Черный',
                                        'EF1821' => 'Красный', 
                                        '368BEB' => 'Синий',
                                        '959385' => 'Серый',
                                        'FBCA0B' => 'Желтый',
                                        '00AA2B' => 'Зеленый',
                                        '9B703F' => 'Коричневый',
                                        'FAAA3C' => 'Оранж'
                                    ] as $colorValue => $colorName): ?>
                                    <option style="color:#<?= h($colorValue) ?>;" value="<?= h($colorValue) ?>" <?= ($value['color'] === $colorValue) ? 'selected="selected"' : '' ?>>
                                        <?= h($colorName) ?>
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

            <!-- FOR MOVE -->
            <div class="popup" id="<?= h($key) ?>_Move">
                <div class="top">
                    <div class="title">Перенос пользователей</div>
                    <div class="close" onClick="closePopup('<?= h($key) ?>_Move')"></div>
                </div>
                <div class="items">
                    <form action="users_groups.php?ac=move" method="POST">
                        <input type="hidden" name="id" value="<?= h($key) ?>" />
                        <div class="item">
                            <div class="left">Куда перенести:</div>
                            <div class="right">
                                <select name="to" required>
                                    <?php foreach ($groups as $targetKey => $targetValue): ?>
                                        <?php if ($targetKey !== $key): ?>
                                            <option value="<?= h($targetKey) ?>"><?= h($targetValue['title']) ?></option>
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
                    <td><?= h($key) ?></td>
                    <td><?= h($value['title']) ?></td>
                    <td><?= h($value['cnt_users']) ?></td>
                    <td>
                        <?php if ($key !== 0 && $key !== 1 && $key !== 4): ?>
                            <a title="<?= h(__('Delete')) ?>" href="users_groups.php?ac=delete&id=<?= h($key) ?>" 
                               onClick="return confirm('<?= h(__('Are you sure?')) ?>')" class="delete"></a>
                        <?php endif; ?>
                        <?php if ($key !== 4): ?>
                            <a title="<?= h(__('Move')) ?>" href="javascript://" 
                               onClick="openPopup('<?= h($key) ?>_Move')" class="move"></a>
                        <?php endif; ?>
                        <a title="<?= h(__('Edit')) ?>" href="javascript://" 
                           onClick="openPopup('<?= h($key) ?>_Edit')" class="edit"></a>
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

<?php 
include_once ROOT . '/admin/template/footer.php';
