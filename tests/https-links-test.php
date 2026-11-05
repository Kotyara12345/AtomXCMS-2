<?php
/**
 * Checks for absolute site links sent outside the page (e-mail, feeds).
 *
 * A site without an HTTPS certificate must keep http:// in its mail and
 * feed links; a site with one must keep https://. The scheme therefore
 * comes from the request, not from a fixed default.
 *
 *     php tests/https-links-test.php
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

if (!defined('WWW_ROOT')) define('WWW_ROOT', '/cms');

require_once $root . '/sys/inc/https.lib.php';

/* Scheme comes from the request. */
$_SERVER = array('HTTP_HOST' => 'www.example.com', 'HTTPS' => 'on', 'SERVER_PORT' => '443');
atm_test_assert(atm_site_origin() === 'https://www.example.com/cms', 'HTTPS origin lost the install path: ' . atm_site_origin());
atm_test_assert(atm_site_origin(false) === 'https://www.example.com', 'HTTPS origin without base is wrong: ' . atm_site_origin(false));

/* No certificate: requests arrive over HTTP and the links must stay HTTP. */
$_SERVER = array('HTTP_HOST' => 'example.com', 'SERVER_PORT' => '80');
atm_test_assert(atm_site_origin() === 'http://example.com/cms', 'HTTP origin is wrong: ' . atm_site_origin());
atm_test_assert(atm_site_origin(false) === 'http://example.com', 'HTTP origin without base is wrong: ' . atm_site_origin(false));

/* Forwarded HTTPS and the default port. */
putenv('ATM_TRUSTED_PROXIES=127.0.0.1');
$_SERVER = array('HTTP_HOST' => 'example.com:443', 'HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '127.0.0.1');
atm_test_assert(atm_site_origin() === 'https://example.com/cms', 'Forwarded HTTPS origin is wrong: ' . atm_site_origin());
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
atm_test_assert(!atm_request_is_https(), 'Untrusted proxy header accepted.');
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'invalid';
atm_test_assert(atm_request_client_ip() === '192.0.2.1', 'Spoofed client IP accepted.');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8::1';
atm_test_assert(atm_request_client_ip() === '2001:db8::1', 'Trusted IPv6 client rejected.');
putenv('ATM_TRUSTED_PROXIES');

/* CLI cron: no request host, fall back to SERVER_NAME. */
$_SERVER = array('SERVER_NAME' => 'example.com');
atm_test_assert(atm_site_origin() === 'http://example.com/cms', 'SERVER_NAME fallback is wrong: ' . atm_site_origin());

/* Garbage host must not reach the header. */
$_SERVER = array('HTTP_HOST' => 'bad<host>');
atm_test_assert(atm_site_origin() === 'http://localhost/cms', 'Invalid host was accepted: ' . atm_site_origin());

/* Source guards: mail code must use the helper and no fixed scheme. */
$mail = atm_test_read($root . '/sys/inc/AtmMail.class.php');
$users = atm_test_read($root . '/modules/users/index.php');
$subscriptions = atm_test_read($root . '/sys/fnc/subscriptions.lib.php');
$rss = atm_test_read($root . '/sys/inc/includes/rss.php');
$forum = atm_test_read($root . '/modules/forum/index.php');
$helpers = atm_test_read($root . '/sys/inc/helpers.lib.php');

atm_test_assert(strpos($mail, 'atm_site_origin') !== false, 'Mailer still builds site_url by hand.');
atm_test_assert(substr_count($users, 'atm_site_origin') >= 3, 'User activation or PM links still use a fixed scheme.');
atm_test_assert(strpos($subscriptions, "'http://' . get_server_host()") === false, 'Subscriptions still prefix links with http://.');
atm_test_assert(strpos($subscriptions, 'atm_site_origin') !== false, 'Subscriptions do not use the site origin helper.');

/* The sitemap protocol pin must not force HTTPS into e-mail links. */
atm_test_assert(strpos($mail, 'atm_sitemap_scheme') === false, 'Mailer follows the sitemap protocol pin.');
atm_test_assert(strpos($subscriptions, 'atm_sitemap_scheme') === false, 'Subscriptions follow the sitemap protocol pin.');

/* RSS uses absolute links with the request scheme and caches per host. */
atm_test_assert(strpos($rss, 'atm_site_origin') !== false, 'RSS does not use the site origin helper.');
atm_test_assert(strpos($rss, "'http://' . \$_SERVER") === false, 'RSS still prefixes the site name with http://.');
atm_test_assert(strpos($rss, 'md5(atm_site_origin())') !== false, 'RSS cache is not split by scheme and host.');
atm_test_assert(strpos($rss, 'atm_sitemap_scheme') === false, 'RSS follows the sitemap protocol pin.');
atm_test_assert(strpos($rss, "\$siteOrigin . '/' . \$this->module . '/'") !== false, 'RSS channel link has no trailing slash.');

/* Forum links and the referer return path must follow the request scheme. */
atm_test_assert(strpos($forum, "'http://' . \$_SERVER['HTTP_HOST']") === false, 'Forum post link still hardcodes http://.');
atm_test_assert(strpos($forum, 'atm_site_origin()') !== false, 'Forum post link does not use the site origin helper.');
atm_test_assert(strpos($helpers, 'function atm_referer_path') !== false, 'Referer helper is missing.');
atm_test_assert(strpos($helpers, "#^http://") === false, 'Referer parsing still accepts only http://.');

require_once $root . '/sys/inc/helpers.lib.php';

/* Referer path: http, https and www must all be accepted for this host. */
$_SERVER = array('HTTP_HOST' => 'example.com', 'HTTP_REFERER' => 'https://example.com/forum/view_theme/3');
atm_test_assert(atm_referer_path() === '/forum/view_theme/3', 'HTTPS referer was rejected: ' . atm_referer_path());

$_SERVER = array('HTTP_HOST' => 'www.example.com', 'HTTP_REFERER' => 'http://www.example.com/forum/view_theme/3');
atm_test_assert(atm_referer_path() === '/forum/view_theme/3', 'HTTP referer with www was rejected: ' . atm_referer_path());

$_SERVER = array('HTTP_HOST' => 'example.com:8080', 'HTTP_REFERER' => 'https://example.com:8080/news/');
atm_test_assert(atm_referer_path() === '/news/', 'Referer with a port was rejected: ' . atm_referer_path());

/* A foreign host or a non-HTTP scheme must not become the return path. */
$_SERVER = array('HTTP_HOST' => 'example.com', 'HTTP_REFERER' => 'https://evil.example/forum/');
atm_test_assert(atm_referer_path() === '', 'Foreign referer was accepted: ' . atm_referer_path());

$_SERVER = array('HTTP_HOST' => 'example.com', 'HTTP_REFERER' => 'https://example.com@evil.example/forum/');
atm_test_assert(atm_referer_path() === '', 'Userinfo referer trick was accepted: ' . atm_referer_path());

$_SERVER = array('HTTP_HOST' => 'example.com', 'HTTP_REFERER' => 'javascript:alert(1)');
atm_test_assert(atm_referer_path() === '', 'Non-HTTP referer was accepted: ' . atm_referer_path());

if ($failures) {
	echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
	echo $assertions . " assertions\n";
	exit(1);
}

echo "OK\n" . $assertions . " assertions\n";
