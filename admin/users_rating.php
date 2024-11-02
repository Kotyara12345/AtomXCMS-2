<?php
##################################################
##                                                ##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      1.2                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackage    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2011       ##
##################################################

##################################################
##                                                ##
## any partial or not partial extension         ##
## CMS AtomX, without the consent of the       ##
## author, is illegal                           ##
##################################################
## Любое распространение                        ##
## CMS AtomX или ее частей,                     ##
## без согласия автора, является незаконным     ##
##################################################

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$errors = [];
$result = [];

// Обработка формы
if (isset($_POST['send'])) {
    // Валидация данных
    for ($i = 0; $i <= 10; $i++) {
        if (empty($_POST['rat' . $i]) || strlen($_POST['rat' . $i]) < 1) {
            $errors['rat' . $i] = 'Слишком короткое значение';
        }
        if ($i > 0) {
            if (empty($_POST['cond' . $i]) || strlen($_POST['cond' . $i]) < 1) {
                $errors['cond' . $i] = 'Слишком короткое значение';
            } elseif (!is_numeric($_POST['cond' . $i])) {
                $errors['cond' . $i] = 'Значение должно быть числом';
            }
        }
    }

    // Если нет ошибок, сохраняем данные
    if (empty($errors)) {
        $TempSet = [];
        for ($i = 0; $i <= 10; $i++) {
            $TempSet['rat' . $i] = $_POST['rat' . $i];
            if ($i > 0) {
                $TempSet['cond' . $i] = $_POST['cond' . $i];
            }
        }

        $prepair = serialize($TempSet);
        $what = $FpsDB->select('users_settings', DB_COUNT, ['cond' => ['type' => 'rating']]);
        
        if (count($what) < 1) {
            $FpsDB->save('users_settings', [
                'type' => 'rating',
                'values' => $prepair,
            ]);
        } else {
            $FpsDB->save('users_settings', [
                'values' => $prepair,
            ], ['type' => 'rating']);
        }

        redirect("/admin/users_rating.php");
    }
}

// Получение существующих настроек
$query = $FpsDB->select('users_settings', DB_FIRST, ['cond' => ['type' => 'rating']]);
if (count($query) > 0) {
    $result = unserialize($query[0]['values']);
}

$pageTitle = __('Users ranks');
$pageNav = $pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';
?>

<form method="POST" action="users_rating.php">
    <div class="list">
        <div class="title">Ранги пользователей</div>
        <div class="level1">
            <div class="head">
                <div class="title settings">Номер</div>
                <div class="title-r">Сообщений / Звание</div>
                <div class="clear"></div>
            </div>
            <div class="items">
                <div class="setting-item">
                    <div class="left">
                        Без ранга
                        <span class="comment">(какое звание будет у нового пользователя)</span>
                    </div>
                    <div class="right">
                        <input type="text" name="rat0" value="<?php echo htmlspecialchars($result['rat0'] ?? ''); ?>">
                        <?php if (!empty($errors['rat0'])): ?>
                            <br /><span class="error"><?php echo $errors['rat0']; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="clear"></div>
                </div>

                <?php for ($i = 1; $i < 11; $i++): ?>
                <div class="setting-item">
                    <div class="left">
                        Ранг № <?php echo $i; ?>
                    </div>
                    <div class="right">
                        <input type="text" name="cond<?php echo $i; ?>" value="<?php echo htmlspecialchars($result['cond' . $i] ?? (10 * $i)); ?>">
                        <span class="help">Кол-во сообщений</span><br /><br />
                        <?php if (!empty($errors['cond' . $i])): ?>
                            <br /><span class="error"><?php echo $errors['cond' . $i]; ?></span>
                        <?php endif; ?>
                        <input type="text" name="rat<?php echo $i; ?>" value="<?php echo htmlspecialchars($result['rat' . $i] ?? ''); ?>">
                        <span class="help">Звание</span><br />
                        <?php if (!empty($errors['rat' . $i])): ?>
                            <br /><span class="error"><?php echo $errors['rat' . $i]; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="clear"></div>
                </div>
                <?php endfor; ?>

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

<?php
include_once ROOT . '/admin/template/footer.php';
?>
