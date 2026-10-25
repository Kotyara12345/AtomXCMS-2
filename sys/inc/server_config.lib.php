<?php
##################################################
##												##
## @Author:       AtomX CMS                     ##
## @Version:      1.0                           ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Web server configuration      ##
##################################################

/**
 * Helpers for reading and writing web server configuration files from
 * the administration panel.
 *
 * Only existing files with a configuration extension can be edited. A
 * backup copy is created before every write operation.
 */

if (!defined('ROOT')) {
	exit;
}


if (!function_exists('atm_server_config_types')) {
	/**
	 * Supported configuration types.
	 *
	 * @return array
	 */
	function atm_server_config_types()
	{
		return array('apache', 'nginx');
	}
}


if (!function_exists('atm_server_config_settings_file')) {
	/**
	 * Path to the file which stores the selected configuration paths.
	 *
	 * The file is a PHP file returning an array. This keeps the paths
	 * unreadable when the site is served by Nginx (which ignores
	 * .htaccess files).
	 *
	 * @return string
	 */
	function atm_server_config_settings_file()
	{
		return ROOT . DS . 'sys' . DS . 'settings' . DS . 'server_config.php';
	}
}


if (!function_exists('atm_server_config_host')) {
	/**
	 * Current host name without a port and the leading "www.".
	 *
	 * @return string
	 */
	function atm_server_config_host()
	{
		$host = '';
		if (!empty($_SERVER['HTTP_HOST'])) {
			$host = (string)$_SERVER['HTTP_HOST'];
		} elseif (!empty($_SERVER['SERVER_NAME'])) {
			$host = (string)$_SERVER['SERVER_NAME'];
		}

		$host = strtolower(trim($host));
		$host = preg_replace('/:\d+$/', '', $host);
		$host = preg_replace('/^www\./', '', $host);

		if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host) || strpos($host, '..') !== false) {
			return '';
		}

		return $host;
	}
}


if (!function_exists('atm_server_config_candidates')) {
	/**
	 * Common configuration file locations for the current site.
	 *
	 * @param string $type
	 * @return array
	 */
	function atm_server_config_candidates($type)
	{
		$out = array();
		$host = atm_server_config_host();

		if ($type === 'apache') {
			$out[] = ROOT . DS . '.htaccess';
			$out[] = ROOT . DS . 'httpd.conf';
			$out[] = ROOT . DS . 'apache2.conf';

			if ($host !== '') {
				$out[] = '/etc/apache2/sites-enabled/' . $host . '.conf';
				$out[] = '/etc/apache2/sites-available/' . $host . '.conf';
				$out[] = '/etc/httpd/conf.d/' . $host . '.conf';
			}
			$out[] = '/etc/apache2/apache2.conf';
			$out[] = '/etc/httpd/conf/httpd.conf';
		} elseif ($type === 'nginx') {
			$out[] = ROOT . DS . 'nginx.conf';
			$out[] = ROOT . DS . '.nginx.conf';
			$out[] = ROOT . DS . 'nginx' . DS . 'nginx.conf';
			$out[] = ROOT . DS . 'nginx' . DS . 'site.conf';

			if ($host !== '') {
				$out[] = '/etc/nginx/sites-enabled/' . $host . '.conf';
				$out[] = '/etc/nginx/sites-available/' . $host . '.conf';
				$out[] = '/etc/nginx/conf.d/' . $host . '.conf';
			}
			$out[] = '/etc/nginx/nginx.conf';
			$out[] = '/usr/local/nginx/conf/nginx.conf';
		}

		return array_values(array_unique($out));
	}
}


if (!function_exists('atm_server_config_defaults')) {
	/**
	 * Default configuration paths.
	 *
	 * Apache uses the site's .htaccess by default. For Nginx the first
	 * existing candidate is used; if there is none, the path is left
	 * empty and has to be filled in by the administrator.
	 *
	 * @return array
	 */
	function atm_server_config_defaults()
	{
		$settings = array(
			'apache' => ROOT . DS . '.htaccess',
			'nginx' => '',
		);

		foreach (atm_server_config_candidates('nginx') as $path) {
			if (@is_file($path)) {
				$settings['nginx'] = $path;
				break;
			}
		}

		return $settings;
	}
}


if (!function_exists('atm_server_config_read_settings')) {
	/**
	 * Read the selected configuration paths.
	 *
	 * @return array
	 */
	function atm_server_config_read_settings()
	{
		$settings = atm_server_config_defaults();
		$file = atm_server_config_settings_file();

		if (!@is_file($file) || !@is_readable($file)) {
			return $settings;
		}

		$data = include $file;
		if (!is_array($data)) {
			return $settings;
		}

		foreach (atm_server_config_types() as $type) {
			if (array_key_exists($type, $data) && is_string($data[$type])) {
				$settings[$type] = trim($data[$type]);
			}
		}

		return $settings;
	}
}


if (!function_exists('atm_server_config_write_settings')) {
	/**
	 * Persist the selected configuration paths.
	 *
	 * @param array $settings
	 * @return bool
	 */
	function atm_server_config_write_settings($settings)
	{
		$data = array();
		foreach (atm_server_config_types() as $type) {
			$data[$type] = (isset($settings[$type]) && is_string($settings[$type]))
				? trim($settings[$type])
				: '';
		}

		$code = "<?php\nreturn " . var_export($data, true) . ";\n";
		return @file_put_contents(atm_server_config_settings_file(), $code, LOCK_EX) !== false;
	}
}


if (!function_exists('atm_server_config_is_absolute')) {
	/**
	 * Check whether a path is absolute.
	 *
	 * @param string $path
	 * @return bool
	 */
	function atm_server_config_is_absolute($path)
	{
		if (!is_string($path) || $path === '') {
			return false;
		}

		if (strpos($path, '/') === 0) {
			return true;
		}

		if (preg_match('#^[a-z]:[\\\\/]#i', $path)) {
			return true;
		}

		return strpos($path, '\\\\') === 0;
	}
}


if (!function_exists('atm_server_config_path_allowed')) {
	/**
	 * Check whether a file name looks like a web server configuration.
	 *
	 * Only .conf files and Apache .htaccess files are accepted. This
	 * prevents accidental editing of PHP, templates and other files.
	 *
	 * @param string $type
	 * @param string $path
	 * @return bool
	 */
	function atm_server_config_path_allowed($type, $path)
	{
		$base = strtolower(basename(str_replace('\\', '/', (string)$path)));

		if ($base === '' || $base === '.' || $base === '..') {
			return false;
		}

		if ($type === 'apache') {
			return $base === '.htaccess' || substr($base, -5) === '.conf';
		}

		if ($type === 'nginx') {
			return substr($base, -5) === '.conf';
		}

		return false;
	}
}


if (!function_exists('atm_server_config_check_path')) {
	/**
	 * Validate a configuration file path.
	 *
	 * The returned "code" is a short machine-readable error code; the
	 * administration page translates it into a message.
	 *
	 * @param string $type
	 * @param string $path
	 * @return array
	 */
	function atm_server_config_check_path($type, $path)
	{
		$result = array(
			'ok' => false,
			'path' => '',
			'code' => '',
			'writable' => false,
		);

		if (!in_array($type, atm_server_config_types(), true)) {
			$result['code'] = 'bad_type';
			return $result;
		}

		$path = trim((string)$path);

		if ($path === '') {
			$result['code'] = 'empty';
			return $result;
		}

		if (strpos($path, "\0") !== false) {
			$result['code'] = 'invalid';
			return $result;
		}

		if (!atm_server_config_is_absolute($path)) {
			$result['code'] = 'relative';
			return $result;
		}

		if (!atm_server_config_path_allowed($type, $path)) {
			$result['code'] = 'extension';
			return $result;
		}

		if (!@file_exists($path)) {
			$result['code'] = 'missing';
			return $result;
		}

		$real = @realpath($path);
		if ($real === false) {
			$result['code'] = 'missing';
			return $result;
		}

		if (!@is_file($real)) {
			$result['code'] = 'not_file';
			return $result;
		}

		if (!atm_server_config_path_allowed($type, $real)) {
			$result['code'] = 'extension';
			return $result;
		}

		if (!@is_readable($real)) {
			$result['code'] = 'not_readable';
			return $result;
		}

		$result['ok'] = true;
		$result['path'] = $real;
		$result['writable'] = @is_writable($real);

		return $result;
	}
}


if (!function_exists('atm_server_config_read_file')) {
	/**
	 * Read a configuration file.
	 *
	 * @param string $path
	 * @param string $code
	 * @return string|bool
	 */
	function atm_server_config_read_file($path, &$code = null)
	{
		$code = '';

		if (!@is_file($path)) {
			$code = 'missing';
			return false;
		}

		if (!@is_readable($path)) {
			$code = 'not_readable';
			return false;
		}

		$size = @filesize($path);
		if ($size !== false && $size > 2097152) {
			$code = 'too_large';
			return false;
		}

		$content = @file_get_contents($path);
		if ($content === false) {
			$code = 'read_failed';
			return false;
		}

		return $content;
	}
}


if (!function_exists('atm_server_config_file_info')) {
	/**
	 * Information about a configuration file for the administration page.
	 *
	 * @param string $path
	 * @return array
	 */
	function atm_server_config_file_info($path)
	{
		$info = array(
			'size' => 0,
			'mtime' => 0,
			'perms' => '',
			'writable' => false,
		);

		if (!@is_file($path)) {
			return $info;
		}

		$size = @filesize($path);
		if ($size !== false) {
			$info['size'] = (int)$size;
		}

		$mtime = @filemtime($path);
		if ($mtime !== false) {
			$info['mtime'] = (int)$mtime;
		}

		$perms = @fileperms($path);
		if ($perms !== false) {
			$info['perms'] = substr(sprintf('%o', $perms), -4);
		}

		$info['writable'] = @is_writable($path);

		return $info;
	}
}


if (!function_exists('atm_server_config_format_size')) {
	/**
	 * Human-readable file size.
	 *
	 * @param int $bytes
	 * @return string
	 */
	function atm_server_config_format_size($bytes)
	{
		$bytes = (int)$bytes;

		if ($bytes < 1024) {
			return $bytes . ' B';
		}
		if ($bytes < 1048576) {
			return round($bytes / 1024, 1) . ' KB';
		}

		return round($bytes / 1048576, 1) . ' MB';
	}
}


if (!function_exists('atm_server_config_backup')) {
	/**
	 * Create a backup copy of a configuration file.
	 *
	 * @param string $type
	 * @param string $path
	 * @param string $code
	 * @return string|bool
	 */
	function atm_server_config_backup($type, $path, &$code = null)
	{
		$code = '';

		$dir = ROOT . DS . 'sys' . DS . 'logs' . DS . 'server_config_backups';
		if (!@is_dir($dir)) {
			if (!@mkdir($dir, 0777, true)) {
				$code = 'backup_dir';
				return false;
			}
		}

		$base = strtolower(basename(str_replace('\\', '/', $path)));
		$base = preg_replace('/[^a-z0-9._-]+/i', '_', $base);
		if ($base === '') {
			$base = 'config';
		}

		$name = $type . '_' . $base . '_' . date('Ymd-His') . '_'
			. substr(sha1($path . '|' . microtime(true)), 0, 8) . '.bak';
		$dest = $dir . DS . $name;

		if (!@copy($path, $dest)) {
			$code = 'backup_failed';
			return false;
		}

		@chmod($dest, 0600);

		// Keep the backup directory from growing without a limit.
		$backups = @glob($dir . DS . $type . '_*.bak');
		if (!empty($backups) && count($backups) > 30) {
			sort($backups);
			$remove = count($backups) - 30;
			for ($i = 0; $i < $remove; $i++) {
				@unlink($backups[$i]);
			}
		}

		return $dest;
	}
}


if (!function_exists('atm_server_config_save_file')) {
	/**
	 * Save a configuration file after creating a backup copy.
	 *
	 * @param string $type
	 * @param string $path
	 * @param string $content
	 * @param array $info
	 * @return bool
	 */
	function atm_server_config_save_file($type, $path, $content, &$info = null)
	{
		$info = array(
			'code' => '',
			'backup' => '',
			'bytes' => 0,
		);

		$check = atm_server_config_check_path($type, $path);
		if (!$check['ok']) {
			$info['code'] = $check['code'];
			return false;
		}

		if (!$check['writable']) {
			$info['code'] = 'not_writable';
			return false;
		}

		$path = $check['path'];
		$content = is_string($content) ? $content : (string)$content;

		if (strlen($content) > 2097152) {
			$info['code'] = 'too_large';
			return false;
		}

		$code = '';
		$backup = atm_server_config_backup($type, $path, $code);
		if ($backup === false) {
			$info['code'] = $code;
			return false;
		}
		$info['backup'] = $backup;

		$bytes = @file_put_contents($path, $content, LOCK_EX);
		if ($bytes === false || $bytes !== strlen($content)) {
			// Try to restore the previous state if the write was incomplete.
			@copy($backup, $path);
			$info['code'] = 'write_failed';
			return false;
		}

		$info['bytes'] = $bytes;
		return true;
	}
}
