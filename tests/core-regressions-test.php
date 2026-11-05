<?php
// Regression checks use an isolated filesystem and never load site settings.
$sourceRoot = dirname(__DIR__);
define('ROOT', sys_get_temp_dir() . '/atomx-regression-' . bin2hex(random_bytes(8)));
mkdir(ROOT . '/sys/cache/sql', 0755, true);
mkdir(ROOT . '/sys/logs/system_log', 0755, true);
class Config {
    public static $limit = 0;
    public static function read($key, $module = null) { return $key === 'max_log_size' ? self::$limit : null; }
}
class Register implements ArrayAccess {
    private static $instance;
    public $db;
    public static function getInstance() { return self::$instance ?: (self::$instance = new self); }
    public function offsetExists($offset): bool { return $offset === 'DB' && $this->db !== null; }
    public function offsetGet($offset): mixed { return $this->db; }
    public function offsetSet($offset, $value): void { $this->db = $value; }
    public function offsetUnset($offset): void { $this->db = null; }
}
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function atm_password_verify($plain, $hash) { return password_verify($plain, $hash); }
require $sourceRoot . '/sys/inc/fpspdo.class.php';
require $sourceRoot . '/sys/inc/logination.class.php';
require $sourceRoot . '/sys/inc/admin_security.lib.php';
try {
    $db = (new ReflectionClass('FpsPDO'))->newInstanceWithoutConstructor();
    $params = new ReflectionProperty('FpsPDO', 'queryParams');
    $params->setAccessible(true);
    $sql = 'SELECT * FROM users WHERE id = :id1';
    $params->setValue($db, array(':id1'=>1));
    $db->writeSqlCache($sql, array('id'=>1));
    $params->setValue($db, array(':id1'=>2));
    check(!$db->turnSqlCache($sql), 'Different bound values share a cache entry');
    $db->writeSqlCache($sql, array('id'=>2));
    $params->setValue($db, array(':id1'=>1));
    check($db->getSqlCache($sql) === array('id'=>1), 'First cached result was overwritten');

    $log = new Logination;
    $logDir = ROOT . '/sys/logs/system_log/';
    foreach (array(1,2) as $n) file_put_contents($logDir.date('Y-m-d').'_'.$n.'.dat', str_repeat('x',1000001));
    $method = new ReflectionMethod('Logination', 'getFileName'); $method->setAccessible(true);
    check($method->invoke($log) === date('Y-m-d').'_3.dat', 'Rotation did not advance past file two');
    foreach (glob($logDir.'*.dat') as $file) unlink($file);
    mkdir($logDir.'undeletable.dat');
    Config::$limit=1;
    new Logination; // A failed unlink must terminate rather than exhaust the worker.
    rmdir($logDir.'undeletable.dat');

    class BrokenSecurityDB {
        public function getFullTableName($table) { return $table; }
        public function escape($value) { return addslashes($value); }
        public function query($sql) { throw new RuntimeException('Simulated store outage'); }
    }
    Register::getInstance()->db = new BrokenSecurityDB;
    check(atm_admin_2fa_get(7) === false, 'Store outage confused with absent 2FA');
    check(atm_admin_2fa_enabled(7), 'Store outage bypassed second factor');
    check(!atm_admin_2fa_verify_code(7,'123456'), 'Store outage accepted a code');
    check(atm_admin_session_is_revoked('test-session'), 'Store outage bypassed session revocation');

    class BackupDB {
        public $consumed=0;
        public $failWrite=false;
        public $row;
        public function getFullTableName($table) { return $table; }
        public function escape($value) { return addslashes($value); }
        public function query($sql) {
            if (strpos($sql,'SELECT *')===0) return array($this->row);
            if (strpos($sql,'UPDATE')===0) { if($this->failWrite) throw new RuntimeException('Write failed'); return array(); }
            if (strpos($sql,'SELECT ROW_COUNT')===0) return array(array('consumed'=>$this->consumed));
            return array();
        }
    }
    $backup = new BackupDB;
    $backup->row=array('enabled'=>1,'secret'=>'JBSWY3DPEHPK3PXP','backup_codes'=>json_encode(array(password_hash('ABCD1234', PASSWORD_DEFAULT))));
    Register::getInstance()->db=$backup;
    check(!atm_admin_2fa_verify_code(7,'ABCD-1234'), 'Lost compare-and-swap accepted code');
    $backup->failWrite=true;
    check(!atm_admin_2fa_verify_code(7,'ABCD-1234'), 'Failed write accepted code');
    $backup->failWrite=false;$backup->consumed=1;
    check(atm_admin_2fa_verify_code(7,'ABCD-1234',$used,$remaining) && $used && $remaining===0, 'Successful atomic consumption failed');
    echo "Core regression checks passed.\n";
} finally {
    foreach(glob(ROOT.'/sys/cache/sql/*.dat') as $file) unlink($file);
    rmdir(ROOT.'/sys/cache/sql');rmdir(ROOT.'/sys/cache');
    rmdir(ROOT.'/sys/logs/system_log');rmdir(ROOT.'/sys/logs');rmdir(ROOT.'/sys');rmdir(ROOT);
}
