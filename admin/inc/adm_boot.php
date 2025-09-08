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
## any partial or not partial extension         ##
## CMS AtomX,without the consent of the         ##
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

$FpsDB = $Register['DB'] ?? null;
$ACL = $Register['ACL'] ?? null;

if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = Config::read('language');
}

// Проверка реферера
if (ADM_REFER_PROTECTED == 1) {
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

// Проверка авторизации
if (!isset($_SESSION['adm_panel_authorize']) 
    || $_SESSION['adm_panel_authorize'] < time() 
    || empty($_SESSION['user'])
) {
    handleLoginForm($FpsDB, $ACL);
    die();
}

// Обновление времени сессии
$_SESSION['adm_panel_authorize'] = time() + (int)Config::read('session_time', 'secure');

// Проверка прав доступа
if ($ACL->turn(['panel', 'restricted_access'], false)) {
    $url = preg_replace('#^.*/([^/]+)\.\w{2,5}$#i', "$1", $_SERVER['SCRIPT_NAME'] ?? '');
    
    if (!empty($url) && $url !== 'index' && $url !== 'exit') {
        if (!$ACL->turn(['panel', 'restricted_access_' . $url], false)) {
            $_SESSION['message'] = __('Permission denied');
            redirect('/admin/');
        }
    }
}

// Установка модулей
if (!empty($_GET['install'])) {
    handleModuleInstallation();
}

/**
 * Обработка формы логина
 */
function handleLoginForm($FpsDB, $ACL): void
{
    $errors = [];
    
    if (isset($_POST['send'], $_POST['login'], $_POST['passwd'])) {
        $login = strtolower(trim((string)$_POST['login']));
        $pass = trim((string)$_POST['passwd']);
        
        // Валидация
        if (empty($login)) {
            $errors[] = 'Заполните поле "Логин"';
        }
        if (empty($pass)) {
            $errors[] = 'Заполните поле "Пароль"';
        }
        
        if (empty($errors)) {
            // Защищенный запрос с подготовленными statement
            $user = $FpsDB->select('users', DB_FIRST, [
                'cond' => [
                    'name' => $login, 
                    'passw' => md5($pass)
                ]
            ]);
            
            if (empty($user)) {
                $errors[] = 'Не верный Пароль или Логин';
            } else {
                $ACL->turn(['panel', 'entry'], true, (int)$user[0]['status']);
                
                if (empty($errors)) {
                    $_SESSION['user'] = $user[0];
                    $_SESSION['adm_panel_authorize'] = time() + (int)Config::read('session_time', 'secure');
                    redirect('/admin/');
                }
            }
        }
    }
    
    renderLoginForm($errors);
}

/**
 * Рендер формы логина
 */
function renderLoginForm(array $errors = []): void
{
    $pageTitle = 'Авторизация в панели Администрирования';
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AtomX Admin Panel Authorization</title>
    <meta name="description" content="Панель администратора CMS AtomX">
    <meta name="keywords" content="AtomX, CMS, admin panel">
    <link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(WWW_ROOT) ?>/admin/template/css/style.css">
    <script src="<?= htmlspecialchars(WWW_ROOT) ?>/sys/js/jquery.js"></script>
    <script>
    $(document).ready(function(){
        const shmask = $('.shadow-mask');
        if (shmask.length > 0) {
            const bodyWidth = $('body').width() || 0;
            let lpos = (bodyWidth - 900) / 2;
            if (lpos < 1) lpos = 0;
            
            const l = lpos % 18;
            const finalLeft = lpos + (18 - l) + 51;
            shmask.css('left', finalLeft);
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
                        <input name="login" type="text" required>
                    </div>
                    <div class="item">
                        <span>Пароль</span>
                        <input name="passwd" type="password" required>
                    </div>
                </div>
                <div class="submit">
                    <input type="submit" name="send" value="Войти">
                </div>
            </form>
        </div>
    </div>
</body>
</html>
    <?php
}

/**
 * Обработка установки модулей
 */
function handleModuleInstallation(): void
{
    $instMod = (string)$_GET['install'];
    
    if (!empty($instMod) && preg_match('#^[a-z]+$#i', $instMod)) {
        $ModulesInstaller = new FpsModuleInstaller();
        
        try {
            $ModulesInstaller->installModule($instMod);
            $_SESSION['message'] = sprintf(__('Module "%s" has been installed'), $instMod);
        } catch (Exception $e) {
            $_SESSION['errors'] = sprintf(
                __('Module "%s" has been not installed (Reason: %s)'), 
                $instMod, 
                $e->getMessage()
            );
        }
        
        redirect('/admin/');
    }
}

/**
 * Получение параметров меню админки
 */
function getAdmFrontMenuParams(): array
{
    $out = [];
    $modules = glob(ROOT . '/modules/*', GLOB_ONLYDIR);
    
    if ($modules === false) {
        return $out;
    }
    
    foreach ($modules as $modPath) {
        $infoFile = $modPath . '/info.php';
        
        if (file_exists($infoFile)) {
            $menuInfo = [];
            include($infoFile);
            
            if (isset($menuInfo) && is_array($menuInfo)) {
                $mod = basename($modPath);
                $out[$mod] = $menuInfo;
            }
        }
    }
    
    return $out;
}

?>
<?php
// admin/inc/security_utils.php

/**
 * Generate CSRF token
 */
function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_tokens'])) {
        $_SESSION['csrf_tokens'] = [];
    }
    
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_tokens'][$token] = time();
    
    // Clean old tokens (older than 1 hour)
    foreach ($_SESSION['csrf_tokens'] as $storedToken => $timestamp) {
        if (time() - $timestamp > 3600) {
            unset($_SESSION['csrf_tokens'][$storedToken]);
        }
    }
    
    return $token;
}

/**
 * Validate CSRF token
 */
function validateCsrfToken(string $token): bool
{
    if (empty($_SESSION['csrf_tokens'][$token])) {
        return false;
    }
    
    // Token is valid for 1 hour
    if (time() - $_SESSION['csrf_tokens'][$token] > 3600) {
        unset($_SESSION['csrf_tokens'][$token]);
        return false;
    }
    
    // Remove used token
    unset($_SESSION['csrf_tokens'][$token]);
    return true;
}

/**
 * Add CSRF token to form
 */
function csrfField(): string
{
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}
