<?php
/**
 * HTTPS detection and canonical redirect helpers.
 *
 * The CMS can be reached over both HTTP and HTTPS. PHP marks the session
 * cookie as "Secure" when a request comes over HTTPS, so switching schemes
 * gives the browser a cookie that is not sent over HTTP. The open editor then
 * loses its session and the authorization watcher shows a warning.
 *
 * When "Force HTTPS" is enabled in the server protocol settings, the CMS
 * bootstrap and the editor auth endpoint redirect to HTTPS before the session
 * is started. This keeps a single Secure session for the public site and the
 * admin panel.
 */

if (!function_exists('atm_request_is_https')) {
	/**
	 * Whether the current request was made over HTTPS.
	 *
	 * Supports direct HTTPS, reverse proxies and non-standard server ports.
	 *
	 * @return bool
	 */
	function atm_request_is_https()
	{
		if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
			return true;
		}
		if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https') {
			return true;
		}
		if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
			$proto = strtok((string)$_SERVER['HTTP_X_FORWARDED_PROTO'], ',');
			if ($proto !== false && strtolower(trim($proto)) === 'https') {
				return true;
			}
		}
		if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
			return true;
		}
		return false;
	}
}


if (!function_exists('atm_https_is_enabled')) {
	/**
	 * Whether the "Force HTTPS" option is enabled in the site config.
	 *
	 * The config is read directly because this helper runs before the CMS
	 * bootstrap creates the Config instance.
	 *
	 * @return bool
	 */
	function atm_https_is_enabled()
	{
		static $enabled = null;
		if ($enabled !== null) return $enabled;

		$enabled = false;
		$configFile = dirname(__DIR__) . '/settings/config.php';
		if (is_file($configFile)) {
			include $configFile;
			if (isset($set) && is_array($set)) {
				$enabled = !empty($set['force_https']);
			}
		}
		return $enabled;
	}
}


if (!function_exists('atm_https_redirect_url')) {
	/**
	 * Build the HTTPS URL for the current request.
	 *
	 * @return string Empty string when the host is missing or invalid.
	 */
	function atm_https_redirect_url()
	{
		$host = '';
		if (!empty($_SERVER['HTTP_HOST'])) {
			$host = (string)$_SERVER['HTTP_HOST'];
		} elseif (!empty($_SERVER['SERVER_NAME'])) {
			$host = (string)$_SERVER['SERVER_NAME'];
		}

		// Drop the default HTTP port so the redirect does not keep it.
		$host = preg_replace('/:80$/i', '', $host);
		if ($host === '' || !preg_match('/^(?:[a-z0-9._\-]+|\[[0-9a-f:.]+\])(?::[0-9]+)?$/i', $host)) {
			return '';
		}

		$uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
		if ($uri === '' || $uri[0] !== '/' || strpos($uri, "\r") !== false || strpos($uri, "\n") !== false) {
			$uri = '/';
		}

		return 'https://' . $host . $uri;
	}
}


if (!function_exists('atm_force_https')) {
	/**
	 * Redirect an HTTP request to HTTPS when the option is enabled.
	 *
	 * A 308 keeps the request method and body, so login and editor forms
	 * submitted from an old HTTP tab are not turned into GET requests.
	 *
	 * @return void
	 */
	function atm_force_https()
	{
		if (PHP_SAPI === 'cli') return;
		if (atm_request_is_https()) return;
		if (!atm_https_is_enabled()) return;

		$url = atm_https_redirect_url();
		if ($url === '' || headers_sent()) return;

		header('Location: ' . $url, true, 308);
		exit;
	}
}
