<?php
/**
 * Static checks for the Unicode emoji picker.
 *
 * These checks do not need a database or a web server. They guard the
 * dataset, the picker assets and the editor integration. Run with:
 *
 *     php tests/emoji-picker-test.php
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

$pickerJs = atm_test_read($root . '/sys/js/emoji/emoji-picker.js');
$pickerCss = atm_test_read($root . '/sys/js/emoji/emoji-picker.css');
$buildScript = atm_test_read($root . '/sys/js/emoji/build-emoji-data.py');
$wysibbConfig = atm_test_read($root . '/sys/plugins/wysibb/config.php');
$pdoSource = atm_test_read($root . '/sys/inc/fpspdo.class.php');
$installSource = atm_test_read($root . '/install/do_queries.php');
$updateSource = atm_test_read($root . '/update.php');

foreach (array('emoji-picker.js', 'emoji-picker.css', 'build-emoji-data.py') as $asset) {
	atm_test_assert(is_file($root . '/sys/js/emoji/' . $asset), 'Missing sys/js/emoji/' . $asset . '.');
}

$datasets = array();
foreach (array('en', 'ru') as $lang) {
	$path = $root . '/sys/js/emoji/emoji-data-' . $lang . '.json';
	atm_test_assert(is_file($path), 'Missing emoji dataset for language ' . $lang . '.');
	$decoded = json_decode((string)@file_get_contents($path));
	$datasets[$lang] = $decoded !== null ? $decoded : null;
	atm_test_assert($datasets[$lang] !== null, 'Emoji dataset for ' . $lang . ' is not valid JSON.');
}

foreach (array_keys($datasets) as $lang) {
	atm_test_assert($datasets[$lang] !== null, 'Emoji dataset for ' . $lang . ' is not valid JSON.');
	if ($datasets[$lang] === null) {
		continue;
	}
	atm_test_assert(isset($datasets[$lang]->v), 'Emoji dataset for ' . $lang . ' has no version field.');
	atm_test_assert($datasets[$lang]->v === '17.0', 'Emoji dataset for ' . $lang . ' is not Unicode 17.0.');
	atm_test_assert(!empty($datasets[$lang]->e), 'Emoji dataset for ' . $lang . ' has no entries.');
	atm_test_assert(count($datasets[$lang]->e) > 1000, 'Emoji dataset for ' . $lang . ' is too small: ' . count($datasets[$lang]->e) . ' entries.');
}

/* Dataset row shape: glyph, category index, tone variants, label, keywords. */
$firstRu = isset($datasets['ru']->e[0]) ? $datasets['ru']->e[0] : null;
atm_test_assert(is_array($firstRu), 'First ru dataset row is missing.');
atm_test_assert(
	array_key_exists(0, $firstRu) && array_key_exists(1, $firstRu) && array_key_exists(2, $firstRu)
		&& array_key_exists(3, $firstRu) && array_key_exists(4, $firstRu),
	'First ru dataset row has the wrong shape.'
);
atm_test_assert($firstRu[2] === null || (is_array($firstRu[2]) && count($firstRu[2]) === 5), 'Skin tone variants must be a list of five entries.');

/* Picker script. */
atm_test_assert($pickerJs !== '', 'Emoji picker script is empty.');
atm_test_assert(strpos($pickerJs, 'emoji-data-') !== false, 'Emoji picker does not load a per-language dataset.');
atm_test_assert(strpos($pickerJs, 'tone') !== false, 'Emoji picker has no skin tone handling.');
atm_test_assert(strpos($pickerJs, 'wysibb') !== false, 'Emoji picker does not know about the WysiBB instance.');

/* Stylesheet. */
atm_test_assert($pickerCss !== '', 'Emoji picker stylesheet is empty.');
atm_test_assert(strpos($pickerCss, '.atm-emoji-panel button') !== false, 'Emoji picker does not neutralise theme button styles.');
atm_test_assert(strpos($pickerCss, 'text-shadow: none !important') !== false, 'Emoji picker buttons inherit the theme text shadow.');
atm_test_assert(strpos($pickerJs, 'requestIdleCallback') !== false, 'Emoji picker does not warm the dataset before the first open.');

/* Build script. */
atm_test_assert($buildScript !== '', 'Emoji dataset build script is empty.');

/* Editor integration. */
atm_test_assert(strpos($wysibbConfig, 'emoji-picker.js') !== false, 'WysiBB editor head does not load the emoji picker.');
atm_test_assert(strpos($wysibbConfig, 'emoji-picker.css') !== false, 'WysiBB editor head does not load the emoji picker styles.');
atm_test_assert(strpos($wysibbConfig, 'sys/js/emoji') !== false, 'WysiBB editor head does not use the emoji asset path.');
atm_test_assert(strpos($wysibbConfig, 'insertAtCursor') !== false, 'WysiBB editor head lost the insertAtCursor bridge.');

/* Database: utf8mb4 must be used for connections and new installs. */
atm_test_assert(strpos($pdoSource, 'charset=utf8mb4') !== false, 'DB connection does not use utf8mb4.');
atm_test_assert(strpos($installSource, 'utf8mb4') !== false, 'Install schema does not use utf8mb4.');
atm_test_assert(strpos($installSource, 'CHARSET=utf8mb4') !== false, 'Install schema engine is not utf8mb4.');
atm_test_assert(strpos($updateSource, 'ALTER TABLE') !== false, 'Update script does not contain migrations.');

echo $failures ? "FAIL\n" : "OK\n";
foreach ($failures as $failure) echo '- ' . $failure . "\n";
echo $assertions . " assertions\n";
exit($failures ? 1 : 0);
