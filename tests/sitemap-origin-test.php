<?php
/**
 * Static and behavioural checks for the sitemap base URL.
 *
 * The sitemap must use the scheme and host of the request that generated
 * it, so a site served over HTTPS (or on www) does not emit HTTP links.
 * Run with:
 *
 *     php tests/sitemap-origin-test.php
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
	return (string)@file_get_contents($path);
}

if (!defined('WWW_ROOT')) define('WWW_ROOT', '');

// Stub the settings reader so the scheme pin can be exercised without the CMS.
class Config
{
	public static $scheme = 'auto';

	public static function read($title, $module = null)
	{
		return self::$scheme;
	}
}

require_once $root . '/sys/inc/https.lib.php';
require_once $root . '/sys/inc/helpers.lib.php';

$sitemap = atm_test_read($root . '/sys/inc/sitemap.class.php');
$https = atm_test_read($root . '/sys/inc/https.lib.php');
$robots = atm_test_read($root . '/sys/fnc/robots.lib.php');
$properties = atm_test_read($root . '/sys/settings/conf_properties.php');

/* Source guards: no hardcoded scheme may survive in the URL building. */
atm_test_assert(strpos($sitemap, "'http://' . \$this->host") === false, 'Sitemap still prefixes links with a literal http://.');
atm_test_assert(strpos($sitemap, "substr(\$page, 0, 7) !== 'http://'") === false, 'Sitemap still assumes http:// in createMap().');
atm_test_assert(strpos($sitemap, 'atm_request_origin') !== false, 'Sitemap does not reuse the request origin helper.');
atm_test_assert(strpos($sitemap, 'atm_sitemap_scheme') !== false, 'Sitemap does not honour the pinned protocol.');
atm_test_assert(strpos($robots, 'atm_sitemap_scheme') !== false, 'robots.txt does not honour the pinned protocol.');
atm_test_assert(strpos($https, "function atm_request_origin") !== false, 'Request origin helper is missing.');
atm_test_assert(strpos($properties, "'sitemap_scheme'") !== false, 'Sitemap protocol setting is missing.');

/* Behaviour: HTTPS, www and forwarded protocol. */
$_SERVER = array('HTTP_HOST' => 'www.example.com', 'HTTPS' => 'on', 'SERVER_PORT' => '443');
atm_test_assert(atm_request_origin() === 'https://www.example.com', 'HTTPS origin is wrong: ' . atm_request_origin());

$_SERVER = array('HTTP_HOST' => 'example.com', 'SERVER_PORT' => '80');
atm_test_assert(atm_request_origin() === 'http://example.com', 'HTTP origin is wrong: ' . atm_request_origin());

$_SERVER = array('HTTP_HOST' => 'www.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https, http');
atm_test_assert(atm_request_origin() === 'https://www.example.com', 'Forwarded HTTPS origin is wrong: ' . atm_request_origin());

$_SERVER = array('HTTP_HOST' => 'example.com:443', 'HTTPS' => 'on');
atm_test_assert(atm_request_origin() === 'https://example.com', 'Default HTTPS port is not stripped: ' . atm_request_origin());

$_SERVER = array('HTTP_HOST' => 'example.com:8080', 'SERVER_PORT' => '80');
atm_test_assert(atm_request_origin() === 'http://example.com:8080', 'Non-standard port was lost: ' . atm_request_origin());

$_SERVER = array('HTTP_HOST' => 'evil.example.com<');
atm_test_assert(atm_request_origin() === '', 'Invalid host was accepted: ' . atm_request_origin());

$_SERVER = array('SERVER_NAME' => 'example.com');
atm_test_assert(atm_request_origin() === 'http://example.com', 'SERVER_NAME fallback is broken: ' . atm_request_origin());

/* The Sitemap settings pin can override the request protocol. */
Config::$scheme = 'auto';
$_SERVER = array('HTTP_HOST' => 'example.com', 'HTTPS' => 'on');
atm_test_assert(atm_sitemap_scheme() === 'https', 'Auto scheme ignores an HTTPS request.');
$_SERVER = array('HTTP_HOST' => 'example.com', 'SERVER_PORT' => '80');
atm_test_assert(atm_sitemap_scheme() === 'http', 'Auto scheme ignores an HTTP request.');

Config::$scheme = 'http';
$_SERVER = array('HTTP_HOST' => 'example.com', 'HTTPS' => 'on');
atm_test_assert(atm_sitemap_scheme() === 'http', 'Pinned HTTP was overridden by an HTTPS request.');

Config::$scheme = 'https';
$_SERVER = array('HTTP_HOST' => 'example.com', 'SERVER_PORT' => '80');
atm_test_assert(atm_sitemap_scheme() === 'https', 'Pinned HTTPS was overridden by an HTTP request.');

if ($failures) {
	echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
	echo $assertions . " assertions\n";
	exit(1);
}

echo "OK\n" . $assertions . " assertions\n";
