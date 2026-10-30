<?php
/*---------------------------------------------\
|											   |
| @Author:       Andrey Brykin (Drunya)        |
| @Email:        drunyacoder@gmail.com         |
| @Site:         http://atomx.net              |
| @Version:      2.0.1                         |
| @Project:      CMS                           |
| @Package       AtomX CMS                     |
| @Subpackege    Define library                |
| @Copyright     ©Andrey Brykin                |
| @Last mod      2017/10/13                    |
|----------------------------------------------|
|											   |
| any partial or not partial extension         |
| CMS AtomX,without the consent of the         |
| author, is illegal                           |
|----------------------------------------------|
| Любое распространение                        |
| CMS AtomX или ее частей,                     |
| без согласия автора, является не законным    |
\---------------------------------------------*/

/**
 * Session initialization. Cookie parameters must be set before
 * session_start(). HttpOnly + SameSite=Lax are enabled, the Secure
 * flag is set automatically when the site works over HTTPS.
 */
require_once __DIR__ . '/inc/https.lib.php';
atm_force_https();

if (session_status() === PHP_SESSION_NONE) {
	if (function_exists('ini_set')) {
		@ini_set('session.use_strict_mode', '1');
		@ini_set('session.use_only_cookies', '1');
		@ini_set('session.gc_maxlifetime', '10000');
	}
	if (PHP_VERSION_ID >= 70300) {
		session_set_cookie_params(array(
			'lifetime' => 3000,
			'path'     => '/',
			'domain'   => '',
			'secure'   => atm_request_is_https(),
			'httponly' => true,
			'samesite' => 'Lax',
		));
	} else {
		session_set_cookie_params(3000);
	}
	session_start();
}
if (isset($_SESSION['db_querys'])) unset($_SESSION['db_querys']);


/**
 * Current version of engine
 */
if (!defined('FPS_VERSION')) define('FPS_VERSION', '2.8 Beta');

/**
 * Path constants
 */
if (!defined('ROOT')) define('ROOT', dirname(dirname(__FILE__)));
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
if (!defined('R')) define('R', dirname(dirname(__FILE__)) . DS);


/**
 * If we uses AtomX from subdir or subdirs
 * we must set this variable, because AtomX
 * must know this for good work.
 */
if (!defined('WWW_ROOT')) {
	$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/')) : '';
	$rootPath = str_replace('\\', '/', rtrim(dirname(dirname(__FILE__)), '/'));
	$wwwRoot = '';
	if ($docRoot !== '' && strncasecmp($rootPath, $docRoot, strlen($docRoot)) === 0
		&& (strlen($rootPath) === strlen($docRoot) || substr($rootPath, strlen($docRoot), 1) === '/')) {
		$wwwRoot = substr($rootPath, strlen($docRoot));
	}
	define('WWW_ROOT', $wwwRoot);
}

/**
 * If set to 1, check referer in admin panel
 * and if he dont match current host redirect to
 * index page of admin panel. It doesn't allow to
 * send inquiries from other hosts.
 */
if (!defined('ADM_REFER_PROTECTED')) define('ADM_REFER_PROTECTED', 0);




/**
 * whether the system is installed
 */
function isInstall() {
	return !file_exists(ROOT . '/install');
}




/**
 * Include some core part (Application)
 *
 * The config file does not exist until the installer writes it.
 */
$settingsConfigFile = ROOT . '/sys/settings/config.php';
if (is_file($settingsConfigFile)) {
	include_once $settingsConfigFile;
}


/**
 * Autoload
 */
include_once ROOT . '/sys/inc/autoload.class.php';
Autoload::loadFuncs();
spl_autoload_register(array('Autoload', 'load'));



/**
 * Registry
 */
$Register = Register::getInstance();
$Register['Config'] = new Config($settingsConfigFile);



include_once ROOT . '/sys/inc/helpers.lib.php';


/**
 *
 */
new Bootstrap;




include_once ROOT . '/sys/fnc/geshi/geshi.php';




