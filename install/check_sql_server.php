<?php
@ini_set('display_errors', 0);


if (empty($_GET['type'])) $_GET['type'] = true;

mysqli_report(MYSQLI_REPORT_OFF);

$host = isset($_GET['host']) ? $_GET['host'] : '';
$user = isset($_GET['user']) ? $_GET['user'] : '';
$pass = isset($_GET['pass']) ? $_GET['pass'] : '';
$base = isset($_GET['base']) ? $_GET['base'] : '';

/*
 * The "host:port" format is supported as well.
 */
$port = 3306;
if (strpos($host, ':') !== false) {
	$host_parts = explode(':', $host, 2);
	$host = $host_parts[0];
	$port = (int)$host_parts[1];
}

if ('base' === $_GET['type']) {
	$link = @mysqli_connect($host, $user, $pass, '', $port);
	if ($link !== false && @mysqli_select_db($link, $base) !== false) {
		echo '<span style="color:#46B100">База найдена</span>';
	} else {
		echo '<span style="color:#FF0000">Не удалось найти базу</span>';
	}
} else {
	if (false === @mysqli_connect($host, $user, $pass, '', $port)) {
		echo '<span style="color:#FF0000">Не удалось подключиться</span>';
	} else {
		echo '<span style="color:#46B100">Подключились</span>';
	}
}

?>
