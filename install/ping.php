<?php
@ini_set('display_errors', 0);
if (empty($_GET['type'])) $_GET['type'] = true;

if ($_GET['type'] === true) {
	checkRequest();
} else {
	checkUpdate();
}


function checkUpdate() {
	/*
	 * The old update endpoint (home.atomx.net) no longer resolves, so the
	 * installer showed "Не удалось узнать" on every fresh install.
	 */
	echo '<a target="_blank" href="https://github.com/Drunyacoder/AtomXCMS-2/releases">AtomX CMS — releases on GitHub</a>';
}

function checkRequest() {
	@$b = file_get_contents('http://home.atomx.net/check.php?v=2.7.0Beta&d=' . $_SERVER['HTTP_HOST']);
}
?>