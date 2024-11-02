<?php
if (empty($_GET['url'])) {
    die('URL не указан.');
}

$url = filter_var($_GET['url'], FILTER_SANITIZE_URL);
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    die('Некорректный URL.');
}

function siteInList($site, $list) {
    if ($site && is_array($list) && count($list) > 0) {
        foreach ($list as $item) {
            // Паттерн для проверки с учетом * в списке
            $pattern = '#^' . preg_quote(trim(mb_strtolower($item)), '#') . '$#i';
            if (preg_match($pattern, mb_strtolower($site))) {
                return true;
            }
        }
    }
    return false;
}

include_once 'sys/boot.php';

$Register = Register::getInstance();

// Преобразуем строки в массивы
$whitelist = array_map('trim', explode(',', $Register['Config']::read('whitelist_sites')));
$blacklist = array_map('trim', explode(',', $Register['Config']::read('blacklist_sites')));

$redirect = Config::read('redirect_active');
$delay = max((int)Config::read('url_delay'), 10); // Минимальная задержка 10 секунд

$in_white = false;
$in_black = false;

if ($redirect) {
    $info = parse_url($url);
    
    if (isset($info['host'])) {
        $site = mb_strtolower($info['host']);
        $in_white = siteInList($site, $whitelist);
        $in_black = (!$in_white) ? siteInList($site, $blacklist) : false;
    }
}

if ($in_white) {
    header('Location: ' . $url);
    exit();
} else {
    if (!$in_black) {
        header('Refresh: ' . $delay . '; url=' . $url);
    }

    $View = $Register['Viewer'];
    echo $View->view('redirect.html', [
        'url' => $url,
        'black' => $in_black,
        'template_path' => get_url('/template/' . Config::read('template'))
    ]);
}
