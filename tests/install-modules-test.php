<?php
/**
 * Static checks for bundled module installation.
 *
 * A fresh install must create the schema of every bundled module. Otherwise
 * the admin menu lists the module (its settings exist) while its tables do
 * not, and the first visit fatals.
 *
 *     php tests/install-modules-test.php
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

$queries = (string)@file_get_contents($root . '/install/do_queries.php');
$helpers = (string)@file_get_contents($root . '/sys/inc/helpers.lib.php');

atm_test_assert($queries !== '', 'Missing install/do_queries.php.');
atm_test_assert(strpos($queries, "glob(ROOT . '/modules/*/install_db.php')") !== false,
	'The installer does not look for bundled module schemas.');
atm_test_assert(strpos($queries, "new FpsModuleInstaller()") !== false,
	'The installer does not run the module installer.');
atm_test_assert(strpos($queries, "\$Register['DB'] = \$DB;") !== false,
	'The installer does not expose the database to the module installer.');

$modules = glob($root . '/modules/*/install_db.php');
atm_test_assert(count($modules) > 0, 'No bundled module ships install_db.php.');
foreach ($modules as $moduleSchema) {
	$name = basename(dirname($moduleSchema));
	atm_test_assert(is_file($root . '/modules/' . $name . '/install_settings.php'),
		'Module ' . $name . ' has a schema but no install_settings.php.');
}

atm_test_assert(strpos($helpers, 'function copyr(') !== false, 'Missing copyr().');
atm_test_assert(strpos($helpers, '@chmod($dest, 0666)') !== false,
	'copyr() cannot overwrite a destination made read-only by an earlier copy.');

echo $failures ? "FAIL\n" : "OK\n";
foreach ($failures as $failure) echo '- ' . $failure . "\n";
echo $assertions . " assertions\n";
exit($failures ? 1 : 0);
