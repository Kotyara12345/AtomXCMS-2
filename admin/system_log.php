<?php
##################################################
##                                                ##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      0.7                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackege    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2011       ##
##################################################


##################################################
##                                                ##
## any partial or not partial extension         ##
## CMS AtomX, without the consent of the         ##
## author, is illegal                           ##
##################################################
## Любое распространение                        ##
## CMS AtomX или ее частей,                     ##
## без согласия автора, является незаконным     ##
##################################################

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$Log = $Register['Log'];
$ACL = $Register['ACL'];

/* current page and cnt pages */
$log_files = glob(ROOT . '/sys/logs/' . $Log->logDir . '/*.dat');
$total_files = count($log_files);
list($pages, $page) = pagination($total_files, 1, '/admin/system_log.php?');

$data = [];
if (!empty($log_files)) {
    $filename = basename($log_files[$page - 1]);
    $data = $Log->read($filename);
}

$pageTitle = __('Action log');
$pageNav = $pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';
?>

<div class="list">
    <div class="title"><?php echo __('Action log') ?></div>
    <table class="grid" cellspacing="0" width="100%">
        <?php if (!empty($data)): ?>
            <thead>
                <tr>
                    <th width="15%">Дата</th>
                    <th width="30%">Действие</th>
                    <th width="20%">Пользователь</th>
                    <th width="10%">IP адрес</th>
                    <th>Доп. информация</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $line): 
                    $color = '';
                    if (!empty($line['user_status']) && is_numeric($line['user_status'])) {
                        $group_info = $ACL->get_user_group($line['user_status']);
                        if (!empty($group_info)) {
                            $color = $group_info['color'] ?? '';
                            $line['user_status'] = '<span style="float:right;color:#' . h($color) . ';">' . h($group_info['title']) . '</span>';
                        } else {
                            $line['user_status'] = '<span style="float:right;color:#F14242;">*</span>';
                        }
                    } else {
                        $line['user_status'] = '<span style="float:right;color:#F14242;">*</span>';
                    }
                ?>
                <tr>
                    <td align="center"><span style="color:green;"><?php echo h($line['date']) ?></span></td>
                    <td><?php echo h($line['action']) ?></td>
                    <td>
                        <?php echo !empty($line['user_id']) && !empty($line['user_name']) && !empty($line['user_status']) ? 
                            '(' . h($line['user_id']) . ')' . h($line['user_name']) . $line['user_status'] : 'Unknown'; ?>
                    </td>
                    <td align="center"><?php echo h($line['ip']) ?></td>
                    <td><?php echo !empty($line['comment']) ? h($line['comment']) : '--'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        <?php else: ?>
            <tr><td colspan="5">Записей нет</td></tr>
        <?php endif; ?>
    </table>
</div>

<div class="pagination"><?php echo $pages ?></div>

<?php
include_once 'template/footer.php';
?>
