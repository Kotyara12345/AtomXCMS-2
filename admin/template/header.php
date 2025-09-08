<?php
/**
 * ==================================================
 * Header template for CMS AtomX Admin Panel
 * ==================================================
 * 
 * @author    Andrey Brykin (Drunya)
 * @version   1.0
 * @project   CMS AtomX
 * @package   Admin Module
 * @copyright © Andrey Brykin 2010-2014
 * 
 * ==================================================
 * Any partial or complete distribution
 * of CMS AtomX without the consent of the author
 * is illegal.
 * ==================================================
 * Любое распространение CMS AtomX или ее частей,
 * без согласия автора, является незаконным.
 * ==================================================
 */

declare(strict_types=1);

// Check if we're in development mode
$isDev = ($_ENV['APP_ENV'] ?? 'production') === 'development';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'AtomX Admin Panel') ?></title>
    <meta name="description" content="Панель администратора CMS AtomX">
    <meta name="keywords" content="AtomX, CMS, admin panel">
    
    <!-- Preload critical resources -->
    <link rel="preload" href="<?= WWW_ROOT ?>/sys/js/jquery.js" as="script">
    <link rel="preload" href="<?= WWW_ROOT ?>/admin/template/css/style.css" as="style">
    
    <!-- jQuery and core libraries -->
    <script src="<?= WWW_ROOT ?>/sys/js/jquery.js" defer></script>
    <script src="<?= WWW_ROOT ?>/sys/js/jquery.validate.js" defer></script>
    <script src="<?= WWW_ROOT ?>/sys/js/jquery-ui.min.js" defer></script>
    <script src="<?= WWW_ROOT ?>/sys/js/jquery.cookie.js" defer></script>
    
    <!-- Admin specific scripts -->
    <script src="<?= WWW_ROOT ?>/admin/js/drunya.lib.js" defer></script>
    
    <!-- Rich text editor -->
    <script src="<?= WWW_ROOT ?>/sys/js/redactor/redactor.js" defer></script>
    <link rel="stylesheet" href="<?= WWW_ROOT ?>/sys/js/redactor/css/redactor.css">
    
    <!-- UI components -->
    <script src="<?= WWW_ROOT ?>/sys/js/jstree/jstree.min.js" defer></script>
    <script src="<?= WWW_ROOT ?>/sys/js/fancybox/jquery.fancybox.js" defer></script>
    <link rel="stylesheet" href="<?= WWW_ROOT ?>/sys/js/fancybox/css/fancy.css">
    
    <!-- Main styles -->
    <link rel="stylesheet" href="<?= WWW_ROOT ?>/admin/template/css/style.css">
    
    <script defer>
    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize fancybox
        if (typeof $.fn.fancybox === 'function') {
            $("a.gallery").fancybox();
        }

        // Layout calculations
        const calculateLayout = () => {
            const wrapper = document.getElementById('wrapper');
            const body = document.body;
            
            if (wrapper) {
                wrapper.style.minHeight = (body.offsetHeight - 136) + 'px';
            }
            
            if (body) {
                body.style.height = (wrapper?.offsetHeight - 55) + 'px';
            }
        };

        // Initial calculation
        calculateLayout();
        
        // Recalculate on resize
        window.addEventListener('resize', calculateLayout);

        // Scroll position handling with throttling
        let scrollTimeout;
        const handleScroll = () => {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                const position = window.pageYOffset || document.documentElement.scrollTop;
                const headmenu = document.querySelector('.headmenuwrap');
                const crumbs = document.querySelector('.headmenuwrap .crumbs');
                
                if (!headmenu) return;
                
                if (position > 106) {
                    if (crumbs) crumbs.style.display = 'none';
                    headmenu.style.cssText = `
                        height: 55px;
                        z-index: 5;
                        position: fixed;
                        top: ${position}px;
                        transition: all 0.3s ease;
                    `;
                } else {
                    if (crumbs) crumbs.style.display = 'block';
                    headmenu.style.cssText = `
                        height: 106px;
                        z-index: 1;
                        position: absolute;
                        top: 0;
                        transition: all 0.5s ease;
                    `;
                }
            }, 50);
        };

        window.addEventListener('scroll', handleScroll);
    });

    // Global admin panel object
    window.FpsAdmPanel = {
        sidePanel: 'visible',
        hideSide: function() {
            const sideMenu = document.getElementById('side-menu');
            const sideMenuTd = document.getElementById('side-menu-td');
            
            if (this.sidePanel === 'visible') {
                sideMenu.style.left = '-300px';
                sideMenuTd.style.width = '0';
                this.sidePanel = 'hidden';
            } else {
                sideMenu.style.left = '3%';
                sideMenuTd.style.width = '237px';
                this.sidePanel = 'visible';
            }
        }
    };
    </script>
</head> 
<body>
    <header class="headmenuwrap" role="banner">
        <div class="headmenu">
            <a href="<?= WWW_ROOT ?>/admin/" aria-label="Админ панель AtomX">
                <div class="logo" role="img" aria-label="Логотип AtomX"></div>
            </a>
            
            <nav class="menu" id="topmenu" aria-label="Главное меню">
                <ul role="menubar">
                    <li role="none"><a href="#" role="menuitem">Общее</a></li>
                    <li role="none"><a href="#" role="menuitem">Плагины</a></li>
                    <li role="none"><a href="#" role="menuitem">Сниппеты</a></li>
                    <li role="none"><a href="#" role="menuitem">Дизайн</a></li>
                    <li role="none"><a href="#" role="menuitem">Статистика</a></li>
                    <li role="none"><a href="#" role="menuitem">Безопасность</a></li>
                    <li role="none"><a href="#" role="menuitem">Дополнительно</a></li>
                    <li role="none"><a href="#" role="menuitem">Помощь</a></li>
                </ul>
            </nav>
            
            <div class="userbar" aria-label="Панель пользователя">
                <?php if (!empty($_SESSION['user'])): 
                    $ava_path = file_exists(ROOT . '/sys/avatars/' . $_SESSION['user']['id'] . '.jpg')
                        ? WWW_ROOT . '/sys/avatars/' . $_SESSION['user']['id'] . '.jpg'
                        : WWW_ROOT . '/sys/img/noavatar.png';
                        
                    $new_ver = AtmApiService::getLastVersion();
                    $newVersion = $new_ver 
                        ? '<a href="https://github.com/Drunyacoder/AtomXCMS-2/releases" title="Новая версия">' . __('New version of AtomX') . '</a>' 
                        : '';
                        
                    $group_info = $Register['ACL']->get_user_group($_SESSION['user']['status']);
                    $group_title = htmlspecialchars($group_info['title'] ?? '');
                ?>
                <div class="ava">
                    <img src="<?= $ava_path ?>" alt="Аватар пользователя" width="32" height="32">
                </div>
                <div class="name">
                    <a href="#" aria-label="Профиль пользователя"><?= htmlspecialchars($_SESSION['user']['name']) ?></a>
                    <span><?= $group_title ?></span>
                </div>
                <a href="exit.php" class="exit" aria-label="Выход"></a>
            </div>
        </div>
        
        <div class="rcrumbs" aria-label="Навигационная цепочка">
            <?= !empty($pageNavr) ? htmlspecialchars($pageNavr) : '' ?>
        </div>
        <div class="crumbs" aria-label="Хлебные крошки">
            <?= !empty($pageNav) ? htmlspecialchars($pageNav) : '' ?>
        </div>
    </header>

    <aside class="side-menu" id="side-menu" aria-label="Боковое меню">
        <div class="search" role="search">
            <form>
                <div class="input">
                    <input type="text" name="search" placeholder="Search..." aria-label="Поиск">
                </div>
                <button class="submit-butt" type="submit" aria-label="Искать"></button>
            </form>
        </div>
        
        <ul role="menu">
            <?php
            $modsInstal = new FpsModuleInstaller;
            $nsmods = $modsInstal->checkNewModules();

            if (!empty($nsmods)):
                foreach ($nsmods as $mk => $mv):
            ?>    
            <li role="none">
                <div class="icon new-module" aria-hidden="true"></div>
                <button class="sub-opener" onclick="subMenu('sub<?= $mk ?>')" aria-expanded="false" aria-controls="sub<?= $mk ?>">
                    <span class="sr-only">Развернуть меню</span>
                </button>
                <a href="#" role="menuitem"><?= htmlspecialchars($mk) ?></a>
                <div id="sub<?= $mk ?>" class="sub" role="menu" hidden>
                    <div class="shadow">
                        <ul>
                            <li role="none">
                                <a href="<?= WWW_ROOT ?>/admin?install=<?= $mk ?>" role="menuitem">Install</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </li>
            <?php endforeach; endif;

            $modules = getAdmFrontMenuParams();
            foreach ($modules as $modKey => $modData): 
                if (!empty($nsmods) && array_key_exists($modKey, $nsmods)) continue;
            ?>
            <li role="none">
                <div class="icon <?= $modKey ?>" aria-hidden="true"></div>
                <button class="sub-opener" onclick="subMenu('sub<?= $modKey ?>')" aria-expanded="false" aria-controls="sub<?= $modKey ?>">
                    <span class="sr-only">Развернуть меню</span>
                </button>
                <a href="<?= $modData['url'] ?>" role="menuitem"><?= htmlspecialchars($modData['ankor']) ?></a>
                <div id="sub<?= $modKey ?>" class="sub" role="menu" hidden>
                    <div class="shadow">
                        <ul>
                            <?php foreach ($modData['sub'] as $url => $ankor): ?>
                            <li role="none">
                                <a href="<?= get_url('/admin/' . $url) ?>" role="menuitem"><?= htmlspecialchars($ankor) ?></a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </aside>

    <button id="side-menu-label" onclick="FpsAdmPanel.hideSide()" aria-label="Скрыть боковое меню">
        <span>Спрятать</span>
    </button>

    <main id="wrapper">
        <div class="center-wrapper">
            <div class="layout-container">
                <div id="side-menu-td" class="side-menu-container"></div>
                <div class="content-area">
                    <div id="content-wrapper">

                        <?php if (!empty($serverMessage)): ?>
                        <div class="warning <?= htmlspecialchars($serverMessage['type'] ?? '') ?>" role="alert">
                            <?= htmlspecialchars($serverMessage['message'] ?? '') ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($_SESSION['message'])): ?>
                        <div class="warning ok" role="status">
                            <?= htmlspecialchars($_SESSION['message']) ?>
                        </div>
                        <?php unset($_SESSION['message']); endif; ?>

                        <?php if (!empty($_SESSION['errors'])): ?>
                        <div class="warning error" role="alert">
                            <?= is_array($_SESSION['errors']) 
                                ? implode('<br>', array_map('htmlspecialchars', $_SESSION['errors'])) 
                                : htmlspecialchars($_SESSION['errors']) ?>
                        </div>
                        <?php unset($_SESSION['errors']); endif; ?>
