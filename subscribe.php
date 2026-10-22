<?php
/**
 * Public subscription endpoint.
 *
 * Handles the general newsletter form and unsubscribe links. Registered
 * users always subscribe with the e-mail address from their profile;
 * guests provide an address in the form.
 */

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

include_once __DIR__ . '/sys/boot.php';

if (isset($_GET['unsubscribe'])) {
	$ok = atm_subscription_unsubscribe_token($_GET['unsubscribe']);
	atm_subscription_redirect($ok ? 'unsubscribed' : 'invalid', '/');
}

if (empty($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
	atm_subscription_redirect('error', '/');
}

$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!atm_subscription_csrf_check($token)) {
	atm_subscription_redirect('error', '/');
}

// Honeypot: browsers do not fill this hidden field, bots often do.
if (!empty($_POST['website'])) {
	atm_subscription_redirect('subscribed', '/');
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$userId = !empty($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
$email = $userId > 0
	? (!empty($_SESSION['user']['email']) ? $_SESSION['user']['email'] : '')
	: (isset($_POST['email']) ? $_POST['email'] : '');

if ($action === 'subscribe_general') {
	$email = atm_subscription_normalize_email($email);
	if ($email === '') {
		atm_subscription_redirect('email', '/');
	}

	$result = atm_subscription_subscribe_general($email, $userId);
	if (empty($result['ok'])) {
		atm_subscription_redirect('error', '/');
	}

	$name = ($userId > 0 && !empty($_SESSION['user']['name'])) ? $_SESSION['user']['name'] : '';
	if (!empty($result['is_new'])) {
		atm_subscription_send_welcome($email, $name, $result['token']);
	}
	atm_subscription_redirect('subscribed', '/');
}

if ($action === 'unsubscribe_general') {
	if ($userId < 1) {
		atm_subscription_redirect('error', '/');
	}
	atm_subscription_unsubscribe_user_general($userId, $email);
	atm_subscription_redirect('unsubscribed', '/');
}

atm_subscription_redirect('error', '/');
