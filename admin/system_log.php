<?php
##################################################
##                                              ##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      0.7                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackege    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2011       ##
##################################################

// Совместимость с PHP 8.1+
declare(strict_types=1);

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$Log = $Register['Log'] ?? null;
$ACL = $Register['ACL'] ?? null;

// Проверка наличия необходимых объектов
if (!$Log || !$ACL) {
    die('Required components are not available');
}

/* current page and cnt pages */
$logDir = $Log->logDir ?? 'logs';
$logPattern = ROOT . '/sys/logs/' . $logDir . '/*.dat';
$logFiles = glob($logPattern);

// Безопасное получение файлов логов
$logFiles = !empty($logFiles) ? $logFiles : [];
$totalFiles = count($logFiles);

// Пагинация
$currentPage = $_GET['page'] ?? 1;
$currentPage = max(1, (int)$currentPage);
list($pages, $page) = pagination($totalFiles, $currentPage, '/admin/system_log.php?');

// Чтение данных лога
$data = [];
$filename = '';

if (!empty($logFiles) && isset($logFiles[$page - 1])) {
    $filePath = $logFiles[$page - 1];
    $filename = basename($filePath);
    
    // Проверка безопасности пути файла
    if (strpos($filePath, ROOT . '/sys/logs/') === 0 && is_file($filePath)) {
        $data = $Log->read($filename);
    }
}

// Валидация данных
if (!is_array($data)) {
    $data = [];
}

$pageTitle = __('Action log');
$pageNav = $pageTitle;
$pageNavr = '';

include_once ROOT . '/admin/template/header.php';
?>

<div class="list">
    <div class="title"><?= h(__('Action log')) ?></div>
    <table class="grid" cellspacing="0" width="100%">
        <?php if(!empty($data)): ?>
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
        <?php foreach($data as $line): 
            // Валидация структуры записи лога
            $line = is_array($line) ? $line : [];
            $date = $line['date'] ?? '';
            $action = $line['action'] ?? '';
            $userId = $line['user_id'] ?? '';
            $userName = $line['user_name'] ?? '';
            $userStatus = $line['user_status'] ?? '';
            $ip = $line['ip'] ?? '';
            $comment = $line['comment'] ?? '';
            
            // Обработка статуса пользователя
            $statusHtml = '<span style="float:right;color:#F14242;">*</span>';
            
            if (!empty($userStatus) && is_numeric($userStatus)) {
                $groupInfo = $ACL->get_user_group((int)$userStatus);
                if (!empty($groupInfo) && is_array($groupInfo)) {
                    $color = $groupInfo['color'] ?? '';
                    $title = $groupInfo['title'] ?? '';
                    
                    if (!empty($color)) {
                        $statusHtml = '<span style="float:right;color:#' . h($color) . ';">' . h($title) . '</span>';
                    } else {
                        $statusHtml = '<span style="float:right;color:#F14242;">' . h($title) . '</span>';
                    }
                }
            }
        ?>
        <tr>
            <td align="center"><span style="color:green;"><?= h($date) ?></span></td>
            <td><?= h($action) ?></td>
            <td>
                <?php if (!empty($userId) && !empty($userName)): ?>
                    (<?= h($userId) ?>)<?= h($userName) ?><?= $statusHtml ?>
                <?php else: ?>
                    Unknown
                <?php endif; ?>
            </td>
            <td align="center"><?= h($ip) ?></td>
            <td><?= !empty($comment) ? h($comment) : '--' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <?php else: ?>
        <tr>
            <td colspan="5">Записей нет</td>
        </tr>
        <?php endif; ?>
    </table>
</div>

<?php if ($totalFiles > 0): ?>
<div class="pagination"><?= $pages ?></div>
<?php endif; ?>

<?php
include_once 'template/footer.php';
