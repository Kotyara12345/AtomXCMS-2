<?php
/*---------------------------------------------\
|											   |
| @Author:       Andrey Brykin (Drunya)        |
| @Version:      1.4.0                         |
| @Project:      CMS                           |
| @Package       AtomX CMS                     |
| @subpackege    Sitemap generator             |
| @copyright     ©Andrey Brykin 2010-2011      |
| @last mod      2011/11/06                    |
|----------------------------------------------|
|											   |
| any partial or not partial extension         |
| CMS AtomX,without the consent of the         |
| author, is illegal                           |
|----------------------------------------------|
| Любое распространение                        |
| CMS AtomX или ее частей,                     |
| без согласия автора, является не законным    |
\---------------------------------------------*/
if (function_exists('set_time_limit')) @set_time_limit(0);
if (function_exists('ignor_user_abort')) @ignor_user_abort();





/**
 * @author      Andrey Brykin
 * @year        2011
 * @url         http://atomx.net
 * @package     AtomX CMS
 * @subpackage  Sitemap Generator
 * @copyright   ©Andrey Brykin
 */
#[\AllowDynamicProperties]
class FpsSitemapGen {

	public $output;
	
	private $base;
	
	/**
	 * Sitemap entries: url => last modification timestamp (or null).
	 *
	 * @var array
	 */
	private $uniqUrl = array();
	
	private $DB;
	


	public function __construct($params = array()) {
        $Register = Register::getInstance();
		$this->base = $this->detectBase();
		$this->addUrl($this->base . $this->installPath());
		$this->DB = $Register['DB'];
	}
	
	
	/**
	 * Add a sitemap entry, keeping the newest known modification date.
	 *
	 * @param string $url
	 * @param mixed $lastmod date string, timestamp or null
	 */
	private function addUrl($url, $lastmod = null) {
		$lastmod = $this->normalizeDate($lastmod);
		if (!isset($this->uniqUrl[$url])
		|| ($lastmod !== null && ($this->uniqUrl[$url] === null || $lastmod > $this->uniqUrl[$url]))) {
			$this->uniqUrl[$url] = $lastmod;
		}
	}
	
	
	/**
	 * Convert a database date to a timestamp. Empty dates such as
	 * "0000-00-00 00:00:00" have no lastmod and return null.
	 *
	 * @param mixed $date
	 * @return int|null
	 */
	private function normalizeDate($date) {
		if (empty($date)) return null;
		if (is_numeric($date)) {
			$timestamp = (int)$date;
		} else {
			$timestamp = strtotime((string)$date);
		}
		return ($timestamp === false || $timestamp <= 0) ? null : $timestamp;
	}
	
	
	/**
	 * Scheme and host the current request was made with. Falls back to
	 * localhost when the host is missing.
	 *
	 * @return string e.g. "https://www.example.com"
	 */
	private function detectBase() {
		$scheme = function_exists('atm_sitemap_scheme') ? atm_sitemap_scheme() : 'http';

		if (function_exists('atm_request_origin')) {
			$origin = atm_request_origin(false);
			if ($origin !== '') {
				$host = substr($origin, strpos($origin, '://') + 3);
				// Drop the port that belongs to the other scheme.
				$host = preg_replace('/:' . ($scheme === 'https' ? '80' : '443') . '$/', '', $host);
				return $scheme . '://' . $host;
			}
		}

		$host = '';
		if (!empty($_SERVER['HTTP_HOST'])) {
			$host = (string)$_SERVER['HTTP_HOST'];
		} elseif (!empty($_SERVER['SERVER_NAME'])) {
			$host = (string)$_SERVER['SERVER_NAME'];
		}
		$host = trim($host);
		if ($host === '' || !preg_match('/^(?:[a-z0-9._\-]+|\[[0-9a-f:.]+\])(?::[0-9]+)?$/i', $host)) {
			$host = 'localhost';
		}
		// Drop the port that belongs to the other scheme.
		$host = preg_replace('/:' . ($scheme === 'https' ? '80' : '443') . '$/', '', $host);

		return $scheme . '://' . $host;
	}


	/**
	 * Install subdirectory below the document root, e.g. "/cms" or "".
	 *
	 * @return string
	 */
	private function installPath() {
		if (!defined('WWW_ROOT')) return '';
		return '/' . trim((string)WWW_ROOT, '/');
	}
	
	
	/**
	 * Whether a link already carries its own scheme and host.
	 *
	 * @param string $link
	 * @return bool
	 */
	private function isAbsoluteUrl($link) {
		return (bool)preg_match('#^(?:[a-z][a-z0-9+.\-]*:)?//#i', $link);
	}
	
	
	/**
	 * Create sitemap. Uses Data Base data and users groups rules.
	 * Sitemap urls must be allowed for search bots.
	 *
	 * @return bool false when the file cannot be written, so the caller can
	 *              retry instead of leaving a stale map in place.
	 */
	public function createMap($type = 'xml') {
		$this->getLinks();
		$this->finalizeLinks();
		
		
		if ($type === 'xml') {
			$this->output = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
				. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n"
				. 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n"
				. 'xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9' . "\n"
				. 'http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . "\n";
				
				foreach ($this->uniqUrl as $page => $lastmod) {
					if (!$this->isAbsoluteUrl($page)) $page = $this->base . $page;
					$this->output .= '<url>' . "\n"
						. '<loc>' . $page . '</loc>' . "\n";
					if ($lastmod !== null) {
						$this->output .= '<lastmod>' . gmdate('c', $lastmod) . '</lastmod>' . "\n";
					}
					$this->output .= '</url>' . "\n";
				}
				
				$this->output .= '</urlset>';
		} else {
			//TODO
		}
		
		$file = ROOT . '/sitemap.xml';
		$written = @file_put_contents($file, $this->output, LOCK_EX);
		if ($written === false) {
			error_log('Sitemap: cannot write ' . $file);
			return false;
		}

		return true;
	}
	
	
	
	/**
	 * Percent-encode a URL for the sitemap protocol.
	 *
	 * Path and query may contain UTF-8 (HLU titles). Characters that are
	 * already valid escapes such as "%D0%9C" are kept as they are, so the
	 * URL is never encoded twice. Raw non-ASCII bytes, spaces and other
	 * unsafe characters become "%XX".
	 *
	 * @param string $url
	 * @return string
	 */
	private function encodeUrl($url) {
		return preg_replace_callback(
			'/%(?![0-9A-Fa-f]{2})|[^A-Za-z0-9\-._~!$&\'()*+,;=:@%\/?#\[\]]/',
			function ($match) {
				return rawurlencode($match[0]);
			},
			$url
		);
	}
	
	
	/**
	 * Delete dublicate and quote chars
	 */
	private function finalizeLinks() {
		$entities = array(
			'&' => '&amp;',
			'"' => '&quot;',
			'\'' => '&apos;',
			'<' => '&lt;',
			'>' => '&gt;',
		);
	
		$links = array();
		foreach ($this->uniqUrl as $link => $lastmod) {
			$link = $this->isAbsoluteUrl($link)
				? $link
				: $this->base . get_url($link);
			$link = $this->encodeUrl($link);
			$link = str_replace(array_keys($entities), $entities, $link);

			if (!isset($links[$link])
			|| ($lastmod !== null && ($links[$link] === null || $lastmod > $links[$link]))) {
				$links[$link] = $lastmod;
			}
		}
		$this->uniqUrl = $links;
	}
	
	
	
	/**
	 * build all posible links
	 */
	public function getLinks() {
		// Unique pages (Module pages)
		if (Config::read('active', 'pages')) {
			$pagesModel = new PagesModel();
			$htmlpages = $pagesModel->getCollection(array('`id` != 1', 'publish' => '1'));
			if (count($htmlpages) > 0) {
				foreach ($htmlpages as $htmlpage) {
					$this->addUrl($pagesModel->buildUrl($htmlpage->getId()));
				}
			}
		}
		
		
		// news, stat, loads, foto
		$hluex = Config::read('hlu_extention');
		$hluactive = Config::read('hlu');
		foreach (array('news', 'stat', 'loads', 'foto') as $mkey) {
			if (Config::read('active', $mkey)) {
				$this->addUrl($mkey);
			
				$entities = $this->DB->select($mkey, DB_ALL, array());
				$entitiesc = $this->DB->select($mkey . '_categories', DB_ALL, array());

				if (count($entitiesc) > 0) {
					foreach ($entitiesc as $entityc) {
						$action = '/category/';
						$categoryUrl = $mkey . $action . $entityc['id'];
						$categoryLastmod = null;
						
						foreach ($entities as $val) {
							if ($val['category_id'] == $entityc['id']) {
								$timestamp = $this->normalizeDate(isset($val['date']) ? $val['date'] : null);
								if ($timestamp !== null
								&& ($categoryLastmod === null || $timestamp > $categoryLastmod)) {
									$categoryLastmod = $timestamp;
								}
							}
						}
						$this->addUrl($categoryUrl, $categoryLastmod);
					}
				}
				
				if (count($entities) > 0) {
					$urlBuilder = new AtmUrl;
					foreach ($entities as $entity) {
						$lastmod = isset($entity['date']) ? $entity['date'] : null;
							
						$hlufile = $urlBuilder->getTmpFilePath($entity['id'], $mkey);
						if ($mkey != 'foto' && $hluactive == 1 && file_exists($hlufile)) {
							$hludata = file_get_contents($hlufile);
							if ($hludata) $this->addUrl($mkey . '/' . $hludata . $hluex, $lastmod);
						} else {
							$this->addUrl($mkey . '/view/' . $entity['id'], $lastmod);
						}
					}
				}
			}
		}
		
		
		// forum
		if (Config::read('active', 'forum')) {
			$this->addUrl('/forum/');
			
			$cats = $this->DB->select('forum_cat', DB_ALL);
			$forums = $this->DB->select('forums', DB_ALL);
			$themes = $this->DB->select('themes', DB_ALL);

			if (count($cats) > 0) {
				foreach ($cats as $cat) {
					$this->addUrl('/forum/index/' . $cat['id']);
				}
			}
			
			if (count($forums) > 0) {
				foreach ($forums as $forum) {
					$this->addUrl('/forum/view_forum/' . $forum['id']);
				}
			}
			
			if (count($themes) > 0) {
				$lastPostsLastmod = null;
				foreach ($themes as $theme) {
					$lastmod = $this->normalizeDate(!empty($theme['last_post']) ? $theme['last_post'] : $theme['time']);
					if ($lastmod !== null
					&& ($lastPostsLastmod === null || $lastmod > $lastPostsLastmod)) {
						$lastPostsLastmod = $lastmod;
					}

					$this->addUrl('/forum/view_theme/' . $theme['id'], $lastmod);
				}
				
				$this->addUrl('/forum/last_posts/', $lastPostsLastmod);
			}
			
			// forum user_posts
			$users =  $this->DB->select('users', DB_ALL, array(
				'joins' => array(
					array(
						'type' => 'LEFT',
						'alias' => 'b',
						'table' => 'posts',
						'cond' => 'b.`id_author` = a.`id`',
					),
					array(
						'type' => 'LEFT',
						'alias' => 'c',
						'table' => 'themes',
						'cond' => 'c.`id` = b.`id_theme`',
					),
				),
				'alias' => 'a',
				'fields' => array(
					'`a`.`id` as id',
					'MAX(b.`time`) as last_post',
				),
				'group' => '`a`.`id`',
			));

			if (count($users) > 0) {
				foreach ($users as $user) {
					$lastmod = isset($user['last_post']) ? $user['last_post'] : null;
					$this->addUrl('/forum/user_posts/' . $user['id'], $lastmod);
				}
			}
		}
		
		
		// Users
		if (Config::read('active', 'users')) {
			$this->addUrl('/users/');
			
			$users = $this->DB->select('users', DB_ALL);

			if (count($users) > 0) {
				foreach ($users as $user) {
					$this->addUrl('/users/info/' . $user['id']);
				}
			}
		}
		
		
		// shop
		if (Config::read('active', 'shop')) {
			try {
				$this->addShopLinks();
			} catch (Exception $e) {
				// Shop tables are missing on an installation without the module schema.
			}
		}
	}
	
	
	/**
	 * Catalog, category, product and landing-page links with real dates.
	 * Product visibility follows the storefront: only active products and
	 * active categories are listed.
	 */
	private function addShopLinks() {
		$products = $this->DB->select('shop_products', DB_ALL);
		$categories = $this->DB->select('shop_categories', DB_ALL);
		$landings = $this->DB->select('shop_landing_pages', DB_ALL);
		$landingLinks = $this->DB->select('shop_landing_page_categories', DB_ALL);

		$children = array();
		$activeCategories = array();
		foreach ($categories as $category) {
			$id = (int)$category['id'];
			$children[(int)$category['parent_id']][] = $id;
			if (!empty($category['active'])) $activeCategories[$id] = true;
		}
		$descendants = function ($categoryId) use (&$descendants, $children) {
			$ids = array((int)$categoryId);
			$queue = array((int)$categoryId);
			while ($queue) {
				$current = array_shift($queue);
				if (empty($children[$current])) continue;
				foreach ($children[$current] as $childId) {
					if (!in_array($childId, $ids, true)) {
						$ids[] = $childId;
						$queue[] = $childId;
					}
				}
			}
			return $ids;
		};

		// Active products grouped by their own category for counters and dates.
		$productsByCategory = array();
		$activeProducts = array();
		$catalogLastmod = null;
		foreach ($products as $product) {
			if (empty($product['active'])) continue;
			$lastmod = $this->normalizeDate(!empty($product['updated_at'])
				? $product['updated_at']
				: (isset($product['created_at']) ? $product['created_at'] : null));
			$categoryId = (int)$product['category_id'];
			$productsByCategory[$categoryId][] = $lastmod;
			$activeProducts[] = array($product, $lastmod);
			if ($lastmod !== null && ($catalogLastmod === null || $lastmod > $catalogLastmod)) {
				$catalogLastmod = $lastmod;
			}
		}

		$this->addUrl('/shop/', $catalogLastmod);

		// A category page lists products from the category and all of its
		// subcategories.
		foreach ($categories as $category) {
			$id = (int)$category['id'];
			if (empty($activeCategories[$id])) continue;

			$lastmod = $this->normalizeDate(!empty($category['updated_at'])
				? $category['updated_at']
				: (isset($category['created_at']) ? $category['created_at'] : null));
			foreach ($descendants($id) as $childId) {
				if (empty($productsByCategory[$childId])) continue;
				foreach ($productsByCategory[$childId] as $productLastmod) {
					if ($productLastmod !== null && ($lastmod === null || $productLastmod > $lastmod)) {
						$lastmod = $productLastmod;
					}
				}
			}

			$this->addUrl('/shop/category/' . $id . '/', $lastmod);
		}

		$hlu = Config::read('hlu');
		$hluExtension = (string)Config::read('hlu_extention');
		foreach ($activeProducts as $entry) {
			list($product, $lastmod) = $entry;
			$slug = (string)$product['clean_url_title'];
			if ($hlu == 1 && $slug !== '') {
				$url = '/shop/' . $slug . $hluExtension;
			} else {
				$url = '/shop/view/' . (int)$product['id'] . '/';
			}
			$this->addUrl($url, $lastmod);
		}

		// Landing pages only exist when at least one active category is linked.
		$landingCategories = array();
		foreach ($landingLinks as $link) {
			$landingCategories[(int)$link['landing_page_id']][] = (int)$link['category_id'];
		}
		foreach ($landings as $landing) {
			$id = (int)$landing['id'];
			if (empty($landing['active']) || empty($landingCategories[$id])) continue;

			$categoryIds = array();
			$hasActiveCategory = false;
			foreach ($landingCategories[$id] as $categoryId) {
				if (empty($activeCategories[$categoryId])) continue;
				$hasActiveCategory = true;
				foreach ($descendants($categoryId) as $childId) {
					$categoryIds[$childId] = true;
				}
			}
			if (!$hasActiveCategory) continue;

			$lastmod = $this->normalizeDate(!empty($landing['updated_at'])
				? $landing['updated_at']
				: (isset($landing['created_at']) ? $landing['created_at'] : null));
			foreach (array_keys($categoryIds) as $categoryId) {
				if (empty($productsByCategory[$categoryId])) continue;
				foreach ($productsByCategory[$categoryId] as $productLastmod) {
					if ($productLastmod !== null && ($lastmod === null || $productLastmod > $lastmod)) {
						$lastmod = $productLastmod;
					}
				}
			}

			$slug = trim((string)$landing['clean_url_title']);
			$this->addUrl('/shop/landing/' . ($slug !== '' ? $slug : $id) . '/', $lastmod);
		}
	}

}


