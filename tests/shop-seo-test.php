<?php
/**
 * Shop SEO checks.
 *
 * Structured data must stay valid when a product title contains HTML, and
 * the storefront must keep emitting the head markers the layout prints.
 *
 *     php tests/shop-seo-test.php
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

class Config
{
	public static $structured = 1;

	public static function read($key, $module = null)
	{
		if ($key === 'structured_data') return self::$structured;
		return null;
	}
}

require_once $root . '/modules/shop/shop.lib.php';

$script = ShopHelper::jsonLdScript(array(
	'@type' => 'Product',
	'name' => 'Тест </script><b>',
));
atm_test_assert(strpos($script, 'type="application/ld+json"') !== false,
	'jsonLdScript() does not emit a JSON-LD script element.');
atm_test_assert(substr_count($script, '</script>') === 1,
	'Product data can close the JSON-LD script element.');
atm_test_assert(strpos($script, 'Тест') !== false,
	'jsonLdScript() escapes non-ASCII text.');
atm_test_assert(ShopHelper::jsonLdScript(array('x' => "\xB1\x31")) === '',
	'jsonLdScript() must return nothing for invalid UTF-8.');

$module = (string)file_get_contents($root . '/modules/shop/index.php');
foreach (array(
	"'@type' => 'Product'",
	"'AggregateOffer'",
	"'BreadcrumbList'",
	'rel="prev"',
	'rel="next"',
	'og:image',
	'noindex, follow',
	"'hasVariant'",
	"'@type' => 'Offer'",
	"'@type' => 'AggregateRating'",
) as $needle) {
	atm_test_assert(strpos($module, $needle) !== false,
		'modules/shop/index.php is missing ' . $needle . '.');
}

$layout = (string)file_get_contents($root . '/template/default/html/default/main.html');
atm_test_assert(strpos($layout, '{{ open_graph }}') !== false,
	'The default layout does not print the head metadata marker.');

atm_test_assert(substr_count($module, "cfg('structured_data', 1)") === 2,
	'Both product and breadcrumb JSON-LD must honour the structured_data setting.');
atm_test_assert(substr_count($module, 'ShopHelper::jsonLdScript(') === 2,
	'Unexpected number of JSON-LD outputs in the shop module.');

$settings = (string)file_get_contents($root . '/modules/shop/info.php');
$defaults = (string)file_get_contents($root . '/modules/shop/install_settings.php');
atm_test_assert(strpos($settings, "'structured_data' => array(") !== false,
	'info.php is missing the structured_data setting.');
atm_test_assert(strpos($settings, 'Микроразметка Schema.org') !== false,
	'info.php does not label the structured_data setting.');
atm_test_assert(strpos($defaults, "'structured_data' => 1") !== false,
	'install_settings.php does not enable structured data by default.');

class Module
{
	public function __construct($params = array())
	{
	}

	protected function getCanonicalOrigin()
	{
		return 'https://shop.test';
	}
}

require_once $root . '/modules/shop/index.php';

$reflection = new ReflectionClass('ShopModule');
$shop = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('productRating')->setValue($shop, array('avg' => 4.5, 'count' => 2));

$product = array(
	'title' => 'Кружка',
	'url' => '/shop/kruzhka/',
	'meta_description' => '',
	'short_description' => '',
	'description' => '',
);
$variants = array(
	array('title' => 'Красная', 'sku' => 'RED', 'price' => 500.0, 'available' => true),
	array('title' => 'Синяя', 'sku' => 'BLUE', 'price' => 700.0, 'available' => false),
);
$decode = function ($html) {
	if ($html === '') return array();
	$json = substr($html, strpos($html, '>') + 1);
	$json = substr($json, 0, -strlen('</script>'));
	$data = json_decode($json, true);
	return is_array($data) ? $data : array();
};

$builder = $reflection->getMethod('buildProductStructuredData');
$productData = $decode($builder->invoke($shop, $product, $variants, array('code' => 'RUB'), array()));
atm_test_assert(isset($productData['@type']) && $productData['@type'] === 'Product',
	'Product JSON-LD is not a Product.');
atm_test_assert(isset($productData['sku']) && $productData['sku'] === 'RED',
	'Product JSON-LD does not use the first available SKU.');
atm_test_assert(isset($productData['offers']['@type']) && $productData['offers']['@type'] === 'AggregateOffer',
	'Product JSON-LD has no AggregateOffer.');
atm_test_assert($productData['offers']['lowPrice'] === '500.00' && $productData['offers']['highPrice'] === '700.00',
	'AggregateOffer price range is wrong.');
atm_test_assert(isset($productData['offers']['offerCount']) && $productData['offers']['offerCount'] === 2,
	'AggregateOffer variant count is wrong.');
atm_test_assert(isset($productData['hasVariant']) && count($productData['hasVariant']) === 2,
	'Product JSON-LD does not list both variants.');
atm_test_assert($productData['hasVariant'][1]['sku'] === 'BLUE',
	'Variant JSON-LD has the wrong SKU.');
atm_test_assert(strpos($productData['hasVariant'][1]['offers']['availability'], 'OutOfStock') !== false,
	'Variant JSON-LD has the wrong availability.');
atm_test_assert(isset($productData['aggregateRating']['ratingValue']) && $productData['aggregateRating']['ratingValue'] === '4.5',
	'Aggregate rating is missing from product JSON-LD.');

Config::$structured = 0;
atm_test_assert($builder->invoke($shop, $product, $variants, array('code' => 'RUB'), array()) === '',
	'Product JSON-LD was printed while structured_data is disabled.');
Config::$structured = 1;

$reflection->getProperty('breadcrumbData')->setValue($shop, array(
	array('title' => 'Главная', 'url' => '/'),
	array('title' => 'Магазин', 'url' => '/shop/'),
));
$crumbBuilder = $reflection->getMethod('buildBreadcrumbStructuredData');
$crumbData = $decode($crumbBuilder->invoke($shop));
atm_test_assert(isset($crumbData['@type']) && $crumbData['@type'] === 'BreadcrumbList',
	'Breadcrumb JSON-LD is not a BreadcrumbList.');
atm_test_assert(isset($crumbData['itemListElement']) && count($crumbData['itemListElement']) === 2,
	'Breadcrumb JSON-LD does not list both crumbs.');
atm_test_assert($crumbData['itemListElement'][1]['item'] === 'https://shop.test/shop/',
	'Breadcrumb JSON-LD URL is not absolute.');

Config::$structured = 0;
atm_test_assert($crumbBuilder->invoke($shop) === '',
	'Breadcrumb JSON-LD was printed while structured_data is disabled.');

if ($failures) {
	echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
	echo $assertions . " assertions\n";
	exit(1);
}

echo "OK\n" . $assertions . " assertions\n";
