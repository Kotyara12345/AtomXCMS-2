<?php
include_once 'sys/boot.php';

// Serve a full-size uploaded image in WebP when the browser supports it.
// Old browsers receive the original JPEG/PNG/GIF file.
if (isset($_GET['src'])) {
	atm_webp_serve($_GET['src']);
	die();
}

$allowed_ext = array('.png', '.gif', '.jpg', '.jpeg');


$Register = Register::getInstance();
$FpsDB = $Register['DB'];


$params = (!empty($_GET['url'])) ? explode('/', $_GET['url']) : array();
if (!empty($params[0]) && !empty($params[1])) {
	$params[0] = str_replace(' ', '/', $params[0]);
	$ext = strchr($params[1], '.');
	$ext = strtolower($ext);
	if (!in_array($ext, $allowed_ext)) die();

	
	if ($params[0] == 'loads') {
		$attach = $FpsDB->select('loads_attaches', DB_FIRST, array('cond' => array('filename' => $params[1])));
		if (count($attach) < 1) die();
	}
	
	
	// Size of future image
	if (!empty($params[2])) {
		$sample_size = (int)$params[2];
	} else {
		$sample_size = $Register['Config']->read('img_preview_size');
	}
	
	// Min allowed size
	if ($sample_size < 50) $sample_size = 50;
	

	$dest_path = ROOT . '/sys/files/' . $params[0] . '/' . $params[1];
	if (!is_file($dest_path)) die();


	// New path
	$tmpdir = ROOT . '/sys/tmp/img_cache/' . $sample_size . '/' . $params[0] . '/';
	if (!file_exists($tmpdir)) mkdir($tmpdir, 0777, true);


	$use_webp = atm_webp_enabled() && atm_webp_supported() && atm_webp_accepted();
	$cache_file = $tmpdir . $params[1] . ($use_webp ? '.webp' : '');

	if (!file_exists($cache_file)) {
		$result = resampleImage($dest_path, $cache_file, $sample_size, false, $use_webp ? 'webp' : null);
		if (!$result && is_file($cache_file)) @unlink($cache_file);

		// If WebP encoding failed, fall back to the original format.
		if (!$result && $use_webp) {
			$use_webp = false;
			$cache_file = $tmpdir . $params[1];
			if (!file_exists($cache_file)) {
				$result = resampleImage($dest_path, $cache_file, $sample_size);
			}
		}

		if (!$result || !is_file($cache_file)) die();
	}

	$mime = atm_webp_mime($cache_file);
	if ($mime === 'application/octet-stream') $mime = 'image/jpeg';

	atm_webp_clean_cache_headers();
	header('Content-Type: ' . $mime);
	header('Content-Length: ' . filesize($cache_file));
	header('Cache-Control: public, max-age=86400');
	header('Vary: Accept');
	header('X-Content-Type-Options: nosniff');
	readfile($cache_file);
}

die();
