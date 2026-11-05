<?php
include_once 'sys/boot.php';


// Check access. This script should be available only from the admin panel
if (empty($_SESSION['adm_panel_authorize']) ||
    $_SESSION['adm_panel_authorize'] < time() ||
    empty($_SESSION['user'])
) {
    die('Access denied');
} else if (!empty($_SESSION['adm_panel_authorize'])) {
    $_SESSION['adm_panel_authorize'] = (time() + Config::read('session_time', 'secure'));
}


// files storage folder
$dir = ROOT . '/sys/files/pages/';
if (!file_exists($dir)) mkdir($dir, 0777, true);


$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '');
if (empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
    || !is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403); exit;
}
$validated = atm_validate_upload(isset($_FILES['file']) ? $_FILES['file'] : null, true);
if ($validated === false) { http_response_code(400); echo json_encode(array('error'=>'Invalid image')); exit; }


if ($validated['is_image'])
{	
	// setting file's mysterious name
	$ext = '.jpg';
	$info = @getimagesize($_FILES['file']['tmp_name']);
	if ($info && $info[2] === IMAGETYPE_PNG) {
		$ext = '.png';
	} else if ($info && $info[2] === IMAGETYPE_GIF) {
		$ext = '.gif';
	}
	$filename = bin2hex(random_bytes(16)) . $validated['extension'];
	$file = $dir.$filename;
	 
	// copying
	if (!move_uploaded_file($_FILES['file']['tmp_name'], $file)) { http_response_code(500); exit; }
	atm_webp_after_upload($file);
	 
	// displaying file
	$array = array(
		'filelink' => WWW_ROOT . '/sys/files/pages/' . $filename,
	);
	echo stripslashes(json_encode($array));
}
