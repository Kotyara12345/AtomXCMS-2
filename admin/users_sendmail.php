<?php
##################################################
##                                              ##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      1.2                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackege    Admin Panel module             ##
## copyright     ©Andrey Brykin                 ##
## last mod.     2014/03/04                     ##
##################################################

// Совместимость с PHP 8.1+
declare(strict_types=1);

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$pageTitle = __('Mass mailing');
$pageNav = $pageTitle;
$pageNavr = '';

$ACL = $Register['ACL'] ?? null;
$FpsDB = $Register['DB'] ?? null;

// Проверка наличия необходимых компонентов
if (!$ACL || !$FpsDB) {
    die('Required components are not available');
}

// Получение информации о группах пользователей
$users_groups = $ACL->get_group_info() ?? [];
$count_usr = $FpsDB->select('users', DB_ALL, [
    'group' => 'status',
    'fields' => [
        'COUNT(*) as cnt',
        'status'
    ],
]) ?? [];

// Подсчет пользователей по группам
foreach ($users_groups as $id => $gr) {
    $users_groups[$id]['cnt'] = 0;
    foreach ($count_usr as $val) {
        if ($id == $val['status']) {
            $users_groups[$id]['cnt'] = (int)$val['cnt'];
            break;
        }
    }
}

// Удаление группы гостей (ID 0)
unset($users_groups[0]);

// Подсчет общего количества пользователей
$all_users_cnt = 0;
foreach ($count_usr as $val) {
    $all_users_cnt += (int)$val['cnt'];
}

// Загрузка email шаблонов
$email_templates_path = ROOT . '/sys/settings/email_templates/';
$email_templates = [];
$message_text = '';

if (is_dir($email_templates_path)) {
    $template_files = glob($email_templates_path . '*.html') ?: [];
    foreach ($template_files as $template_file) {
        $template_name = basename($template_file, '.html');
        $email_templates[] = $template_name;
    }
}

// Загрузка выбранного шаблона
if (!empty($_GET['tpl']) && in_array($_GET['tpl'], $email_templates)) {
    $template_file = $email_templates_path . $_GET['tpl'] . '.html';
    if (file_exists($template_file)) {
        $message_text = file_get_contents($template_file) ?: '';
    }
}

// Обработка отправки рассылки
if (isset($_POST['send'])) {
    $errors = [];
    
    if (empty($_POST['message'])) {
        $errors[] = __('Email text is empty');
    }
    
    if (empty($_POST['subject'])) {
        $errors[] = __('Subject is empty');
    }
    
    if (empty($_POST['groups']) || !is_array($_POST['groups'])) {
        $errors[] = __('No groups selected');
    }
    
    if (empty($errors)) {
        // Валидация ID групп
        $status_ids = [];
        foreach ($_POST['groups'] as $group) {
            $group_id = (int)$group;
            if ($group_id > 0 && array_key_exists($group_id, $users_groups)) {
                $status_ids[] = $group_id;
            }
        }
        
        $status_ids = array_unique($status_ids);
        
        if (!empty($status_ids)) {
            $placeholders = implode(',', array_fill(0, count($status_ids), '?'));
            $mail_list = $FpsDB->select('users', DB_ALL, [
                'cond' => ["status IN ($placeholders)"],
                'params' => $status_ids
            ]) ?? [];
            
            if (!empty($mail_list)) {
                $from = !empty($_POST['from']) ? trim($_POST['from']) : Config::read('admin_email');
                $subject = trim($_POST['subject']);
                $headers = "Precedence: bulk\n";

                try {
                    $mailer = new AtmMail($email_templates_path);
                    $mailer->prepare(false, $from, $headers);
                    $mailer->setBody($_POST['message']);

                    $n = 0;
                    $start_time = microtime(true);
                    
                    foreach ($mail_list as $result) {
                        // Удаление пароля из данных пользователя
                        unset($result['passw']);
                        
                        $context = [
                            'user' => $result,
                            'site_title' => Config::read('site_title'),
                            'site_url' => get_url('/'),
                            'subject' => $subject
                        ];
                        
                        if ($mailer->sendMail($result['email'], $subject, $context)) {
                            $n++;
                        }
                    }
                    
                    $execution_time = round(microtime(true) - $start_time, 4);
                    $_SESSION['message'] = __('Mails are sent') . ': ' . $n
                        . '<br>' . __('Time spent') . ': ' . $execution_time . ' ' . __('sec');
                        
                } catch (Exception $e) {
                    $_SESSION['errors'] = '<span style="color:red;">' . __('Mail sending error') . ': ' 
                        . h($e->getMessage()) . '</span>';
                }
            } else {
                $_SESSION['errors'] = '<span style="color:red;">' . __('Users not found') . '</span>';
            }
        } else {
            $_SESSION['errors'] = '<span style="color:red;">' . __('No valid groups selected') . '</span>';
        }
    } else {
        $_SESSION['errors'] = '<span style="color:red;">' . __('Needed fields are empty') . '</span>';
    }
    
    redirect('/admin/users_sendmail.php');
}

include_once ROOT . '/admin/template/header.php';

// Отображение сообщений
if (!empty($_SESSION['message'])) {
    echo '<div class="success-message">' . $_SESSION['message'] . '</div>';
    unset($_SESSION['message']);
}

if (!empty($_SESSION['errors'])) {
    echo '<div class="error-message">' . $_SESSION['errors'] . '</div>';
    unset($_SESSION['errors']);
}
?>

<div class="warning">
    <span class="greytxt"><?= h(__('Available emails')) ?>:</span> <?= h($all_users_cnt) ?><br /><br />

    <span class="greytxt"><?= h(__('Max email length')) ?>:</span> 10000 <?= h(__('Symbols')) ?><br /><br />

    <span class="greytxt"><b><?= h(__('In the mail body available below markers')) ?>:</b></span><br />
    {{ user }}<span class="greytxt"> - <?= h(__('Receiver')) ?>(<?= h(__('also available an user object variables')) ?>)</span><br />
    {{ site_title }}<span class="greytxt"> - <?= h(__('Site name')) ?></span><br />
    {{ site_url }}<span class="greytxt"> - <?= h(__('Your site URL')) ?></span><br />
    {{ subject }}<span class="greytxt"> - <?= h(__('Subject')) ?></span><br />
</div>

<form action="" method="POST">
<div class="list">
    <div class="title"><?= h(__('Mass mailing')) ?></div>
    <div class="level1">
        <div class="items">
            <div class="setting-item">
                <div class="left">
                    <?= h(__('Send to groups')) ?>
                </div>
                <div class="right">
                    <table>
                        <tr>
                        <?php foreach ($users_groups as $id => $group): ?>
                            <?php $chb_id = 'group_' . $id . '_' . bin2hex(random_bytes(4)); ?>
                            <td style="text-align:center;">
                                <input id="<?= h($chb_id) ?>" type="checkbox" name="groups[]" 
                                       value="<?= h($id) ?>" checked="checked" />
                                <label for="<?= h($chb_id) ?>">
                                    <?= h($group['title']) . ' (' . h($group['cnt']) . ')' ?>
                                </label><br />
                            </td>
                        <?php endforeach; ?>
                        </tr>
                    </table>
                </div>
                <div class="clear"></div>
            </div>
            
            <div class="setting-item">
                <div class="left">
                    <?= h(__('Email template')) ?>
                </div>
                <div class="right">
                    <select onchange="window.location.href = '<?= h(get_url('/admin/users_sendmail.php?tpl=')) ?>' + encodeURIComponent(this.value);" name="template">
                        <option value=""><?= h(__('Without template')) ?></option>
                        <?php foreach ($email_templates as $template): ?>
                            <option value="<?= h($template) ?>" <?= (!empty($_GET['tpl']) && $_GET['tpl'] === $template) ? 'selected="selected"' : '' ?>>
                                <?= h($template) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="clear"></div>
            </div>
            
            <div class="setting-item">
                <div class="left">
                    <?= h(__('Subject')) ?>
                </div>
                <div class="right">
                    <input size="120" type="text" name="subject" required 
                           value="<?= !empty($_POST['subject']) ? h($_POST['subject']) : '' ?>" />
                </div>
                <div class="clear"></div>
            </div>
            
            <div class="setting-item">
                <div class="left">
                    <?= h(__('Sender\'s email')) ?>
                </div>
                <div class="right">
                    <input size="120" type="email" name="from" 
                           value="<?= h(!empty($_POST['from']) ? $_POST['from'] : (Config::read('admin_email') ?: '')) ?>" />
                </div>
                <div class="clear"></div>
            </div>
            
            <div class="setting-item">
                <div class="left">
                    <?= h(__('Email text')) ?>
                </div>
                <div class="right">
                    <textarea name="message" style="height:200px;" required><?= h($message_text) ?></textarea>
                </div>
                <div class="clear"></div>
            </div>
            
            <div class="setting-item">
                <div class="left"></div>
                <div class="right">
                    <input class="save-button" type="submit" name="send" value="<?= h(__('Send')) ?>" />
                </div>
                <div class="clear"></div>
            </div>
        </div>
    </div>
</div>
</form>

<?php
include_once ROOT . '/admin/template/footer.php';
