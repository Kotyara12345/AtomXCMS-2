<?php
declare(strict_types=1);

/**
 * @author    Andrey Brykin (Drunya)
 * @email     drunyacoder@gmail.com
 * @site      http://atomx.net
 * @version   1.5
 * @project   CMS AtomX
 * @package   Authors List (Admin Part)
 * @copyright ©Andrey Brykin 2010-2014
 *
 * Любое распространение CMS AtomX или ее частей
 * без согласия автора является незаконным.
 */

require_once '../sys/boot.php';
require_once ROOT . '/admin/inc/adm_boot.php';

// Установка заголовков страницы
$pageTitle = $page_title = __('Dev. Team');
$pageNav = $page_title;
$pageNavr = '<span style="float:right;">
                <a href="javascript://" onClick="showHelpWin(\'Арбайтен! Арбайтен! Арбайтен!\', \'А никто и не мешает\')">
                    ' . __('I want to be here') . '
                </a>
             </span>';

// Подключение шапки
include_once ROOT . '/admin/template/header.php';
?>

<div class="list">
    <div class="title"><?= __('Authors') ?></div>
    <div class="level1">
        <div class="items">
            <?php
            $authors = [
                'Idea by' => ['Andrey Brykin (Drunya)'],
                'Programmers' => ['Andrey Brykin (Drunya)', 'Danilov Alexandr (modos189)'],
                'Testers and audit' => [
                    'Andrey Konyaev (Ater)',
                    'Laguta Dmitry (ARMI)',
                    'Roman Maximov (r00t_san)',
                    'Alexandr Verenik (Wasja)',
                    'Danilov Alexandr (modos189)',
                ],
                'Marketing' => ['Andrey Brykin (Drunya)', 'Andrey Konyaev (Ater)'],
                'Design and Templates' => [
                    'Lapin Boris (MrBoriska)',
                    'Andrey Brykin (Drunya)',
                    'Alexandr Bognar (Krevedko)',
                    'Roman Maximov (r00t_san)',
                    'Laguta Dmitry (ARMI)',
                ],
                'Specialists by Security' => ['Roman Maximov (r00t_san)'],
                'Additional Software' => ['Andrey Brykin (Drunya)', 'Alexandr Verenik (Wasja)'],
                'Translation' => ['Victor Sproot (Sproot)', 'Andrey Brykin (Drunya)'],
            ];

            foreach ($authors as $role => $names) {
                echo '<div class="setting-item">
                        <div class="center">
                            <h3>' . __($role) . '</h3>
                            ' . implode('<br />', $names) . '
                        </div>
                      </div>';
            }
            ?>
        </div>
    </div>
</div>

<?php
// Подключение подвала
include_once 'template/footer.php';
