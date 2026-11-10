<?php
/**
 * Static check: serialized data must not instantiate classes.
 *
 * Cache files, logs, menus and module settings are stored on disk and in the
 * database. A tampered value passed to unserialize() without allowed_classes
 * can create arbitrary objects and trigger their magic methods.
 *
 *     php tests/unserialize-hardening-test.php
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

$files = array();
foreach (array('sys', 'modules', 'admin') as $dir) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)
	);
	foreach ($iterator as $file) {
		if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
			$files[] = $file->getPathname();
		}
	}
}

atm_test_assert(count($files) > 100, 'Source scan found too few PHP files.');

$calls = 0;
foreach ($files as $file) {
	$source = (string)file_get_contents($file);
	// Match one level of nested parentheses, e.g. file_get_contents($path).
	if (!preg_match_all('/unserialize\s*(\((?:[^()]|\([^()]*\))*\))/s', $source, $matches, PREG_OFFSET_CAPTURE)) {
		continue;
	}
	foreach ($matches[1] as $match) {
		$calls++;
		$call = $match[0];
		$line = substr_count(substr($source, 0, $match[1]), "\n") + 1;
		$relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
		atm_test_assert(
			strpos($call, 'allowed_classes') !== false,
			$relative . ':' . $line . ' calls unserialize() without allowed_classes.'
		);
	}
}

atm_test_assert($calls > 20, 'Source scan found too few unserialize() calls.');

if ($failures) {
	echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
	echo $assertions . " assertions\n";
	exit(1);
}

echo "OK\n" . $assertions . " assertions\n";
