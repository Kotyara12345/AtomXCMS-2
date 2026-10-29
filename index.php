<?php
/*-----------------------------------------------\
| 												 |
|  @Author:       Andrey Brykin (Drunya)         |
|  @Version:      1.2.9                          |
|  @Project:      CMS                            |
|  @package       CMS AtomX                      |
|  @subpackege    Entry dot                      |
|  @copyright     ©Andrey Brykin 2010-2012       |
|  @last mod.     2012/04/29                     |
\-----------------------------------------------*/

/*-----------------------------------------------\
| 												 |
|  any partial or not partial extension          |
|  CMS AtomX,without the consent of the          |
|  author, is illegal                            |
|------------------------------------------------|
|  Любое распространение                         |
|  CMS AtomX или ее частей,                      |
|  без согласия автора, является не законным     |
\-----------------------------------------------*/




header('Content-Type: text/html; charset=utf-8');


/*
 * Collapse duplicate index.html URLs to their canonical directory URL.
 * Examples: /index.html -> /, /shop/index.html -> /shop/
 */
$requestMethod = isset($_SERVER['REQUEST_METHOD'])
    ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
$requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
$requestPath = $requestUri !== '' ? parse_url($requestUri, PHP_URL_PATH) : null;
$requestQuery = $requestUri !== '' ? parse_url($requestUri, PHP_URL_QUERY) : null;
if (($requestMethod === 'GET' || $requestMethod === 'HEAD')
    && is_string($requestPath)
    && preg_match('#/index\.html/?$#i', $requestPath)) {
    $target = preg_replace('#/index\.html/?$#i', '/', $requestPath);
    if ($target === '') $target = '/';
    if (is_string($requestQuery) && $requestQuery !== '') {
        $requestQuery = str_replace(array("\r", "\n"), '', $requestQuery);
        // "url" is the CMS routing parameter and must not leak into canonical URLs.
        $requestQuery = preg_replace('/(?:^|&)url(?:=[^&]*)?(?=&|$)/', '', $requestQuery);
        $requestQuery = trim($requestQuery, '&');
        if ($requestQuery !== '') $target .= '?' . $requestQuery;
    }
    header('Location: ' . $target, true, 301);
    exit;
}




if (file_exists('install')) {
	include_once ('sys/settings/config.php');
	if (!empty($set) &&
        !empty($set['db']['name']) &&
        (
            !empty($set['db']['user']) ||
            !empty($set['db']['pass'])
        )
    ) {
		die('<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>AtomX CMS - вместе в будущее</title>
<link rel="shortcut icon" href="sys/img/favicon.ico" type="image/x-icon">
<link rel="stylesheet" href="install/css/style.css" />
</head>
<body>
<div id="head">
	<div id="logo"></div>
</div>
<div id="subhead"></div>

<div id="container">
	<div id="descr">
		<h2>Внимание!</h2>
		Сайт уже установлен. Перед использованием удалите папку <b>INSTALL</b> (или переименуйте её).<br /><br />
		The site is already installed. Before use your site, delete the <b>INSTALL</b> dir (or rename it).
	</div>
</div>
<div id="footer1">
	<div style="float:left;">
		<a href="http://atomx.net/">OFF. SITE</a>
		<a href="http://home.atomx.net/">ABOUT</a>
		<a href="http://atomx.net/forum/">SUPPORT</a>
		<a href="http://atomx.net/documa/">WIKI</a>
	</div>
	<div style="clear:both;"></div>
</div>
</body>
</html>');	
	}
	header('Location: install'); die();
}




include_once 'sys/boot.php';

Plugins::intercept('before_pather', array());

/**
 * Parser URL
 * Get params from URL and launch needed module and action
 */
new Pather($Register);
Plugins::intercept('after_pather', array());
//pr($Register);
