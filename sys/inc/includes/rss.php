<?php

$cache_key = $this->module . '_rss_' . md5(atm_site_origin());
$cache_tags = array(
	'module_' . $this->module,
	'action_rss',
);


$check = $this->Register['Config']->read('rss_' . $this->module, 'rss');
if (!$check) redirect('/');


if ($this->Cache->check($cache_key)) {
	$html = $this->Cache->read($cache_key);
	
	
} else {
	// Absolute site root with the current scheme: http for a site without
	// a certificate, https for a site that has one.
	$siteOrigin = atm_site_origin();
	$moduleUrl = $siteOrigin . '/' . $this->module . '/';
	
	$html = '<?xml version="1.0" encoding="UTF-8"?>';
	$html .= '<rss version="2.0">';
	$html .= '<channel>';
	$html .= '<title>' . h($this->getCompleteMetaTag($this->Register['Config']->read('title', $this->module))) . '</title>';
	$html .= '<link>' . h($moduleUrl) . '</link>';
	$html .= '<description>' . h($this->getCompleteMetaTag($this->Register['Config']->read('description', $this->module))) . '</description>';
	$html .= '<pubDate>' . date('r') . '</pubDate>';
	$html .= '<generator>FPS RSS Generator (AtomX CMS)</generator>';

	
	// we need to know whether to show hidden
	$group = (!empty($_SESSION['user']['status'])) ? $_SESSION['user']['status'] : 0;
	$sectionModel = $this->Register['ModManager']->getModelInstance($this->module . 'Categories');
	$deni_sections = $sectionModel->getCollection(array("CONCAT(',', `no_access`, ',') NOT LIKE '%,$group,%'"));
	$ids = array();
	if ($deni_sections) {
		foreach ($deni_sections as $deni_section) {
			$ids[] = $deni_section->getId();
		}
	}
	$ids = (count($ids)) ? implode(', ', $ids) : 'NULL';
	
	$query_params = array(
		"`category_id` IN ({$ids})",
		'premoder' => 'confirmed',
	);
	if (!$this->ACL->turn(array('other', 'can_see_hidden'), false)) {
		$query_params['available'] = 1;
	}
	
	$this->Model->bindModel('category');
	$this->Model->bindModel('author');
	$records = $this->Model->getCollection(
		$query_params, 
		array(
			'limit' => $this->Register['Config']->read('rss_cnt', 'rss'),
			'order' => $this->Model->getOrderParam(),
		)
	);
	
	
	if (!empty($records) && is_array($records)) {
		$html .= '<lastBuildDate>' . date('r', strtotime($records[0]->getDate())) . '</lastBuildDate>';
		
		foreach ($records as $record) { 
			$entryUrl = (string)entryUrl($record, $this->module);
			$itemUrl = preg_match('#^[a-z][a-z0-9+.\-]*://#i', $entryUrl)
				? $entryUrl
				: $siteOrigin . '/' . ltrim($entryUrl, '/');

			$html .= '<item>';
			$html .= '<link>' . h($itemUrl) . '</link>';
			$html .= '<pubDate>' . date('r', strtotime($record->getDate())) . '</pubDate>';
			$html .= '<title>' . h($record->getTitle()) . '</title>';
			$html .= '<description><![CDATA[' . mb_substr($record->getMain(), 0, $this->Register['Config']->read('rss_lenght', 'rss')) . '<br />';
			$html .= 'Автор: ' . $record->getAuthor()->getName() . '<br />]]></description>';
			$html .= '<category>' . h($record->getCategory()->getTitle()) . '</category>';
			$html .= '<guid>' . h($siteOrigin . '/' . $this->module . '/view/' . $record->getId()) . '</guid>';
			$html .= '</item>';
		}
	}
	

	
	$html .= '</channel>';
	$html .= '</rss>';
	
	$this->Cache->write($html, $cache_key, $cache_tags);
}

echo $html; 

?>