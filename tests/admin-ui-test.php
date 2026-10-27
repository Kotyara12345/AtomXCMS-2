<?php
/**
 * Static checks for the admin UI changes.
 *
 * These checks do not need a database or a web server. They guard the
 * header contract (notifications, user menu, site/exit buttons), the
 * responsive breakpoints, the vanilla UI script and the Russian
 * translations. Run with:
 *
 *     php tests/admin-ui-test.php
 */

$root = dirname(__DIR__);
$assertions = 0;
$failures = array();

function atm_test_assert($condition, $message)
{
	global $assertions, $failures;
	$assertions++;
	if (!$condition) {
		$failures[] = $message;
	}
}

function atm_test_read($path)
{
	return @file_get_contents($path);
}

function atm_test_language($path)
{
	$text = atm_test_read($path);
	if ($text === false) return array();
	$language = array();
	if (preg_match_all("/'([^']*)'\\s*=>/", $text, $matches)) {
		foreach ($matches[1] as $key) {
			$language[$key] = true;
		}
	}
	return $language;
}

$required = array(
	'admin/security.php',
	'admin/profile.php',
	'admin/audit_log.php',
	'admin/moderation.php',
	'admin/notifications.php',
	'admin/errors_log.php',
	'admin/server_config.php',
	'admin/js/admin-ui.js',
	'admin/template/header.php',
	'admin/template/footer.php',
	'admin/template/css/style.css',
	'sys/inc/admin_security.lib.php',
	'sys/inc/server_config.lib.php',
	'sys/settings/languages/rus.php',
	'install/do_queries.php',
);

foreach ($required as $relative) {
	$path = $root . '/' . $relative;
	atm_test_assert(is_file($path), 'Missing file: ' . $relative);
	if (is_file($path)) {
		atm_test_assert(substr((string)file_get_contents($path), 0, 3) !== "\xEF\xBB\xBF", 'Unexpected UTF-8 BOM: ' . $relative);
	}
}

$header = atm_test_read($root . '/admin/template/header.php');
$css = atm_test_read($root . '/admin/template/css/style.css');
$js = atm_test_read($root . '/admin/js/admin-ui.js');
$footer = atm_test_read($root . '/admin/template/footer.php');
$schema = atm_test_read($root . '/install/do_queries.php');
$moderation = atm_test_read($root . '/admin/moderation.php');
$serverConfig = atm_test_read($root . '/admin/server_config.php');
$admBoot = atm_test_read($root . '/admin/inc/adm_boot.php');
$errorsLog = atm_test_read($root . '/admin/errors_log.php');
$language = atm_test_language($root . '/sys/settings/languages/rus.php');

atm_test_assert(strpos($header, 'id="atm-notify-toggle"') !== false, 'Header is missing the notification bell.');
atm_test_assert(strpos($header, 'id="atm-user-toggle"') !== false, 'Header is missing the user menu.');
atm_test_assert(strpos($header, 'class="go-site"') !== false, 'Header is missing the site button.');
atm_test_assert(strpos($header, 'class="exit"') !== false, 'Header is missing the logout button.');
atm_test_assert(strpos($header, 'id="atm-theme-toggle"') !== false, 'Header is missing the theme toggle.');
atm_test_assert(strpos($header, 'debugheader') === false, 'Temporary header debug code must not be committed.');
atm_test_assert(strpos($header, 'id="atm-toasts"') !== false, 'Header is missing the toast container.');
atm_test_assert(strpos($header, 'atm-skip-link') !== false, 'Header is missing the skip link.');

atm_test_assert(strpos($css, '@media screen and (min-width: 1201px) and (max-width: 1359px)') !== false, 'Missing compact header breakpoint.');
atm_test_assert(strpos($css, '@media screen and (max-width: 1200px)') !== false, 'Missing mobile header breakpoint.');
atm_test_assert(strpos($css, '@media screen and (max-width: 560px)') !== false, 'Missing small-phone header breakpoint.');
atm_test_assert(strpos($css, '.headmenu .userbar .go-site') !== false, 'The site button is not styled inside the userbar.');
atm_test_assert(strpos($css, '.headmenu .userbar a.exit') !== false, 'The exit button is not styled inside the userbar.');
atm_test_assert(strpos($css, 'html.atm-dark') !== false, 'Missing dark theme styles.');
atm_test_assert(strpos($css, '.atm-confirm-dialog') !== false, 'Missing confirmation dialog styles.');
atm_test_assert(strpos($css, '.atm-toast') !== false, 'Missing toast styles.');

atm_test_assert(strpos($js, 'atmAdminTheme') !== false, 'The theme preference is not persisted.');
atm_test_assert(strpos($js, 'atm-theme-toggle') !== false, 'The theme toggle is not wired.');
atm_test_assert(strpos($js, 'data-atm-confirm') !== false, 'Confirmation buttons are not handled.');
atm_test_assert(strpos($js, 'beforeunload') !== false, 'Unsaved form protection is missing.');
atm_test_assert(strpos($js, 'atmToast') !== false, 'The toast API is missing.');
atm_test_assert(strpos($js, 'atmConfirm') !== false, 'The confirmation API is missing.');
atm_test_assert(strpos($js, 'Закрыть уведомление') !== false, 'The toast close label is not translated.');
atm_test_assert(strpos($js, 'Подтверждение') !== false, 'The confirmation title is not translated.');
atm_test_assert(strpos($js, "\xEF\xBF\xBD") === false, 'The UI script contains a Unicode replacement character.');

atm_test_assert(strpos($admBoot, 'function atm_admin_login_csrf_check') !== false, 'The login CSRF fallback is missing.');
atm_test_assert(substr_count($admBoot, 'atm_admin_login_csrf_check(') >= 3, 'The login flow does not use the CSRF fallback.');
atm_test_assert(strpos($admBoot, 'HTTP_ORIGIN') !== false, 'Same-origin validation does not inspect the Origin header.');
atm_test_assert(strpos($admBoot, 'HTTP_REFERER') !== false, 'Same-origin validation does not inspect the Referer header.');
atm_test_assert(strpos($errorsLog, "fopen(\$path, 'rb')") !== false, 'The error log reader must use a bounded file handle.');
atm_test_assert(strpos($errorsLog, "action === 'clear'") !== false, 'Clearing the error log must be a POST action.');
atm_test_assert(strpos($errorsLog, "action === 'rotate'") !== false, 'The error log rotation action is missing.');
atm_test_assert(strpos($errorsLog, "\$_GET['del']") === false, 'The error log must not be cleared through a GET request.');

atm_test_assert(strpos($footer, 'Все права защищены.') !== false, 'The footer copyright is not translated.');
atm_test_assert(strpos($footer, 'All rights reserved.') === false, 'The English footer copyright is still present.');

foreach (array('admin_sessions', 'admin_audit', 'admin_login_attempts', 'admin_2fa', 'admin_notifications', 'admin_notification_reads', 'admin_notification_state') as $table) {
	atm_test_assert(strpos($schema, $table) !== false, 'Installer schema is missing ' . $table . '.');
}

atm_test_assert(strpos($moderation, '$commentModules') !== false, 'Moderation summary does not group comments by module.');
atm_test_assert(strpos($moderation, 'comments_list.php?m=news&amp;premoder=1') === false, 'Moderation comments link is hard-coded to the news module.');

atm_test_assert(strpos($serverConfig, 'confirm_critical') !== false, 'Critical directive confirmation is missing.');
atm_test_assert(strpos($serverConfig, 'download_backup') !== false, 'Backup download action is missing.');
atm_test_assert(strpos($serverConfig, 'restore_backup') !== false, 'Backup restore action is missing.');
atm_test_assert(strpos($serverConfig, 'save_backup_limit') !== false, 'Configurable backup retention is missing.');
atm_test_assert(strpos($serverConfig, 'backup_limit') !== false, 'The backup retention field is missing.');

$securityLib = atm_test_read($root . '/sys/inc/admin_security.lib.php');
$serverLib = atm_test_read($root . '/sys/inc/server_config.lib.php');
$auditLog = atm_test_read($root . '/admin/audit_log.php');
$notifications = atm_test_read($root . '/admin/notifications.php');
$index = atm_test_read($root . '/admin/index.php');
atm_test_assert(strpos($securityLib, 'Content-Security-Policy-Report-Only') !== false, 'Security headers are missing the CSP.');
atm_test_assert(strpos($securityLib, 'atm_admin_csrf') === false || strpos($admBoot, 'atm_admin_csrf_validate_request') !== false, 'Global authenticated POST CSRF validation is missing.');
atm_test_assert(strpos($admBoot, 'atm_admin_csrf_buffer_start') !== false, 'Legacy form CSRF injection is missing.');
atm_test_assert(strpos($header, 'ATM_CSRF_TOKEN') !== false, 'The AJAX CSRF token is missing from the header.');
atm_test_assert(strpos($header, 'ajaxPrefilter') !== false, 'The AJAX CSRF prefilter is missing.');
atm_test_assert(strpos($moderation, 'bulk_moderate') !== false, 'Bulk moderation is missing.');
atm_test_assert(strpos($moderation, 'data-atm-select-all') !== false, 'Bulk selection controls are missing.');
atm_test_assert(strpos($auditLog, "['export'] = 'csv'") !== false, 'Audit CSV export is missing.');
atm_test_assert(strpos($auditLog, 'atm_admin_audit_details') !== false, 'Human-readable audit details are missing.');
atm_test_assert(strpos($notifications, 'mark_all_read') !== false, 'Notification read state is missing.');
atm_test_assert(strpos($notifications, 'atm_admin_notifications_list') !== false, 'Notification history is missing.');
atm_test_assert(strpos($index, 'atm_admin_security_overview') !== false, 'The dashboard security block is missing.');
atm_test_assert(strpos($serverLib, '0700') !== false, 'Backup directory permissions are not hardened.');
atm_test_assert(strpos($serverLib, 'atm_server_config_validate_content') !== false, 'Server configuration syntax validation is missing.');
atm_test_assert(strpos($serverLib, 'atm_server_config_backup_limit') !== false, 'Configurable backup retention is missing from the library.');
atm_test_assert(strpos($securityLib, 'atm_admin_notifications_list') !== false, 'Notification table helpers are missing.');
atm_test_assert(strpos($securityLib, 'atm_admin_security_cleanup') !== false, 'Security retention cleanup is missing.');
atm_test_assert(strpos($securityLib, 'admin_notification_reads') !== false, 'Notification read markers are missing.');

atm_test_assert(is_array($language), 'The language file must return an array.');
if (!is_array($language)) {
	$language = array();
}

$translationFiles = array(
	'admin/security.php',
	'admin/profile.php',
	'admin/audit_log.php',
	'admin/moderation.php',
	'admin/notifications.php',
	'admin/errors_log.php',
	'admin/index.php',
	'admin/server_config.php',
	'admin/template/header.php',
	'admin/inc/adm_boot.php',
	'sys/inc/admin_security.lib.php',
	'sys/inc/server_config.lib.php',
	'admin/settings.php',
	'admin/exit.php',
);

$keys = array();
foreach ($translationFiles as $relative) {
	$text = atm_test_read($root . '/' . $relative);
	if ($text === false) continue;
	if (preg_match_all("/__\('([^']*)'\)/", $text, $matches)) {
		foreach ($matches[1] as $key) {
			$keys[$key] = true;
		}
	}
}
foreach (array_keys($keys) as $key) {
	atm_test_assert(array_key_exists($key, $language), 'Missing Russian translation: ' . $key);
}

if (!empty($failures)) {
	fwrite(STDERR, "Admin UI checks failed:\n");
	foreach ($failures as $failure) {
		fwrite(STDERR, ' - ' . $failure . "\n");
	}
	exit(1);
}

echo 'Admin UI checks passed (' . $assertions . " assertions).\n";
