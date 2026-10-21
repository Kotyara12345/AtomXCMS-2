/**
 * Shows a one-time notice that the site uses cookies.
 *
 * The acknowledgement is stored in localStorage so the notice does not repeat
 * on every page. The notice is informational and does not block the site.
 */
(function () {
	'use strict';

	var STORAGE_KEY = 'atm_cookie_notice_v1';
	var lang = (document.documentElement.getAttribute('lang') || 'ru').toLowerCase();
	var isRussian = lang.indexOf('ru') === 0;

	var strings = isRussian ? {
		title: 'Файлы cookie',
		message: 'Сайт использует файлы cookie для работы, сохранения настроек и авторизации. Нажимая «Принять», вы соглашаетесь с их использованием.',
		accept: 'Принять'
	} : {
		title: 'Cookies',
		message: 'This site uses cookies to keep working, remember your settings and keep you signed in. By clicking "Accept", you agree to their use.',
		accept: 'Accept'
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	function init() {
		if (isAccepted()) return;
		showNotice();
	}

	function isAccepted() {
		try {
			return !!(window.localStorage && window.localStorage.getItem(STORAGE_KEY) === '1');
		} catch (error) {
			return false;
		}
	}

	function accept() {
		try {
			if (window.localStorage) window.localStorage.setItem(STORAGE_KEY, '1');
		} catch (error) {}
		removeNotice();
	}

	function showNotice() {
		if (document.getElementById('atm-cookie-notice')) return;

		ensureStyles();

		var notice = document.createElement('div');
		notice.id = 'atm-cookie-notice';
		notice.setAttribute('role', 'region');
		notice.setAttribute('aria-live', 'polite');
		notice.setAttribute('aria-label', strings.title);

		var title = document.createElement('div');
		title.className = 'atm-cookie-notice__title';
		title.textContent = strings.title;

		var message = document.createElement('p');
		message.className = 'atm-cookie-notice__message';
		message.textContent = strings.message;

		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'atm-cookie-notice__accept';
		button.textContent = strings.accept;
		button.addEventListener('click', accept);

		notice.appendChild(title);
		notice.appendChild(message);
		notice.appendChild(button);
		document.body.appendChild(notice);
	}

	function removeNotice() {
		var notice = document.getElementById('atm-cookie-notice');
		if (notice && notice.parentNode) notice.parentNode.removeChild(notice);
	}

	function ensureStyles() {
		if (document.getElementById('atm-cookie-notice-style')) return;

		var style = document.createElement('style');
		style.id = 'atm-cookie-notice-style';
		style.type = 'text/css';
		style.appendChild(document.createTextNode(
			'#atm-cookie-notice{position:fixed;left:20px;bottom:20px;z-index:99998;width:400px;' +
			'max-width:calc(100% - 24px);box-sizing:border-box;padding:16px 18px;border:1px solid #c8d6e5;' +
			'border-radius:8px;background:#fff;color:#243447;box-shadow:0 10px 30px rgba(0,0,0,.2);' +
			'font:14px/1.45 Arial,Helvetica,sans-serif;text-align:left}' +
			'#atm-cookie-notice .atm-cookie-notice__title{font-size:16px;font-weight:700;margin:0 0 6px;color:#1b5a8a}' +
			'#atm-cookie-notice .atm-cookie-notice__message{margin:0 0 12px}' +
			'#atm-cookie-notice .atm-cookie-notice__accept{display:inline-block;padding:8px 18px;border:0;' +
			'border-radius:5px;background:#1b5a8a;color:#fff;font:inherit;font-weight:700;line-height:1.2;' +
			'text-transform:none;cursor:pointer}' +
			'#atm-cookie-notice .atm-cookie-notice__accept:hover{background:#2470a8}' +
			'@media (max-width:600px){#atm-cookie-notice{left:12px;right:12px;bottom:12px;width:auto;max-width:none}}' +
			'@media print{#atm-cookie-notice{display:none}}'
		));
		document.head.appendChild(style);
	}
})();
