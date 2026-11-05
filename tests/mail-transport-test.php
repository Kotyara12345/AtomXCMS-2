<?php
if(!isset($argv[1]) || $argv[1]!=='child'){
    $process=proc_open(array(PHP_BINARY,'-d','opcache.enable_cli=0','-d','disable_functions=mail',__FILE__,'child'),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot start transport test');
    fclose($pipes[0]);echo stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);if($error)fwrite(STDERR,$error);exit($code);
}
if(!function_exists('mail')){function mail($to,$subject,$body,$headers=''){$GLOBALS['test_mail_called']=true;return false;}}
class Config{public static function read($key){return 'test@example.com';}}
class Fps_Viewer_Loader{}
class Fps_Viewer_Manager{public function __construct($loader){}public function parseTemplate($body,$context){return $body;}}
function atm_site_origin(){return 'https://example.com';}
define('DS',DIRECTORY_SEPARATOR);$_SERVER['SERVER_NAME']='example.com';
require dirname(__DIR__).'/sys/inc/AtmMail.class.php';
$mail=new AtmMail(dirname(__DIR__));$mail->prepare('Hello test');
if($mail->sendMail('test@example.com','Subject')!==false)throw new RuntimeException('mail() rejection reported as success');
if(empty($GLOBALS['test_mail_called']))throw new RuntimeException('Transport stub was not called');
echo "Mail transport checks passed.\n";
