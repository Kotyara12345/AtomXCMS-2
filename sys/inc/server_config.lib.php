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
			'backup_limit' => 30,
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
		if (isset($data['backup_limit'])) {
			$settings['backup_limit'] = max(1, min(500, (int)$data['backup_limit']));
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
		$data['backup_limit'] = isset($settings['backup_limit'])
			? max(1, min(500, (int)$settings['backup_limit']))
			: 30;

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


if (!function_exists('atm_server_config_backup_dir')) {
	/**
	 * Directory with automatic configuration backups.
	 *
	 * @return string
	 */
	function atm_server_config_backup_dir()
	{
		return ROOT . DS . 'sys' . DS . 'logs' . DS . 'server_config_backups';
	}
}


if (!function_exists('atm_server_config_backup_limit')) {
	/**
	 * Number of configuration backups kept per server type.
	 *
	 * @return int
	 */
	function atm_server_config_backup_limit()
	{
		$settings = atm_server_config_read_settings();
		$limit = isset($settings['backup_limit']) ? (int)$settings['backup_limit'] : 30;
		return max(1, min(500, $limit));
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

		$dir = atm_server_config_backup_dir();
		if (!@is_dir($dir)) {
			if (!@mkdir($dir, 0700, true)) {
				$code = 'backup_dir';
				return false;
			}
		}
		// The backup directory must not be readable by other users on
		// POSIX systems. On Windows this call is harmless.
		if (DIRECTORY_SEPARATOR === '/') {
			@chmod($dir, 0700);
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

		// Keep the backup directory from growing without a limit. Only
		// delete files that match our generated naming scheme.
		$limit = atm_server_config_backup_limit();
		$backups = @glob($dir . DS . $type . '_*.bak');
		if (!empty($backups)) {
			$backups = array_values(array_filter($backups, function ($file) use ($type) {
				return atm_server_config_backup_name_allowed($type, basename($file));
			}));
		}
		if (!empty($backups) && count($backups) > $limit) {
			sort($backups);
			$remove = count($backups) - $limit;
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


if (!function_exists('atm_server_config_status_all')) {
	/**
	 * Compact status information for the admin header.
	 *
	 * @return array
	 */
	function atm_server_config_status_all()
	{
		$settings = atm_server_config_read_settings();
		$out = array();

		foreach (atm_server_config_types() as $type) {
			$path = isset($settings[$type]) ? trim((string)$settings[$type]) : '';
			$check = atm_server_config_check_path($type, $path);
			$info = $check['ok'] ? atm_server_config_file_info($check['path']) : array(
				'size' => 0,
				'mtime' => 0,
				'perms' => '',
				'writable' => false,
			);

			$out[$type] = array(
				'type' => $type,
				'configured' => ($path !== ''),
				'ok' => !empty($check['ok']),
				'code' => isset($check['code']) ? (string)$check['code'] : '',
				'path' => !empty($check['path']) ? $check['path'] : $path,
				'info' => $info,
			);
		}

		return $out;
	}
}


if (!function_exists('atm_server_config_backup_name_allowed')) {
	/**
	 * Check that a file name looks like a generated configuration backup.
	 *
	 * @param string $type
	 * @param string $name
	 * @return bool
	 */
	function atm_server_config_backup_name_allowed($type, $name)
	{
		if (!in_array($type, atm_server_config_types(), true)) return false;
		$name = basename((string)$name);
		return (bool)preg_match(
			'/^' . preg_quote($type, '/') . '_[a-z0-9._-]+_[0-9]{8}-[0-9]{6}_[a-f0-9]{8}\.bak$/i',
			$name
		);
	}
}


if (!function_exists('atm_server_config_backup_list')) {
	/**
	 * List automatic backups for a configuration type, newest first.
	 *
	 * @param string $type
	 * @param int $limit
	 * @return array
	 */
	function atm_server_config_backup_list($type, $limit = 10)
	{
		if (!in_array($type, atm_server_config_types(), true)) return array();
		$dir = atm_server_config_backup_dir();
		if (!@is_dir($dir)) return array();

		$files = @glob($dir . DS . $type . '_*.bak');
		if (empty($files)) return array();

		$out = array();
		foreach ($files as $file) {
			$name = basename($file);
			if (!atm_server_config_backup_name_allowed($type, $name)) continue;
			$mtime = @filemtime($file);
			$size = @filesize($file);
			$out[] = array(
				'name' => $name,
				'path' => $file,
				'mtime' => ($mtime !== false) ? (int)$mtime : 0,
				'size' => ($size !== false) ? (int)$size : 0,
			);
		}

		usort($out, function ($a, $b) {
			if ($a['mtime'] === $b['mtime']) return strcmp($b['name'], $a['name']);
			return ($a['mtime'] > $b['mtime']) ? -1 : 1;
		});

		$limit = max(1, (int)$limit);
		return array_slice($out, 0, $limit);
	}
}


if (!function_exists('atm_server_config_backup_path')) {
	/**
	 * Resolve a backup name to a safe path inside the backup directory.
	 *
	 * @param string $type
	 * @param string $name
	 * @return string|bool
	 */
	function atm_server_config_backup_path($type, $name)
	{
		if (!atm_server_config_backup_name_allowed($type, $name)) return false;
		$dir = atm_server_config_backup_dir();
		$path = $dir . DS . basename((string)$name);
		if (!@is_file($path)) return false;

		$realDir = @realpath($dir);
		$realFile = @realpath($path);
		if ($realDir === false || $realFile === false) return false;

		$realDir = rtrim(str_replace('\\', '/', $realDir), '/') . '/';
		$realFile = str_replace('\\', '/', $realFile);
		if (strpos($realFile, $realDir) !== 0) return false;

		return $path;
	}
}


if (!function_exists('atm_server_config_restore_backup')) {
	/**
	 * Restore a configuration file from a backup.
	 *
	 * A safety backup of the current file is created first.
	 *
	 * @param string $type
	 * @param string $name
	 * @param array $info
	 * @return bool
	 */
	function atm_server_config_restore_backup($type, $name, &$info = null)
	{
		$info = array(
			'code' => '',
			'backup' => '',
			'restored' => '',
			'bytes' => 0,
		);

		$backup = atm_server_config_backup_path($type, $name);
		if ($backup === false) {
			$info['code'] = 'backup_missing';
			return false;
		}

		$settings = atm_server_config_read_settings();
		$path = isset($settings[$type]) ? (string)$settings[$type] : '';
		$check = atm_server_config_check_path($type, $path);
		if (!$check['ok']) {
			$info['code'] = $check['code'];
			return false;
		}
		if (!$check['writable']) {
			$info['code'] = 'not_writable';
			return false;
		}

		$content = atm_server_config_read_file($backup, $readCode);
		if ($content === false) {
			$info['code'] = $readCode;
			return false;
		}
		$validation = atm_server_config_validate_content($type, $content);
		if (empty($validation['ok'])) {
			$info['code'] = 'invalid_backup';
			return false;
		}

		$code = '';
		$safety = atm_server_config_backup($type, $check['path'], $code);
		if ($safety === false) {
			$info['code'] = $code;
			return false;
		}

		$bytes = @file_put_contents($check['path'], $content, LOCK_EX);
		if ($bytes === false || $bytes !== strlen($content)) {
			@copy($safety, $check['path']);
			$info['code'] = 'write_failed';
			return false;
		}

		$info['backup'] = $safety;
		$info['restored'] = basename((string)$name);
		$info['bytes'] = $bytes;
		return true;
	}
}

if (!function_exists('atm_server_config_validate_content')) {
	/**
	 * Lightweight structural check before writing a configuration file.
	 *
	 * This is not a replacement for `apachectl -t` or `nginx -t`; those
	 * commands are not available to PHP in every hosting environment.
	 * The check catches the common fatal mistakes (unbalanced blocks,
	 * unclosed quotes and missing braces) without executing a binary.
	 *
	 * @param string $type
	 * @param string $content
	 * @return array
	 */
	function atm_server_config_validate_content($type, $content)
	{
		$type = (string)$type;
		$content = (string)$content;
		$result = array('ok' => true, 'message' => '', 'warnings' => array());

		if (strpos($content, "\0") !== false) {
			return array(
				'ok' => false,
				'message' => __('The configuration contains a null byte.'),
				'warnings' => array(),
			);
		}
		if (strlen($content) > 2097152) {
			return array(
				'ok' => false,
				'message' => __('The file is too large to edit (maximum 2 MB).'),
				'warnings' => array(),
			);
		}

		$single = false;
		$double = false;
		$escaped = false;
		$lineComment = false;
		$blockComment = false;
		$trackBraces = ($type === 'nginx');
		$braces = 0;
		$length = strlen($content);
		for ($i = 0; $i < $length; $i++) {
			$char = $content[$i];
			$next = ($i + 1 < $length) ? $content[$i + 1] : '';

			if ($lineComment) {
				if ($char === "\n") $lineComment = false;
				continue;
			}
			if ($blockComment) {
				if ($char === '*' && $next === '/') {
					$blockComment = false;
					$i++;
				}
				continue;
			}
			if ($escaped) {
				$escaped = false;
				continue;
			}
			if (($single || $double) && $char === '\\') {
				$escaped = true;
				continue;
			}
			if ($single) {
				if ($char === "'") $single = false;
				continue;
			}
			if ($double) {
				if ($char === '"') $double = false;
				continue;
			}
			if ($char === '#') {
				$lineComment = true;
				continue;
			}
			if ($char === '/' && $next === '*') {
				$blockComment = true;
				$i++;
				continue;
			}
			if ($char === "'") {
				$single = true;
				continue;
			}
			if ($char === '"') {
				$double = true;
				continue;
			}
			if ($trackBraces && $char === '{') $braces++;
			if ($trackBraces && $char === '}') {
				$braces--;
				if ($braces < 0) {
					return array(
						'ok' => false,
						'message' => __('Unbalanced closing brace in the configuration.'),
						'warnings' => array(),
					);
				}
			}
		}

		if ($single || $double) {
			return array(
				'ok' => false,
				'message' => __('An unclosed quote was found in the configuration.'),
				'warnings' => array(),
			);
		}
		if ($blockComment) {
			$result['warnings'][] = __('The configuration ends inside a block comment.');
		}

		if ($type === 'nginx') {
			if ($braces !== 0) {
				return array(
					'ok' => false,
					'message' => __('Unbalanced curly braces in the Nginx configuration.'),
					'warnings' => array(),
				);
			}

			$lines = preg_split('/\R/u', $content);
			foreach ($lines as $lineNumber => $line) {
				$line = trim($line);
				if ($line === '' || $line[0] === '#') continue;
				// Ignore trailing Nginx comments before checking the
				// directive terminator: "listen 80; # comment" is valid.
				$checkLine = rtrim(preg_replace('/\s+#.*$/', '', $line));
				if ($checkLine === ''
					|| substr($checkLine, -1) === ';'
					|| substr($checkLine, -1) === '{'
					|| substr($checkLine, -1) === '}'
					|| substr($checkLine, -1) === '\\') {
					continue;
				}
				$result['warnings'][] = sprintf(
					__('Line %d may be missing a semicolon.'),
					$lineNumber + 1
				);
			}
		} elseif ($type === 'apache') {
			$tags = array(
				'ifmodule', 'ifdefine', 'ifversion', 'directory', 'directorymatch',
				'files', 'filesmatch', 'location', 'locationmatch', 'virtualhost',
				'limit', 'limitexcept', 'proxy', 'requireall',
			);
			$stack = array();
			// Blocks mentioned in Apache comments must not affect the
			// balance check (for example "# </VirtualHost>").
			$structure = preg_replace('/^\s*#.*$/m', '', $content);
			$structure = preg_replace('/[ \t]+#.*$/m', '', $structure);
			if (preg_match_all('/<\s*(\/?)\s*([a-z][a-z0-9_]*)\b[^>]*>/i', $structure, $matches, PREG_SET_ORDER)) {
				foreach ($matches as $match) {
					$closing = $match[1] === '/';
					$tag = strtolower($match[2]);
					if (!in_array($tag, $tags, true)) continue;
					if (!$closing) {
						$stack[] = $tag;
					} else {
						$open = array_pop($stack);
						if ($open !== $tag) {
							return array(
								'ok' => false,
								'message' => sprintf(
									__('Mismatched Apache block tag: %s.'),
									'</' . $tag . '>'
								),
								'warnings' => array(),
							);
						}
					}
				}
			}
			if (!empty($stack)) {
				return array(
					'ok' => false,
					'message' => sprintf(
						__('Unclosed Apache block: %s.'),
						'<' . end($stack) . '>'
					),
					'warnings' => array(),
				);
			}
		}

		return $result;
	}
}


if (!function_exists('atm_server_config_critical_directives')) {
	/**
	 * Extract directives which can make a web server unavailable.
	 *
	 * Comments, empty lines and block braces are ignored. The function is
	 * deliberately conservative: a changed critical line requires an extra
	 * confirmation from the administrator before the file is written.
	 *
	 * @param string $content
	 * @return array
	 */
	function atm_server_config_critical_directives($content)
	{
		$critical = array(
			'listen', 'virtualhost', 'servername', 'serveralias', 'documentroot',
			'directory', 'sslcertificatefile', 'sslcertificatekeyfile', 'sslengine',
			'proxypass', 'proxypassreverse', 'rewriteengine', 'rewriterule',
			'rewritecond', 'rewritebase', 'allowoverride', 'require', 'deny',
			'allow', 'options', 'errorlog', 'customlog', 'include', 'includeoptional',
			'server', 'server_name', 'root', 'ssl_certificate', 'ssl_certificate_key',
			'location', 'proxy_pass', 'try_files', 'error_page', 'return', 'rewrite',
		);

		$out = array();
		$lines = preg_split('/\R/u', (string)$content);
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#') continue;
			$line = preg_replace('/\s+/', ' ', $line);
			if (preg_match('/^<\s*([a-z0-9_]+)/i', $line, $match)) {
				$directive = strtolower($match[1]);
			} else {
				$parts = explode(' ', $line, 2);
				$directive = strtolower($parts[0]);
			}
			if (in_array($directive, $critical, true)) {
				$out[] = $line;
			}
		}

		return array_values(array_unique($out));
	}
}


if (!function_exists('atm_server_config_critical_changes')) {
	/**
	 * Diff of critical directives between two configuration files.
	 *
	 * @param string $oldContent
	 * @param string $newContent
	 * @return array
	 */
	function atm_server_config_critical_changes($oldContent, $newContent)
	{
		$old = atm_server_config_critical_directives($oldContent);
		$new = atm_server_config_critical_directives($newContent);
		$removed = array_diff($old, $new);
		$added = array_diff($new, $old);
		$out = array();
		foreach ($removed as $line) {
			$out[] = '- ' . $line;
		}
		foreach ($added as $line) {
			$out[] = '+ ' . $line;
		}
		return $out;
	}
}
