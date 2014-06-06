<?php
/*---------------------------------------------\
|											   |
| @Author:       Andrey Brykin (Drunya)        |
| @Version:      1.0                           |
| @Project:      CMS                           |
| @Package       AtomX CMS                     |
| @subpackege    Fps URL class                 |
| @copyright     ©Andrey Brykin 2010-2014      |
| @last mod.     2014/02/09                    |
|----------------------------------------------|
|											   |
| any partial or not partial extension         |
| CMS Fapos,without the consent of the         |
| author, is illegal                           |
|----------------------------------------------|
| Любое распространение                        |
| CMS Fapos или ее частей,                     |
| без согласия автора, является не законным    |
\---------------------------------------------*/



/**
 * @version       1.0.0
 * @author        Andrey Brykin
 * @url           http://fapos.net
 */
class AtmUrl {

    public function saveOldEntryUrl($entity, $module, $old_title, $removeTmpFile = false)
    {
        if ($removeTmpFile === true) {
            $this->removeOldTmpFile($entity, $module);
            return true;
        }
        $matId = $entity->getId();
        $matTitle = $entity->getTitle();


        if (empty($matId))
            throw new Exception('Empty material ID');

        // Check tmp file with assocciations and build human like URL
        clearstatcache();
        $old_title = $this->getUrlByTitle($old_title);
        $tmp_file_id = $this->getTmpFilePath($matId, $module);
        $tmp_file_title = $this->getTmpFilePath($old_title, $module);
        $title = $this->getUrlByTitle($matTitle);
        $params = array(
            'id' => $matId,
            'title' => $title,
        );


        // Colission protect
        while (file_exists($tmp_file_title)) {
            $collision = json_decode(file_get_contents($tmp_file_title), true);
            if (is_array($collision) && isset($collision['id']) && $collision['id'] != $matId) {
                $old_title .= '_';
                $tmp_file_title = $this->getTmpFilePath($old_title, $module);
            } else break;
        }

        // check the old URLs (by the old title(s))
        if (file_exists($tmp_file_id)) {
            $path_to_tmp_file_title = json_decode(file_get_contents($tmp_file_id), true);
            if (count($path_to_tmp_file_title) && is_array($path_to_tmp_file_title)) {
                foreach ($path_to_tmp_file_title as $path) {
                    if (file_exists($path)) {
                        file_put_contents($path, json_encode($params));
                    }
                }
            }
        }
        if (empty($path_to_tmp_file_title) || !is_array($path_to_tmp_file_title))
            $path_to_tmp_file_title = array();
        if (!in_array($tmp_file_title, $path_to_tmp_file_title)) $path_to_tmp_file_title[] = $tmp_file_title;
        file_put_contents($tmp_file_id, json_encode($path_to_tmp_file_title));


        file_put_contents($tmp_file_title, json_encode($params));
        return true;
    }


    public function removeOldTmpFile($entity, $module)
    {
        $tmp_file_title = $this->getTmpFilePath($entity->getTitle(), $module);
        if (file_exists($tmp_file_title)) @unlink($tmp_file_title);
    }


	public function getEntryUrl($material, $module){
        $pattern = '/' . $module . '/%s';
        $title = $this->getUrlByTitle($material->getTitle());

        return h(sprintf($pattern, $title));
	}
	
	
	/**
	 * Only create HLU URL by title
	 *
	 * @param stirng title
	 * @return string
	 */
	public function getUrlByTitle($title, $use_extention = true) {
		//$title = $this->translit($title);
		$title = strtolower(preg_replace('#[^0-9a-zА-Я]#ui', '_', $title));
		$hlu_extention = Config::read('hlu_extention');
		return ($use_extention === true) ? $title . $hlu_extention : $title;
	}
	
	
	public function getTmpFilePath($filename, $module) {
		$padStr = str_pad($filename, 8, 0, STR_PAD_LEFT);
		$dir1 = substr($padStr, 0, 4);

		$tmp_dir = ROOT . '/sys/tmp/hlu_' . $module . '/' . $dir1 . '/';
		$tmp_file = $tmp_dir . $filename . '.dat';
		touchDir($tmp_dir, 0777);
		
		return $tmp_file;
	}
	
		
	/**
	 * Translit. Convert cirilic chars to 
	 * latinic chars.
	 *
	 * @param string $str
	 * @return string 
	 */
	public function translit($str) {
		$cirilic = array('й', 'ц', 'у', 'к', 'е', 'н', 'г', 'ш', 'щ', 'з', 'х', 'ъ', 'ф', 'ы', 'в', 'а', 'п', 'р', 'о', 'л', 'д', 'ж', 'э', 'я', 'ч', 'с', 'м', 'и', 'т', 'ь', 'б', 'ю', 'ё', 'Й', 'Ц', 'У', 'К', 'Е', 'Н', 'Г', 'Ш', 'Щ', 'З', 'Х', 'Ъ', 'Ф', 'Ы', 'В', 'А', 'П', 'Р', 'О', 'Л', 'Д', 'Ж', 'Э', 'Я', 'Ч', 'С', 'М', 'И', 'Т', 'Ь', 'Б', 'Ю', 'Ё');
		$latinic = array('i', 'c', 'u', 'k', 'e', 'n', 'g', 'sh', 'sh', 'z', 'h', '', 'f', 'y', 'v', 'a', 'p', 'r', 'o', 'l', 'd', 'j', 'e', 'ya', 'ch', 's', 'm', 'i', 't', '', 'b', 'yu', 'yo', 'i', 'c', 'u', 'k', 'e', 'n', 'g', 'sh', 'sh', 'z', 'h', '', 'f', 'y', 'v', 'a', 'p', 'r', 'o', 'l', 'd', 'j', 'e', 'ya', 'ch', 's', 'm', 'i', 't', '', 'b', 'yu', 'yo');
		
		return str_replace($cirilic, $latinic, $str);
	}


	/**
	 * Deprecated
	 */
    public function check($url) {
        if (empty($url) && $url === '/') return true;
        $url_params = parse_url('http://' . $_SERVER['HTTP_HOST'] . $url);
        if (!empty($url_params['path']) && $_SERVER['REQUEST_METHOD'] == 'GET') {
            return (false === (strpos($url_params['path'], '//')) &&
                (preg_match('#(\.[\w\-_]+|((%20|\s)+\w+$)#umi', $url_params['path']) ||
                preg_match('#/[^/\?&\.\s(%20)]+/$#umi', $url_params['path'])));
        }
        return true;
    }


    /**
     * @param $url
     * @return string
     */
    public static function checkAndRepair($url) {
        $url_params = parse_url('http://' . $_SERVER['HTTP_HOST'] . $url);
        if (!empty($url_params['path'])) {

            // if path doesn't like file(has extension), add slash at the end
            $url_params['path'] = rtrim($url_params['path'], '/');
            if (!preg_match('#(\.[\w\-_]+$)|((%20|\s)+\w+$)#umi', $url_params['path']))
                $url_params['path'] .= '/';

            if (false !== (strpos($url_params['path'], '//'))) {
                $url_params['path'] = preg_replace('#/+#', '/', $url_params['path']);
            }
        }
        $url = $url_params['path']
            . ((!empty($url_params['query'])) ? '?' . $url_params['query'] : '')
            . ((!empty($url_params['fragment'])) ? '#' . $url_params['fragment'] : '');
        return $url;
    }


    /**
     * @param $url
     * @param bool $notRoot
     * @return mixed
     */
    public function __invoke($url, $notRoot = false, $useLang = true) {
        if ($notRoot || substr($url, 0, 7) === 'http://') return Pather::parseRoutes($url);
		
        $lang = getLang();
		$def_lang = Config::read('language');
		$root = (
			$useLang && 
			!preg_match('#^(/?sys/.+|/image/.+|/?template/|/?admin)#', $url) &&
			$lang !== $def_lang
		) 
			? '/' . trim(WWW_ROOT, '/') . '/' . $lang . '/'
			: '/' . trim(WWW_ROOT, '/');

		if (substr($url, 0, strlen($root)) !== $root) $url = $root . $url;
        $url = self::checkAndRepair($url);
        return Pather::parseRoutes($url);
    }
}

