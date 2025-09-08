<?php
declare(strict_types=1);

/**
 * Server Configuration Check Script
 * 
 * Проверяет доступность критически важных функций для работы с изображениями
 * и файловой системой. Оптимизирован для PHP 8.1+
 */

// Заголовки для предотвращения кеширования и обеспечения безопасности
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// Отключаем display_errors более современным способом
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Проверяем, что это GET запрос
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Функция для безопасного вывода HTML
function escapeHtml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Функция проверки доступности функций
function checkFunctions(array $functions): array
{
    $missing = [];
    
    foreach ($functions as $function) {
        if (!function_exists($function)) {
            $missing[] = $function;
        }
    }
    
    return $missing;
}

// Основные проверки
$warnings = [];

// Проверка системных функций
$systemFunctions = ['set_time_limit', 'chmod'];
$missingSystem = checkFunctions($systemFunctions);

foreach ($missingSystem as $function) {
    $warnings[] = [
        'function' => $function,
        'message' => match($function) {
            'set_time_limit' => 'Понадобится при быстром росте сайта',
            'chmod' => 'Необходимо для смены прав на файлы и папки',
            default => 'Необходима для работы системы'
        }
    ];
}

// Проверка функций обработки изображений
$imageFunctions = [
    'getImageSize' => 'Необходимо для обработки изображений',
    'imageCreateFromString' => 'Необходимо для обработки изображений',
    'imagecreatetruecolor' => 'Необходимо для обработки изображений',
    'imageCopy' => 'Необходимо для обработки изображений',
    'imageGIF' => 'Необходимо для обработки изображений GIF',
    'imageJPEG' => 'Необходимо для обработки изображений JPEG',
    'imagePNG' => 'Необходимо для обработки изображений PNG',
    'imagecreatefromjpeg' => 'Необходимо для обработки JPEG изображений',
    'imagecreatefromgif' => 'Необходимо для обработки GIF изображений',
    'imagecreatefrompng' => 'Необходимо для обработки PNG изображений',
    'imagesx' => 'Необходимо для обработки изображений',
    'imagesy' => 'Необходимо для обработки изображений',
    'imageDestroy' => 'Необходимо для обработки изображений',
    'exif_imagetype' => 'Необходимо для определения типа изображений',
    'imagecopyresampled' => 'Необходимо для изменения размера изображений',
    'imagecolorsforindex' => 'Необходимо для работы с цветами изображений',
    'imagecolorat' => 'Необходимо для работы с пикселями изображений',
    'imagesetpixel' => 'Необходимо для работы с пикселями изображений',
    'imagecolorclosest' => 'Необходимо для работы с цветами изображений'
];

$missingImage = checkFunctions(array_keys($imageFunctions));

foreach ($missingImage as $function) {
    $warnings[] = [
        'function' => $function,
        'message' => $imageFunctions[$function] ?? 'Необходима для обработки изображений'
    ];
}

// Проверка расширений
$requiredExtensions = ['gd', 'exif'];
foreach ($requiredExtensions as $extension) {
    if (!extension_loaded($extension)) {
        $warnings[] = [
            'function' => $extension . ' extension',
            'message' => 'Требуется расширение ' . strtoupper($extension) . ' для работы с изображениями'
        ];
    }
}

// Вывод результатов
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Проверка конфигурации сервера</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        .success { color: #46B100; font-weight: bold; }
        .warning { color: #FF0000; margin-bottom: 10px; }
        .container { max-width: 800px; margin: 0 auto; }
        .header { text-align: center; margin-bottom: 30px; }
        .php-version { color: #666; font-style: italic; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Проверка конфигурации сервера</h1>
            <p class="php-version">PHP <?= escapeHtml(PHP_VERSION) ?></p>
        </div>

        <?php if (empty($warnings)): ?>
            <p class="success">✅ Ваш сервер настроен идеально! Все необходимые функции доступны.</p>
        <?php else: ?>
            <div class="warnings">
                <h2>⚠️ Обнаружены проблемы:</h2>
                <?php foreach ($warnings as $warning): ?>
                    <div class="warning">
                        <strong><?= escapeHtml($warning['function']) ?>()</strong> - 
                        <?= escapeHtml($warning['message']) ?>
                    </div>
                <?php endforeach; ?>
                
                <div style="margin-top: 20px; padding: 15px; background: #f8f8f8; border-radius: 5px;">
                    <h3>Рекомендации:</h3>
                    <ul>
                        <li>Установите расширение GD: <code>sudo apt-get install php-gd</code></li>
                        <li>Установите расширение EXIF: <code>sudo apt-get install php-exif</code></li>
                        <li>Включите расширения в php.ini</li>
                        <li>Перезапустите веб-сервер после установки</li>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
