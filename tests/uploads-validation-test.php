<?php
require dirname(__DIR__).'/sys/fnc/files.lib.php';
function upload_check($ok,$message) { if(!$ok) throw new RuntimeException($message); }
$file=tempnam(sys_get_temp_dir(),'atomx-upload-');
try {
    file_put_contents($file,'<?php echo "bad";');
    $upload=array('name'=>'image.png','tmp_name'=>$file,'type'=>'image/png','size'=>1,'error'=>UPLOAD_ERR_OK);
    upload_check(atm_validate_upload($upload,true,false)===false,'Client MIME accepted a fake image');
    $upload['name']='script.phtml';upload_check(atm_validate_upload($upload,false,false)===false,'Executable extension accepted');
    $upload['name']='image.svg';upload_check(atm_validate_upload($upload,false,false)===false,'Active SVG accepted');
    $image=imagecreatetruecolor(1,1);imagepng($image,$file);unset($image);
    $upload['name']='image.PNG';$upload['type']='application/x-php';
    upload_check(atm_validate_upload($upload,true,false)!==false,'Valid image depended on client MIME');
    upload_check(atm_validate_upload($upload,true,true)===false,'Non-uploaded local file accepted');
    putenv('ATM_UPLOAD_MAX_BYTES=1');upload_check(atm_validate_upload($upload,true,false)===false,'Disk size limit ignored');putenv('ATM_UPLOAD_MAX_BYTES');
    $name=getSecureFilename('photo.png',sys_get_temp_dir().'/');
    upload_check(preg_match('/^[0-9a-f]{32}\.png$/',$name)===1,'Filename was not randomized');
    echo "Upload validation checks passed.\n";
} finally {putenv('ATM_UPLOAD_MAX_BYTES');unlink($file);}
