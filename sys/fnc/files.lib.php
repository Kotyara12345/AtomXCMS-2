<?php
##################################################
##												##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      0.7                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackege    Geting file size function      ##
## copyright     ©Andrey Brykin 2010-2011       ##
##################################################


##################################################
##												##
## any partial or not partial extension         ##
## CMS AtomX,without the consent of the         ##
## author, is illegal                           ##
##################################################
## Любое распространение                        ##
## CMS AtomX или ее частей,                     ##
## без согласия автора, является не законным    ##
##################################################

// Возвращает размер файла в Кб
function getFileSize( $file )
{
  return number_format( (filesize($file)/1024), 2, '.', '' );
}



function deleteAttach($module, $entity_id, $attachNum) {
    $Register = Register::getInstance();

	$attachModelClass = $Register['ModManager']->getModelNameFromModule($module . 'Attaches');
	$attachModel = new $attachModelClass;
	$attaches = $attachModel->getCollection(array(
		'entity_id' => $entity_id,
		'attach_number' => $attachNum,
	), array(
	));

    if (count($attaches) > 0 && is_array($attaches)) {
		foreach ($attaches as $attach) {
			if (!empty($attach)) {
				$filePath = ROOT . '/sys/files/' . $module . '/' . $attach->getFilename();
				if (file_exists($filePath)) {
					atm_webp_delete($filePath);
					_unlink($filePath);
				}
				$attach->delete();
			}
		}
    }
    return true;
}



/**
 * Download attached files
 *
 * @param string $module
 * @param int $entity_id
 */
function downloadAttaches($module, $entity_id) {
	$Register = Register::getInstance();

	$attaches = true;
	if (empty($entity_id) || !is_numeric($entity_id)) return false;
	$files_dir = ROOT . '/sys/files/' . $module . '/';
	// delete collizions if exists 
	//$this->deleteCollizions(array('id' => $post_id), true);
	
	
	$max_attach = Config::read('max_attaches', $module);
	if (empty($max_attach) || !is_numeric($max_attach)) $max_attach = 5;
	for ($i = 1; $i <= $max_attach; $i++) {
		$attach_name = 'attach' . $i;
		if (!empty($_FILES[$attach_name]['name'])) {
			$validated = atm_validate_upload($_FILES[$attach_name]);
			if ($validated === false) { $attaches = false; continue; }
		
		
			// Извлекаем из имени файла расширение
			$filename = getSecureFilename($_FILES[$attach_name]['name'], $files_dir);
			$ext = strrchr($_FILES[$attach_name]['name'], ".");

			$is_image = $validated['is_image'] ? 1 : 0;

			// Перемещаем файл из временной директории сервера в директорию files
			if (move_uploaded_file($_FILES[$attach_name]['tmp_name'], $files_dir . $filename)) {
				if ($is_image == '1') {
					$watermark_path = ROOT . '/sys/img/' . (Config::read('watermark_type') == '1' ? 'watermark_text.png' : Config::read('watermark_img'));
					if (Config::read('use_watermarks') && !empty($watermark_path) && file_exists($watermark_path)) {
						$waterObj = new FpsImg;
						$save_path = $files_dir . $filename;
						$waterObj->createWaterMark($save_path, $watermark_path);
					}
				}
				chmod($files_dir . $filename, 0644);
				if ($is_image == '1') {
					atm_webp_after_upload($files_dir . $filename);
				}
				$attach_file_data = array(
					'entity_id'     => $entity_id,
					'user_id'       => $_SESSION['user']['id'],
					'attach_number' => $i,
					'filename'      => $filename,
					'size'          => $_FILES[$attach_name]['size'],
					'date'          => new Expr('NOW()'),
					'is_image'      => $is_image,
				);
				
				$className = ucfirst($module) . 'AttachesEntity';
				$entity = new $className($attach_file_data);
				$entity->save();
			}
		}
	}
	
	return $attaches;
}


/**
 * Download attached files
 *
 * @param string $module
 * @param int $entity_id
 */
function downloadAtomAttaches($module, $entity_id = '') {
	$Register = Register::getInstance();
	$user = (!empty($_SESSION['user'])) ? $_SESSION['user'] : array('id' => 0, 'name' => __('Guest'));

	$attaches = array();
	$files_dir = ROOT . '/sys/files/' . $module . '/';
	//$files_dir = ROOT . '/tmp/';


	$max_attach = Config::read('max_attaches', $module);
	if (empty($max_attach) || !is_numeric($max_attach)) $max_attach = 5;
	for ($i = 1; $i <= $max_attach; $i++) {
		$attach_name = 'attach' . $i;
		if (!empty($_FILES[$attach_name]['name'])) {
			$validated = atm_validate_upload($_FILES[$attach_name]);
			if ($validated === false) continue;
		
		
			// Извлекаем из имени файла расширение
			$filename = getSecureFilename($_FILES[$attach_name]['name'], $files_dir);
			$ext = strrchr($_FILES[$attach_name]['name'], ".");

			$is_image = $validated['is_image'];

			// Перемещаем файл из временной директории сервера в директорию files
			if (move_uploaded_file($_FILES[$attach_name]['tmp_name'], $files_dir . $filename)) {
				if ($is_image == '1') {
					$watermark_path = ROOT . '/sys/img/' . (Config::read('watermark_type') == '1' ? 'watermark_text.png' : Config::read('watermark_img'));
					if (Config::read('use_watermarks') && !empty($watermark_path) && file_exists($watermark_path)) {
						$waterObj = new FpsImg;
						$save_path = $files_dir . $filename;
						$waterObj->createWaterMark($save_path, $watermark_path);
					}
				}
				chmod($files_dir . $filename, 0644);
				if ($is_image == '1') {
					atm_webp_after_upload($files_dir . $filename);
				}
				$attach_file_data = array(
					'entity_id'     => $entity_id,
					'user_id'       => $user['id'],
					'attach_number' => $i,
					'filename'      => $filename,
					'size'          => $_FILES[$attach_name]['size'],
					'date'          => new Expr('NOW()'),
					'is_image'      => $is_image,
				);
				
				$className = ucfirst($module) . 'AttachesEntity';
				$entity = new $className($attach_file_data);
				$id = $entity->save();
				$entity->setId($id);
				$attaches[] = array_merge($entity->asArray(), array('user' => $user));
			}
		}
	}
	
	return $attaches;
}


/**
 * Create secure and allowed filename.
 * Check to dublicate;
 *
 * @param string $filename
 * @param string $dirToCheck - dirrectory to check by dublicate
 * @return string
 */
function getSecureFilename($filename, $dirToCheck) {
	if (empty($filename) || !is_string($filename)) {
        $filename = md5(microtime().rand(0, 99999)) . '-' . date("Y-m-d-H-i-s") . '.txt';
	}
	
	
	$ext = strrchr($filename, ".");
	$ext = (isPermittedFile($ext)) ? strtolower($ext) : '.txt';

	
	$filename = bin2hex(random_bytes(16));
	while (file_exists($dirToCheck . $filename . $ext)) {
		$filename .= rand(0, 999);
		clearstatcache();
	}
	return $filename . $ext;
}



function isImageFile($mime, $ext = null) {
	/**
	 * Types of images
	 */
	$allowed_types = array('image/jpeg','image/jpg','image/gif','image/png');
	/**
	 * Images extensions
	 */
	$img_extentions = array('.png','.jpg','.gif','.jpeg');
	
	$is_image = in_array($mime, $allowed_types);
	if (!empty($ext)) $is_image = $is_image && in_array(strtolower($ext), $img_extentions);
	return $is_image;
}



function isPermittedFile($ext) {
	return in_array(strtolower((string)$ext), array('.jpg','.jpeg','.png','.gif','.webp','.pdf','.txt','.csv',
		'.zip','.rar','.7z','.gz','.tar','.bz2','.mp3','.mp4','.ogg','.wav','.webm',
		'.doc','.docx','.xls','.xlsx','.ppt','.pptx','.odt','.ods'), true);
}

/** Validate bytes on disk, never the MIME or size supplied by the browser. */
function atm_validate_upload($file, $imagesOnly = false, $requireUploaded = true) {
	if (!is_array($file) || !isset($file['name'], $file['tmp_name']) || !is_string($file['name']) || !is_string($file['tmp_name'])) return false;
	if (isset($file['error']) && (int)$file['error'] !== UPLOAD_ERR_OK) return false;
	$tmp = $file['tmp_name'];
	if (!is_file($tmp) || ($requireUploaded && !is_uploaded_file($tmp))) return false;
	$limit = (int)getenv('ATM_UPLOAD_MAX_BYTES');
	if ($limit < 1) $limit = 20 * 1024 * 1024;
	$size = filesize($tmp);
	if ($size === false || $size < 1 || $size > $limit) return false;
	$ext = '.' . strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
	if (!isPermittedFile($ext) || !class_exists('finfo')) return false;
	$mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
	$images = array('.jpg'=>'image/jpeg','.jpeg'=>'image/jpeg','.png'=>'image/png','.gif'=>'image/gif','.webp'=>'image/webp');
	$isImage = isset($images[$ext]);
	if ($imagesOnly && !$isImage) return false;
	if ($isImage) {
		$info = @getimagesize($tmp);
		if (!$info || $mime !== $images[$ext] || empty($info['mime']) || $info['mime'] !== $mime
			|| $info[0] < 1 || $info[1] < 1 || $info[0] > 10000 || $info[1] > 10000 || $info[0] * $info[1] > 20000000) return false;
		$memory = trim((string)ini_get('memory_limit'));
		if ($memory !== '-1') {
			$bytes = (float)$memory;
			$suffix = strtolower(substr($memory, -1));
			if ($suffix === 'g') $bytes *= 1024 * 1024 * 1024;
			elseif ($suffix === 'm') $bytes *= 1024 * 1024;
			elseif ($suffix === 'k') $bytes *= 1024;
			if ($bytes > 0 && $info[0] * $info[1] * 6 + $size * 2 + 8388608 > $bytes - memory_get_usage(true)) return false;
		}
		if (!function_exists('imagecreatefromstring')) return false;
		$image = @imagecreatefromstring(file_get_contents($tmp));
		if ($image === false) return false;
		unset($image);
	} else {
		$types = array(
			'.pdf'=>array('application/pdf'), '.txt'=>array('text/plain'), '.csv'=>array('text/plain','text/csv','application/csv'),
			'.zip'=>array('application/zip','application/x-zip-compressed'), '.rar'=>array('application/vnd.rar','application/x-rar'),
			'.7z'=>array('application/x-7z-compressed'), '.gz'=>array('application/gzip','application/x-gzip'),
			'.tar'=>array('application/x-tar'), '.bz2'=>array('application/x-bzip2'),
			'.mp3'=>array('audio/mpeg'), '.mp4'=>array('video/mp4'), '.ogg'=>array('audio/ogg','application/ogg'),
			'.wav'=>array('audio/x-wav','audio/wav'), '.webm'=>array('video/webm','audio/webm'),
			'.doc'=>array('application/msword','application/x-ole-storage'), '.xls'=>array('application/vnd.ms-excel','application/x-ole-storage'),
			'.ppt'=>array('application/vnd.ms-powerpoint','application/x-ole-storage')
		);
		foreach (array('.docx','.xlsx','.pptx','.odt','.ods') as $office) $types[$office] = array('application/zip',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation','application/vnd.oasis.opendocument.text','application/vnd.oasis.opendocument.spreadsheet');
		if (!isset($types[$ext]) || !in_array($mime, $types[$ext], true)) return false;
	}
	return array('extension'=>$ext, 'mime'=>$mime, 'size'=>$size, 'is_image'=>$isImage);
}

