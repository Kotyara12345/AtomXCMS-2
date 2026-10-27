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

include_once ROOT . '/sys/inc/admin_security.lib.php';
atm_admin_security_ensure_tables();
atm_admin_send_security_headers();


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

if (!function_exists('atm_admin_csrf_input')) {
	/**
	 * Hidden CSRF field for a form.
	 *
	 * @return string
	 */
	function atm_admin_csrf_input()
	{
		return '<input type="hidden" name="csrf_token" value="'
			. h(atm_admin_csrf_token()) . '" />';
	}
}

if (!function_exists('atm_admin_csrf_fail_response')) {
	/**
	 * Reject a POST request with an invalid CSRF token.
	 *
	 * AJAX callers receive JSON so the existing scripts can show an
	 * error; normal form submissions receive a small HTML page.
	 */
	function atm_admin_csrf_fail_response()
	{
		atm_admin_audit(
			'csrf_failed',
			'uri: ' . (isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '')
		);

		$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
				&& strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
			|| (isset($_SERVER['HTTP_ACCEPT'])
				&& stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

		http_response_code(403);
		if ($isAjax) {
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(array(
				'status' => false,
				'errors' => array(__('Session expired, please reload the page')),
			), JSON_UNESCAPED_UNICODE);
			exit;
		}

		header('Content-Type: text/html; charset=utf-8');
		echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8" />'
			. '<meta name="viewport" content="width=device-width, initial-scale=1" />'
			. '<title>' . h(__('Permission denied')) . '</title>'
			. '<style>body{font:16px/1.5 Arial,sans-serif;margin:40px;color:#333}'
			. 'a{color:#3573a8}</style></head><body><h1>'
			. h(__('Permission denied')) . '</h1><p>'
			. h(__('Session expired, please reload the page'))
			. '</p><p><a href="' . h(WWW_ROOT . '/admin/') . '">'
			. h(__('Admin Panel')) . '</a></p></body></html>';
		exit;
	}
}

if (!function_exists('atm_admin_csrf_validate_request')) {
	/**
	 * Validate the CSRF token of an authenticated POST request.
	 */
	function atm_admin_csrf_validate_request()
	{
		if (PHP_SAPI === 'cli') return;
		if (empty($_SERVER['REQUEST_METHOD'])
			|| strtoupper((string)$_SERVER['REQUEST_METHOD']) !== 'POST') return;

		$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
		if (!atm_admin_csrf_check($token)) {
			atm_admin_csrf_fail_response();
		}
	}
}

if (!function_exists('atm_admin_csrf_buffer_callback')) {
	/**
	 * Add a hidden token to POST forms rendered by legacy admin pages.
	 *
	 * The admin panel contains dozens of old forms. Doing this at the
	 * output boundary keeps the change central and makes the global POST
	 * validation above safe for every legacy form, including forms that
	 * are assembled by modules.
	 *
	 * @param string $html
	 * @return string
	 */
	function atm_admin_csrf_buffer_callback($html)
	{
		if (stripos($html, '<form') === false) return $html;
		$token = atm_admin_csrf_token();
		if ($token === '') return $html;

		// Never modify downloads, CSV exports, JSON responses or log
		// files that merely happen to contain a form-like string.
		$headers = headers_list();
		foreach ($headers as $header) {
			if (stripos($header, 'content-type:') === 0
				&& stripos($header, 'text/html') === false) {
				return $html;
			}
		}

		return preg_replace_callback(
			'/<form\b[^>]*>.*?<\/form>/is',
			function ($match) use ($token) {
				if (!preg_match('/\bmethod\s*=\s*["\']?\s*post\b/i', $match[0])) {
					return $match[0];
				}
				if (preg_match('/\bname\s*=\s*["\']?csrf_token\b/i', $match[0])) {
					return $match[0];
				}

				$field = '<input type="hidden" name="csrf_token" value="'
					. h($token) . '" />';
				return preg_replace_callback(
					'/<form\b[^>]*>/i',
					function ($open) use ($field) {
						return $open[0] . $field;
					},
					$match[0],
					1
				);
			},
			$html
		);
	}
}

if (!function_exists('atm_admin_csrf_buffer_start')) {
	/**
	 * Start output buffering for automatic legacy-form tokens.
	 */
	function atm_admin_csrf_buffer_start()
	{
		static $started = false;
		if ($started || PHP_SAPI === 'cli') return;
		$started = true;
		ob_start('atm_admin_csrf_buffer_callback');
	}
}

if (!function_exists('atm_admin_login_csrf_check')) {
	/**
	 * CSRF validation for the login form.
	 *
	 * A stale login page must not make the login impossible: browsers can
	 * keep a login tab open longer than the session cookie lives. If the
	 * hidden token no longer matches, accept the request only when the
	 * browser explicitly says it comes from the same origin. The token is
	 * still checked first and remains the primary defence.
	 *
	 * @param string $token
	 * @return bool
	 */
	function atm_admin_login_csrf_check($token)
	{
		if (atm_admin_csrf_check($token)) return true;
		return atm_admin_request_is_same_origin();
	}
}

if (!function_exists('atm_admin_request_is_same_origin')) {
	/**
	 * Check the Origin/Referer headers of the current request.
	 *
	 * @return bool
	 */
	function atm_admin_request_is_same_origin()
	{
		if (empty($_SERVER['REQUEST_METHOD']) || strtoupper((string)$_SERVER['REQUEST_METHOD']) !== 'POST') {
			return false;
		}

		$currentHost = !empty($_SERVER['HTTP_HOST'])
			? (string)$_SERVER['HTTP_HOST']
			: (!empty($_SERVER['SERVER_NAME']) ? (string)$_SERVER['SERVER_NAME'] : '');
		$currentHost = strtolower(trim($currentHost));
		if ($currentHost === '') return false;

		$source = !empty($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';
		if ($source === '' && !empty($_SERVER['HTTP_REFERER'])) {
			$source = (string)$_SERVER['HTTP_REFERER'];
		}
		if ($source === '') return false;

		$parts = @parse_url($source);
		if (empty($parts['host'])) return false;

		$sourceHost = strtolower((string)$parts['host']);
		if (!empty($parts['port'])) $sourceHost .= ':' . (int)$parts['port'];

		$normalize = function ($host) {
			$host = strtolower(trim((string)$host));
			if (substr($host, -3) === ':80') $host = substr($host, 0, -3);
			if (substr($host, -4) === ':443') $host = substr($host, 0, -4);
			return $host;
		};

		return hash_equals($normalize($currentHost), $normalize($sourceHost));
	}
}


if (!function_exists('atm_admin_complete_login')) {
	/**
	 * Finish a successful login (with or without 2FA).
	 *
	 * @param array $user
	 * @param bool $remember
	 * @param bool $twofa
	 * @return bool
	 */
	function atm_admin_complete_login($user, $remember = false, $twofa = false)
	{
		if (empty($user['id'])) return false;

		$Register = Register::getInstance();
		$ACL = !empty($Register['ACL']) ? $Register['ACL'] : null;
		if (!empty($ACL) && !$ACL->turn(array('panel', 'entry'), true, $user['status'], (int)$user['id'])) {
			return false;
		}

		// Protect against session fixation.
		session_regenerate_id(true);
		$_SESSION['user'] = $user;
		$_SESSION['adm_panel_authorize'] = (time() + Config::read('session_time', 'secure'));
		atm_admin_2fa_pending_clear();

		if ($remember) {
			atm_admin_remember_create($user['id']);
		} else {
			atm_admin_remember_forget();
		}

		atm_admin_session_touch($user);
		atm_admin_audit(
			'login_success',
			$twofa ? 'two-factor' : 'password',
			(int)$user['id'],
			isset($user['name']) ? (string)$user['name'] : ''
		);

		redirect('/admin/');
		return true;
	}
}


if (!function_exists('atm_admin_render_login_page')) {
	/**
	 * Render the login screen or the second (2FA) step.
	 *
	 * @param string $errors
	 * @param array|null $twofaPending
	 */
	function atm_admin_render_login_page($errors = '', $twofaPending = null)
	{
?>
<!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<link rel="icon" type="image/png" sizes="16x16" href="<?php echo WWW_ROOT ?>/admin/template/img/favicon.png?v=<?php echo filemtime(ROOT . '/admin/template/img/favicon.png'); ?>" />
	<title>AtomX — <?php echo !empty($twofaPending) ? h(__('Two-factor authentication')) : h(__('Authorization')); ?></title>
	<meta name="description" content="" />
	<meta name="keywords" content="" />
	<link rel="stylesheet" href="<?php echo WWW_ROOT ?>/admin/template/css/style.css?v=<?php echo filemtime(ROOT . '/admin/template/css/style.css'); ?>" />
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
		<?php if (!empty($twofaPending)): ?>
			<div class="form atm-twofa-form">
				<div class="title"><?php echo h(__('Two-factor authentication')); ?></div>
				<form method="POST" action="<?php echo WWW_ROOT ?>/admin/index.php" id="admin-2fa-form" name="admin-2fa-form" autocomplete="off">
					<div class="items">
						<?php if (!empty($errors)): ?>
							<ul class="error atm-twofa-errors"><?php echo $errors; ?></ul>
						<?php endif; ?>
						<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
						<div class="item">
							<span><?php echo h(__('Code')); ?></span>
							<input id="admin-twofa-code" name="twofa_code" type="text" inputmode="numeric" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" required autofocus />
						</div>
						<div class="atm-twofa-hint">
							<?php echo h(__('Enter the 6-digit code from the authenticator app or one of the backup codes.')); ?>
						</div>
					</div>
					<div class="submit"><input type="submit" name="twofa_send" value="<?php echo h(__('Sign in')); ?>" /></div>
					<div class="remember"><a href="?cancel_2fa=1"><?php echo h(__('Cancel')); ?></a></div>
				</form>
			</div>
		<?php else: ?>
			<div class="form">
				<div class="title"><?php echo h(__('Authorization')); ?></div>
				<form method="POST" action="<?php echo WWW_ROOT ?>/admin/index.php" id="admin-login-form" name="admin-login-form" autocomplete="on">
					<div class="items">
						<?php if (!empty($errors)): ?>
							<ul class="error"><?php echo $errors; ?></ul>
						<?php endif; ?>
						<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
						<div class="item"><span><?php echo h(__('Login')); ?></span><input id="admin-login" name="login" type="text" value="<?php echo h(isset($_POST['login']) ? $_POST['login'] : ''); ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required /></div>
						<div class="item"><span><?php echo h(__('Password')); ?></span><input id="admin-passwd" name="passwd" type="password" autocomplete="current-password" required /></div>
					</div>
					<div class="remember"><input type="checkbox" name="remember" id="admin-remember" value="1"<?php echo !empty($_POST['remember']) ? ' checked="checked"' : ''; ?> /><label for="admin-remember"><?php echo h(__('Remember me')); ?></label></div>
					<div class="submit"><input type="submit" name="send" value="<?php echo h(__('Sign in')); ?>" /></div>
				</form>
			</div>
		<?php endif; ?>
	</div>
</body>
</html>
<?php
		die();
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
 * "Remember me": if the regular admin session has expired, try to
 * restore it from the long-lived cookie.
 */
if ((!isset($_SESSION['adm_panel_authorize']) || $_SESSION['adm_panel_authorize'] < time() || empty($_SESSION['user']))
	&& !empty($_COOKIE['atm_admin_remember'])) {
	$rememberedUser = atm_admin_remember_login();
	if (!empty($rememberedUser)) {
		// Protect against session fixation
		session_regenerate_id(true);
		$_SESSION['user'] = $rememberedUser;
		$_SESSION['adm_panel_authorize'] = (time() + Config::read('session_time', 'secure'));
	}
}


/*
 * A session can be terminated from the security center. The session row
 * is keyed by a hash, so the actual PHP session id is never stored.
 */
$sessionNotice = '';
if (!empty($_SESSION['user']) && atm_admin_session_is_revoked(session_id())) {
	atm_admin_audit(
		'session_denied',
		'',
		(int)$_SESSION['user']['id'],
		isset($_SESSION['user']['name']) ? (string)$_SESSION['user']['name'] : ''
	);
	unset($_SESSION['user'], $_SESSION['adm_panel_authorize']);
	atm_admin_remember_forget();
	$sessionNotice = __('Your session has been terminated. Please sign in again.');
}
if (!empty($_SESSION['admin_login_notice'])) {
	$sessionNotice = (string)$_SESSION['admin_login_notice'];
	unset($_SESSION['admin_login_notice']);
}


/*
 * The authorization block. The `adm_panel_authorize` session key keeps
 * the time when the current admin session expires; it is refreshed on
 * every request of the panel.
 */
///if (empty($_SESSION['user'])) redirect('/');
if (!isset($_SESSION['adm_panel_authorize']) || $_SESSION['adm_panel_authorize'] < time() || empty($_SESSION['user'])) {
	$errors = '';
	if ($sessionNotice !== '') {
		$errors .= '<li>' . h($sessionNotice) . '</li>';
	}

	// The user can abandon the second login step and start over.
	if (isset($_GET['cancel_2fa'])) {
		atm_admin_2fa_pending_clear();
		redirect('/admin/index.php');
	}

	$twofaPending = atm_admin_2fa_pending_get();

	/*
	 * Second step: a TOTP code or a backup code.
	 */
	if (!empty($twofaPending) && isset($_POST['twofa_code'])) {
		$code = isset($_POST['twofa_code']) ? trim((string)$_POST['twofa_code']) : '';
		$ip = atm_admin_client_ip();

		if (!atm_admin_login_csrf_check(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
			$errors .= '<li>' . h(__('Session expired, please reload the page')) . '</li>';
		}
		if ($code === '') {
			$errors .= '<li>' . h(__('Enter the code from the authenticator app.')) . '</li>';
		}

		if ($errors === '') {
			$remaining = atm_admin_login_block_remaining($twofaPending['login'], $ip);
			if ($remaining > 0) {
				$minutes = max(1, (int)ceil($remaining / 60));
				$errors .= '<li>' . h(sprintf(__('Too many failed login attempts. Try again in %d minutes.'), $minutes)) . '</li>';
				atm_admin_audit('login_blocked', '2fa, login: ' . $twofaPending['login']);
			} else {
				$pendingUser = $FpsDB->select('users', DB_FIRST, array(
					'cond' => array('id' => (int)$twofaPending['user_id']),
				));
				if (empty($pendingUser)) {
					atm_admin_2fa_pending_clear();
					$errors .= '<li>' . h(__('Permission denied')) . '</li>';
				} else {
					$usedBackup = false;
					$remainingCodes = null;
					if (atm_admin_2fa_verify_code((int)$pendingUser[0]['id'], $code, $usedBackup, $remainingCodes)) {
						atm_admin_login_attempt_record($twofaPending['login'], $ip, true);
						if ($usedBackup) {
							atm_admin_audit(
								'login_2fa_backup',
								'remaining: ' . (int)$remainingCodes,
								(int)$pendingUser[0]['id'],
								isset($pendingUser[0]['name']) ? (string)$pendingUser[0]['name'] : ''
							);
						}
						if (!atm_admin_complete_login($pendingUser[0], !empty($twofaPending['remember']), true)) {
							atm_admin_2fa_pending_clear();
							$errors .= '<li>' . h(__('Permission denied')) . '</li>';
						}
					} else {
						atm_admin_login_attempt_record($twofaPending['login'], $ip, false);
						atm_admin_audit(
							'login_2fa_failed',
							'login: ' . $twofaPending['login'],
							(int)$pendingUser[0]['id'],
							isset($pendingUser[0]['name']) ? (string)$pendingUser[0]['name'] : ''
						);
						$errors .= '<li>' . h(__('Invalid two-factor code')) . '</li>';
					}
				}
			}
		}

		// A successful login redirects above; an unsuccessful one keeps the step.
		$twofaPending = atm_admin_2fa_pending_get();
	}

	/*
	 * First step: login and password.
	 */
	if (empty($twofaPending) && isset($_POST['send']) && isset($_POST['login']) && isset($_POST['passwd'])) {
		$login = strtolower(trim((string)$_POST['login']));
		$pass = trim((string)$_POST['passwd']);
		$ip = atm_admin_client_ip();

		if (empty($login)) $errors .= '<li>' . h(__('Fill in the "Login" field')) . '</li>';
		if (empty($pass)) $errors .= '<li>' . h(__('Fill in the "Password" field')) . '</li>';

		if (!atm_admin_login_csrf_check(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
			$errors .= '<li>' . h(__('Session expired, please reload the page')) . '</li>';
		}

		if ($errors === '') {
			$remaining = atm_admin_login_block_remaining($login, $ip);
			if ($remaining > 0) {
				$minutes = max(1, (int)ceil($remaining / 60));
				$errors .= '<li>' . h(sprintf(__('Too many failed login attempts. Try again in %d minutes.'), $minutes)) . '</li>';
				atm_admin_audit('login_blocked', 'login: ' . $login);
			} else {
				$user = $FpsDB->select('users', DB_FIRST, array('cond' => array('name' => $login)));
				if (empty($user) || !atm_password_verify($pass, $user[0]['passw'])) {
					atm_admin_login_attempt_record($login, $ip, false);
					atm_admin_audit('login_failed', 'login: ' . $login);
					$errors .= '<li>' . h(__('Invalid login or password')) . '</li>';
				} else {
					// Upgrade legacy md5 hashes transparently on a successful login.
					if (atm_password_needs_rehash($user[0]['passw'])) {
						$newHash = atm_password_hash($pass);
						$FpsDB->query("UPDATE `" . $FpsDB->getFullTableName('users') . "` SET passw='" . $FpsDB->escape($newHash) . "' WHERE id=" . (int)$user[0]['id']);
						$user[0]['passw'] = $newHash;
						$FpsDB->cleanSqlCache();
					}

					if (atm_admin_2fa_enabled((int)$user[0]['id'])) {
						atm_admin_login_attempt_record($login, $ip, true);
						atm_admin_2fa_pending_set((int)$user[0]['id'], $login, !empty($_POST['remember']));
						atm_admin_audit(
							'login_2fa_required',
							'login: ' . $login,
							(int)$user[0]['id'],
							isset($user[0]['name']) ? (string)$user[0]['name'] : ''
						);
						$twofaPending = atm_admin_2fa_pending_get();
					} else {
						if (!atm_admin_complete_login($user[0], !empty($_POST['remember']), false)) {
							$errors .= '<li>' . h(__('Permission denied')) . '</li>';
						}
					}
				}
			}
		}
	}

	if (!empty($twofaPending)) {
		atm_admin_render_login_page($errors, $twofaPending);
	}
	atm_admin_render_login_page($errors, null);
} else if (!empty($_SESSION['adm_panel_authorize'])) {
	$_SESSION['adm_panel_authorize'] = (time() + Config::read('session_time', 'secure'));

	if (!empty($_SESSION['user'])) {
		atm_admin_session_touch($_SESSION['user']);
	}

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


/*
 * Existing admin forms are protected centrally: every authenticated
 * POST must carry the session token, and the output buffer below adds
 * the hidden field to legacy forms that do not render it themselves.
 */
if (!empty($_SESSION['user'])) {
	atm_admin_csrf_validate_request();
	atm_admin_security_cleanup();
	atm_admin_csrf_buffer_start();
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

		if (!empty($_SESSION['user'])) {
			$securitySub = array(
				'security.php' => __('Security center'),
				'profile.php' => __('Profile'),
			);
			if ((int)$_SESSION['user']['status'] === 4) {
				$securitySub['audit_log.php'] = __('Audit log');
			}
			$out['security'] = array(
				'url' => 'security.php',
				'ankor' => __('Security'),
				'icon' => 'security',
				'sub' => $securitySub,
			);
		}

		return $out;
	}
}

?>
