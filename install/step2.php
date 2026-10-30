<?php 
define('ATM_INSTALL_MODE', true);
include_once '../sys/boot.php';
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>AtomX - вместе в будущее</title>
<link rel="shortcut icon" href="../sys/img/favicon.ico" type="image/x-icon">
<link rel="stylesheet" href="css/style.css" />
<script src="../sys/js/jquery.js"></script>
</head>
<body>
<div id="head">
	<div id="logo"></div>
	<ul id="progressbar">
		<li class="progressbar-li"><img src="img/loader-progressbar.gif" style="display:none;" id="ajaxLoader3" /></li>
	</ul>
</div>
<div id="subhead"></div>


<div id="container2">

	<h3>Создание базы данных ...</h3>
	<img src="img/ajax_loader.gif" style="display:none;" id="ajaxLoader" />
	<div id="queries"></div>


	<br />
	<br />
	<br />
	<br />



</div>

<div id="footer2"></div>
<script>

function doQueries() {
	$('#ajaxLoader').show();
	$.get('do_queries.php?', function(data){
		$('#queries').html(data);
		$('#ajaxLoader').hide();
		progressBar(3);
		
		$('#queries').scrollTop(1500);
	}).fail(function(jqXHR){
		/*
		 * jQuery runs only the success callback on HTTP 4xx/5xx, so without
		 * this handler a failed request leaves the loader spinning forever.
		 */
		$('#ajaxLoader').hide();
		var details = jqXHR.responseText ? jqXHR.responseText.slice(0, 1000) : '';
		$('#queries').html('<span style="color:#FF0000">Не удалось выполнить установку (HTTP ' + jqXHR.status + '). '
			+ 'Проверьте настройки базы данных и начните установку заново с <a href="step1.php">первого шага</a>.</span>'
			+ (details ? '<div style="margin-top:10px;">' + details + '</div>' : ''));
	});
}
function progressBar(step) {
	$('#ajaxLoader3').show();
	$.get('progressbar.php?step='+step, function(data){
		$('#ajaxLoader3').hide();
		$('#progressbar').html(data);
	});
}
progressBar(2);
doQueries();
$.get('ping.php');
</script>
</body>
</html>