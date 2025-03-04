<?php
declare(strict_types=1);

/**
 * AtomX CMS Admin Panel
 *
 * @author    Andrey Brykin (Drunya)
 * @version   0.7
 * @project   CMS AtomX
 * @package   Admin Module
 * @copyright ©Andrey Brykin 2010-2011
 *
 * Любое распространение CMS AtomX или ее частей
 * без согласия автора является незаконным.
 */

// Заголовок страницы
$pageTitle = $pageTitle ?? 'Админ-панель AtomX';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description" content="Админ-панель CMS AtomX">
    <meta name="keywords" content="CMS, AtomX, Admin">

    <!-- Подключение стилей -->
    <link rel="stylesheet" href="<?= htmlspecialchars(WWW_ROOT, ENT_QUOTES, 'UTF-8') ?>/admin/template/css/style.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(WWW_ROOT, ENT_QUOTES, 'UTF-8') ?>/sys/js/redactor/css/redactor.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(WWW_ROOT, ENT_QUOTES, 'UTF-8') ?>/sys/js/fancybox/css/fancy.css">

    <!-- Подключение скриптов -->
    <script src="<?= htmlspecialchars(WWW_ROOT, ENT_QUOTES, 'UTF-8') ?>/sys/js/jquery.js"></script>
    <script src="<?= htmlspecialchars(WWW_ROOT, ENT_QUOTES, 'UTF-8') ?>/sys/js/jquery.validate.js"></script>
    <script src="<?= htmlspecialchars(WWW_ROOT, ENT_QUOTES, 'UTF-8') ?>/sys/js/jquery-ui-1.8.14.custom.min.js"></script>
    <script src="<?= htmlspecialchars(WWW_ROOT, ENT_QUOTES, 'UTF-8') ?>/admin/js/drunya.lib.js"></script>
    <script src="<?= htmlspecialchars(WWW_ROOT, ENT_QUOTES, 'UTF-8') ?>/sys/js/redactor/redactor.js"></script>
    <script src="<?= htmlspecialchars(WWW_ROOT, ENT_QUOTES, 'UTF-8') ?>/sys/js/jquery.cookie.js"></script>
    <script src="<?= htmlspecialchars(WWW_ROOT, ENT_QUOTES, 'UTF-8') ?>/sys/js/jquery.hotkeys.js"></script>
    <script src="<?= htmlspecialchars(WWW_ROOT, ENT_QUOTES, 'UTF-8') ?>/sys/js/jstree/jstree.min.js"></script>
    <script src="<?= htmlspecialchars(WWW_ROOT, ENT_QUOTES, 'UTF-8') ?>/sys/js/fancybox/jquery.fancybox.js"></script>

    <script>
        $(document).ready(function() {
            // Инициализация Fancybox
            $("a.gallery").fancybox();

            // Установка высоты wrapper
            $('#wrapper').css('min-height', ($('body').height() - 136)); // 136 - top+bottom wrapper padding
            $('body').height($('#wrapper').outerHeight() - 55); // 55 - side menu top indent

            // Функция для управления положением меню при скролле
            function setMenuPosition() {
                const position = window.scrollY || document.documentElement.scrollTop;
                if (!position) return;

                const headMenuWrap = $('.headmenuwrap');
                const crumbs = $('.headmenuwrap .crumbs');

                if (position > 106) {
                    if (crumbs.is(':visible')) crumbs.hide();
                    headMenuWrap.stop().animate({
                        'height': '55px',
                        'z-index': 5,
                        'top': position + 'px'
                    }, 100);
                } else {
                    if (!crumbs.is(':visible')) crumbs.show();
                    headMenuWrap.stop().animate({
                        'height': '106px',
                        'z-index': 1,
                        'top': '0px'
                    }, 500);
                }
            }

            // Обработчик скролла
            window.addEventListener("scroll", setMenuPosition, false);
        });
    </script>
</head>
<body>
    <div class="headmenuwrap">
        <div class="headmenu">
            <a href="<?= htmlspecialchars(WWW_ROOT, ENT_QUOTES, 'UTF-8') ?>/admin/">
                <div class="logo"></div>
            </a>
            <div class="menu" id="topmenu">
                <ul>
                    <li><a href="#">Общее</a></li>
                    <li><a href="#">Плагины</a></li>
                    <li><a href="#">Сниппеты</a></li>
                    <li><a href="#">Дизайн</a></li>
                    <li><a href="#">Статистика</a></li>
                    <li><a href="#">Безопасность</a></li>
                    <li><a href="#">Дополнительно</a></li>
                    <li><a href="#">Помощь</a></li>
                    <div class="clear"></div>
                </ul>
            </div>
            <div class="userbar">
                <?php if (!empty($_SESSION['user'])): ?>
                    <?php
                    $ava_path = file_exists(ROOT . '/sys/avatars/' . $_SESSION['user']['id'] . '.jpg')
                        ? WWW_ROOT . '/sys/avatars/' . $_SESSION['user']['id'] . '.jpg'
                        : WWW_ROOT . '/sys/img/noavatar.png';

                    $group_info = $Register['ACL']->get_user_group($_SESSION['user']['status']);
                    $group_title = $group_info['title'];
                    ?>
                    <div class="ava"><img src="<?= htmlspecialchars($ava_path, ENT_QUOTES, 'UTF-8') ?>" alt="user ava" title="user ava" /></div>
                    <div class="name">
                        <a href="#"><?= htmlspecialchars($_SESSION['user']['name'], ENT_QUOTES, 'UTF-8') ?></a>
                        <span><?= htmlspecialchars($group_title, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <a href="exit.php" class="exit"></a>
                <?php endif; ?>
            </div>
            <div class="clear"></div>
        </div>
        <div class="rcrumbs">
            <?= !empty($pageNavr) ? htmlspecialchars($pageNavr, ENT_QUOTES, 'UTF-8') : '' ?>
        </div>
        <div class="crumbs">
            <?= !empty($pageNav) ? htmlspecialchars($pageNav, ENT_QUOTES, 'UTF-8') : '' ?>
        </div>
    </div>

    <div id="side-menu" class="side-menu">
        <div class="search">
            <form>
                <div class="input"><input type="text" name="search" placeholder="Search..." /></div>
                <input class="submit-butt" type="submit" name="send" value="" />
            </form>
        </div>
        <ul>
            <?php
            $modsInstal = new FpsModuleInstaller;
            $nsmods = $modsInstal->checkNewModules();

            if (!empty($nsmods)):
                foreach ($nsmods as $mk => $mv):
            ?>
                <li>
                    <div class="icon new-module"></div>
                    <div class="sub-opener" onClick="subMenu('sub<?= htmlspecialchars($mk, ENT_QUOTES, 'UTF-8') ?>')"></div>
                    <a href="#"><?= htmlspecialchars($mk, ENT_QUOTES, 'UTF-8') ?></a>
                    <div class="clear"></div>
                    <div id="sub<?= htmlspecialchars($mk, ENT_QUOTES, 'UTF-8') ?>" class="sub">
                        <div class="shadow">
                            <ul>
                                <li><a href="<?= htmlspecialchars(WWW_ROOT, ENT_QUOTES, 'UTF-8') ?>/admin?install=<?= htmlspecialchars($mk, ENT_QUOTES, 'UTF-8') ?>">Install</a></li>
                            </ul>
                        </div>
                    </div>
                </li>
            <?php endforeach; endif; ?>

            <?php
            $modules = getAdmFrontMenuParams();
            foreach ($modules as $modKey => $modData):
                if (!empty($nsmods) && array_key_exists($modKey, $nsmods)) continue;
            ?>
                <li>
                    <div class="icon <?= htmlspecialchars($modKey, ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="sub-opener" onClick="subMenu('sub<?= htmlspecialchars($modKey, ENT_QUOTES, 'UTF-8') ?>')"></div>
                    <a href="<?= htmlspecialchars($modData['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($modData['ankor'], ENT_QUOTES, 'UTF-8') ?></a>
                    <div class="clear"></div>
                    <div id="sub<?= htmlspecialchars($modKey, ENT_QUOTES, 'UTF-8') ?>" class="sub">
                        <div class="shadow">
                            <ul>
                                <?php foreach ($modData['sub'] as $url => $ankor): ?>
                                    <li><a href="<?= htmlspecialchars(get_url('/admin/' . $url), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($ankor, ENT_QUOTES, 'UTF-8') ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="clear"></div>
    </div>

    <script>
        var FpsAdmPanel = {
            sidePanel: 'visible'
        };

        function hideSide() {
            if (FpsAdmPanel.sidePanel === 'visible') {
                $('#side-menu').animate({ left: '-300px' }, 500);
                $('#side-menu-td').animate({ width: '0px' }, 500);
                FpsAdmPanel.sidePanel = 'hidden';
            } else {
                $('#side-menu').animate({ left: '3%' }, 500);
                $('#side-menu-td').animate({ width: '237px' }, 500);
                FpsAdmPanel.sidePanel = 'visible';
            }
        }
    </script>

    <div id="side-menu-label" onClick="hideSide();">
        <a href="#">Спрятать</a>
    </div>

    <div id="wrapper">
        <div class="center-wrapper">
            <table class="side-separator" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                    <td id="side-menu-td" width="237" min-height="100%"></td>
                    <td style="position:relative;">
                        <div id="content-wrapper">
                            <?php if (!empty($serverMessage)): ?>
                                <div class="warning <?= htmlspecialchars($serverMessage['type'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($serverMessage['message'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($_SESSION['message'])): ?>
                                <div class="warning ok">
                                    <?= htmlspecialchars($_SESSION['message'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php unset($_SESSION['message']); ?>
                            <?php endif; ?>

                            <?php if (!empty($_SESSION['errors'])): ?>
                                <div class="warning error">
                                    <?= htmlspecialchars($_SESSION['errors'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php unset($_SESSION['errors']); ?>
                            <?php endif; ?>
