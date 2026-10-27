<?php
/**
 * Read-only authorization status for the publication editor.
 *
 * The endpoint must not refresh the session cookie or write session data:
 * otherwise polling would keep an otherwise expired session alive.
 */

require_once __DIR__ . '/sys/inc/https.lib.php';
atm_force_https();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

$sessionName = session_name();
$sessionId = isset($_COOKIE[$sessionName]) ? (string) $_COOKIE[$sessionName] : '';

if ($sessionId !== '' && preg_match('/^[a-zA-Z0-9,\-]+$/', $sessionId)
	&& session_status() === PHP_SESSION_NONE) {
	ini_set('session.use_cookies', '0');
	ini_set('session.use_only_cookies', '1');
	ini_set('session.use_strict_mode', '1');
	// Keep the same lifetime as sys/boot.php. Otherwise this read-only
	// endpoint can garbage-collect a session that the rest of the site
	// still considers valid.
	ini_set('session.gc_maxlifetime', '10000');

	session_id($sessionId);
	@session_start(array('read_and_close' => true));
}

// Belt and suspenders: this endpoint must never refresh the session cookie.
if (function_exists('header_remove')) {
	header_remove('Set-Cookie');
}

$userId = (!empty($_SESSION['user']['id'])) ? (int) $_SESSION['user']['id'] : 0;

echo json_encode(array(
	'authorized' => $userId > 0,
	'user_id' => $userId,
	'server_time' => time(),
));
