<?php
##################################################
##                                              ##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      1.2                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackege    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2011       ##
##################################################

// Совместимость с PHP 8.1+
declare(strict_types=1);

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Clean snippets Cache
$cache = new Cache();
$cache->prefix = 'block';
$cache->cacheDir = ROOT . '/sys/cache/blocks/';
$cache->clean();

$pageTitle = $pageNav = __('Snippets');
$pageNavr = $pageTitle;

$action = $_GET['a'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($action === 'ed') {
    $pageNavr = __('Snippets') . ' &raquo; [' . strtolower(__('Editing')) . '] &raquo; <a href="snippets.php">' . strtolower(__('Adding')) . '</a>';
} else {
    $pageNavr = __('Snippets') . ' &raquo; <a href="snippets.php?a=ed">' . strtolower(__('Editing')) . '</a> &raquo; [' . strtolower(__('Adding')) . ']';
}

if ($action === 'ed') {
    // Обработка сохранения сниппета
    if (isset($_POST['send']) && isset($_POST['text_edit'])) {
        $sql = $Register['DB']->save('snippets', [
            'body' => $_POST['text_edit'],
            'id' => $id,
        ]);
        $_SESSION['message'] = __('Snippet successfuly created');
        redirect('/admin/snippets.php?a=ed&id=' . $id);
    }

    // Обработка удаления сниппета
    if (isset($_GET['delete'])) {
        $sql = $FpsDB->query("DELETE FROM `" . $FpsDB->getFullTableName('snippets') . "` WHERE id='" . $id . "'");
        $_SESSION['message'] = __('Snippet successfuly deleted');
        redirect('/admin/snippets.php?a=ed');
    }

    // Загрузка данных сниппета
    $content = __('Select snippet');
    $name = '';
    
    if ($id > 0) {
        $sql = $FpsDB->select('snippets', DB_FIRST, ['cond' => ['id' => $id]]);
        if (!empty($sql)) {
            $content = h($sql[0]['body'] ?? '');
            $name = h($sql[0]['name'] ?? '');
        }
    }

    include_once ROOT . '/admin/template/header.php';
?>

<div class="warning">
    Снипеты позволяют создать блоки php кода и подключать их в любом месте сайта, прямо в шаблонах.<br />
    Вызвать сниппет из шаблона можно так <strong>{[ИМЯ СНИППЕТА]}</strong><br />
    После того, как Вы добавите метку в шаблон, она будет заменена на результат выполнения кода сниппета.<br />
    Тут приведен список, уже созданных, сниппетов. Вы можете их просматривать и редактировать.<br />
    Для то, что бы создавать и редактировать сниппеты, желательно, обладать, хотя бы, базовыми знаниями PHP
</div>

<div class="white">
    <div class="pages-tree">
        <div class="title"><?= h(__('Snippets')) ?></div>
        <div class="wrapper" style="height:390px;">
            <div class="tree-wrapper">
                <div id="pageTree">
                <?php
                $sql = $FpsDB->select('snippets', DB_ALL);
                foreach ($sql as $record) {
                    echo '<div id="mItem' . h($record['id']) . '" class="tba"><a href="snippets.php?a=ed&id='
                         . h($record['id']) . '">'
                         . h($record['name']) . '</a></div>';
                }
                ?>
                </div>
            </div>
        </div>
    </div>

    <div style="display:none;" class="ajax-wrapper" id="ajax-loader"><div class="loader"></div></div>
    <form action="<?= h($_SERVER['REQUEST_URI']) ?>" method="post">
        <div class="list pages-form">
            <div class="title"><?= h(__('Snippet editing')) ?></div>
            <div class="level1">
                <div class="items">
                    <div class="setting-item">
                        <div class="left">
                            <?= h(__('Title')) ?>
                        </div>
                        <div class="right">
                            <input disabled="disabled" name="my_title" type="text" value="<?= h($name) ?>">
                            <?php if ($id > 0) : ?> 
                                <a class="delete" href="snippets.php?a=ed&id=<?= h($id) ?>&delete=y" 
                                   onclick="return confirm('<?= h(__('Are you sure?')) ?>')"></a>
                            <?php endif; ?>
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="center">
                            <textarea name="text_edit" style="width:98%; height:280px;"><?= h($content) ?></textarea>
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left"></div>
                        <div class="right">
                            <input class="save-button" type="submit" name="send" value="<?= h(__('Save')) ?>" />
                        </div>
                        <div class="clear"></div>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <div class="clear"></div>
</div>

<?php
} else {
    // Режим добавления нового сниппета
    $errors = [];
    $my_title = $_POST['my_title'] ?? '';
    $my_text = $_POST['my_text'] ?? '';

    if (isset($_POST['send'])) {
        if (empty($my_title) || mb_strlen($my_text) < 3) {
            $errors[] = 'Заполните все поля';
        }
        
        if (empty($errors)) {
            $countchank = $FpsDB->select('snippets', DB_COUNT, ['cond' => ['name' => $my_title]]);
            if ($countchank == 0) {
                $last_id = $FpsDB->save('snippets', [
                    'name' => $my_title,
                    'body' => $my_text,
                ]);
                
                $_SESSION['message'] = __('Snippet successfuly created');
                redirect('/admin/snippets.php?a=ed&id=' . $last_id);
            } else {
                $errors[] = __('Same snippet is already exists');
            }
        }
        
        if (!empty($errors)) {
            $_SESSION['errors'] = implode('<br>', $errors);
        }
    }

    include_once ROOT . '/admin/template/header.php';
?>

<div class="warning">
    Снипеты позволяют создать блоки php кода и подключать их в любом месте сайта, прямо в шаблонах.<br />
    Вызвать сниппет из шаблона можно так <strong>{[ИМЯ СНИППЕТА]}</strong><br />
    После того, как Вы добавите метку в шаблон, она будет заменена на результат выполнения кода сниппета.<br />
    На странице редактирования приведен список, уже созданных, сниппетов. Вы можете их просматривать и редактировать.<br />
    Для то, что бы создавать и редактировать сниппеты, желательно, обладать, хотя бы, базовыми знаниями PHP
</div>

<div class="white">
    <div class="pages-tree">
        <div class="title"><?= h(__('Snippets')) ?></div>
        <div class="wrapper" style="height:390px;">
            <div class="tree-wrapper">
                <div id="pageTree">
                <?php
                $sql = $FpsDB->select('snippets', DB_ALL);
                foreach ($sql as $record) {
                    echo '<div id="mItem' . h($record['id']) . '" class="tba"><a href="snippets.php?a=ed&id='
                         . h($record['id']) . '">'
                         . h($record['name']) . '</a></div>';
                }
                ?>
                </div>
            </div>
        </div>
    </div>

    <div style="display:none;" class="ajax-wrapper" id="ajax-loader"><div class="loader"></div></div>
    <form action="snippets.php" method="post">
        <div class="list pages-form">
            <div class="title"><?= h(__('Snippet editing')) ?></div>
            <div class="level1">
                <div class="items">
                    <?php if (!empty($_SESSION['errors'])): ?>
                    <div class="setting-item">
                        <div class="error"><?= h($_SESSION['errors']) ?></div>
                        <?php unset($_SESSION['errors']); ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="setting-item">
                        <div class="left">
                            <?= h(__('Title')) ?>
                        </div>
                        <div class="right">
                            <input name="my_title" type="text" value="<?= h($my_title) ?>" />
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="center">
                            <textarea name="my_text" style="width:98%; height:280px;"><?= h($my_text) ?></textarea>
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left"></div>
                        <div class="right">
                            <input class="save-button" type="submit" name="send" value="<?= h(__('Save')) ?>" />
                        </div>
                        <div class="clear"></div>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <div class="clear"></div>
</div>

<?php } ?>

<?php
include_once 'template/footer.php';
?>
