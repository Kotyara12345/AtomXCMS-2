<?php
##################################################
##												##
## @Author:       Andrey Brykin (Drunya)        ##
## @Version:      1.1                           ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Config class                  ##
## @copyright     ©Andrey Brykin 2010-2014      ##
## @last mod.     2014/01/10                    ##
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


/**
* Uses for read | write | clean settings
*
* @author     Andrey Brykin
* @version    1.0
* @link       http://atomx.net
*/
#[\AllowDynamicProperties]
class Config {


    static public $settings = array();


    public function __construct($path = null)
    {
		/*
		 * Loaded once per request. read() creates Config instances without a
		 * path, so reloading here would reset the live settings to defaults.
		 */
		if (empty(self::$settings)) {
			$defaults = ROOT . '/sys/settings/config.default.php';
			if (is_file($defaults)) {
				$set = null;
				include ($defaults);
				if (isset($set) && is_array($set)) {
					self::$settings = $set;
				}
			}
		}

		if ($path && is_file($path)) {
			$set = null;
			include ($path);
			if (isset($set) && is_array($set)) {
				self::$settings = array_replace_recursive(self::$settings, $set);
			}
		}
    }
	
	
	/**
	* writing settings
	*
	* @param array $set - settings for save
	*/
	static public function write($set) {
		$file = ROOT . '/sys/settings/config.php';
		/*
		 * A settings save must never wipe the database credentials. If the
		 * new settings have no db user, keep the credentials from the file.
		 */
		if (is_array($set) && empty($set['db']['user']) && is_file($file)) {
			$newSet = $set;
			$current = null;
			include ($file);
			if (isset($set) && is_array($set)) {
				$current = $set;
			}
			$set = $newSet;
			if (!empty($current['db']['user'])) {
				$set['db'] = $current['db'];
			}
		}
		if ($fopen=@fopen($file, 'w')) {
            $data = '<?php ' . "\n" . '$set = ' . var_export($set, true) . ";\n" . '?>';
			fputs($fopen, $data);
			fclose($fopen);
			/*
			 * The config is read with include(), so OPcache may keep serving
			 * the previous version for opcache.revalidate_freq seconds. The
			 * installer writes the database settings and reads them back a
			 * moment later in another request (the AJAX installation step).
			 */
			if (function_exists('opcache_invalidate')) {
				@opcache_invalidate(ROOT . '/sys/settings/config.php', true);
			}
			return true;
		}
		return false;
	}
	
	
	/**
	* read settings
	* Examples:
	* Config::read(param, module)
	* Config::read(module.param)
	*
	* @param string $title - title of setting
	* @param string $module - parent module of setting
	*/
	public static function read($title, $module = null) {
		$set = self::$settings;
		if ($title == 'all') return $set;

		if (!empty($module)) {
			if (isset($set[$module][$title])) return $set[$module][$title];
		} else {
			
			if (false !== strpos($title, '.')) {
				$params = explode('.', $title);
				$obj = new self();
				return $obj->__find($set, $params);
				
			} else {
				if (isset($set[$title])) return $set[$title];
			}
		}
		return null;
	}
	
	
	
	/**
	 * Find value in global config
	 *
	 * @Recursive
	 * @param array $conf
	 * @param array $params
	 */
	private function __find($conf, $params) {
		$first_param = array_shift($params);
		if (!isset($conf[$first_param])) return null;
		
		// last key - only return value
		if (count($params) == 0) 
			return $conf[$first_param];
		
		// not last key - one more iteration
		return $this->__find($conf[$first_param], $params);
	}
	
}
