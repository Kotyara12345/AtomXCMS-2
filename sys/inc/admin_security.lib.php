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

		try {
			$rows = $db->query('SHOW TABLES');
			$existing = array();
			foreach ($rows as $row) {
				$values = array_values((array)$row);
				if (!empty($values[0])) $existing[(string)$values[0]] = true;
			}
			if (isset($existing[$sessions], $existing[$audit], $existing[$attempts], $existing[$twofa])) {
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
		} catch (Exception $e) {
			// Security features are optional; never break the panel here.
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
			'settings_saved'       => __('Settings saved'),
			'login_attempts_clear' => __('Login attempts cleared'),
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
		} catch (Exception $e) {
			// Audit is best-effort.
		}
	}
}


if (!function_exists('atm_admin_audit_list')) {
	/**
	 * Read the audit trail.
	 *
	 * @param int $limit
	 * @param int $offset
	 * @param string $action
	 * @return array
	 */
	function atm_admin_audit_list($limit = 50, $offset = 0, $action = '')
	{
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return array();
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_audit');
		$where = '';
		if ($action !== '') {
			$where = " WHERE `action` = '" . $db->escape($action) . "'";
		}
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
	 * @return int
	 */
	function atm_admin_audit_count($action = '')
	{
		atm_admin_security_ensure_tables();
		$Register = Register::getInstance();
		if (empty($Register['DB'])) return 0;
		$db = $Register['DB'];
		$table = $db->getFullTableName('admin_audit');
		$where = '';
		if ($action !== '') {
			$where = " WHERE `action` = '" . $db->escape($action) . "'";
		}
		try {
			$rows = $db->query("SELECT COUNT(*) AS `cnt` FROM `{$table}`{$where}");
			return !empty($rows[0]['cnt']) ? (int)$rows[0]['cnt'] : 0;
		} catch (Exception $e) {
			return 0;
		}
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
