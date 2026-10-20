<?php
/**
 * Keep the Sitemap directive in robots.txt bound to the current domain.
 *
 * The file is still served as a plain static file, so this works on both
 * Apache and Nginx without extra rewrite rules. The line is refreshed when
 * the sitemap is generated and when the CMS is installed.
 *
 * @param string|null $robotsFile Optional path for tests.
 * @return bool
 */
function atm_update_robots_domain($robotsFile = null)
{
    if ($robotsFile === null) {
        $robotsFile = ROOT . '/robots.txt';
    }

    if (!is_file($robotsFile) || !is_readable($robotsFile)) {
        return false;
    }

    $host = '';
    if (!empty($_SERVER['HTTP_HOST'])) {
        $host = (string) $_SERVER['HTTP_HOST'];
    } elseif (!empty($_SERVER['SERVER_NAME'])) {
        $host = (string) $_SERVER['SERVER_NAME'];
    }

    $host = trim($host);
    if ($host === '' || !preg_match('/^[a-z0-9.\-:\[\]]+$/i', $host)) {
        return false;
    }

    $scheme = 'http';
    if (
        (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    ) {
        $scheme = 'https';
    }

    $base = defined('WWW_ROOT') ? rtrim((string) WWW_ROOT, '/') : '';
    $sitemapUrl = $scheme . '://' . $host . $base . '/sitemap.xml';

    $content = file_get_contents($robotsFile);
    if ($content === false) {
        return false;
    }

    // Keep exactly one Sitemap directive and always append the current one.
    $content = preg_replace('/^Sitemap:\s*.*$/mi', '', $content);
    if ($content === null) {
        return false;
    }

    $content = rtrim($content) . "\n\nSitemap: " . $sitemapUrl . "\n";

    return file_put_contents($robotsFile, $content, LOCK_EX) !== false;
}
