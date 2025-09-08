<?php
declare(strict_types=1);

/**
 * Ping Service for AtomX CMS
 * 
 * Handles version checking and update notifications
 * Optimized for PHP 8.1+ with security and performance improvements
 */

// Security headers
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Error handling configuration
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Sanitize and validate input
$type = filter_input(INPUT_GET, 'type', FILTER_VALIDATE_BOOLEAN, [
    'flags' => FILTER_NULL_ON_FAILURE
]);

// Main execution logic
try {
    if ($type === true) {
        checkRequest();
    } else {
        checkUpdate();
    }
} catch (Throwable $e) {
    error_log('Ping service error: ' . $e->getMessage());
    // Don't expose internal errors to users
    if ($type === false) {
        echo 'Не удалось узнать';
    }
}

/**
 * Check for available updates
 */
function checkUpdate(): void
{
    $versionInfo = getLatestVersion();
    
    if ($versionInfo !== null) {
        $version = htmlspecialchars($versionInfo, ENT_QUOTES, 'UTF-8');
        echo sprintf(
            '<a target="_blank" rel="noopener noreferrer" href="https://github.com/Drunyacoder/AtomXCMS-2/releases">Последняя версия %s</a>',
            $version
        );
    } else {
        echo 'Не удалось узнать';
    }
}

/**
 * Send anonymous usage statistics (opt-in)
 */
function checkRequest(): void
{
    // Respect user privacy - make this opt-in
    if (!shouldSendStatistics()) {
        return;
    }
    
    sendAnonymousStatistics();
}

/**
 * Get latest version information with caching
 */
function getLatestVersion(): ?string
{
    $cacheKey = 'atomx_latest_version';
    $cacheFile = __DIR__ . '/cache/version.cache';
    $cacheTime = 3600; // 1 hour
    
    // Try to get from cache first
    if (file_exists($cacheFile) && time() - filemtime($cacheFile) < $cacheTime) {
        $cachedVersion = file_get_contents($cacheFile);
        if ($cachedVersion !== false && $cachedVersion !== '') {
            return $cachedVersion;
        }
    }
    
    // Fetch from remote source
    $url = 'https://raw.githubusercontent.com/Drunyacoder/AtomXCMS-2/main/version.txt';
    $version = fetchRemoteContent($url);
    
    if ($version !== null && preg_match('/^[a-zA-Z0-9.\-]+$/', $version)) {
        // Cache the result
        @file_put_contents($cacheFile, $version, LOCK_EX);
        return $version;
    }
    
    return null;
}

/**
 * Fetch remote content with proper error handling
 */
function fetchRemoteContent(string $url, int $timeout = 5): ?string
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header' => "User-Agent: AtomXCMS/2.0\r\n",
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false
        ]
    ]);
    
    try {
        $content = file_get_contents($url, false, $context);
        
        if ($content === false) {
            return null;
        }
        
        $content = trim($content);
        return $content !== '' ? $content : null;
        
    } catch (Throwable $e) {
        error_log('Remote fetch error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Determine if we should send usage statistics
 */
function shouldSendStatistics(): bool
{
    // Make this configurable - respect user privacy
    // For now, default to false for privacy reasons
    return false;
    
    // Alternative: check config setting if available
    // return Config::get('send_anonymous_stats', false);
}

/**
 * Send anonymous usage statistics
 */
function sendAnonymousStatistics(): void
{
    $url = 'https://home.atomx.net/check.php';
    
    $data = [
        'v' => '2.7.0Beta',
        'd' => getAnonymizedDomain(),
        'php' => PHP_VERSION,
        'os' => PHP_OS,
        'timestamp' => time()
    ];
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 2,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);
    
    try {
        // We don't need the response, just send and forget
        @file_get_contents($url, false, $context);
    } catch (Throwable $e) {
        // Silent fail - this is non-critical functionality
        error_log('Statistics send error: ' . $e->getMessage());
    }
}

/**
 * Get anonymized domain information for privacy
 */
function getAnonymizedDomain(): string
{
    $domain = $_SERVER['HTTP_HOST'] ?? 'unknown';
    
    // Anonymize for privacy - only send domain without subdomains
    $parts = explode('.', $domain);
    if (count($parts) >= 2) {
        return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
    }
    
    return 'unknown';
}

/**
 * Initialize cache directory
 */
function initializeCache(): void
{
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
        @file_put_contents($cacheDir . '/.htaccess', 'Deny from all');
    }
}

// Initialize cache directory on first run
initializeCache();

?>
