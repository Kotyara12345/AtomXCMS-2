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
if (!defined('DB_ALL')) define('DB_ALL', 1);

// Stub the settings reader so the scheme pin can be exercised without the CMS.
class Config
{
	public static $scheme = 'auto';
	public static $values = array();

	public static function read($title, $module = null)
	{
		if ($module !== null && isset(self::$values[$module][$title])) {
			return self::$values[$module][$title];
		}
		if (isset(self::$values[''][$title])) {
			return self::$values[''][$title];
		}
		return self::$scheme;
	}
}

class SitemapFakeDB
{
	public $tables = array();

	public function __construct($tables)
	{
		$this->tables = $tables;
	}

	public function select($table, $mode = null, $params = array())
	{
		return isset($this->tables[$table]) ? $this->tables[$table] : array();
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

/* Every page used to be marked "daily" and had no date. */
atm_test_assert(strpos($sitemap, 'changefreq') === false, 'Sitemap still emits a changefreq for every page.');
atm_test_assert(strpos($sitemap, '<lastmod>') !== false, 'Sitemap does not emit lastmod.');
atm_test_assert(strpos($sitemap, 'addUrl(') !== false, 'Sitemap entries do not go through the date-aware helper.');
atm_test_assert(strpos($sitemap, '?page=') === false, 'Sitemap still lists pagination URLs.');

require_once $root . '/sys/inc/sitemap.class.php';

$sitemapRef = new ReflectionClass('FpsSitemapGen');
$sitemapObj = $sitemapRef->newInstanceWithoutConstructor();

$normalizeDate = $sitemapRef->getMethod('normalizeDate');
$normalizeDate->setAccessible(true);
atm_test_assert($normalizeDate->invoke($sitemapObj, '0000-00-00 00:00:00') === null, 'Zero date produced a lastmod.');
atm_test_assert($normalizeDate->invoke($sitemapObj, '') === null, 'Empty date produced a lastmod.');
atm_test_assert(
	$normalizeDate->invoke($sitemapObj, '2026-11-02 17:14:33') === strtotime('2026-11-02 17:14:33'),
	'Valid material date was not converted.'
);

/* HLU titles must be percent-encoded exactly once. */
atm_test_assert(strpos($sitemap, 'rawurlencode') !== false, 'Sitemap does not percent-encode URLs.');

$encodeUrl = $sitemapRef->getMethod('encodeUrl');
$encodeUrl->setAccessible(true);
$cyrillic = '/news/' . rawurlencode('Моя_первая_новость') . '.html';
atm_test_assert($encodeUrl->invoke($sitemapObj, '/news/Моя_первая_новость.html') === $cyrillic, 'Cyrillic path was not encoded: ' . $encodeUrl->invoke($sitemapObj, '/news/Моя_первая_новость.html'));
atm_test_assert($encodeUrl->invoke($sitemapObj, '/news/%D0%9C.html') === '/news/%D0%9C.html', 'Existing escape was encoded twice.');
atm_test_assert($encodeUrl->invoke($sitemapObj, '/news/%d0%9c.html') === '/news/%d0%9c.html', 'Lowercase escape was encoded twice.');
atm_test_assert($encodeUrl->invoke($sitemapObj, '/news/my news.html') === '/news/my%20news.html', 'Space was not encoded.');
atm_test_assert($encodeUrl->invoke($sitemapObj, '/news/100%.html') === '/news/100%25.html', 'Bare percent was not encoded.');
atm_test_assert($encodeUrl->invoke($sitemapObj, 'https://site.ru/news/Моя.html') === 'https://site.ru/news/' . rawurlencode('Моя') . '.html', 'Absolute URL was not encoded.');
atm_test_assert($encodeUrl->invoke($sitemapObj, '/news/view/1?x=1&y=2') === '/news/view/1?x=1&y=2', 'Query delimiters were encoded.');
atm_test_assert($encodeUrl->invoke($sitemapObj, '/news/a"b.html') === '/news/a%22b.html', 'Quote was not encoded.');

$addUrl = $sitemapRef->getMethod('addUrl');
$addUrl->setAccessible(true);
$addUrl->invoke($sitemapObj, '/news/view/1', '2026-11-01 10:00:00');
$addUrl->invoke($sitemapObj, '/news/view/1', '2026-11-02 10:00:00');
$addUrl->invoke($sitemapObj, '/news/view/1', '2026-10-01 10:00:00');
$urls = $sitemapRef->getProperty('uniqUrl');
$urls->setAccessible(true);
$entries = $urls->getValue($sitemapObj);
atm_test_assert(count($entries) === 1, 'Duplicate sitemap URL was not merged.');
atm_test_assert(
	isset($entries['/news/view/1']) && $entries['/news/view/1'] === strtotime('2026-11-02 10:00:00'),
	'Sitemap kept an older modification date.'
);

/* Shop: catalog, categories, products and landing pages with dates. */
atm_test_assert(strpos($sitemap, 'addShopLinks') !== false, 'Sitemap does not collect shop links.');
atm_test_assert(strpos($sitemap, 'shop_products') !== false, 'Sitemap does not read shop products.');
atm_test_assert(strpos($sitemap, 'shop_landing_pages') !== false, 'Sitemap does not read shop landing pages.');

Config::$values[''] = array('hlu' => 0, 'hlu_extention' => '.html');

$shopObj = $sitemapRef->newInstanceWithoutConstructor();
$dbProp = $sitemapRef->getProperty('DB');
$dbProp->setAccessible(true);
$dbProp->setValue($shopObj, new SitemapFakeDB(array(
	'shop_products' => array(
		array('id' => 1, 'category_id' => 1, 'active' => 1, 'clean_url_title' => 'one', 'updated_at' => '2026-01-02 10:00:00', 'created_at' => '2025-01-01 10:00:00'),
		array('id' => 2, 'category_id' => 2, 'active' => 1, 'clean_url_title' => 'two', 'updated_at' => '2026-01-03 10:00:00', 'created_at' => '2025-01-01 10:00:00'),
		array('id' => 3, 'category_id' => 1, 'active' => 0, 'clean_url_title' => 'hidden', 'updated_at' => '2026-02-01 10:00:00', 'created_at' => '2025-01-01 10:00:00'),
	),
	'shop_categories' => array(
		array('id' => 1, 'parent_id' => 0, 'active' => 1, 'updated_at' => '2026-01-01 10:00:00', 'created_at' => '2025-01-01 10:00:00'),
		array('id' => 2, 'parent_id' => 1, 'active' => 1, 'updated_at' => '2026-01-01 10:00:00', 'created_at' => '2025-01-01 10:00:00'),
		array('id' => 3, 'parent_id' => 0, 'active' => 0, 'updated_at' => '2026-01-01 10:00:00', 'created_at' => '2025-01-01 10:00:00'),
	),
	'shop_landing_pages' => array(
		array('id' => 5, 'active' => 1, 'clean_url_title' => 'sale', 'updated_at' => '2025-12-01 10:00:00', 'created_at' => '2025-01-01 10:00:00'),
		array('id' => 6, 'active' => 1, 'clean_url_title' => 'empty', 'updated_at' => '2026-01-01 10:00:00', 'created_at' => '2025-01-01 10:00:00'),
	),
	'shop_landing_page_categories' => array(
		array('landing_page_id' => 5, 'category_id' => 1),
	),
)));
$urls->setValue($shopObj, array());
$addShopLinks = $sitemapRef->getMethod('addShopLinks');
$addShopLinks->setAccessible(true);
$addShopLinks->invoke($shopObj);
$shopUrls = $urls->getValue($shopObj);
$newest = strtotime('2026-01-03 10:00:00');

atm_test_assert(isset($shopUrls['/shop/']) && $shopUrls['/shop/'] === $newest, 'Shop catalog has no latest product date.');
atm_test_assert(!isset($shopUrls['/shop/?page=2']), 'Shop catalog pagination was added.');
atm_test_assert(isset($shopUrls['/shop/category/1/']) && $shopUrls['/shop/category/1/'] === $newest, 'Parent category lost the child product date.');
atm_test_assert(!isset($shopUrls['/shop/category/1/?page=2']), 'Category pagination was added.');
atm_test_assert(isset($shopUrls['/shop/category/2/']), 'Child category is missing.');
atm_test_assert(!isset($shopUrls['/shop/category/3/']), 'Inactive category was added.');
atm_test_assert(isset($shopUrls['/shop/view/1/']) && $shopUrls['/shop/view/1/'] === strtotime('2026-01-02 10:00:00'), 'Product page date is wrong.');
atm_test_assert(!isset($shopUrls['/shop/view/3/']), 'Inactive product was added.');
atm_test_assert(isset($shopUrls['/shop/landing/sale/']) && $shopUrls['/shop/landing/sale/'] === $newest, 'Landing page date ignores its products.');
atm_test_assert(!isset($shopUrls['/shop/landing/empty/']), 'Landing page without an active category was added.');

/* HLU changes only the product URL shape. */
Config::$values['']['hlu'] = 1;
$hluObj = $sitemapRef->newInstanceWithoutConstructor();
$dbProp->setValue($hluObj, new SitemapFakeDB(array(
	'shop_products' => array(
		array('id' => 1, 'category_id' => 1, 'active' => 1, 'clean_url_title' => 'one', 'updated_at' => '2026-01-02 10:00:00', 'created_at' => null),
	),
	'shop_categories' => array(),
	'shop_landing_pages' => array(),
	'shop_landing_page_categories' => array(),
)));
$urls->setValue($hluObj, array());
$addShopLinks->invoke($hluObj);
$hluUrls = $urls->getValue($hluObj);
atm_test_assert(isset($hluUrls['/shop/one.html']), 'HLU product URL is missing.');
atm_test_assert(!isset($hluUrls['/shop/view/1/']), 'HLU product still got the id URL.');
Config::$values['']['hlu'] = 0;

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
