<?php
/*---------------------------------------------\
|											   |
| @Author:       Andrey Brykin (Drunya)        |
| @Version:      2.0                           |
| @Project:      CMS                           |
| @Package:      AtomX CMS                     |
| @subpackege    Data Base library             |
| @copyright     ©Andrey Brykin 2010-2013      |
| @last mod.     2026                          |
|----------------------------------------------|
|											   |
|  any partial or not partial extension        |
|  CMS AtomX,without the consent of the        |
|  author, is illegal                          |
|----------------------------------------------|
|  Любое распространение                       |
|  CMS AtomX или ее частей,                    |
|  без согласия автора, является не законным   |
\---------------------------------------------*/

if (!defined('DB_ALL')) define ('DB_ALL', 'DB_ALL');
if (!defined('DB_FIRST')) define ('DB_FIRST', 'DB_FIRST');
if (!defined('DB_COUNT')) define ('DB_COUNT', 'DB_COUNT');

require_once __DIR__ . '/fpspdo.class.php';


/**
 * Legacy database driver.
 *
 * The old implementation was based on the mysql_* extension which was
 * removed in PHP 7.0, so on modern PHP versions it could not work at
 * all. The class keeps its name and public API for backward
 * compatibility, but all the work is delegated to the PDO based
 * driver (FpsPDO).
 *
 * @version       2.0.0
 */
#[\AllowDynamicProperties]
class FpsDataBase extends FpsPDO {

	/**
	 * Singleton getter. Kept for the code which uses
	 * FpsDataBase::get() directly.
	 */
	public static function get() {
		if (!self::$instance) {
			self::$instance = new FpsDataBase;
		}
		return self::$instance;
	}
}
