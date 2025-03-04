<?php

declare(strict_types=1);

##################################################
##                                              ##
## @Author:       Andrey Brykin (Drunya)        ##
## @Version:      1.3                           ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Admin module                  ##
## @copyright     ©Andrey Brykin 2010-2014      ##
## @Last mod.     2014/01/10                    ##
##################################################

##################################################
##                                              ##
## Any partial or not partial extension         ##
## CMS AtomX, without the consent of the        ##
## author, is illegal                           ##
##################################################
## Любое распространение                        ##
## CMS AtomX или ее частей,                     ##
## без согласия автора, является не законным    ##
##################################################

header('Content-Type: text/html; charset=utf-8');

if (!isInstall()) {
    redirect('/install');
}

$FpsDB = $Register['DB']; // TODO: Refactor to use dependency injection
$ACL = $Register['ACL'];
$_SESSION['lang'] = Config::read('language');

// Referer protection
if (ADM_REFER_PROTECTED === 1) {
    $script_name = $_SERVER['REQUEST_URI'] ?? '';
    $script_name = strrchr($script_name, '/');
    if ($script_name !== '/index.php') {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        preg_match('#^https?://([^/]+)#', $referer, $match);
        if (empty($match[1]) || $match[1] !== $_SERVER['SERVER_NAME']) {
            redirect('/admin/index.php');
        }
    }
}

// Authorization check
if (!isset($_SESSION['adm_panel_authorize']) || $_SESSION['adm_panel_authorize'] < time() || empty($_SESSION['user'])) {
    if (isset($_POST['send'], $_POST['login'], $_POST['passwd'])) {
        $errors = [];
        $login = strtolower(trim($_POST['login']));
        $pass = trim($_POST['passwd']);

        if (empty($login)) {
            $errors[] = 'Заполните поле "Логин"';
        }
        if (empty($pass)) {
            $errors[] = 'Заполните поле "Пароль"';
        }

        if (empty($errors)) {
            $user = $FpsDB->select('users', DB_FIRST, ['cond' => ['name' => $login, 'passw' => md5($pass)]]);
            if (empty($user)) {
                $errors[] = 'Неверный пароль или логин';
            } else {
                // Grant access
                $ACL->turn(['panel', 'entry'], true, $user[0]['status']);
            }

            if (empty($errors)) {
                $_SESSION['user'] = $user[0];
                $_SESSION['adm_panel_authorize'] = time() + Config::read('session_time', 'secure');
                redirect('/admin/');
            }
        }
    }

    $pageTitle = 'Авторизация в панели Администрирования';
    $pageNav = '';
    $pageNavr = '';
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>AtomX Admin Panel Authorization</title>
    <meta name="description" content="">
    <meta name="keywords" content="">
    <link rel="stylesheet" type="text/css" href="<?= WWW_ROOT ?>/admin/template/css/style.css">
    <script src="<?= WWW_ROOT ?>/sys/js/jquery.js"></script>
    <script>
        $(document).ready(function() {
            const shmask = $('.shadow-mask');
            if (shmask.length) {
                const bodyWidth = $('body').css('width');
                let lpos = (parseInt(bodyWidth) - 900) / 2;
                if (lpos < 1) lpos = 0;

                const l = lpos + (18 - (lpos % 18)) + 51;
                shmask.css('left', l);
            }
        });
    </script>
</head>
<body>
    <div id="login-wrapper">
        <div class="shadow-mask"></div>
        <div class="form">
            <div class="title">Авторизация</div>
            <form method="POST" action="">
                <div class="items">
                    <?php if (!empty($errors)): ?>
                        <ul class="error">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="item">
                        <span>Логин</span>
                        <input name="login" type="text">
                    </div>
                    <div class="item">
                        <span>Пароль</span>
                        <input name="passwd" type="password">
                    </div>
                </div>
                <div class="submit">
                    <input type="submit" name="send" value="">
                </div>
            </form>
        </div>
    </div>
</body>
</html>

<?php
    exit;
} elseif (!empty($_SESSION['adm_panel_authorize'])) {
    $_SESSION['adm_panel_authorize'] = time() + Config::read('session_time', 'secure');

    if (!empty($ACL)) {
        $ACL = $Register['ACL'];
    }

    if ($ACL->turn(['panel', 'restricted_access'], false)) {
        $url = preg_replace('#^.*/([^/]+)\.\w{2,5}$#i', "$1", $_SERVER['SCRIPT_NAME']);

        if (!empty($url) && $url !== 'index' && $url !== 'exit') {
            if (!$ACL->turn(['panel', 'restricted_access_' . $url], false)) {
                $_SESSION['message'] = __('Permission denied');
                redirect('/admin/');
            }
        }
    }
}

// Module installation
if (!empty($_GET['install'])) {
    $instMod = (string)$_GET['install'];
    if (!empty($instMod) && preg_match('#^[a-z]+$#i', $instMod)) {
        $ModulesInstaller = new FpsModuleInstaller();
        try {
            $ModulesInstaller->installModule($instMod);
            $_SESSION['message'] = sprintf(__('Module "%s" has been installed'), $instMod);
        } catch (Exception $e) {
            $_SESSION['errors'] = sprintf(__('Module "%s" has been not installed (Reason: %s)'), $instMod, $e->getMessage());
        }
        redirect('/admin/');
    }
}

/**
 * Get admin front menu parameters.
 *
 * @return array
 */
function getAdmFrontMenuParams(): array
{
    $out = [];
    $modules = glob(ROOT . '/modules/*', GLOB_ONLYDIR);

    if (!empty($modules)) {
        foreach ($modules as $modPath) {
            if (file_exists($modPath . '/info.php')) {
                include $modPath . '/info.php';
                if (isset($menuInfo)) {
                    $mod = basename($modPath);
                    $out[$mod] = $menuInfo;
                }
            }
        }
    }

    return $out;
}
