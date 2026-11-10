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
) as $needle) {
	atm_test_assert(strpos($module, $needle) !== false,
		'modules/shop/index.php is missing ' . $needle . '.');
}

$layout = (string)file_get_contents($root . '/template/default/html/default/main.html');
atm_test_assert(strpos($layout, '{{ open_graph }}') !== false,
	'The default layout does not print the head metadata marker.');

if ($failures) {
	echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
	echo $assertions . " assertions\n";
	exit(1);
}

echo "OK\n" . $assertions . " assertions\n";
