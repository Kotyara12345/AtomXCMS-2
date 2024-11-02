<?php
@ini_set('display_errors', '0');
sleep(2);

$out = '';

// Проверяем настройки сервера
if (ini_get('safe_mode') == 1) {
    $out .= '<span style="color:#FF0000">safe_mode</span> - Возможности сервера ограничены<br />';
}

// Проверяем доступные функции
$requiredFunctions = [
    'set_time_limit',
    'chmod',
    'getImageSize',
    'imageCreateFromString',
    'imagecreatetruecolor',
    'imageCopy',
    'imageGIF',
    'imageJPEG',
    'imagePNG',
    'imagecreatefromjpeg',
    'imagecreatefromgif',
    'imagecreatefrompng',
    'imagesx',
    'imagesy',
    'imageDestroy',
    'exif_imagetype',
    'imagecopyresampled',
    'imagecolorsforindex',
    'imagecolorat',
    'imagesetpixel',
    'imagecolorclosest'
];

foreach ($requiredFunctions as $function) {
    if (!function_exists($function)) {
        $out .= '<span style="color:#FF0000">' . h($function) . '()</span> - Необходимо для обработки изображений<br />';
    }
}

// Вывод результата
if (empty($out)) {
    echo '<span style="color:#46B100">Ваш сервер настроен идеально! :)</span><br />';
} else {
    echo $out;
}

// Функция для экранирования вывода
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
?>
