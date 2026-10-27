<?php
/**
 * MySQL-backed integration checks for the admin security helpers.
 *
 * Run locally when a test database is available:
 *
 *     ATM_TEST_DSN="mysql:host=127.0.0.1;dbname=atomx_test;charset=utf8mb4" \
 *     ATM_TEST_USER=root ATM_TEST_PASS=secret \
 *     php tests/admin-security-integration-test.php
 *
 * In CI the database is provided by the service container and
 * ATM_TEST_REQUIRED=1 turns an unavailable database into a failure.
 * All tables use a unique prefix and are removed at the end of the run.
 */

$root = dirname(__DIR__);
$required = getenv('ATM_TEST_REQUIRED') === '1';
$dsn = getenv('ATM_TEST_DSN');
if ($dsn === false || $dsn === '') {
	$dsn = 'mysql:host=127.0.0.1;port=3306;dbname=atomx_test;charset=utf8mb4';
}
$dbUser = getenv('ATM_TEST_USER');
if ($dbUser === false || $dbUser === '') $dbUser = 'root';
$dbPass = getenv('ATM_TEST_PASS');
if ($dbPass === false) $dbPass = '';

$assertions = 0;
$failures = array();

function atm_it_assert($condition, $message)
{
	global $assertions, $failures;
	$assertions++;
	if (!$condition) {
		$failures[] = $message;
	}
}

/**
 * Minimal registry implementing the ArrayAccess contract used by the
 * production helpers.
 */
class Register implements ArrayAccess
{
	private static $instance;
	private $values = array();

	public static function getInstance()
	{
		if (empty(self::$instance)) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function offsetExists($offset): bool
	{
		return array_key_exists($offset, $this->values);
	}

	#[\ReturnTypeWillChange]
	public function offsetGet($offset)
	{
		return isset($this->values[$offset]) ? $this->values[$offset] : null;
	}

	#[\ReturnTypeWillChange]
	public function offsetSet($offset, $value)
	{
		if ($offset === null) {
			$this->values[] = $value;
		} else {
			$this->values[$offset] = $value;
		}
	}

	#[\ReturnTypeWillChange]
	public function offsetUnset($offset)
	{
		unset($this->values[$offset]);
	}
}

/**
 * Configuration reader stub. Values are explicit so the test never reads
 * or writes the live sys/settings/config.php file.
 */
class Config
{
	public static $values = array(
		'secure' => array(
			'admin_session_retention' => 30,
			'admin_revoked_retention' => 7,
			'admin_audit_retention' => 180,
			'admin_attempt_retention' => 30,
			'admin_notification_retention' => 90,
		),
		'db' => array('prefix' => ''),
	);

	public static function read($title, $module = null)
	{
		if (!empty($module)) {
			return isset(self::$values[$module][$title])
				? self::$values[$module][$title] : null;
		}
		return isset(self::$values[$title]) ? self::$values[$title] : null;
	}
}

/**
 * Test database adapter. The helpers use raw SQL for their critical
 * writes, so PDO is enough for those paths; save()/delete() mirror the
 * small part of the production DB API they use.
 */
class AtmTestDB
{
	public $pdo;
	public $prefix;

	public function __construct($pdo, $prefix)
	{
		$this->pdo = $pdo;
		$this->prefix = $prefix;
	}

	public function getFullTableName($table)
	{
		return $this->prefix . $table;
	}

	public function escape($value)
	{
		return str_replace(
			array('\\', "\0", "\n", "\r", "'", '"', "\x1a"),
			array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'),
			(string)$value
		);
	}

	public function query($sql)
	{
		$stmt = $this->pdo->query($sql);
		if ($stmt === false) {
			$error = $this->pdo->errorInfo();
			throw new RuntimeException(isset($error[2]) ? $error[2] : 'SQL error');
		}
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function save($table, $values, $params = array())
	{
		$tableName = '`' . $this->getFullTableName($table) . '`';
		if ((isset($values['id']) && !empty($values['id'])) || !empty($params)) {
			$conditions = $params;
			if (isset($values['id'])) {
				$conditions = array('id' => $values['id']);
				unset($values['id']);
			}
			$set = array();
			foreach ($values as $field => $value) {
				$set[] = '`' . $field . '` = ' . $this->pdo->quote((string)$value);
			}
			$where = array();
			foreach ($conditions as $field => $value) {
				$where[] = '`' . $field . '` = ' . $this->pdo->quote((string)$value);
			}
			$this->pdo->exec('UPDATE ' . $tableName . ' SET ' . implode(', ', $set)
				. ' WHERE ' . implode(' AND ', $where));
			return isset($values['id']) ? $values['id'] : true;
		}

		$fields = array();
		$placeholders = array();
		foreach ($values as $field => $value) {
			$fields[] = '`' . $field . '`';
			$placeholders[] = $this->pdo->quote((string)$value);
		}
		$this->pdo->exec('INSERT INTO ' . $tableName . ' (' . implode(', ', $fields)
			. ') VALUES (' . implode(', ', $placeholders) . ')');
		return (int)$this->pdo->lastInsertId();
	}

	public function delete($table, $params)
	{
		$where = array();
		foreach ($params as $field => $value) {
			$where[] = '`' . $field . '` = ' . $this->pdo->quote((string)$value);
		}
		$this->pdo->exec('DELETE FROM `' . $this->getFullTableName($table) . '`'
			. (!empty($where) ? ' WHERE ' . implode(' AND ', $where) : ''));
	}

	public function select($table, $type, $params = array())
	{
		$sql = 'SELECT * FROM `' . $this->getFullTableName($table) . '`';
		if (!empty($params['cond']) && is_array($params['cond'])) {
			$where = array();
			foreach ($params['cond'] as $field => $value) {
				$where[] = '`' . $field . '` = ' . $this->pdo->quote((string)$value);
			}
			$sql .= ' WHERE ' . implode(' AND ', $where);
		}
		$rows = $this->query($sql);
		return $type === 'count' ? count($rows) : $rows;
	}

	public function cleanSqlCache()
	{
	}
}

function __($key)
{
	return (string)$key;
}

function h($value)
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function atm_password_hash($password)
{
	return password_hash((string)$password, PASSWORD_DEFAULT);
}

function atm_password_verify($password, $hash)
{
	$hash = (string)$hash;
	if ($hash === '') return false;
	if (preg_match('#^[0-9a-f]{32}$#i', $hash)) {
		return hash_equals(strtolower($hash), md5((string)$password));
	}
	return password_verify((string)$password, $hash);
}

function atm_password_needs_rehash($hash)
{
	$hash = (string)$hash;
	if (preg_match('#^[0-9a-f]{32}$#i', $hash)) return true;
	return password_needs_rehash($hash, PASSWORD_DEFAULT);
}

if (!defined('ROOT')) define('ROOT', $root);
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
if (!defined('CACHE_MATCHING_TAG')) define('CACHE_MATCHING_TAG', 'matching_tag');
if (!defined('DB_COUNT')) define('DB_COUNT', 'count');
if (!defined('DB_FIRST')) define('DB_FIRST', 'first');

try {
	$pdo = new PDO($dsn, $dbUser, $dbPass, array(
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	));
} catch (Exception $e) {
	if ($required) {
		fwrite(STDERR, "Integration database unavailable: " . $e->getMessage() . PHP_EOL);
		exit(1);
	}
	fwrite(STDOUT, "SKIP: integration database unavailable (" . $e->getMessage() . ")" . PHP_EOL);
	exit(0);
}

$prefix = 'atmtest_' . substr(md5(uniqid('', true)), 0, 8) . '_';
$db = new AtmTestDB($pdo, $prefix);
$Register = Register::getInstance();
$Register['DB'] = $db;
Config::$values['db']['prefix'] = $prefix;

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'AtomX integration test';
$_SESSION = array();
if (session_status() === PHP_SESSION_NONE) {
	@session_id('atmtest' . substr(md5(uniqid('', true)), 0, 20));
	@session_start();
}

require_once $root . '/sys/inc/admin_security.lib.php';
require_once $root . '/sys/inc/server_config.lib.php';

atm_admin_security_ensure_tables();
foreach (array('admin_sessions', 'admin_audit', 'admin_login_attempts', 'admin_2fa',
	'admin_notifications', 'admin_notification_reads', 'admin_notification_state') as $table) {
	$rows = $db->query("SHOW TABLES LIKE '" . $db->escape($prefix . $table) . "'");
	atm_it_assert(!empty($rows), 'Security table was not created: ' . $prefix . $table);
}

/* Audit trail and readable details. */
atm_admin_audit('login_success', 'login: tester', 7, 'Tester');
atm_admin_audit('login_failed', 'login: tester, ip: 127.0.0.1', 0, '');
atm_admin_audit('profile_saved', 'marker: unique-search-marker', 7, 'Tester');
atm_it_assert(atm_admin_audit_count() === 3, 'Audit count is wrong.');
atm_it_assert(atm_admin_audit_count('login_success') === 1, 'Audit action filter is wrong.');
atm_it_assert(count(atm_admin_audit_list(10, 0, '', array('q' => 'unique-search-marker'))) === 1,
	'Audit search filter is wrong.');
atm_it_assert(strpos(atm_admin_audit_details('login: tester, ip: 127.0.0.1'), 'Login: tester') !== false,
	'Audit details are not human-readable.');

/* Notifications and per-admin read state. */
$notificationId = atm_admin_notification_add('info', 'Test notification', 'Body text', 'security.php', 0);
atm_it_assert($notificationId > 0, 'Notification was not stored.');
atm_it_assert(atm_admin_notifications_unread_count(7) === 1, 'Unread notification count is wrong.');
$notificationRows = atm_admin_notifications_list(7, 10);
atm_it_assert(count($notificationRows) === 1, 'Notification list is wrong.');
atm_it_assert(atm_admin_notification_mark_all_read(7, array(
	'news' => 2, 'loads' => 1, 'stat' => 0, 'comments' => 3,
)), 'Mark-all-read failed.');
atm_it_assert(atm_admin_notifications_unread_count(7) === 0, 'Notifications were not marked read.');
$snapshot = atm_admin_notification_state_get(7);
atm_it_assert(isset($snapshot['news']) && $snapshot['news'] === 2, 'Moderation snapshot is wrong.');
$summary = atm_admin_notification_summary(array(
	'news' => 4, 'loads' => 1, 'stat' => 0, 'comments' => 3,
), 7);
atm_it_assert($summary['moderation']['news'] === 2, 'New moderation counter is wrong.');
atm_it_assert($summary['total'] === 2, 'Notification summary total is wrong.');

/* Login throttling and attempt cleanup. */
atm_admin_login_clear_failures();
for ($i = 0; $i < 5; $i++) {
	atm_admin_login_attempt_record('tester', '127.0.0.1', false);
}
atm_it_assert(atm_admin_login_block_remaining('tester', '127.0.0.1') > 0,
	'Login throttling did not block after five failures.');
atm_it_assert(count(atm_admin_login_failures(20)) === 5, 'Failed login history is wrong.');
atm_admin_login_attempt_record('tester', '127.0.0.1', true);
atm_it_assert(atm_admin_login_block_remaining('tester', '127.0.0.1') === 0,
	'Successful login did not reset failures.');

/* TOTP, backup codes and 2FA status. */
$secret = atm_admin_totp_secret();
$code = atm_admin_totp_code($secret);
atm_it_assert(strlen($secret) >= 16 && $secret !== '', 'TOTP secret generation failed.');
atm_it_assert(atm_admin_totp_verify($secret, $code), 'TOTP verification failed.');
$backupCodes = atm_admin_2fa_backup_codes_generate(2);
$hashes = atm_admin_2fa_backup_codes_hash($backupCodes);
atm_it_assert(atm_admin_2fa_save(7, $secret, true, $hashes), '2FA save failed.');
atm_it_assert(atm_admin_2fa_enabled(7), '2FA is not enabled.');
$usedBackup = false;
$remaining = null;
atm_it_assert(atm_admin_2fa_verify_code(7, $backupCodes[0], $usedBackup, $remaining),
	'Backup code verification failed.');
atm_it_assert($usedBackup === true && $remaining === 1, 'Backup code was not consumed.');
atm_it_assert(!atm_admin_2fa_verify_code(7, $backupCodes[0]), 'A used backup code was accepted twice.');
atm_it_assert(atm_admin_2fa_delete(7), '2FA delete failed.');
atm_it_assert(!atm_admin_2fa_enabled(7), '2FA is still enabled.');

/* Session tracking and revocation. */
atm_admin_session_touch(array('id' => 7, 'name' => 'Tester'));
atm_it_assert(count(atm_admin_session_list(7, 10)) === 1, 'Session was not registered.');
$currentHash = atm_admin_session_hash();
atm_it_assert(strlen($currentHash) === 64, 'Session hash is invalid.');
atm_it_assert(!atm_admin_session_is_revoked(), 'New session is already revoked.');
$db->query("INSERT INTO `" . $prefix . "admin_sessions` SET
	`session_hash` = '" . str_repeat('a', 64) . "',
	`user_id` = 7, `user_name` = 'Other', `ip` = '127.0.0.1',
	`user_agent` = 'other', `created` = " . time() . ", `last_seen` = " . time() . ", `revoked` = 0");
atm_it_assert(atm_admin_session_revoke_others(7) === 1, 'Other session was not revoked.');
atm_it_assert(count(atm_admin_session_list(7, 10)) === 1, 'Session list after revocation is wrong.');

/* Retention cleanup removes only old rows. */
$old = time() - 500 * 86400;
$db->query("INSERT INTO `" . $prefix . "admin_audit` SET `user_id` = 7, `user_name` = 'Old',
	`action` = 'old_event', `details` = '', `ip` = '', `user_agent` = '', `created` = " . $old);
$db->query("INSERT INTO `" . $prefix . "admin_login_attempts` SET `login` = 'old',
	`ip` = '127.0.0.1', `success` = 0, `created` = " . $old);
$db->query("INSERT INTO `" . $prefix . "admin_sessions` SET `session_hash` = '" . str_repeat('b', 64) . "',
	`user_id` = 7, `user_name` = 'Old', `ip` = '', `user_agent` = '', `created` = " . $old . ",
	`last_seen` = " . $old . ", `revoked` = 0");
$oldNotification = atm_admin_notification_add('info', 'Old notification', '', '', 0);
$db->query("UPDATE `" . $prefix . "admin_notifications` SET `created` = " . $old
	. " WHERE `id` = " . (int)$oldNotification);
$cleanup = atm_admin_security_cleanup(true);
atm_it_assert($cleanup['audit'] >= 1, 'Old audit records were not cleaned up.');
atm_it_assert($cleanup['attempts'] >= 1, 'Old login attempts were not cleaned up.');
atm_it_assert($cleanup['sessions'] >= 1, 'Old sessions were not cleaned up.');
atm_it_assert($cleanup['notifications'] >= 1, 'Old notifications were not cleaned up.');
atm_it_assert(atm_admin_audit_count('old_event') === 0, 'Old audit record still exists.');

/* Configuration syntax validation. */
$validNginx = "server {\n    listen 80;\n    server_name example.com;\n}\n";
$valid = atm_server_config_validate_content('nginx', $validNginx);
atm_it_assert(!empty($valid['ok']), 'A valid Nginx file failed validation.');
$invalid = atm_server_config_validate_content('nginx', "server {\n    listen 80;\n");
atm_it_assert(empty($invalid['ok']), 'Unbalanced Nginx braces were accepted.');
$invalid = atm_server_config_validate_content('apache', "<VirtualHost *:80>\n    ServerName example.com\n");
atm_it_assert(empty($invalid['ok']), 'An unclosed Apache block was accepted.');
$invalid = atm_server_config_validate_content('apache', "<VirtualHost *:80>\n</Directory>\n");
atm_it_assert(empty($invalid['ok']), 'A mismatched Apache tag was accepted.');
$warnings = atm_server_config_validate_content('nginx', "server {\n    listen 80\n}\n");
atm_it_assert(!empty($warnings['ok']) && !empty($warnings['warnings']),
	'Nginx missing-semicolon warning is missing.');
$commented = atm_server_config_validate_content('nginx', "server {\n    listen 80; # public port\n}\n");
atm_it_assert(!empty($commented['ok']) && empty($commented['warnings']),
	'An inline Nginx comment produced a false semicolon warning.');
$commentedApache = atm_server_config_validate_content('apache',
	"# </VirtualHost>\n<VirtualHost *:80>\n    ServerName example.com\n</VirtualHost>\n");
atm_it_assert(!empty($commentedApache['ok']),
	'A commented Apache block tag produced a false imbalance error.');
$apacheBraces = atm_server_config_validate_content('apache',
	"<VirtualHost *:80>\n    SetEnv EXAMPLE value}\n</VirtualHost>\n");
atm_it_assert(!empty($apacheBraces['ok']),
	'Apache braces were treated as Nginx block delimiters.');

/* Remove only this run's tables. */
$tables = $db->query("SHOW TABLES LIKE '" . $db->escape($prefix) . "%'");
foreach ($tables as $row) {
	$name = (string)array_values($row)[0];
	if (strpos($name, $prefix) === 0) {
		$pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $name) . '`');
	}
}

if (!empty($failures)) {
	fwrite(STDERR, "Admin security integration checks failed:" . PHP_EOL);
	foreach ($failures as $failure) {
		fwrite(STDERR, ' - ' . $failure . PHP_EOL);
	}
	fwrite(STDERR, sprintf("%d assertions, %d failure(s).%s", $assertions, count($failures), PHP_EOL));
	exit(1);
}

fwrite(STDOUT, sprintf("Admin security integration checks passed (%d assertions).%s", $assertions, PHP_EOL));
