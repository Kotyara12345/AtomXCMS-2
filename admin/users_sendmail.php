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

##################################################
##                                              ##
## Any partial or not partial extension         ##
## CMS AtomX, without the consent of the       ##
## author, is illegal                           ##
##################################################
## Любое распространение                        ##
## CMS AtomX или ее частей,                     ##
## без согласия автора, является не законным    ##
##################################################

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$pageTitle = __('Mass mailing');
$pageNav = $pageTitle;

$ACL = $Register['ACL'];
$FpsDB = $Register['DB'];
$users_groups = $ACL->get_group_info();

$count_usr = $FpsDB->select('users', DB_ALL, [
    'group' => 'status',
    'fields' => [
        'COUNT(*) as cnt',
        'status'
    ],
]);

foreach ($users_groups as $id => $group) {
    $users_groups[$id]['cnt'] = 0;
    foreach ($count_usr as $val) {
        if ($id == $val['status']) {
            $users_groups[$id]['cnt'] = $val['cnt'];
            break;
        }
    }
}

if (isset($users_groups[0])) unset($users_groups[0]);

$all_users_cnt = array_sum(array_column($count_usr, 'cnt'));

// Load email templates
$email_templates_path = ROOT . '/sys/settings/email_templates/';
$email_templates = array_map(function($template) {
    return pathinfo($template, PATHINFO_FILENAME);
}, glob($email_templates_path . '*.html'));

$message_text = '';
if (!empty($_GET['tpl']) && file_exists($email_templates_path . $_GET['tpl'] . '.html')) {
    $message_text = file_get_contents($email_templates_path . $_GET['tpl'] . '.html');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
    $message = trim($_POST['message']);
    $subject = trim($_POST['subject']);
    $groups = $_POST['groups'] ?? [];

    if ($message && $subject && count($groups) > 0) {
        $status_ids = array_map('intval', array_unique($groups));
        $status_ids_str = implode(', ', $status_ids);

        $mail_list = $FpsDB->select('users', DB_ALL, [
            'cond' => ['`status` IN (' . $status_ids_str . ')'],
        ]);

        if (count($mail_list) > 0) {
            $from = !empty($_POST['from']) ? trim($_POST['from']) : Config::read('admin_email');
            $headers = "Precedence: bulk\n";

            $mailer = new AtmMail($email_templates_path);
            $mailer->prepare(false, $from, $headers);
            $mailer->setBody($message);

            $n = 0;
            $start_time = getMicroTime();
            foreach ($mail_list as $result) {
                unset($result['passw']); // Don't send passwords
                $context = ['user' => $result];
                if ($mailer->sendMail($result['email'], $subject, $context)) {
                    $n++;
                }
            }

            $_SESSION['message'] = __('Mails are sent') . ': ' . $n
                . '<br>Времени потрачено: ' . round(getMicroTime($start_time), 4) . ' сек.';
        } else {
            $_SESSION['errors'] = '<span style="color:red;">' . __('Users not found') . '</span>';
        }
    } else {
        $_SESSION['errors'] = '<span style="color:red;">' . __('Needed fields are empty') . '</span>';
    }

    redirect('/admin/users_sendmail.php');
}

include_once ROOT . '/admin/template/header.php';
?>

<div class="warning">
    <span class="greytxt"><?php echo __('Available emails') ?>:</span> <?php echo $all_users_cnt; ?><br /><br />
    <span class="greytxt"><?php echo __('Max email length') ?>:</span> 10000 <?php echo __('Symbols') ?><br /><br />
    <span class="greytxt"><b><?php echo __('In the mail body available below markers') ?>:</b></span><br />
    {{ user }}<span class="greytxt"> - <?php echo __('Receiver') ?>(<?php echo __('also available an user object variables') ?>)</span><br />
    {{ site_title }}<span class="greytxt"> - <?php echo __('Site name') ?></span><br />
    {{ site_url }}<span class="greytxt"> - <?php echo __('Your site URL') ?></span><br />
    {{ subject }}<span class="greytxt"> - <?php echo __('Subject') ?></span><br />
</div>

<form action="" method="POST">
    <div class="list">
        <div class="title"><?php echo __('Mass mailing') ?></div>
        <div class="level1">
            <div class="items">
                <div class="setting-item">
                    <div class="left"><?php echo __('Send to groups') ?></div>
                    <div class="right">
                        <table>
                            <tr>
                            <?php foreach ($users_groups as $id => $group):  $chb_id = md5(rand(0, 9999) . $id); ?>
                                <td style="text-align:center;">
                                    <input id="<?php echo $chb_id; ?>" type="checkbox" name="groups[<?php echo (int)$id; ?>]" value="<?php echo (int)$id; ?>" checked="checked" />
                                    <label for="<?php echo $chb_id; ?>"><?php echo h($group['title']) . ' (' . $group['cnt'] . ')'; ?></label><br />
                                </td>
                            <?php endforeach; ?>
                            </tr>
                        </table>
                    </div>
                    <div class="clear"></div>
                </div>
                <div class="setting-item">
                    <div class="left"><?php echo __('Email template') ?></div>
                    <div class="right">
                        <select onChange="window.location.href = '<?php echo get_url('/admin/users_sendmail.php?tpl=') ?>'+this.value;" name="template">
                            <option value=""><?php echo __('Without template') ?></option>
                            <?php foreach ($email_templates as $template): ?>
                                <option value="<?php echo $template; ?>" <?php echo (!empty($_GET['tpl']) && $_GET['tpl'] == $template) ? 'selected' : ''; ?>><?php echo $template; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="clear"></div>
                </div>
                <div class="setting-item">
                    <div class="left"><?php echo __('Subject') ?></div>
                    <div class="right"><input size="120" type="text" name="subject" /></div>
                    <div class="clear"></div>
                </div>
                <div class="setting-item">
                    <div class="left"><?php echo __('Sender\'s email') ?></div>
                    <div class="right"><input size="120" type="text" name="from" value="<?php echo Config::read('admin_email') ?: ''; ?>" /></div>
                    <div class="clear"></div>
                </div>
                <div class="setting-item">
                    <div class="left"><?php echo __('Email text') ?></div>
                    <div class="right"><textarea name="message" style="height:200px;"><?php echo h($message_text) ?></textarea></div>
                    <div class="clear"></div>
                </div>
                <div class="setting-item">
                    <div class="left"></div>
                    <div class="right"><input class="save-button" type="submit" name="send" value="<?php echo __('Send') ?>" /></div>
                    <div class="clear"></div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
include_once ROOT . '/admin/template/footer.php';
?>
