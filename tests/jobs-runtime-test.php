<?php
define('ROOT',dirname(__DIR__));
require ROOT.'/sys/inc/atmjobs.class.php';
$dir=sys_get_temp_dir().'/atomx-jobs-test-'.bin2hex(random_bytes(8));
putenv('ATM_JOB_DIR='.$dir);
function job_check($ok,$message) { if(!$ok) throw new RuntimeException($message); }
try {
    $id=AtmJobs::enqueue('newsletter.delivery',array('to'=>'test@example.com'),'one',true);
    AtmJobs::enqueue('newsletter.delivery',array(),'one',true);
    job_check(count(glob($dir.'/pending/*.php'))===1,'Duplicate queued job');
    $count=0;
    AtmJobs::run(function($type,$payload)use(&$count){$count++;});
    AtmJobs::enqueue('newsletter.delivery',array(),'one',true);
    job_check($count===1 && count(glob($dir.'/pending/*.php'))===0,'Completed delivery was queued again');
    $id=AtmJobs::enqueue('search.rebuild',array(),'search');
    AtmJobs::run(function(){throw new RuntimeException('Simulated failure');});
    $path=$dir.'/pending/'.$id.'.php';$job=AtmJobs::read($path);
    job_check($job['attempts']===1 && $job['available']>time(),'Failed job was not delayed');
    $job['available']=0;file_put_contents($path,"<?php http_response_code(404); exit; ?>\n".json_encode($job));
    rename($path,$dir.'/working/'.$id.'.php');
    AtmJobs::run(function()use(&$count){$count++;});
    job_check($count===2,'Interrupted job was not recovered');
    AtmJobs::enqueue('search.rebuild',array(),'search');
    AtmJobs::run(function()use(&$count){$count++;AtmJobs::enqueue('search.rebuild',array(),'search');},1);
    job_check(count(glob($dir.'/pending/*.php'))===1,'Edit during a rebuild was lost');
    echo "Background queue runtime checks passed.\n";
} finally {
    foreach(array('pending','working','done','failed') as $part){foreach(glob($dir.'/'.$part.'/*') as $file)unlink($file);rmdir($dir.'/'.$part);}
    foreach(glob($dir.'/*') as $file)if(is_file($file))unlink($file);
    if(is_file($dir.'/.legacy-migrated'))unlink($dir.'/.legacy-migrated');rmdir($dir);putenv('ATM_JOB_DIR');
}
