<?php
define('ROOT',dirname(__DIR__));
require ROOT.'/sys/inc/atmjobs.class.php';require ROOT.'/sys/fnc/subscriptions.lib.php';
class Register extends ArrayObject {private static $instance;public static function getInstance(){return self::$instance ?: (self::$instance=new self);}}
class NewsletterDB {
    public $active=true;
    public function getFullTableName($name){return $name;}
    public function escape($value){return addslashes($value);}
    public function query($sql){
        if(strpos($sql,'SHOW TABLES')===0)return array(array('table'=>'mailing_subscriptions'));
        if(strpos($sql,'SELECT `active`')===0)return array(array('active'=>$this->active?1:0));
        return array(array('user_id'=>0,'email'=>'test@example.com','module'=>'','category_id'=>0,'token'=>str_repeat('a',64)));
    }
}
class NewsletterEntity {
    public function getId(){return 1;}public function getAvailable(){return 1;}public function getPremoder(){return 'confirmed';}
    public function getTitle(){return 'Title';}public function getMain(){return 'Body';}public function getDescription(){return 'Intro';}
}
class AtmMail {public static $calls=0;public static $ok=false;public function __construct($path){}public function prepare($template){}public function sendMail($to,$subject,$context){self::$calls++;return self::$ok;}}
function __($value){return $value;}function h($value){return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}function get_url($path,$a=false,$b=false){return $path;}function atm_site_origin($withBase=true){return 'https://example.com';}
function atm_search_result_visible($row,$indexing=false){return true;}
function newsletter_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/atomx-newsletter-test-'.bin2hex(random_bytes(8));putenv('ATM_JOB_DIR='.$dir);
$db=new NewsletterDB;Register::getInstance()['DB']=$db;
try{
    $entity=new NewsletterEntity;
    newsletter_check(atm_subscription_notify_material('news',$entity,array(1))===1,'Publication was not queued');
    newsletter_check(AtmMail::$calls===0,'Publication sent mail synchronously');
    atm_subscription_process_material('news',$entity,array(1),'event');
    atm_subscription_process_material('news',$entity,array(1),'event');
    $deliveries=array();foreach(glob($dir.'/pending/*.php')as$file){$job=AtmJobs::read($file);if($job['type']==='newsletter.delivery')$deliveries[]=$job['payload'];}
    newsletter_check(count($deliveries)===1,'Retrying newsletter expansion duplicated a delivery');
    $failed=false;try{atm_subscription_deliver_job($deliveries[0]);}catch(RuntimeException $e){$failed=true;}
    newsletter_check($failed,'Transport failure did not request a retry');
    AtmMail::$ok=true;atm_subscription_deliver_job($deliveries[0]);$calls=AtmMail::$calls;
    $db->active=false;atm_subscription_deliver_job($deliveries[0]);newsletter_check(AtmMail::$calls===$calls,'Queued mail ignored an unsubscribe');
    echo "Newsletter queue checks passed.\n";
}finally{
    foreach(array('pending','working','done','failed')as$part){foreach(glob($dir.'/'.$part.'/*')as$file)unlink($file);rmdir($dir.'/'.$part);}
    foreach(glob($dir.'/*')as$file)if(is_file($file))unlink($file);if(is_file($dir.'/.legacy-migrated'))unlink($dir.'/.legacy-migrated');rmdir($dir);putenv('ATM_JOB_DIR');
}
