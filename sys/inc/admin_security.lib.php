<?php
##################################################
##												##
## @Author:       AtomX CMS                     ##
## @Version:      1.0                           ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Admin security                ##
##################################################

/**
 * Admin panel security helpers.
 *
 * The module keeps optional data in separate tables so it can be added to
 * an existing AtomX installation without changing the users table:
 *
 *  - admin_sessions       active admin sessions (revocation support);
 *  - admin_audit          security and configuration audit trail;
 *  - admin_login_attempts recent failed/successful login attempts;
 *  - admin_2fa            TOTP secrets and backup codes.
 *
 * All functions degrade gracefully when the database is unavailable: the
 * administration panel must keep working even if the tables can not be
 * created because of restricted database permissions.
 */

if (!defined('ROOT')) {
	exit;
}


if (!function_exists('atm_admin_security_ensure_tables')) {
	/**
	 * Create the security tables on demand.
	 *
	 * At most one SHOW TABLES query is executed per request. Fresh
	 * installations get the tables from install/do_queries.php; updated
	 * installations get them here.
	 */
	function atm_admin_security_ensure_tables()
	{
		static $checked = false;
		if ($checked) return;
		$checked = true;

		$Register = Register::getInstance();
		if (empty($Register['DB'])) return;

		$db = $Register['DB'];
		$sessions = $db->getFullTableName('admin_sessions');
		$audit = $db->getFullTableName('admin_audit');
		$attempts = $db->getFullTableName('admin_login_attempts');
		$twofa = $db->getFullTableName('admin_2fa');
		$notifications = $db->getFullTableName('admin_notifications');
		$notificationReads = $db->getFullTableName('admin_notification_reads');
		$notificationState = $db->getFullTableName('admin_notification_state');

		try {
			$rows = $db->query('SHOW TABLES');
			$existing = array();
			foreach ($rows as $row) {
				$values = array_values((array)$row);
				if (!empty($values[0])) $existing[(string)$values[0]] = true;
			}
			if (isset(
				$existing[$sessions],
				$existing[$audit],
				$existing[$attempts],
				$existing[$twofa],
				$existing[$notifications],
				$existing[$notificationReads],
				$existing[$notificationState]
			)) {
				return;
			}

			$db->query("CREATE TABLE IF NOT EXISTS `{$sessions}` (
				`id` int(11) NOT NULL auto_increment,
				`session_hash` char(64) NOT NULL,
				`user_id` int(11) NOT NULL default '0',
				`user_name` varchar(64) NOT NULL default '',
				`ip` varchar(64) NOT NULL default '',
				`user_agent` varchar(255) NOT NULL default '',
				`created` int(11) NOT NULL default '0',
				`last_seen` int(11) NOT NULL default '0',
				`revoked` tinyint(1) NOT NULL default '0',
				PRIMARY KEY (`id`),
				UNIQUE KEY `session_hash` (`session_hash`),
				KEY `user_id` (`user_id`),
				KEY `last_seen` (`last_seen`),
				KEY `revoked` (`revoked`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");

			$db->query("CREATE TABLE IF NOT EXISTS `{$audit}` (
				`id` int(11) NOT NULL auto_increment,
				`user_id` int(11) NOT NULL default '0',
				`user_name` varchar(64) NOT NULL default '',
				`action` varchar(64) NOT NULL default '',
				`details` text,
				`ip` varchar(64) NOT NULL default '',
				`user_agent` varchar(255) NOT NULL default '',
				`created` int(11) NOT NULL default '0',
				PRIMARY KEY (`id`),
				KEY `user_id` (`user_id`),
				KEY `action` (`action`),
				KEY `created` (`created`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");

			$db->query("CREATE TABLE IF NOT EXISTS `{$attempts}` (
				`id` int(11) NOT NULL auto_increment,
				`login` varchar(64) NOT NULL default '',
				`ip` varchar(64) NOT NULL default '',
				`success` tinyint(1) NOT NULL default '0',
				`created` int(11) NOT NULL default '0',
				PRIMARY KEY (`id`),
				KEY `login` (`login`),
				KEY `ip` (`ip`),
				KEY `created` (`created`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");

			$db->query("CREATE TABLE IF NOT EXISTS `{$twofa}` (
				`user_id` int(11) NOT NULL,
				`secret` varchar(64) NOT NULL default '',
				`enabled` tinyint(1) NOT NULL default '0',
				`backup_codes` text,
				`created` int(11) NOT NULL default '0',
				`updated` int(11) NOT NULL default '0',
				PRIMARY KEY (`user_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");

			$db->query("CREATE TABLE IF NOT EXISTS `{$notifications}` (
				`id` int(11) NOT NULL auto_increment,
				`user_id` int(11) NOT NULL default '0',
				`type` varchar(32) NOT NULL default '',
				`title` varchar(255) NOT NULL default '',
				`message` text,
				`url` varchar(255) NOT NULL default '',
				`created` int(11) NOT NULL default '0',
				PRIMARY KEY (`id`),
				KEY `user_id` (`user_id`),
				KEY `type` (`type`),
				KEY `created` (`created`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");

			$db->query("CREATE TABLE IF NOT EXISTS `{$notificationReads}` (
				`notification_id` int(11) NOT NULL,
				`user_id` int(11) NOT NULL,
				`read_at` int(11) NOT NULL default '0',
				PRIMARY KEY (`notification_id`, `user_id`),
				KEY `user_id` (`user_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");

			$db->query("CREATE TABLE IF NOT EXISTS `{$notificationState}` (
				`user_id` int(11) NOT NULL,
				`counts` text,
				`updated` int(11) NOT NULL default '0',
				PRIMARY KEY (`user_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");
		} catch (Exception $e) {
			// Security features are optional; never break the panel here.
		}
	}
}


if (!function_exists('atm_admin_send_security_headers')) {
	/**
	 * Send browser hardening headers for every admin response.
	 *
	 * The CSP is report-only: legacy modules may still include inline
	 * handlers or external preview resources. It documents the intended
	 * policy without breaking those modules during the transition.
	 */
	function atm_admin_send_security_headers()
	{
		if (headers_sent()) return;

		header('X-Content-Type-Options: nosniff');
		header('X-Frame-Options: SAMEORIGIN');
		header('X-XSS-Protection: 0');
		header('Referrer-Policy: same-origin');
		header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
		header('Cache-Control: no-store, private');
		header('Pragma: no-cache');
		header(
			'Content-Security-Policy-Report-Only: '
			. "default-src 'self' https: data: blob:; "
			. "script-src 'self' https: 'unsafe-inline' 'unsafe-eval'; "
			. "style-src 'self' https: 'unsafe-inline'; "
			. "img-src 'self' https: data: blob:; "
			. "font-src 'self' https: data:; "
			. "connect-src 'self' https:; "
			. "worker-src 'self' blob:; "
			. "child-src 'self' blob:; "
			. "object-src 'none'; "
			. "frame-src 'self' https:; "
			. "frame-ancestors 'self'; "
			. "base-uri 'self'; "
			. "form-action 'self'"
		);

		if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
			header('Strict-Transport-Security: max-age=31536000');
		}
	}
}


if (!function_exists('atm_admin_client_ip')) {
	/**
	 * Client IP address (without trusting proxy headers).
	 *
	 * @return string
	 */
	function atm_admin_client_ip()
	{
		$ip = !empty($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
		$ip = trim($ip);
		if (strlen($ip) > 64) $ip = substr($ip, 0, 64);
		return $ip;
	}
}


if (!function_exists('atm_admin_user_agent')) {
	/**
	 * Browser user agent, limited to the database column size.
	 *
	 * @return string
	 */
	function atm_admin_user_agent()
	{
		$ua = !empty($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
		$ua = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $ua));
		if (strlen($ua) > 255) $ua = substr($ua, 0, 255);
		return $ua;
	}
}


if (!function_exists('atm_admin_audit_actions')) {
	/**
	 * Human-readable labels for audit action keys.
	 *
	 * @return array
	 */
	function atm_admin_audit_actions()
	{
		return array(
			'login_success'        => __('Login successful'),
			'login_failed'         => __('Login failed'),
			'login_blocked'        => __('Login blocked'),
			'login_2fa_required'   => __('Two-factor code requested'),
			'login_2fa_failed'     => __('Invalid two-factor code'),
			'login_2fa_backup'     => __('Backup code used'),
			'logout'               => __('Logout'),
			'session_revoked'      => __('Session terminated'),
			'session_revoked_all'  => __('Other sessions terminated'),
			'session_denied'       => __('Revoked session rejected'),
			'2fa_enabled'          => __('Two-factor authentication enabled'),
			'2fa_disabled'         => __('Two-factor authentication disabled'),
			'2fa_codes'            => __('Backup codes regenerated'),
			'profile_updated'      => __('Profile updated'),
			'password_changed'     => __('Password changed'),
			'config_path_saved'    => __('Server configuration path saved'),
			'config_saved'         => __('Server configuration saved'),
			'config_restored'      => __('Server configuration restored'),
			'config_downloaded'    => __('Server configuration downloaded'),
			'config_validation_failed' => __('Server configuration check failed'),
			'config_validated'     => __('Server configuration checked'),
			'backup_limit_saved'   => __('Backup retention saved'),
			'settings_saved'       => __('Settings saved'),
			'login_attempts_clear' => __('Login attempts cleared'),
			'csrf_failed'          => __('CSRF check failed'),
			'security_cleanup'     => __('Security data cleanup'),
			'error_log_cleared'    => __('Error log cleared'),
			'error_log_rotated'    => __('Error log rotated'),
			'error_log_downloaded' => __('Error log downloaded'),
			'audit_export'         => __('Audit log exported'),
			'notifications_read'   => __('Notifications read'),
			'bulk_moderated'       => __('Bulk moderation'),
		);
	}
}


if (!function_exists('atm_admin_audit_label')) {
	/**
	 * @param string $action
	 * @return string
	 */
	function atm_admin_audit_label($action)
	{
		$labels = atm_admin_audit_actions();
		return isset($labels[$action]) ? $labels[$action] : (string)$action;
	}
}


if (!function_exists('atm_admin_audit_details')) {
	/**
	 * Turn technical audit details into a readable line.
	 *
	 * Details are stored as plain text or JSON. Only the technical labels
	 * are translated; values supplied by the administrator stay untouched.
	 *
	 * @param string $details
	 * @return string
	 */
	function atm_admin_audit_details($details)
	{
		$details = trim((string)$details);
		if ($details === '') return '';

		$labels = array(
			'password'         => __('Password'),
			'two-factor'       => __('Two-factor code'),
			'type'             => __('Type'),
			'file'             => __('File'),
			'backup'           => __('Backup'),
			'path'             => __('Path'),
			'login'            => __('Login'),
			'user id'          => __('User ID'),
			'ip'               => __('IP address'),
			'terminated'       => __('Sessions terminated'),
			'remaining'        => __('Backup codes remaining'),
			'new backup codes' => __('New backup codes'),
			'backup codes'     => __('Backup codes'),
			'uri'              => __('Address'),
			'module'           => __('Module'),
			'ids'              => __('IDs'),
			'decision'         => __('Decision'),
			'count'            => __('Quantity'),
			'profile'          => __('Profile'),
			'e-mail'           => __('E-mail'),
			'current session'  => __('Current session'),
			'yes'              => __('Yes'),
			'no'               => __('No'),
			'reason'           => __('Reason'),
			'sessions'         => __('Sessions'),
			'audit'            => __('Audit records'),
			'attempts'         => __('Login attempts'),
			'notifications'    => __('Notifications'),
			'rows'             => __('Rows'),
			'message'          => __('Message'),
			'action'           => __('Event'),
		);

		$decoded = json_decode($details, true);
		if (is_array($decoded)) {
			$parts = array();
			foreach ($decoded as $key => $value) {
				$key = strtolower(trim((string)$key));
				$label = isset($labels[$key]) ? $labels[$key] : $key;
				if (is_array($value) || is_object($value)) {
					$value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				}
				$value = trim((string)$value);
				$parts[] = $label . ': ' . $value;
			}
			return implode(' - ', $parts);
		}

		$details = preg_replace_callback(
			'/([a-z][a-z _-]{1,24})\((.*?)\)/i',
			function ($match) use ($labels) {
				$key = strtolower(trim($match[1]));
				$label = isset($labels[$key]) ? $labels[$key] : $match[1];
				return $label . ': ' . $match[2];
			},
			$details
		);
		$details = preg_replace_callback(
			'/([a-z][a-z _-]{1,24}):\s*([^,;]+)/i',
			function ($match) use ($labels) {
				$key = strtolower(trim($match[1]));
				$label = isset($labels[$key]) ? $labels[$key] : $match[1];
				return $label . ': ' . $match[2];
			},
			$details
		);

		return $details;
	}
}


if (!function_exists('atm_admin_audit')) {
	/**
	 * Add an event to the admin audit trail.
	 *
	 * @param string $action
	 * @param mixed $details
	 * @param int|null $userId
	 * @param string|null $userName
	 */
	function atm_admin_audit($action, $details = '', $userId = null, $userName = null)
	{
		$action = trim((string)$action);
		if ($action === '') return;

		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return;
		$db = $Register['DB'];

		if ($userId === null && !empty($_SESSION['user']['id'])) {
			$userId = (int)$_SESSION['user']['id'];
		}
		if ($userName === null && !empty($_SESSION['user']['name'])) {
			$userName = (string)$_SESSION['user']['name'];
		}

		if (is_array($details) || is_object($details)) {
			$details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		$details = (string)$details;
		if (strlen($details) > 60000) $details = substr($details, 0, 60000);

		$table = $db->getFullTableName('admin_audit');
		try {
			$db->query("INSERT INTO `{$table}` SET
				`user_id` = " . (int)$userId . ",
				`user_name` = '" . $db->escape((string)$userName) . "',
				`action` = '" . $db->escape($action) . "',
				`details` = '" . $db->escape($details) . "',
				`ip` = '" . $db->escape(atm_admin_client_ip()) . "',
				`user_agent` = '" . $db->escape(atm_admin_user_agent()) . "',
				`created` = " . time());

			if (function_exists('atm_admin_notification_from_audit')) {
				atm_admin_notification_from_audit($action, $details, (int)$userId, (string)$userName);
			}
		} catch (Exception $e) {
			// Audit is best-effort.
		}
	}
}


if (!function_exists('atm_admin_audit_filter_sql')) {
	/**
	 * Build the audit list WHERE clause from the legacy action filter
	 * and the additional admin filters.
	 *
	 * @param string $action
	 * @param array $filters
	 * @return string
	 */
	function atm_admin_audit_filter_sql($action = '', $filters = array())
	{
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return '';
		$db = $Register['DB'];

		$where = array();
		if ($action !== '') {
			$where[] = "`action` = '" . $db->escape((string)$action) . "'";
		}

		if (!empty($filters['user'])) {
			$where[] = "(`user_name` LIKE '%" . $db->escape((string)$filters['user']) . "%'"
				. " OR `user_id` = " . (int)$filters['user'] . ")";
		}
		if (!empty($filters['user_id'])) {
			$where[] = "`user_id` = " . (int)$filters['user_id'];
		}
		if (!empty($filters['q'])) {
			$q = $db->escape((string)$filters['q']);
			$where[] = "(`details` LIKE '%{$q}%' OR `user_name` LIKE '%{$q}%'"
				. " OR `ip` LIKE '%{$q}%' OR `action` LIKE '%{$q}%')";
		}
		if (!empty($filters['from'])) {
			$from = strtotime((string)$filters['from'] . ' 00:00:00');
			if ($from) $where[] = "`created` >= " . (int)$from;
		}
		if (!empty($filters['to'])) {
			$to = strtotime((string)$filters['to'] . ' 23:59:59');
			if ($to) $where[] = "`created` <= " . (int)$to;
		}

		return !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';
	}
}


if (!function_exists('atm_admin_audit_list')) {
	/**
	 * Read the audit trail.
	 *
	 * @param int $limit
	 * @param int $offset
	 * @param string $action
	 * @param array $filters
	 * @return array
	 */
	function atm_admin_audit_list($limit = 50, $offset = 0, $action = '', $filters = array())
	{
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return array();
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_audit');
		$where = atm_admin_audit_filter_sql($action, $filters);
		try {
			$rows = $db->query("SELECT * FROM `{$table}`{$where}
				ORDER BY `id` DESC LIMIT " . (int)$offset . ", " . (int)$limit);
			return !empty($rows) ? $rows : array();
		} catch (Exception $e) {
			return array();
		}
	}
}


if (!function_exists('atm_admin_audit_count')) {
	/**
	 * @param string $action
	 * @param array $filters
	 * @return int
	 */
	function atm_admin_audit_count($action = '', $filters = array())
	{
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return 0;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_audit');
		$where = atm_admin_audit_filter_sql($action, $filters);
		try {
			$rows = $db->query("SELECT COUNT(*) AS `cnt` FROM `{$table}`{$where}");
			return !empty($rows[0]['cnt']) ? (int)$rows[0]['cnt'] : 0;
		} catch (Exception $e) {
			return 0;
		}
	}
}


if (!function_exists('atm_admin_notification_add')) {
	/**
	 * Store an administrator notification.
	 *
	 * A user_id of zero means that the notification is visible to every
	 * administrator; positive values target one account.
	 *
	 * @param string $type
	 * @param string $title
	 * @param string $message
	 * @param string $url
	 * @param int $userId
	 * @return int|bool
	 */
	function atm_admin_notification_add($type, $title, $message = '', $url = '', $userId = 0)
	{
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return false;
		$db = $Register['DB'];

		$type = substr(trim((string)$type), 0, 32);
		$title = substr(trim((string)$title), 0, 255);
		$message = trim((string)$message);
		if ($title === '') return false;
		if (strlen($message) > 60000) $message = substr($message, 0, 60000);

		$url = trim((string)$url);
		if ($url !== '' && !preg_match('~^[a-z0-9_./?&=#%-]+$~i', $url)) {
			$url = '';
		}

		try {
			$id = $db->save('admin_notifications', array(
				'user_id' => (int)$userId,
				'type'    => $type,
				'title'   => $title,
				'message' => $message,
				'url'     => $url,
				'created' => time(),
			));
			return (int)$id;
		} catch (Exception $e) {
			return false;
		}
	}
}


if (!function_exists('atm_admin_notification_from_audit')) {
	/**
	 * Create a notification for important audit events.
	 *
	 * Ordinary login successes and profile changes are intentionally not
	 * duplicated here; the notification center is for events that need
	 * attention or are useful as a short security history.
	 *
	 * @param string $action
	 * @param string $details
	 * @param int $userId
	 * @param string $userName
	 */
	function atm_admin_notification_from_audit($action, $details, $userId, $userName)
	{
		$events = array(
			'login_blocked'           => array('warning', __('Login blocked'), 'security.php'),
			'login_2fa_failed'        => array('warning', __('Invalid two-factor code'), 'security.php'),
			'login_2fa_backup'        => array('warning', __('Backup code used'), 'security.php'),
			'session_denied'          => array('warning', __('Revoked session rejected'), 'security.php'),
			'session_revoked'         => array('warning', __('Session terminated'), 'security.php'),
			'session_revoked_all'     => array('warning', __('Other sessions terminated'), 'security.php'),
			'2fa_enabled'             => array('success', __('Two-factor authentication enabled'), 'security.php'),
			'2fa_disabled'            => array('warning', __('Two-factor authentication disabled'), 'security.php'),
			'2fa_codes'               => array('info', __('Backup codes regenerated'), 'security.php'),
			'password_changed'        => array('info', __('Password changed'), 'profile.php'),
			// Only superadmins can open these pages. Send every role to the
			// security center instead; it is available to all administrators.
			'config_path_saved'       => array('info', __('Server configuration path saved'), 'security.php'),
			'config_saved'            => array('warning', __('Server configuration saved'), 'security.php'),
			'config_restored'         => array('warning', __('Server configuration restored'), 'security.php'),
			'config_validation_failed' => array('error', __('Server configuration check failed'), 'security.php'),
			'backup_limit_saved'      => array('info', __('Backup retention saved'), 'security.php'),
			'csrf_failed'             => array('error', __('CSRF check failed'), 'security.php'),
			'security_cleanup'        => array('info', __('Security data cleanup'), 'security.php'),
			'error_log_cleared'       => array('info', __('Error log cleared'), 'security.php'),
			'error_log_rotated'       => array('info', __('Error log rotated'), 'security.php'),
			'audit_export'            => array('info', __('Audit log exported'), 'security.php'),
			'bulk_moderated'          => array('info', __('Bulk moderation'), 'moderation.php'),
		);

		if (!isset($events[$action])) return;
		$message = atm_admin_audit_details($details);
		if ($userName !== '') {
			$message = ($message !== '' ? $message . ' - ' : '') . __('User') . ': ' . $userName;
		}
		atm_admin_notification_add(
			$events[$action][0],
			$events[$action][1],
			$message,
			$events[$action][2],
			0
		);
	}
}


if (!function_exists('atm_admin_notifications_list')) {
	/**
	 * Notification history for one administrator.
	 *
	 * @param int $userId
	 * @param int $limit
	 * @param bool $onlyUnread
	 * @return array
	 */
	function atm_admin_notifications_list($userId, $limit = 30, $onlyUnread = false)
	{
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return array();
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_notifications');
		$reads = $db->getFullTableName('admin_notification_reads');
		$userId = (int)$userId;

		$where = "(`n`.`user_id` = 0 OR `n`.`user_id` = {$userId})";
		if ($onlyUnread) $where .= " AND `r`.`notification_id` IS NULL";

		try {
			$rows = $db->query("SELECT `n`.*, `r`.`read_at`
				FROM `{$table}` AS `n`
				LEFT JOIN `{$reads}` AS `r`
					ON `r`.`notification_id` = `n`.`id` AND `r`.`user_id` = {$userId}
				WHERE {$where}
				ORDER BY `n`.`created` DESC, `n`.`id` DESC
				LIMIT " . max(1, (int)$limit));
			return !empty($rows) ? $rows : array();
		} catch (Exception $e) {
			return array();
		}
	}
}


if (!function_exists('atm_admin_notifications_unread_count')) {
	/**
	 * @param int $userId
	 * @return int
	 */
	function atm_admin_notifications_unread_count($userId)
	{
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return 0;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_notifications');
		$reads = $db->getFullTableName('admin_notification_reads');
		$userId = (int)$userId;
		try {
			$rows = $db->query("SELECT COUNT(*) AS `cnt`
				FROM `{$table}` AS `n`
				LEFT JOIN `{$reads}` AS `r`
					ON `r`.`notification_id` = `n`.`id` AND `r`.`user_id` = {$userId}
				WHERE (`n`.`user_id` = 0 OR `n`.`user_id` = {$userId})
					AND `r`.`notification_id` IS NULL");
			return !empty($rows[0]['cnt']) ? (int)$rows[0]['cnt'] : 0;
		} catch (Exception $e) {
			return 0;
		}
	}
}


if (!function_exists('atm_admin_notifications_mark_read')) {
	/**
	 * Mark all visible notifications as read.
	 *
	 * @param int $userId
	 * @return bool
	 */
	function atm_admin_notifications_mark_read($userId)
	{
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return false;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_notifications');
		$reads = $db->getFullTableName('admin_notification_reads');
		$userId = (int)$userId;
		if ($userId < 1) return false;

		try {
			$db->query("INSERT IGNORE INTO `{$reads}` (`notification_id`, `user_id`, `read_at`)
				SELECT `id`, {$userId}, " . time() . " FROM `{$table}`
				WHERE `user_id` = 0 OR `user_id` = {$userId}");
			return true;
		} catch (Exception $e) {
			return false;
		}
	}
}


if (!function_exists('atm_admin_notification_state_get')) {
	/**
	 * Last moderation counter snapshot marked as read.
	 *
	 * @param int $userId
	 * @return array
	 */
	function atm_admin_notification_state_get($userId)
	{
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return array();
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_notification_state');
		$userId = (int)$userId;
		try {
			$rows = $db->query("SELECT * FROM `{$table}` WHERE `user_id` = {$userId} LIMIT 1");
			if (empty($rows)) return array();
			$counts = json_decode((string)$rows[0]['counts'], true);
			return is_array($counts) ? $counts : array();
		} catch (Exception $e) {
			return array();
		}
	}
}


if (!function_exists('atm_admin_notification_moderation_new')) {
	/**
	 * Difference between current pending counters and the last read snapshot.
	 *
	 * @param array $counts
	 * @param int $userId
	 * @return array
	 */
	function atm_admin_notification_moderation_new($counts, $userId)
	{
		$previous = atm_admin_notification_state_get($userId);
		$out = array();
		foreach (array('news', 'loads', 'stat', 'comments') as $key) {
			$current = !empty($counts[$key]) ? (int)$counts[$key] : 0;
			$before = !empty($previous[$key]) ? (int)$previous[$key] : 0;
			$out[$key] = max(0, $current - $before);
		}
		return $out;
	}
}


if (!function_exists('atm_admin_notification_mark_all_read')) {
	/**
	 * Mark notifications and the current moderation snapshot as read.
	 *
	 * @param int $userId
	 * @param array $counts
	 * @return bool
	 */
	function atm_admin_notification_mark_all_read($userId, $counts)
	{
		$userId = (int)$userId;
		if ($userId < 1) return false;
		atm_admin_notifications_mark_read($userId);

		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return false;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_notification_state');
		$snapshot = array();
		foreach (array('news', 'loads', 'stat', 'comments') as $key) {
			$snapshot[$key] = !empty($counts[$key]) ? (int)$counts[$key] : 0;
		}

		try {
			$db->query("INSERT INTO `{$table}` SET
				`user_id` = {$userId},
				`counts` = '" . $db->escape(json_encode($snapshot)) . "',
				`updated` = " . time() . "
				ON DUPLICATE KEY UPDATE
					`counts` = VALUES(`counts`),
					`updated` = VALUES(`updated`)");
			return true;
		} catch (Exception $e) {
			return false;
		}
	}
}


if (!function_exists('atm_admin_notification_summary')) {
	/**
	 * Compact counters for the header bell.
	 *
	 * @param array $counts
	 * @param int $userId
	 * @return array
	 */
	function atm_admin_notification_summary($counts, $userId)
	{
		$moderation = atm_admin_notification_moderation_new($counts, $userId);
		$security = atm_admin_notifications_unread_count($userId);
		return array(
			'moderation' => $moderation,
			'moderation_total' => array_sum($moderation),
			'security' => $security,
			'total' => array_sum($moderation) + $security,
		);
	}
}


if (!function_exists('atm_admin_notification_cleanup')) {
	/**
	 * Remove old notification records and their read markers.
	 *
	 * @param int $days
	 * @return int
	 */
	function atm_admin_notification_cleanup($days = 90)
	{
		$days = max(7, (int)$days);
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return 0;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_notifications');
		$reads = $db->getFullTableName('admin_notification_reads');
		$cutoff = time() - $days * 86400;
		$removed = 0;
		try {
			$rows = $db->query("SELECT COUNT(*) AS `cnt` FROM `{$table}` WHERE `created` < {$cutoff}");
			$removed = !empty($rows[0]['cnt']) ? (int)$rows[0]['cnt'] : 0;
			$db->query("DELETE FROM `{$reads}` WHERE `notification_id` IN
				(SELECT `id` FROM `{$table}` WHERE `created` < {$cutoff})");
			$db->query("DELETE FROM `{$table}` WHERE `created` < {$cutoff}");
		} catch (Exception $e) {
			return 0;
		}
		return $removed;
	}
}


if (!function_exists('atm_admin_security_retention_days')) {
	/**
	 * Read a retention period from the secure configuration.
	 *
	 * @param string $key
	 * @param int $default
	 * @return int
	 */
	function atm_admin_security_retention_days($key, $default)
	{
		$value = $default;
		if (class_exists('Config')) {
			$value = (int)Config::read($key, 'secure');
		}
		if ($value < 1) $value = (int)$default;
		return $value;
	}
}


if (!function_exists('atm_admin_security_cleanup')) {
	/**
	 * Remove expired security data.
	 *
	 * The cleanup is deliberately conservative and runs at most once per
	 * day for normal panel requests. The security page can force a run.
	 *
	 * @param bool $force
	 * @return array
	 */
	function atm_admin_security_cleanup($force = false)
	{
		$today = date('Y-m-d');
		if (!$force && !empty($_SESSION['admin_security_cleanup_day'])
			&& $_SESSION['admin_security_cleanup_day'] === $today) {
			return array();
		}
		$_SESSION['admin_security_cleanup_day'] = $today;

		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return array();
		$db = $Register['DB'];

		$sessions = $db->getFullTableName('admin_sessions');
		$audit = $db->getFullTableName('admin_audit');
		$attempts = $db->getFullTableName('admin_login_attempts');

		$sessionDays = atm_admin_security_retention_days('admin_session_retention', 30);
		$revokedDays = atm_admin_security_retention_days('admin_revoked_retention', 7);
		$auditDays = atm_admin_security_retention_days('admin_audit_retention', 180);
		$attemptDays = atm_admin_security_retention_days('admin_attempt_retention', 30);
		$notificationDays = atm_admin_security_retention_days('admin_notification_retention', 90);

		$result = array(
			'sessions' => 0,
			'audit' => 0,
			'attempts' => 0,
			'notifications' => 0,
		);

		try {
			$rows = $db->query("SELECT COUNT(*) AS `cnt` FROM `{$sessions}`
				WHERE `last_seen` < " . (time() - $sessionDays * 86400) . "
					OR (`revoked` = 1 AND `last_seen` < " . (time() - $revokedDays * 86400) . ")");
			$result['sessions'] = !empty($rows[0]['cnt']) ? (int)$rows[0]['cnt'] : 0;
			$db->query("DELETE FROM `{$sessions}`
				WHERE `last_seen` < " . (time() - $sessionDays * 86400) . "
					OR (`revoked` = 1 AND `last_seen` < " . (time() - $revokedDays * 86400) . ")");

			$rows = $db->query("SELECT COUNT(*) AS `cnt` FROM `{$audit}`
				WHERE `created` < " . (time() - $auditDays * 86400));
			$result['audit'] = !empty($rows[0]['cnt']) ? (int)$rows[0]['cnt'] : 0;
			$db->query("DELETE FROM `{$audit}` WHERE `created` < " . (time() - $auditDays * 86400));

			$rows = $db->query("SELECT COUNT(*) AS `cnt` FROM `{$attempts}`
				WHERE `created` < " . (time() - $attemptDays * 86400));
			$result['attempts'] = !empty($rows[0]['cnt']) ? (int)$rows[0]['cnt'] : 0;
			$db->query("DELETE FROM `{$attempts}` WHERE `created` < " . (time() - $attemptDays * 86400));

			$result['notifications'] = atm_admin_notification_cleanup($notificationDays);
		} catch (Exception $e) {
			return $result;
		}

		if ($force || array_sum($result) > 0) {
			atm_admin_audit(
				'security_cleanup',
				'sessions: ' . $result['sessions']
				. ', audit: ' . $result['audit']
				. ', attempts: ' . $result['attempts']
				. ', notifications: ' . $result['notifications']
			);
		}

		return $result;
	}
}


if (!function_exists('atm_admin_security_overview')) {
	/**
	 * Security status for the dashboard.
	 *
	 * @param array $user
	 * @return array
	 */
	function atm_admin_security_overview($user = null)
	{
		if (empty($user) && !empty($_SESSION['user'])) $user = $_SESSION['user'];
		if (empty($user['id'])) return array('warnings' => array(), 'items' => array());

		$userId = (int)$user['id'];
		$login = isset($user['name']) ? (string)$user['name'] : '';
		$isSuperAdmin = !empty($user['status']) && (int)$user['status'] === 4;
		$auditUrl = $isSuperAdmin ? 'audit_log.php' : 'security.php';
		$errorLogUrl = $isSuperAdmin ? 'errors_log.php' : 'security.php';
		$serverConfigUrl = $isSuperAdmin ? 'server_config.php' : 'security.php';
		$warnings = array();

		$twofaEnabled = atm_admin_2fa_enabled($userId);
		if (!$twofaEnabled) {
			$warnings[] = array(
				'code' => '2fa',
				'label' => __('Two-factor authentication is disabled.'),
				'url' => 'security.php',
				'weight' => 2,
			);
		}

		$passwordHash = isset($user['passw']) ? (string)$user['passw'] : '';
		$commonPassword = false;
		if ($passwordHash !== '' && function_exists('atm_password_verify')) {
			$commonPassword = atm_password_verify('admin', $passwordHash)
				|| ($login !== '' && atm_password_verify($login, $passwordHash));
		}
		if ($commonPassword) {
			$warnings[] = array(
				'code' => 'password',
				'label' => __('A common password is used. Change it in your profile.'),
				'url' => 'profile.php',
				'weight' => 3,
			);
		} elseif ($passwordHash !== '' && function_exists('atm_password_needs_rehash')
			&& atm_password_needs_rehash($passwordHash)) {
			$warnings[] = array(
				'code' => 'password_hash',
				'label' => __('The password hash is outdated. Change the password to update it.'),
				'url' => 'profile.php',
				'weight' => 1,
			);
		}

		$sessions = atm_admin_session_list($userId, 100);
		$lastLogin = atm_admin_audit_list(1, 0, 'login_success', array('user_id' => $userId));
		$errorLog = ROOT . DS . 'sys' . DS . 'logs' . DS . 'php_errors.log';
		$errorLogSize = @is_file($errorLog) ? (int)@filesize($errorLog) : 0;
		if ($errorLogSize > 0) {
			$warnings[] = array(
				'code' => 'errors',
				'label' => sprintf(__('The PHP error log is not empty (%s).'), atm_admin_format_size($errorLogSize)),
				'url' => $errorLogUrl,
				'weight' => 1,
			);
		}

		$backupDir = ROOT . DS . 'sys' . DS . 'logs' . DS . 'server_config_backups';
		if (@is_dir($backupDir) && DIRECTORY_SEPARATOR === '/') {
			$perms = @fileperms($backupDir);
			if ($perms !== false && ($perms & 0777) !== 0700) {
				$warnings[] = array(
					'code' => 'backup_permissions',
					'label' => __('The server configuration backup directory has too broad permissions.'),
					'url' => $serverConfigUrl,
					'weight' => 1,
				);
			}
		}

		$items = array(
			array(
				'label' => __('Two-factor authentication'),
				'value' => $twofaEnabled ? __('Enabled') : __('Disabled'),
				'class' => $twofaEnabled ? 'ok' : 'warn',
				'url' => 'security.php',
			),
			array(
				'label' => __('Active sessions'),
				'value' => (string)count($sessions),
				'class' => count($sessions) > 3 ? 'warn' : 'ok',
				'url' => 'security.php',
			),
			array(
				'label' => __('Last successful sign-in'),
				'value' => !empty($lastLogin[0]['created'])
					? date('d.m.Y H:i', (int)$lastLogin[0]['created'])
					: __('No data'),
				'class' => 'ok',
				'url' => $auditUrl,
			),
			array(
				'label' => __('PHP error log'),
				'value' => $errorLogSize > 0 ? atm_admin_format_size($errorLogSize) : __('Empty'),
				'class' => $errorLogSize > 0 ? 'warn' : 'ok',
				'url' => $errorLogUrl,
			),
		);

		return array(
			'warnings' => $warnings,
			'items' => $items,
			'score' => count($warnings),
		);
	}
}


if (!function_exists('atm_admin_format_size')) {
	/**
	 * @param int $bytes
	 * @return string
	 */
	function atm_admin_format_size($bytes)
	{
		$bytes = (int)$bytes;
		if ($bytes < 1024) return $bytes . ' B';
		if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
		return round($bytes / 1048576, 1) . ' MB';
	}
}


if (!function_exists('atm_admin_session_hash')) {
	/**
	 * @param string|null $sessionId
	 * @return string
	 */
	function atm_admin_session_hash($sessionId = null)
	{
		if ($sessionId === null) $sessionId = session_id();
		$sessionId = (string)$sessionId;
		return $sessionId !== '' ? hash('sha256', $sessionId) : '';
	}
}


if (!function_exists('atm_admin_session_touch')) {
	/**
	 * Register or refresh the current admin session.
	 *
	 * @param array $user
	 * @return bool
	 */
	function atm_admin_session_touch($user)
	{
		if (empty($user['id'])) return false;
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return false;
		$db = $Register['DB'];
		$hash = atm_admin_session_hash();
		if ($hash === '') return false;

		$table = $db->getFullTableName('admin_sessions');
		$now = time();
		try {
			$db->query("INSERT INTO `{$table}` SET
				`session_hash` = '" . $db->escape($hash) . "',
				`user_id` = " . (int)$user['id'] . ",
				`user_name` = '" . $db->escape((string)(isset($user['name']) ? $user['name'] : '')) . "',
				`ip` = '" . $db->escape(atm_admin_client_ip()) . "',
				`user_agent` = '" . $db->escape(atm_admin_user_agent()) . "',
				`created` = " . $now . ",
				`last_seen` = " . $now . ",
				`revoked` = 0
				ON DUPLICATE KEY UPDATE
					`user_id` = VALUES(`user_id`),
					`user_name` = VALUES(`user_name`),
					`ip` = VALUES(`ip`),
					`user_agent` = VALUES(`user_agent`),
					`last_seen` = VALUES(`last_seen`)");
			return true;
		} catch (Exception $e) {
			return false;
		}
	}
}


if (!function_exists('atm_admin_session_is_revoked')) {
	/**
	 * Check whether the current (or supplied) session was revoked.
	 *
	 * @param string|null $sessionId
	 * @return bool
	 */
	function atm_admin_session_is_revoked($sessionId = null)
	{
		$hash = atm_admin_session_hash($sessionId);
		if ($hash === '') return false;
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return false;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_sessions');
		try {
			$rows = $db->query("SELECT `revoked` FROM `{$table}`
				WHERE `session_hash` = '" . $db->escape($hash) . "' LIMIT 1");
			return !empty($rows[0]['revoked']);
		} catch (Exception $e) {
			return false;
		}
	}
}


if (!function_exists('atm_admin_session_list')) {
	/**
	 * List active sessions.
	 *
	 * @param int|null $userId
	 * @param int $limit
	 * @return array
	 */
	function atm_admin_session_list($userId = null, $limit = 100)
	{
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return array();
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_sessions');
		$where = " WHERE `revoked` = 0 AND `last_seen` >= " . (time() - 2592000);
		if ($userId !== null) {
			$where .= " AND `user_id` = " . (int)$userId;
		}
		try {
			$rows = $db->query("SELECT * FROM `{$table}`{$where}
				ORDER BY `last_seen` DESC LIMIT " . (int)$limit);
			return !empty($rows) ? $rows : array();
		} catch (Exception $e) {
			return array();
		}
	}
}


if (!function_exists('atm_admin_session_revoke')) {
	/**
	 * Revoke a session by its hash.
	 *
	 * @param string $hash
	 * @param int|null $userId
	 * @param array|null $found
	 * @return bool
	 */
	function atm_admin_session_revoke($hash, $userId = null, &$found = null)
	{
		$found = null;
		$hash = strtolower(trim((string)$hash));
		if (!preg_match('/^[a-f0-9]{64}$/', $hash)) return false;

		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return false;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_sessions');
		$where = "`session_hash` = '" . $db->escape($hash) . "'";
		if ($userId !== null) {
			$where .= " AND `user_id` = " . (int)$userId;
		}
		try {
			$rows = $db->query("SELECT * FROM `{$table}` WHERE {$where} LIMIT 1");
			if (empty($rows)) return false;
			$found = $rows[0];
			$db->query("UPDATE `{$table}` SET `revoked` = 1, `last_seen` = " . time() . " WHERE {$where}");
			return true;
		} catch (Exception $e) {
			return false;
		}
	}
}


if (!function_exists('atm_admin_session_revoke_current')) {
	/**
	 * Revoke the current session.
	 *
	 * @return bool
	 */
	function atm_admin_session_revoke_current()
	{
		$userId = !empty($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
		return atm_admin_session_revoke(atm_admin_session_hash(), $userId);
	}
}


if (!function_exists('atm_admin_session_revoke_others')) {
	/**
	 * Revoke all sessions of a user except the current one.
	 *
	 * @param int $userId
	 * @return int number of affected rows (best effort)
	 */
	function atm_admin_session_revoke_others($userId)
	{
		$userId = (int)$userId;
		if ($userId < 1) return 0;
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return 0;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_sessions');
		$current = atm_admin_session_hash();
		try {
			$rows = $db->query("SELECT COUNT(*) AS `cnt` FROM `{$table}`
				WHERE `user_id` = {$userId} AND `revoked` = 0 AND `session_hash` <> '" . $db->escape($current) . "'");
			$db->query("UPDATE `{$table}` SET `revoked` = 1
				WHERE `user_id` = {$userId} AND `revoked` = 0 AND `session_hash` <> '" . $db->escape($current) . "'");
			return !empty($rows[0]['cnt']) ? (int)$rows[0]['cnt'] : 0;
		} catch (Exception $e) {
			return 0;
		}
	}
}


if (!function_exists('atm_admin_login_attempt_record')) {
	/**
	 * Store a login attempt. Successful logins reset recent failures.
	 *
	 * @param string $login
	 * @param string $ip
	 * @param bool $success
	 */
	function atm_admin_login_attempt_record($login, $ip, $success)
	{
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_login_attempts');
		$login = substr(trim((string)$login), 0, 64);
		$ip = substr(trim((string)$ip), 0, 64);
		try {
			if ($success) {
				$db->query("DELETE FROM `{$table}`
					WHERE (`login` = '" . $db->escape($login) . "'
						OR `ip` = '" . $db->escape($ip) . "') AND `success` = 0");
			}
			$db->query("INSERT INTO `{$table}` SET
				`login` = '" . $db->escape($login) . "',
				`ip` = '" . $db->escape($ip) . "',
				`success` = " . ($success ? 1 : 0) . ",
				`created` = " . time());

			// Keep the table from growing without a limit.
			if (mt_rand(1, 20) === 1) {
				$db->query("DELETE FROM `{$table}` WHERE `created` < " . (time() - 2592000));
			}
		} catch (Exception $e) {
			// Best effort.
		}
	}
}


if (!function_exists('atm_admin_login_block_remaining')) {
	/**
	 * Seconds until the next login attempt is allowed.
	 *
	 * @param string $login
	 * @param string $ip
	 * @return int
	 */
	function atm_admin_login_block_remaining($login, $ip)
	{
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return 0;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_login_attempts');
		$login = substr(trim((string)$login), 0, 64);
		$ip = substr(trim((string)$ip), 0, 64);
		try {
			$rows = $db->query("SELECT COUNT(*) AS `cnt`, MAX(`created`) AS `last_failed`
				FROM `{$table}`
				WHERE `success` = 0
					AND `created` >= " . (time() - 900) . "
					AND (`login` = '" . $db->escape($login) . "'
						OR `ip` = '" . $db->escape($ip) . "')");
			if (empty($rows[0]['cnt']) || (int)$rows[0]['cnt'] < 5) return 0;
			$remaining = 900 - (time() - (int)$rows[0]['last_failed']);
			return $remaining > 0 ? $remaining : 0;
		} catch (Exception $e) {
			return 0;
		}
	}
}


if (!function_exists('atm_admin_login_failures')) {
	/**
	 * Recent failed login attempts.
	 *
	 * @param int $limit
	 * @return array
	 */
	function atm_admin_login_failures($limit = 50)
	{
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return array();
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_login_attempts');
		try {
			$rows = $db->query("SELECT * FROM `{$table}`
				WHERE `success` = 0
				ORDER BY `created` DESC LIMIT " . (int)$limit);
			return !empty($rows) ? $rows : array();
		} catch (Exception $e) {
			return array();
		}
	}
}


if (!function_exists('atm_admin_login_clear_failures')) {
	/**
	 * Remove failed login attempts.
	 */
	function atm_admin_login_clear_failures()
	{
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_login_attempts');
		try {
			$db->query("DELETE FROM `{$table}` WHERE `success` = 0");
		} catch (Exception $e) {}
	}
}


if (!function_exists('atm_admin_base32_alphabet')) {
	/**
	 * @return string
	 */
	function atm_admin_base32_alphabet()
	{
		return 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	}
}


if (!function_exists('atm_admin_base32_encode')) {
	/**
	 * Base32 encoding without padding (RFC 4648 alphabet).
	 *
	 * @param string $data
	 * @return string
	 */
	function atm_admin_base32_encode($data)
	{
		$alphabet = atm_admin_base32_alphabet();
		$out = '';
		$buffer = 0;
		$bits = 0;
		$length = strlen($data);
		for ($i = 0; $i < $length; $i++) {
			$buffer = ($buffer << 8) | ord($data[$i]);
			$bits += 8;
			while ($bits >= 5) {
				$bits -= 5;
				$out .= $alphabet[($buffer >> $bits) & 31];
			}
		}
		if ($bits > 0) {
			$out .= $alphabet[($buffer << (5 - $bits)) & 31];
		}
		return $out;
	}
}


if (!function_exists('atm_admin_base32_decode')) {
	/**
	 * Base32 decoding.
	 *
	 * @param string $data
	 * @return string
	 */
	function atm_admin_base32_decode($data)
	{
		$alphabet = atm_admin_base32_alphabet();
		$data = strtoupper(preg_replace('/[^A-Z2-7]/i', '', (string)$data));
		$out = '';
		$buffer = 0;
		$bits = 0;
		$length = strlen($data);
		for ($i = 0; $i < $length; $i++) {
			$value = strpos($alphabet, $data[$i]);
			if ($value === false) continue;
			$buffer = ($buffer << 5) | $value;
			$bits += 5;
			if ($bits >= 8) {
				$bits -= 8;
				$out .= chr(($buffer >> $bits) & 255);
			}
		}
		return $out;
	}
}


if (!function_exists('atm_admin_totp_secret')) {
	/**
	 * Generate a random TOTP secret.
	 *
	 * @param int $bytes
	 * @return string
	 */
	function atm_admin_totp_secret($bytes = 20)
	{
		$bytes = max(10, min(32, (int)$bytes));
		return atm_admin_base32_encode(random_bytes($bytes));
	}
}


if (!function_exists('atm_admin_totp_code')) {
	/**
	 * Generate a TOTP code.
	 *
	 * @param string $secret
	 * @param int|null $time
	 * @param int $period
	 * @param int $digits
	 * @return string
	 */
	function atm_admin_totp_code($secret, $time = null, $period = 30, $digits = 6)
	{
		$key = atm_admin_base32_decode($secret);
		if ($key === '') return '';
		if ($time === null) $time = time();
		$period = max(10, (int)$period);
		$digits = max(6, min(8, (int)$digits));
		$counter = (int)floor((int)$time / $period);
		$binary = pack('N2', 0, $counter);
		$hash = hash_hmac('sha1', $binary, $key, true);
		$offset = ord($hash[19]) & 0x0f;
		$value = ((ord($hash[$offset]) & 0x7f) << 24)
			| ((ord($hash[$offset + 1]) & 0xff) << 16)
			| ((ord($hash[$offset + 2]) & 0xff) << 8)
			| (ord($hash[$offset + 3]) & 0xff);
		$modulo = (int)pow(10, $digits);
		return str_pad((string)($value % $modulo), $digits, '0', STR_PAD_LEFT);
	}
}


if (!function_exists('atm_admin_totp_verify')) {
	/**
	 * Verify a TOTP code with a small clock window.
	 *
	 * @param string $secret
	 * @param string $code
	 * @param int $window
	 * @param int $period
	 * @return bool
	 */
	function atm_admin_totp_verify($secret, $code, $window = 1, $period = 30)
	{
		$code = preg_replace('/\D/', '', (string)$code);
		if (strlen($code) !== 6 && strlen($code) !== 8) return false;
		$window = max(0, (int)$window);
		$period = max(10, (int)$period);
		for ($i = -$window; $i <= $window; $i++) {
			$expected = atm_admin_totp_code($secret, time() + ($i * $period), $period, strlen($code));
			if ($expected !== '' && hash_equals($expected, $code)) {
				return true;
			}
		}
		return false;
	}
}


if (!function_exists('atm_admin_2fa_get')) {
	/**
	 * Read the 2FA row of a user.
	 *
	 * @param int $userId
	 * @return array|null
	 */
	function atm_admin_2fa_get($userId)
	{
		$userId = (int)$userId;
		if ($userId < 1) return null;
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return null;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_2fa');
		try {
			$rows = $db->query("SELECT * FROM `{$table}` WHERE `user_id` = {$userId} LIMIT 1");
			return !empty($rows) ? $rows[0] : null;
		} catch (Exception $e) {
			return null;
		}
	}
}


if (!function_exists('atm_admin_2fa_enabled')) {
	/**
	 * @param int $userId
	 * @return bool
	 */
	function atm_admin_2fa_enabled($userId)
	{
		$row = atm_admin_2fa_get($userId);
		return !empty($row['enabled']) && !empty($row['secret']);
	}
}


if (!function_exists('atm_admin_2fa_save')) {
	/**
	 * Create or update a 2FA row.
	 *
	 * @param int $userId
	 * @param string $secret
	 * @param bool $enabled
	 * @param string $backupCodesJson
	 * @return bool
	 */
	function atm_admin_2fa_save($userId, $secret, $enabled = false, $backupCodesJson = '')
	{
		$userId = (int)$userId;
		if ($userId < 1) return false;
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return false;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_2fa');
		$now = time();
		try {
			$db->query("INSERT INTO `{$table}` SET
				`user_id` = {$userId},
				`secret` = '" . $db->escape((string)$secret) . "',
				`enabled` = " . ($enabled ? 1 : 0) . ",
				`backup_codes` = '" . $db->escape((string)$backupCodesJson) . "',
				`created` = {$now},
				`updated` = {$now}
				ON DUPLICATE KEY UPDATE
					`secret` = VALUES(`secret`),
					`enabled` = VALUES(`enabled`),
					`backup_codes` = VALUES(`backup_codes`),
					`updated` = VALUES(`updated`)");
			return true;
		} catch (Exception $e) {
			return false;
		}
	}
}


if (!function_exists('atm_admin_2fa_delete')) {
	/**
	 * Disable and remove 2FA data.
	 *
	 * @param int $userId
	 * @return bool
	 */
	function atm_admin_2fa_delete($userId)
	{
		$userId = (int)$userId;
		if ($userId < 1) return false;
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return false;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_2fa');
		try {
			$db->query("DELETE FROM `{$table}` WHERE `user_id` = {$userId}");
			return true;
		} catch (Exception $e) {
			return false;
		}
	}
}


if (!function_exists('atm_admin_2fa_backup_codes_generate')) {
	/**
	 * Generate readable backup codes.
	 *
	 * @param int $count
	 * @return array
	 */
	function atm_admin_2fa_backup_codes_generate($count = 8)
	{
		$count = max(1, min(20, (int)$count));
		$codes = array();
		for ($i = 0; $i < $count; $i++) {
			$raw = strtoupper(bin2hex(random_bytes(4)));
			$codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
		}
		return $codes;
	}
}


if (!function_exists('atm_admin_2fa_backup_codes_hash')) {
	/**
	 * Hash backup codes for storage.
	 *
	 * @param array $codes
	 * @return string JSON
	 */
	function atm_admin_2fa_backup_codes_hash($codes)
	{
		$hashes = array();
		foreach ((array)$codes as $code) {
			$normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$code));
			if ($normalized === '') continue;
			$hashes[] = atm_password_hash($normalized);
		}
		return json_encode($hashes);
	}
}


if (!function_exists('atm_admin_2fa_verify_code')) {
	/**
	 * Verify a TOTP or backup code for a user.
	 *
	 * When a backup code is used it is removed from the stored list.
	 *
	 * @param int $userId
	 * @param string $code
	 * @param bool|null $usedBackup
	 * @param int|null $remainingBackupCodes
	 * @return bool
	 */
	function atm_admin_2fa_verify_code($userId, $code, &$usedBackup = null, &$remainingBackupCodes = null)
	{
		$usedBackup = false;
		$remainingBackupCodes = null;
		$row = atm_admin_2fa_get($userId);
		if (empty($row) || empty($row['enabled']) || empty($row['secret'])) return false;

		if (atm_admin_totp_verify($row['secret'], $code)) {
			return true;
		}

		$normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$code));
		if ($normalized === '') return false;
		$hashes = json_decode((string)$row['backup_codes'], true);
		if (!is_array($hashes)) return false;

		foreach ($hashes as $index => $hash) {
			if (atm_password_verify($normalized, $hash)) {
				unset($hashes[$index]);
				$hashes = array_values($hashes);
				$remainingBackupCodes = count($hashes);
				atm_admin_2fa_save(
					$userId,
					$row['secret'],
					true,
					json_encode($hashes)
				);
				$usedBackup = true;
				return true;
			}
		}
		return false;
	}
}


if (!function_exists('atm_admin_2fa_otpauth_url')) {
	/**
	 * otpauth:// URL for authenticator applications.
	 *
	 * @param string $secret
	 * @param string $account
	 * @param string $issuer
	 * @return string
	 */
	function atm_admin_2fa_otpauth_url($secret, $account, $issuer = 'AtomX')
	{
		$issuer = (string)$issuer;
		$account = (string)$account;
		return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
			. '?secret=' . rawurlencode((string)$secret)
			. '&issuer=' . rawurlencode($issuer)
			. '&algorithm=SHA1&digits=6&period=30';
	}
}


if (!function_exists('atm_admin_2fa_pending_set')) {
	/**
	 * Start the second login step.
	 *
	 * @param int $userId
	 * @param string $login
	 * @param bool $remember
	 */
	function atm_admin_2fa_pending_set($userId, $login, $remember = false)
	{
		$_SESSION['atm_admin_2fa_pending'] = array(
			'user_id' => (int)$userId,
			'login' => (string)$login,
			'remember' => (bool)$remember,
			'created' => time(),
			'expires' => time() + 600,
		);
	}
}


if (!function_exists('atm_admin_2fa_pending_get')) {
	/**
	 * @return array|null
	 */
	function atm_admin_2fa_pending_get()
	{
		if (empty($_SESSION['atm_admin_2fa_pending'])) return null;
		$pending = $_SESSION['atm_admin_2fa_pending'];
		if (empty($pending['expires']) || (int)$pending['expires'] < time()) {
			unset($_SESSION['atm_admin_2fa_pending']);
			return null;
		}
		return $pending;
	}
}


if (!function_exists('atm_admin_2fa_pending_clear')) {
	/**
	 * Cancel the second login step.
	 */
	function atm_admin_2fa_pending_clear()
	{
		unset($_SESSION['atm_admin_2fa_pending']);
	}
}


if (!function_exists('atm_admin_notification_counts')) {
	/**
	 * Moderation counters for the header bell.
	 *
	 * The result is cached in the session for a minute to avoid running
	 * five count queries on every admin page request.
	 *
	 * @return array
	 */
	function atm_admin_notification_counts()
	{
		$cache = isset($_SESSION['admin_notification_counts'])
			? $_SESSION['admin_notification_counts'] : null;
		if (!empty($cache['ts']) && (int)$cache['ts'] > time() - 60) {
			return $cache;
		}

		$counts = array(
			'news' => 0,
			'loads' => 0,
			'stat' => 0,
			'comments' => 0,
			'ts' => time(),
		);

		$Register = Register::getInstance();
		if (!empty($Register['DB'])) {
			$db = $Register['DB'];
			foreach (array('news', 'loads', 'stat') as $module) {
				try {
					$counts[$module] = (int)$db->select(
						$module,
						DB_COUNT,
						array('cond' => array('premoder' => 'nochecked'))
					);
				} catch (Exception $e) {
					$counts[$module] = 0;
				}
			}
			try {
				$counts['comments'] = (int)$db->select(
					'comments',
					DB_COUNT,
					array('cond' => array('premoder' => 'nochecked'))
				);
			} catch (Exception $e) {
				$counts['comments'] = 0;
			}
		}

		$_SESSION['admin_notification_counts'] = $counts;
		return $counts;
	}
}


if (!function_exists('atm_admin_notification_clear_cache')) {
	/**
	 * Force the header counters to be recalculated on the next request.
	 */
	function atm_admin_notification_clear_cache()
	{
		unset($_SESSION['admin_notification_counts']);
	}
}
