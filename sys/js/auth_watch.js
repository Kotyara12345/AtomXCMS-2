/**
 * Keeps an eye on the authorization state while a publication is being
 * written/edited. If the session disappears, the user gets an in-page warning
 * and, when permitted, a browser notification before trying to save.
 */
(function () {
	'use strict';

	var watcherScript = document.querySelector('script[data-atm-auth-watch]');
	if (!watcherScript) return;

	var initialUserId = parseInt(watcherScript.getAttribute('data-user-id'), 10) || 0;
	if (initialUserId < 1) return;

	var statusUrl = watcherScript.getAttribute('data-status-url') || '/auth_status.php';
	var loginUrl = watcherScript.getAttribute('data-login-url') || '/users/login_form/';
	var checkInterval = 15000;
	var lang = (document.documentElement.getAttribute('lang') || 'ru').toLowerCase();
	var isEnglish = lang.indexOf('en') === 0;

	var strings = isEnglish ? {
		title: 'You are not signed in',
		message: 'Your session has expired or you have signed out. If you try to save now, your publication may be lost.',
		hint: 'Copy the text and sign in again in a new tab. Saving is blocked until authorization is restored.',
		copy: 'Copy text',
		copied: 'Text copied',
		copyError: 'Could not copy automatically. Please select the text manually.',
		login: 'Sign in again',
		fieldTitle: 'Title',
		draft: 'Publication draft',
		source: 'Page',
		date: 'Copied at',
		nativeTitle: 'Authorization lost',
		nativeBody: 'You are not signed in. Copy the publication text so that your changes are not lost.'
	} : {
		title: 'Вы не авторизованы',
		message: 'Сессия завершилась или вы вышли из аккаунта. Если попытаться сохранить сейчас, публикация может быть потеряна.',
		hint: 'Скопируйте текст и войдите снова в новой вкладке. Сохранение заблокировано до восстановления авторизации.',
		copy: 'Скопировать текст',
		copied: 'Текст скопирован',
		copyError: 'Не удалось скопировать автоматически. Выделите текст вручную.',
		login: 'Войти снова',
		fieldTitle: 'Заголовок',
		draft: 'Черновик публикации',
		source: 'Страница',
		date: 'Скопировано',
		nativeTitle: 'Авторизация потеряна',
		nativeBody: 'Вы не авторизованы. Скопируйте текст публикации, чтобы не потерять изменения.'
	};

	var form = null;
	var dirty = false;
	var authLost = false;
	var warningElement = null;
	var checkTimer = null;
	var requestPending = false;
	var nativeNotificationShown = false;
	var notificationPermissionRequested = false;

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	function init() {
		form = findPublicationForm();
		if (!form) return;

		var events = ['input', 'change', 'keyup', 'paste', 'cut', 'drop'];
		for (var i = 0; i < events.length; i++) {
			form.addEventListener(events[i], markDirty, true);
		}

		form.addEventListener('submit', handleSubmit, true);
		window.addEventListener('focus', checkIfDirty);
		window.addEventListener('pageshow', checkIfDirty);
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) checkIfDirty();
		});
	}

	function findPublicationForm() {
		var forms = document.querySelectorAll('form#sendForm');
		for (var i = 0; i < forms.length; i++) {
			if (forms[i].querySelector('textarea[name="main_text"]')) {
				return forms[i];
			}
		}
		return null;
	}

	function markDirty() {
		if (dirty) return;
		dirty = true;
		requestNotificationPermission();
		startWatching();
		window.setTimeout(checkAuth, 700);
	}

	function checkIfDirty() {
		if (dirty) checkAuth();
	}

	function startWatching() {
		if (checkTimer) return;
		checkTimer = window.setInterval(checkAuth, checkInterval);
	}

	function checkAuth() {
		if (requestPending) return;
		requestPending = true;

		var url = statusUrl + (statusUrl.indexOf('?') >= 0 ? '&' : '?') + '_atm=' + (new Date()).getTime();
		var xhr = new XMLHttpRequest();
		var finished = false;

		function finish() {
			if (finished) return;
			finished = true;
			requestPending = false;
		}

		xhr.open('GET', url, true);
		xhr.timeout = 10000;
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4) return;
			finish();

			if (xhr.status !== 200) return;

			var data;
			try {
				data = JSON.parse(xhr.responseText);
			} catch (error) {
				return;
			}

			if (!data || typeof data.authorized === 'undefined') return;
			handleStatus(data);
		};
		xhr.ontimeout = finish;
		xhr.onerror = finish;

		try {
			xhr.send(null);
		} catch (error) {
			finish();
		}
	}

	function handleStatus(data) {
		var currentUserId = parseInt(data.user_id, 10) || 0;
		var sameUser = !!data.authorized && currentUserId === initialUserId;

		if (sameUser) {
			authLost = false;
			nativeNotificationShown = false;
			removeWarning();
			return;
		}

		authLost = true;
		showWarning();

		if (!nativeNotificationShown) {
			nativeNotificationShown = true;
			showNativeNotification();
		}
	}

	function handleSubmit(event) {
		// Never let a known-unauthenticated save wipe out the editor data.
		if (!authLost) return;

		event.preventDefault();
		event.stopImmediatePropagation();
		showWarning();
		checkAuth();
	}

	function showWarning() {
		if (!form || warningElement) return;

		ensureStyles();

		warningElement = document.createElement('div');
		warningElement.id = 'atm-auth-warning';
		warningElement.setAttribute('role', 'alert');
		warningElement.setAttribute('aria-live', 'assertive');

		var title = document.createElement('div');
		title.className = 'atm-auth-warning__title';
		title.textContent = strings.title;

		var message = document.createElement('div');
		message.className = 'atm-auth-warning__message';
		message.textContent = strings.message;

		var hint = document.createElement('div');
		hint.className = 'atm-auth-warning__hint';
		hint.textContent = strings.hint;

		var actions = document.createElement('div');
		actions.className = 'atm-auth-warning__actions';

		var copyButton = document.createElement('button');
		copyButton.type = 'button';
		copyButton.className = 'atm-auth-warning__copy';
		copyButton.textContent = strings.copy;
		copyButton.addEventListener('click', function () {
			copyText(buildDraftText(), function (copied) {
				copyButton.textContent = copied ? strings.copied : strings.copyError;
				window.setTimeout(function () {
					copyButton.textContent = strings.copy;
				}, 3500);
			});
		});

		var loginLink = document.createElement('a');
		loginLink.className = 'atm-auth-warning__login';
		loginLink.href = loginUrl;
		loginLink.target = '_blank';
		loginLink.rel = 'noopener noreferrer';
		loginLink.textContent = strings.login;

		actions.appendChild(copyButton);
		actions.appendChild(loginLink);

		warningElement.appendChild(title);
		warningElement.appendChild(message);
		warningElement.appendChild(hint);
		warningElement.appendChild(actions);

		document.body.appendChild(warningElement);
	}

	function removeWarning() {
		if (!warningElement) return;
		if (warningElement.parentNode) warningElement.parentNode.removeChild(warningElement);
		warningElement = null;
	}

	function ensureStyles() {
		if (document.getElementById('atm-auth-warning-style')) return;

		var style = document.createElement('style');
		style.id = 'atm-auth-warning-style';
		style.type = 'text/css';
		style.appendChild(document.createTextNode(
			'#atm-auth-warning{position:fixed;right:20px;bottom:20px;z-index:99999;width:440px;max-width:calc(100% - 24px);' +
			'box-sizing:border-box;padding:16px 18px;border:1px solid #e0b300;border-radius:8px;' +
			'background:#fff8d6;color:#3b2d00;box-shadow:0 10px 30px rgba(0,0,0,.25);' +
			'font:14px/1.45 Arial,Helvetica,sans-serif;text-align:left}' +
			'#atm-auth-warning .atm-auth-warning__title{font-size:16px;font-weight:700;margin:0 0 6px}' +
			'#atm-auth-warning .atm-auth-warning__message{margin:0 0 4px}' +
			'#atm-auth-warning .atm-auth-warning__hint{margin:0 0 12px;color:#6b5700}' +
			'#atm-auth-warning .atm-auth-warning__actions{display:flex;flex-wrap:wrap;gap:8px}' +
			'#atm-auth-warning .atm-auth-warning__actions button,' +
			'#atm-auth-warning .atm-auth-warning__actions a{display:inline-block;padding:7px 12px;border-radius:5px;' +
			'font:inherit;line-height:1.2;text-decoration:none;cursor:pointer;border:1px solid #b38b00}' +
			'#atm-auth-warning .atm-auth-warning__copy{background:#f0c400;color:#2f2400;font-weight:700}' +
			'#atm-auth-warning .atm-auth-warning__copy:hover{background:#ffd633}' +
			'#atm-auth-warning .atm-auth-warning__login{background:#fff;color:#3b2d00}' +
			'#atm-auth-warning .atm-auth-warning__login:hover{background:#fffdf2}' +
			'@media (max-width:600px){#atm-auth-warning{right:12px;bottom:12px;left:12px;width:auto;max-width:none}}'
		));
		document.head.appendChild(style);
	}

	function requestNotificationPermission() {
		if (notificationPermissionRequested || !('Notification' in window)) return;
		if (Notification.permission !== 'default') {
			notificationPermissionRequested = true;
			return;
		}

		try {
			if (window.sessionStorage && sessionStorage.getItem('atm_auth_notify_asked') === '1') {
				notificationPermissionRequested = true;
				return;
			}
			if (window.sessionStorage) sessionStorage.setItem('atm_auth_notify_asked', '1');
		} catch (error) {
			// Storage may be unavailable in private mode; asking once per page is fine.
		}

		notificationPermissionRequested = true;
		try {
			var result = Notification.requestPermission();
			if (result && typeof result.then === 'function') result.catch(function () {});
		} catch (error) {
			// Browsers that do not allow the request from an input event are ignored.
		}
	}

	function showNativeNotification() {
		if (!('Notification' in window) || Notification.permission !== 'granted') return;

		try {
			var notification = new Notification(strings.nativeTitle, {
				body: strings.nativeBody,
				tag: 'atm-auth-lost'
			});
			notification.onclick = function () {
				try {
					window.focus();
				} catch (error) {}
				try {
					notification.close();
				} catch (error) {}
			};
		} catch (error) {
			// Most mobile browsers require a service worker; the in-page banner
			// remains available in that case.
		}
	}

	function syncEditor() {
		if (!window.jQuery || !form) return;
		try {
			var editor = window.jQuery(form).find('#editor');
			var wysibb = editor.data('wbb');
			if (wysibb && typeof wysibb.sync === 'function') wysibb.sync();
		} catch (error) {}
	}

	function buildDraftText() {
		syncEditor();

		var parts = [];
		var title = form.querySelector('[name="title"]');
		var body = form.querySelector('[name="main_text"]');

		parts.push(strings.draft);
		parts.push('');

		if (title && title.value) {
			parts.push(strings.fieldTitle + ': ' + title.value);
			parts.push('');
		}

		if (body && body.value) {
			parts.push(body.value);
			parts.push('');
		} else {
			// WysiBB keeps its content in a contenteditable block. Usually sync()
			// copies it back to the textarea, but keep a readable fallback.
			var editorBody = form.querySelector('.wysibb-body');
			var editorText = editorBody ? (editorBody.innerText || editorBody.textContent || '') : '';
			if (editorText) {
				parts.push(editorText);
				parts.push('');
			}
		}

		var fields = form.querySelectorAll('input[name], textarea[name], select[name]');
		var seen = { title: true, main_text: true };
		var extra = [];

		for (var i = 0; i < fields.length; i++) {
			var field = fields[i];
			var name = field.name || '';
			var type = (field.type || '').toLowerCase();

			if (!name || seen[name]) continue;
			if (type === 'hidden' || type === 'file' || type === 'submit' ||
				type === 'button' || type === 'reset' || type === 'image' || type === 'password') {
				continue;
			}
			if (name === 'submitForm' || name === 'viewMessage' || name === 'form_key') continue;

			var value = '';
			if (type === 'checkbox' || type === 'radio') {
				if (!field.checked) continue;
				value = field.value;
			} else if (field.tagName.toLowerCase() === 'select') {
				for (var j = 0; j < field.options.length; j++) {
					if (field.options[j].selected) {
						value += (value ? ', ' : '') + field.options[j].text;
					}
				}
			} else {
				value = field.value;
			}

			if (value === null || value === undefined) continue;
			value = String(value).replace(/\s+$/, '');
			if (!value) continue;

			extra.push(name + ': ' + value);
		}

		if (extra.length) {
			parts.push('---');
			parts = parts.concat(extra);
			parts.push('');
		}

		parts.push('---');
		parts.push(strings.source + ': ' + window.location.href);
		parts.push(strings.date + ': ' + new Date().toLocaleString());

		return parts.join('\n');
	}

	function copyText(text, callback) {
		if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(function () {
				callback(true);
			}, function () {
				callback(fallbackCopy(text));
			});
			return;
		}
		callback(fallbackCopy(text));
	}

	function fallbackCopy(text) {
		var textarea = document.createElement('textarea');
		textarea.value = text;
		textarea.setAttribute('readonly', 'readonly');
		textarea.style.position = 'fixed';
		textarea.style.left = '-9999px';
		textarea.style.top = '0';
		document.body.appendChild(textarea);

		var copied = false;
		try {
			textarea.select();
			if (textarea.setSelectionRange) textarea.setSelectionRange(0, textarea.value.length);
			copied = document.execCommand('copy');
		} catch (error) {
			copied = false;
		}

		document.body.removeChild(textarea);
		return copied;
	}
})();
