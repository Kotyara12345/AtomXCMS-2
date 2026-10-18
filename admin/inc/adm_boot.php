<?php
##################################################
##												##
## @Author:       Andrey Brykin (Drunya)        ##
## @Version:      1.4                           ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Admin module                  ##
## @Last mod.     2026                          ##
##################################################


##################################################
##												##
## any partial or not partial extension         ##
## CMS AtomX,without the consent of the         ##
## author, is illegal                           ##
##################################################
## Любое распространение                        ##
## CMS AtomX или ее частей,                     ##
## без согласия автора, является не законным    ##
##################################################


header('Content-Type: text/html; charset=utf-8');
if (!isInstall()) redirect('/install');



$FpsDB = $Register['DB']; //TODO
$ACL = $Register['ACL'];
$_SESSION['lang'] = Config::read('language');


/*
 * Simple CSRF protection for the authorization form.
 */
if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('atm_admin_csrf_token')) {
	function atm_admin_csrf_token()
	{
		return $_SESSION['csrf_token'];
	}
}

if (!function_exists('atm_admin_csrf_check')) {
	function atm_admin_csrf_check($token)
	{
		return !empty($_SESSION['csrf_token'])
			&& is_string($token)
			&& hash_equals($_SESSION['csrf_token'], $token);
	}
}


if (ADM_REFER_PROTECTED == 1) {
	$requestUri = (!empty($_SERVER['REQUEST_URI'])) ? $_SERVER['REQUEST_URI'] : '';
	$script_name = strrchr($requestUri, '/');
	if (!is_string($script_name)) $script_name = '';
	if ($script_name != '/index.php') {
		$referer = (!empty($_SERVER['HTTP_REFERER'])) ? $_SERVER['HTTP_REFERER'] : '';
		$refererHost = (string)parse_url($referer, PHP_URL_HOST);
		$currentHost = (!empty($_SERVER['HTTP_HOST'])) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'];
		if ($refererHost === '' || strcasecmp($refererHost, $currentHost) !== 0)
			redirect('/admin/index.php');
	}
}


/*
 * The authorization block. The `adm_panel_authorize` session key keeps
 * the time when the current admin session expires; it is refreshed on
 * every request of the panel.
 */
///if (empty($_SESSION['user'])) redirect('/');
if (!isset($_SESSION['adm_panel_authorize']) || $_SESSION['adm_panel_authorize'] < time() || empty($_SESSION['user'])) {
	if (isset($_POST['send']) && isset($_POST['login']) && isset($_POST['passwd'])) {
		$errors = '';
		$login = strtolower(trim((string)$_POST['login']));
		$pass = trim((string)$_POST['passwd']);

		if (empty($login)) $errors .= '<li>Заполните поле "Логин"</li>';
		if (empty($pass)) $errors .= '<li>Заполните поле "Пароль"</li>';

		if (!atm_admin_csrf_check(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
			$errors .= '<li>Сессия устарела, обновите страницу и попробуйте снова</li>';
		}

		if (empty($errors)) {

			$user = $FpsDB->select('users', DB_FIRST, array('cond' => array('name' => $login, 'passw' => md5($pass))));
			if (!count($user)) {
				$errors .= '<li>Не верный Пароль или Логин</li>';
			} else {
				//turn access
				$ACL->turn(array('panel', 'entry'), true, $user[0]['status']);
			}

			if (empty($errors)) {
				// Protect against session fixation
				session_regenerate_id(true);
				$_SESSION['user'] = $user[0];
				$_SESSION['adm_panel_authorize'] = (time() + Config::read('session_time', 'secure'));
				redirect('/admin/');
			}
		}
	}


    $pageTitle = 'Авторизация в панели Администратора';
    $pageNav = '';
    $pageNavr = '';


?>



<!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title>AtomX — Авторизация</title>
	<meta name="description" content="" />
	<meta name="keywords" content="" />
	<link rel="StyleSheet" type="text/css" href="<?php echo WWW_ROOT ?>/admin/template/css/style.css" />
	<script src="<?php echo WWW_ROOT ?>/sys/js/jquery.js"></script>
</head>
<body>
	<script>
	$(document).ready(function(){
		var shmask = $('.shadow-mask');
		if (shmask.length) {
		
			var body = $('body').css('width');
			var lpos = (parseInt(body) - 900) / 2;
			if (isNaN(lpos) || lpos < 1) lpos = 0;
			
			var l = (lpos % 18);
			l = lpos + (18 - l) + 51;
			shmask.css({'left': l});
		}
	});
	</script>
	<div id="login-wrapper">
		<div class="shadow-mask"></div>
		<div class="form">
			<div class="title">Авторизация</div>
			<form method="POST" action="" >
				<div class="items">
					<?php 
					if (!empty($errors)) {
						echo '<ul class="error">' . $errors . '</ul>';
						unset($errors);
					}
					?>
					<input type="hidden" name="csrf_token" value="<?php echo atm_admin_csrf_token(); ?>" />
					<div class="item"><span>Логин</span><input name="login" type="text" autocomplete="username" /></div>
					<div class="item"><span>Пароль</span><input name="passwd" type="password" autocomplete="current-password" /></div>
				</div>
				<div class="submit"><input type="submit" name="send" value="" /></div>
			</form>
		</div>
	</div>
</body>
</html>




<?php	
	die();

	
	
} else if (!empty($_SESSION['adm_panel_authorize'])) {
	$_SESSION['adm_panel_authorize'] = (time() + Config::read('session_time', 'secure'));
	
	
	if (!empty($ACL) && $ACL->turn(array('panel', 'restricted_access'), false)) {

		$url = preg_replace('#^.*/([^/]+)\.\w{2,5}$#i', "$1", (string)(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : ''));
		
		if (!empty($url) && $url != 'index' && $url != 'exit') {
			if (!$ACL->turn(array('panel', 'restricted_access_' . $url), false)) {
				$_SESSION['message'] = __('Permission denied');
				redirect('/admin/');
			}
		}
	}
}


/**
 * Install new a modules.
 * If you has put a new module files to modules directory,
 * you can find that module in the left-side menu.
 * Choose "install" in the module dropdown menu to start install process.
 *
 * During the installation process a some files and settings will be import
 * to the Atom.
 * For more information see FpsModuleInstaller class.
 */
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




if (!function_exists('getAdmFrontMenuParams')) {
	function getAdmFrontMenuParams()
	{
		$out = array();
		$modules = glob(ROOT . '/modules/*', GLOB_ONLYDIR);
		if (count($modules)) {
			foreach ($modules as $key => $modPath) {
				if (file_exists($modPath . '/info.php')) {
					unset($menuInfo);
					include($modPath . '/info.php');
					if (isset($menuInfo) && is_array($menuInfo)) {
						$mod = basename($modPath);
						$out[$mod] = $menuInfo;
					}
				}
			}
		}
		return $out;
	}
}

?>
