<?php
##################################################
##                                              ##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      1.0                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackege    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2011       ##
##################################################

// Совместимость с PHP 8.1+
declare(strict_types=1);

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$pageTitle = __('Registration rules');
$pageNav = $pageTitle;
$pageNavr = '';

// Обработка формы
$message = '';
$currentRules = '';

if (isset($_POST['send'])) {
    if (!empty($_POST['message'])) {
        $message = trim($_POST['message']);
        
        // Проверка существования записи
        $check = $FpsDB->select('users_settings', DB_COUNT, [
            'cond' => ['type' => 'reg_rules']
        ]);
        
        if ($check > 0) {
            // Обновление существующей записи
            $FpsDB->save('users_settings', 
                ['values' => $message],
                ['type' => 'reg_rules']
            );
        } else {
            // Создание новой записи
            $FpsDB->save('users_settings', 
                [
                    'values' => $message,
                    'type' => 'reg_rules'
                ]
            );
        }
        
        $_SESSION['message'] = __('Rules successfully saved');
        redirect('/admin/users_reg_rules.php');
        
    } else {
        $message = __('Fill in rules');
    }
}

// Загрузка текущих правил
$query = $FpsDB->select('users_settings', DB_FIRST, [
    'cond' => ['type' => 'reg_rules']
]);

if (!empty($query)) {
    $currentRules = $query[0]['values'] ?? '';
}

include_once ROOT . '/admin/template/header.php';

// Отображение сообщений
if (!empty($_SESSION['message'])) {
    echo '<div class="success-message">' . h($_SESSION['message']) . '</div>';
    unset($_SESSION['message']);
}

if (!empty($message) && $message === __('Fill in rules')) {
    echo '<div class="error-message">' . h($message) . '</div>';
}
?>

<div class="warning">
    <?= h(__('Fill in registration rules on the your site. Users will be duty to read their.')) ?>
</div>

<div class="list">
    <div class="title"><?= h(__('Registration rules')) ?></div>
    <table style="width:100%;" cellspacing="0" class="grid">
        <form action="" method="POST">
            <tr>
                <td>
                    <textarea name="message" style="width:99%; height:400px;" 
                              required minlength="10"><?= h($currentRules) ?></textarea>
                </td>
            </tr>
            <tr>
                <td align="center">
                    <input class="save-button" type="submit" name="send" value="<?= h(__('Save')) ?>" />
                </td>
            </tr>
        </form>
    </table>
</div>

<?php
include_once 'template/footer.php';
