<?php

/**
 * WebP support helpers.
 *
 * The original image is always kept. A WebP copy is created next to it,
 * and the image endpoint serves WebP only when the browser sends
 * "Accept: image/webp". Other browsers receive the original file.
 */

/**
 * Is the GD WebP encoder available on this server?
 *
 * @return bool
 */
function atm_webp_supported()
{
    static $supported = null;

    if ($supported === null) {
        $supported = function_exists('imagewebp')
            && function_exists('imagecreatefromjpeg')
            && function_exists('imagecreatetruecolor');
    }

    return $supported;
}

/**
 * Is the WebP option enabled in the admin panel?
 *
 * @return bool
 */
function atm_webp_enabled()
{
    return (int)Config::read('webp_enabled') === 1;
}

/**
 * Quality used for WebP copies. It follows the JPEG quality setting.
 *
 * @return int
 */
function atm_webp_quality()
{
    $quality = Config::read('quality_jpeg');
    if ($quality === null || $quality === '') {
        return 75;
    }

    $quality = (int)$quality;
    if ($quality < 0 || $quality > 100) {
        $quality = 75;
    }

    return $quality;
}

/**
 * Build the path of the WebP copy for an image.
 *
 * @param string $path
 * @return string
 */
function atm_webp_path($path)
{
    $path = (string)$path;
    $ext = strrchr($path, '.');

    if ($ext === false || $ext === '.') {
        return $path . '.webp';
    }

    return substr($path, 0, -strlen($ext)) . '.webp';
}

/**
 * Does the current browser accept WebP?
 *
 * @return bool
 */
function atm_webp_accepted()
{
    if (empty($_SERVER['HTTP_ACCEPT'])) {
        return false;
    }

    return stripos((string)$_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
}

/**
 * Only public uploaded images may be converted or served by the endpoint.
 *
 * @param string $path absolute file system path
 * @return bool
 */
function atm_webp_is_allowed_path($path)
{
    $real = realpath($path);
    if ($real === false) {
        return false;
    }

    $real = str_replace('\\', '/', $real);
    $roots = array(
        realpath(ROOT . '/sys/files'),
        realpath(ROOT . '/sys/avatars'),
        realpath(ROOT . '/sys/tmp/images'),
    );

    foreach ($roots as $root) {
        if ($root === false) {
            continue;
        }

        $root = str_replace('\\', '/', rtrim($root, '/'));
        if (strncmp($real, $root . '/', strlen($root) + 1) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * Animated GIFs must stay animated, so they are not converted to a static WebP.
 *
 * @param string $path
 * @return bool
 */
function atm_webp_gif_is_animated($path)
{
    $data = @file_get_contents($path, false, null, 0, 1024 * 1024);
    if ($data === false) {
        return true;
    }

    return strpos($data, 'NETSCAPE2.0') !== false
        || strpos($data, 'ANIMEXTS1.0') !== false;
}

/**
 * Create a WebP copy of a JPEG, PNG or static GIF image.
 *
 * @param string $source
 * @param string|null $destination
 * @return bool
 */
function atm_webp_convert($source, $destination = null)
{
    if (!atm_webp_enabled() || !atm_webp_supported()) {
        return false;
    }

    if (!is_file($source) || !atm_webp_is_allowed_path($source)) {
        return false;
    }

    if ($destination === null) {
        $destination = atm_webp_path($source);
    }

    if (is_file($destination) && filemtime($destination) >= filemtime($source)) {
        return true;
    }

    if (@filesize($source) > 25 * 1024 * 1024) {
        return false;
    }

    $info = @getimagesize($source);
    if (!$info || empty($info[2])) {
        return false;
    }

    $type = $info[2];
    $image = null;

    switch ($type) {
        case IMAGETYPE_JPEG:
            if (!function_exists('imagecreatefromjpeg')) {
                return false;
            }
            $image = @imagecreatefromjpeg($source);
            break;
        case IMAGETYPE_PNG:
            if (!function_exists('imagecreatefrompng')) {
                return false;
            }
            $image = @imagecreatefrompng($source);
            break;
        case IMAGETYPE_GIF:
            if (!function_exists('imagecreatefromgif') || atm_webp_gif_is_animated($source)) {
                return false;
            }
            $image = @imagecreatefromgif($source);
            break;
        default:
            return false;
    }

    if (!$image) {
        return false;
    }

    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($image);
        }
        @imagealphablending($image, false);
        @imagesavealpha($image, true);
    }

    $dir = dirname($destination);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $result = @imagewebp($image, $destination, atm_webp_quality());
    @imagedestroy($image);

    if (!$result) {
        return false;
    }

    @chmod($destination, 0644);
    return true;
}

/**
 * Make sure a fresh WebP copy exists for the image.
 *
 * @param string $source
 * @return string|false path to the WebP file
 */
function atm_webp_ensure($source)
{
    if (!atm_webp_enabled() || !atm_webp_supported()) {
        return false;
    }

    if (!is_file($source) || !atm_webp_is_allowed_path($source)) {
        return false;
    }

    $destination = atm_webp_path($source);
    if (is_file($destination) && filemtime($destination) >= filemtime($source)) {
        return $destination;
    }

    return atm_webp_convert($source, $destination) ? $destination : false;
}

/**
 * Remove a stale WebP copy.
 *
 * @param string $source
 */
function atm_webp_delete($source)
{
    if (empty($source) || preg_match('#\.webp$#i', $source)) {
        return;
    }

    $webp = atm_webp_path($source);
    if (is_file($webp)) {
        @unlink($webp);
    }
}

/**
 * Call this after an uploaded image has been saved and processed.
 *
 * @param string $source
 * @return string|false
 */
function atm_webp_after_upload($source)
{
    if (!atm_webp_enabled() || !atm_webp_supported()) {
        return false;
    }

    atm_webp_delete($source);
    return atm_webp_ensure($source);
}

/**
 * Detect the MIME type of an image without trusting its extension.
 *
 * @param string $path
 * @return string
 */
function atm_webp_mime($path)
{
    $info = @getimagesize($path);
    if ($info && !empty($info['mime'])) {
        return $info['mime'];
    }

    return 'application/octet-stream';
}

/**
 * Remove session cache headers that PHP sends by default. They would
 * prevent browsers and proxies from caching image responses.
 */
function atm_webp_clean_cache_headers()
{
    header_remove('Pragma');
    header_remove('Expires');
    header_remove('Set-Cookie');
}

/**
 * Serve an uploaded image in WebP or in its original format.
 *
 * @param string $src public path, for example /sys/files/pages/foo.jpg
 */
function atm_webp_serve($src)
{
    $src = rawurldecode((string)$src);
    $path = parse_url($src, PHP_URL_PATH);

    if (!is_string($path) || $path === '' || strpos($path, "\0") !== false) {
        http_response_code(404);
        return;
    }

    $path = str_replace('\\', '/', $path);
    if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
        http_response_code(404);
        return;
    }

    $base = rtrim(str_replace('\\', '/', WWW_ROOT), '/');
    if ($base !== '' && strpos($path, $base . '/') === 0) {
        $path = substr($path, strlen($base));
    }

    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }

    if (!preg_match('#^/(sys/files|sys/avatars|sys/tmp/images)/#', $path)) {
        http_response_code(404);
        return;
    }

    if (!preg_match('#\.(jpe?g|png|gif|webp)$#i', $path)) {
        http_response_code(404);
        return;
    }

    $real = realpath(ROOT . $path);
    if ($real === false || !is_file($real) || !atm_webp_is_allowed_path($real)) {
        http_response_code(404);
        return;
    }

    $file = $real;
    $use_webp = atm_webp_enabled() && atm_webp_supported() && atm_webp_accepted();

    if ($use_webp) {
        $webp = atm_webp_ensure($file);
        if ($webp) {
            $file = $webp;
        } else {
            $use_webp = false;
        }
    }

    $mime = $use_webp ? 'image/webp' : atm_webp_mime($file);
    if ($mime === 'application/octet-stream') {
        http_response_code(404);
        return;
    }

    $mtime = filemtime($file);
    $size = filesize($file);
    $etag = '"' . md5($file . '|' . $mtime . '|' . $size) . '"';

    atm_webp_clean_cache_headers();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Cache-Control: public, max-age=86400');
    header('Vary: Accept');
    header('X-Content-Type-Options: nosniff');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('ETag: ' . $etag);

    if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }

    readfile($file);
    exit;
}

/**
 * Rewrite local uploaded image URLs in rendered HTML to direct file URLs.
 * Browsers that accept WebP receive the .webp copy; other browsers receive
 * the original JPEG/PNG/GIF file.
 *
 * @param string $html
 * @return string
 */
function atm_webp_rewrite_html($html)
{
    if (!atm_webp_enabled() || !atm_webp_supported() || !is_string($html)) {
        return $html;
    }

    if (stripos($html, '<img') === false && stripos($html, 'gallery') === false) {
        return $html;
    }

    if (!headers_sent()) {
        header('Vary: Accept', false);
    }

    if (stripos($html, '<img') !== false) {
        $html = preg_replace_callback('#<img\b[^>]*>#i', function ($img) {
            return preg_replace_callback(
                '#(\bsrc\s*=\s*)(["\'])(.*?)\2#i',
                function ($attr) {
                    $url = atm_webp_direct_url($attr[3]);
                    if ($url === null) {
                        return $attr[0];
                    }

                    return $attr[1] . $attr[2] . h($url) . $attr[2];
                },
                $img[0]
            );
        }, $html);
    }

    // Full-size gallery images open through fancybox; point them directly to
    // the original or WebP file.
    if (stripos($html, 'gallery') !== false) {
        $html = preg_replace_callback('#<a\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\bgallery\b[^"\']*\1[^>]*>#i', function ($link) {
            return preg_replace_callback(
                '#(\bhref\s*=\s*)(["\'])(.*?)\2#i',
                function ($attr) {
                    $url = atm_webp_direct_url($attr[3]);
                    if ($url === null) {
                        return $attr[0];
                    }

                    return $attr[1] . $attr[2] . h($url) . $attr[2];
                },
                $link[0]
            );
        }, $html);
    }

    return $html;
}

/**
 * Convert a public image URL to a direct URL. Returns null when the URL is
 * external, technical or points to a missing file.
 *
 * @param string $src
 * @return string|null
 */
function atm_webp_direct_url($src)
{
    if (!atm_webp_enabled() || !atm_webp_supported()) {
        return null;
    }

    $src = trim(html_entity_decode((string)$src, ENT_QUOTES, 'UTF-8'));
    if ($src === '' || stripos($src, 'data:') === 0) {
        return null;
    }

    if (preg_match('#^https?://#i', $src)) {
        $host = parse_url($src, PHP_URL_HOST);
        $self = !empty($_SERVER['HTTP_HOST'])
            ? preg_replace('#:\d+$#', '', (string)$_SERVER['HTTP_HOST'])
            : (string)(!empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '');

        if (empty($host) || strcasecmp($host, $self) !== 0) {
            return null;
        }

        $path = parse_url($src, PHP_URL_PATH);
    } else if (substr($src, 0, 2) === '//') {
        return null;
    } else {
        $path = parse_url($src, PHP_URL_PATH);
    }

    if (!is_string($path) || $path === '' || strpos($path, "\0") !== false) {
        return null;
    }

    $path = str_replace('\\', '/', $path);
    $base = rtrim(str_replace('\\', '/', WWW_ROOT), '/');
    if ($base !== '' && strpos($path, $base . '/') === 0) {
        $path = substr($path, strlen($base));
    }
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }

    $decoded = rawurldecode($path);
    if (strpos($decoded, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $decoded)) {
        return null;
    }

    // Attachment thumbnails use /image/<module>/<file>[/<size>/]. Resized
    // copies are generated as static files so the HTML can link to them
    // directly without going through img.php.
    if (preg_match('#^/image/([a-z0-9_]+)/([^/]+?)(?:/(\d+))?/?$#i', $path, $imageMatch)) {
        $module = strtolower($imageMatch[1]);
        $encodedFilename = $imageMatch[2];
        $filename = rawurldecode($encodedFilename);
        if ($filename === '' || strpos($filename, '..') !== false
            || strpos($filename, '/') !== false || strpos($filename, '\\') !== false
            || !preg_match('#\.(jpe?g|png|gif)$#i', $filename)
        ) {
            return null;
        }

        $source = ROOT . '/sys/files/' . $module . '/' . $filename;
        if (!is_file($source) || !atm_webp_is_allowed_path($source)) {
            return null;
        }

        $size = !empty($imageMatch[3]) ? (int)$imageMatch[3] : null;
        $thumbnail = atm_webp_direct_thumbnail($module, $filename, $encodedFilename, $size);
        if ($thumbnail !== null) {
            return $thumbnail;
        }

        $fullPath = '/sys/files/' . $module . '/' . $encodedFilename;
        return atm_webp_direct_public_url($fullPath, $source);
    }

    if (!preg_match('#^/(sys/files|sys/avatars|sys/tmp/images)/#', $path)) {
        return null;
    }

    if (!preg_match('#\.(jpe?g|png|gif)$#i', $decoded)) {
        return null;
    }

    $source = ROOT . $decoded;
    if (!is_file($source) || !atm_webp_is_allowed_path($source)) {
        return null;
    }

    return atm_webp_direct_public_url($path, $source);
}

/**
 * Build a direct public URL for an image. Use WebP when the browser accepts
 * it and a WebP copy can be created; otherwise keep the original file.
 *
 * @param string $publicPath
 * @param string $source
 * @return string
 */
function atm_webp_direct_public_url($publicPath, $source)
{
    $publicPath = (string)$publicPath;
    if ($publicPath === '' || $publicPath[0] !== '/') {
        $publicPath = '/' . $publicPath;
    }

    if (atm_webp_accepted() && atm_webp_ensure($source)) {
        $publicPath = preg_replace('#\.[^.]+$#', '.webp', $publicPath);
    }

    $base = rtrim(str_replace('\\', '/', WWW_ROOT), '/');
    return $base . $publicPath;
}

/**
 * Create a static thumbnail and return its direct public URL.
 *
 * @param string $module
 * @param string $filename decoded filename
 * @param string $encodedFilename filename as it appears in the URL
 * @param int|null $size
 * @return string|null
 */
function atm_webp_direct_thumbnail($module, $filename, $encodedFilename, $size = null)
{
    $source = ROOT . '/sys/files/' . $module . '/' . $filename;
    if (!is_file($source) || !atm_webp_is_allowed_path($source)) {
        return null;
    }

    if (empty($size)) {
        $size = (int)Config::read('img_preview_size');
    } else {
        $size = (int)$size;
    }
    if ($size < 50) {
        $size = 50;
    }

    $dir = ROOT . '/sys/tmp/img_cache/' . $size . '/' . $module . '/';
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return null;
    }

    $cache = $dir . $filename;
    $public = '/sys/tmp/img_cache/' . $size . '/' . $module . '/' . $encodedFilename;
    $base = rtrim(str_replace('\\', '/', WWW_ROOT), '/');
    $sourceMtime = filemtime($source);

    if (atm_webp_accepted()) {
        $webp = $cache . '.webp';
        if (is_file($webp) && filemtime($webp) >= $sourceMtime) {
            return $base . $public . '.webp';
        }

        @unlink($webp);
        if (@resampleImage($source, $webp, $size, false, 'webp') && is_file($webp)) {
            return $base . $public . '.webp';
        }
    }

    if (!is_file($cache) || filemtime($cache) < $sourceMtime) {
        @unlink($cache);
        if (!@resampleImage($source, $cache, $size) || !is_file($cache)) {
            return null;
        }
    }

    return $base . $public;
}
