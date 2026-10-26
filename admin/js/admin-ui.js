/**
 * AtomX admin UI enhancements.
 *
 * Vanilla JavaScript only: the admin panel has to keep working even if
 * jQuery plugins are unavailable.  Features:
 *  - accessible header dropdowns (notifications and user menu);
 *  - toast notifications (temporary admin messages become toasts);
 *  - confirmation dialogs;
 *  - copy-to-clipboard buttons;
 *  - unsaved form changes warning;
 *  - dark theme persisted in localStorage.
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn, { once: true });
		} else {
			fn();
		}
	}

	function closest(element, selector) {
		if (!element) return null;
		if (element.closest) return element.closest(selector);
		while (element && element.nodeType === 1) {
			if (element.matches && element.matches(selector)) return element;
			element = element.parentNode;
		}
		return null;
	}

	function focusable(root) {
		return Array.prototype.slice.call(root.querySelectorAll(
			'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
		)).filter(function (element) {
			return element.offsetParent !== null || element === document.activeElement;
		});
	}

	/* ------------------------------------------------------------------
	 * Theme
	 * ------------------------------------------------------------------ */

	var themeToggle = document.getElementById('atm-theme-toggle');

	function applyTheme(dark) {
		if (dark) {
			document.documentElement.classList.add('atm-dark');
		} else {
			document.documentElement.classList.remove('atm-dark');
		}
		if (themeToggle) {
			themeToggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
		}
		try {
			localStorage.setItem('atmAdminTheme', dark ? 'dark' : 'light');
		} catch (e) {}
	}

	function currentThemeIsDark() {
		return document.documentElement.classList.contains('atm-dark');
	}

	if (themeToggle) {
		themeToggle.setAttribute('aria-pressed', currentThemeIsDark() ? 'true' : 'false');
		themeToggle.addEventListener('click', function () {
			applyTheme(!currentThemeIsDark());
		});
	}

	/* ------------------------------------------------------------------
	 * Header dropdowns
	 * ------------------------------------------------------------------ */

	var dropdownButtons = Array.prototype.slice.call(
		document.querySelectorAll('.atm-icon-button[aria-controls], .atm-user-toggle[aria-controls]')
	);

	function dropdownFor(button) {
		var id = button.getAttribute('aria-controls');
		return id ? document.getElementById(id) : null;
	}

	function closeDropdown(button, restoreFocus) {
		var dropdown = dropdownFor(button);
		if (!dropdown) return;
		dropdown.hidden = true;
		button.setAttribute('aria-expanded', 'false');
		if (restoreFocus) button.focus();
	}

	function closeAllDropdowns(except) {
		dropdownButtons.forEach(function (button) {
			if (button !== except) closeDropdown(button, false);
		});
	}

	function openDropdown(button, focusFirst) {
		var dropdown = dropdownFor(button);
		if (!dropdown) return;
		closeAllDropdowns(button);
		dropdown.hidden = false;
		button.setAttribute('aria-expanded', 'true');
		if (focusFirst) {
			var items = focusable(dropdown);
			if (items.length) items[0].focus();
		}
	}

	dropdownButtons.forEach(function (button) {
		var dropdown = dropdownFor(button);
		if (!dropdown) return;

		button.addEventListener('click', function (event) {
			event.preventDefault();
			var isOpen = button.getAttribute('aria-expanded') === 'true';
			if (isOpen) {
				closeDropdown(button, true);
			} else {
				openDropdown(button, event.detail === 0);
			}
		});

		button.addEventListener('keydown', function (event) {
			if (event.key === 'ArrowDown') {
				event.preventDefault();
				openDropdown(button, true);
			} else if (event.key === 'Escape') {
				closeDropdown(button, true);
			}
		});

		dropdown.addEventListener('keydown', function (event) {
			var items = focusable(dropdown);
			if (!items.length) return;
			var index = items.indexOf(document.activeElement);

			if (event.key === 'Escape') {
				event.preventDefault();
				closeDropdown(button, true);
			} else if (event.key === 'ArrowDown') {
				event.preventDefault();
				items[(index + 1) % items.length].focus();
			} else if (event.key === 'ArrowUp') {
				event.preventDefault();
				items[(index - 1 + items.length) % items.length].focus();
			} else if (event.key === 'Home') {
				event.preventDefault();
				items[0].focus();
			} else if (event.key === 'End') {
				event.preventDefault();
				items[items.length - 1].focus();
			}
		});
	});

	document.addEventListener('click', function (event) {
		if (!closest(event.target, '.atm-notify') && !closest(event.target, '.atm-user-menu')) {
			closeAllDropdowns(null);
		}
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') closeAllDropdowns(null);
	});

	/* ------------------------------------------------------------------
	 * Toasts
	 * ------------------------------------------------------------------ */

	var toastContainer = document.getElementById('atm-toasts');

	function ensureToastContainer() {
		if (!toastContainer) {
			toastContainer = document.createElement('div');
			toastContainer.id = 'atm-toasts';
			toastContainer.className = 'atm-toasts';
			toastContainer.setAttribute('role', 'region');
			toastContainer.setAttribute('aria-live', 'polite');
			document.body.appendChild(toastContainer);
		}
		return toastContainer;
	}

	function toast(message, type, options) {
		options = options || {};
		var container = ensureToastContainer();
		var node = document.createElement('div');
		node.className = 'atm-toast ' + (type || 'ok');
		node.setAttribute('role', type === 'error' ? 'alert' : 'status');

		var body = document.createElement('div');
		if (options.html) {
			body.innerHTML = String(message);
		} else {
			body.textContent = String(message);
		}
		node.appendChild(body);

		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'atm-toast-close';
		close.setAttribute('aria-label', 'Закрыть уведомление');
		close.innerHTML = '&times;';
		node.appendChild(close);

		var remove = function () {
			if (!node.parentNode) return;
			node.style.opacity = '0';
			node.style.transform = 'translateY(-5px)';
			node.style.transition = 'opacity .18s ease, transform .18s ease';
			window.setTimeout(function () {
				if (node.parentNode) node.parentNode.removeChild(node);
			}, 190);
		};

		close.addEventListener('click', remove);
		container.appendChild(node);

		var timeout = options.timeout === undefined ? 7000 : parseInt(options.timeout, 10);
		if (timeout > 0) {
			window.setTimeout(remove, timeout);
		}
		return node;
	}

	window.atmToast = toast;

	/* Convert the old in-page alerts into transient toasts. */
	ready(function () {
		var warnings = Array.prototype.slice.call(
			document.querySelectorAll('#content-wrapper > .warning.atm-flash')
		);
		warnings.forEach(function (warning, index) {
			var type = 'ok';
			if (warning.classList.contains('error')) type = 'error';
			else if (!warning.classList.contains('ok')) type = 'info';
			var html = warning.innerHTML;
			warning.parentNode.removeChild(warning);
			window.setTimeout(function () {
				toast(html, type, { html: true, timeout: type === 'error' ? 10000 : 7000 });
			}, index * 120);
		});
	});

	/* ------------------------------------------------------------------
	 * Confirmation modal
	 * ------------------------------------------------------------------ */

	var activeDialog = null;

	function closeDialog(restoreFocus) {
		if (!activeDialog) return;
		var dialog = activeDialog;
		activeDialog = null;
		if (dialog.overlay.parentNode) dialog.overlay.parentNode.removeChild(dialog.overlay);
		document.removeEventListener('keydown', dialog.onKeyDown, true);
		if (restoreFocus && dialog.previousFocus && dialog.previousFocus.focus) {
			dialog.previousFocus.focus();
		}
		if (dialog.onCancel) {
			try { dialog.onCancel(); } catch (e) {}
		}
	}

	function confirmDialog(message, onConfirm, options) {
		options = options || {};
		if (activeDialog) closeDialog(false);

		var previousFocus = document.activeElement;
		var overlay = document.createElement('div');
		overlay.className = 'atm-confirm-overlay';
		overlay.setAttribute('role', 'presentation');

		var dialog = document.createElement('div');
		dialog.className = 'atm-confirm-dialog';
		dialog.setAttribute('role', 'dialog');
		dialog.setAttribute('aria-modal', 'true');
		dialog.setAttribute('aria-labelledby', 'atm-confirm-title');
		dialog.setAttribute('aria-describedby', 'atm-confirm-message');

		var title = document.createElement('h3');
		title.id = 'atm-confirm-title';
		title.textContent = options.title || 'Подтверждение';
		dialog.appendChild(title);

		var text = document.createElement('div');
		text.id = 'atm-confirm-message';
		text.className = 'atm-confirm-message';
		if (options.html) text.innerHTML = String(message);
		else text.textContent = String(message);
		dialog.appendChild(text);

		var actions = document.createElement('div');
		actions.className = 'atm-confirm-actions';

		var cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.className = 'atm-button secondary';
		cancel.textContent = options.cancelText || 'Отмена';

		var confirm = document.createElement('button');
		confirm.type = 'button';
		confirm.className = 'atm-button' + (options.danger ? ' danger' : '');
		confirm.textContent = options.confirmText || 'Подтвердить';

		actions.appendChild(cancel);
		actions.appendChild(confirm);
		dialog.appendChild(actions);
		overlay.appendChild(dialog);
		document.body.appendChild(overlay);

		var onKeyDown = function (event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				closeDialog(true);
			} else if (event.key === 'Tab') {
				var items = focusable(dialog);
				if (!items.length) return;
				var index = items.indexOf(document.activeElement);
				if (event.shiftKey && index <= 0) {
					event.preventDefault();
					items[items.length - 1].focus();
				} else if (!event.shiftKey && index === items.length - 1) {
					event.preventDefault();
					items[0].focus();
				}
			}
		};

		activeDialog = {
			overlay: overlay,
			onKeyDown: onKeyDown,
			onCancel: options.onCancel || null,
			previousFocus: previousFocus
		};

		cancel.addEventListener('click', function () { closeDialog(true); });
		confirm.addEventListener('click', function () {
			closeDialog(false);
			onConfirm();
		});
		overlay.addEventListener('click', function (event) {
			if (event.target === overlay) closeDialog(true);
		});
		document.addEventListener('keydown', onKeyDown, true);

		window.setTimeout(function () { confirm.focus(); }, 20);
	}

	window.atmConfirm = function (message, onConfirm, options) {
		if (typeof window.confirm === 'function' && !options) {
			// Keep a simple fallback for older browsers.
			if (window.confirm(message)) onConfirm();
			return;
		}
		confirmDialog(message, onConfirm, options);
	};

	document.addEventListener('click', function (event) {
		var button = closest(event.target, '[data-atm-confirm]');
		if (!button) return;
		event.preventDefault();
		var message = button.getAttribute('data-atm-confirm') || 'Продолжить действие?';
		var options = {
			title: button.getAttribute('data-atm-confirm-title') || 'Подтверждение',
			confirmText: button.getAttribute('data-atm-confirm-ok') || 'Подтвердить',
			cancelText: button.getAttribute('data-atm-confirm-cancel') || 'Отмена',
			danger: button.hasAttribute('data-atm-danger')
		};
		confirmDialog(message, function () {
			var form = closest(button, 'form');
			if (form) {
				form.setAttribute('data-atm-submitting', '1');
				form.submit();
				return;
			}
			if (button.tagName === 'A' && button.href) {
				window.location.href = button.href;
			}
		}, options);
	});

	/* ------------------------------------------------------------------
	 * Copy to clipboard
	 * ------------------------------------------------------------------ */

	function copyText(value) {
		value = String(value || '');
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(value).then(function () {
				toast('Скопировано', 'ok', { timeout: 2500 });
			}, function () {
				toast('Не удалось скопировать', 'error', { timeout: 4000 });
			});
		}

		var area = document.createElement('textarea');
		area.value = value;
		area.setAttribute('readonly', 'readonly');
		area.style.position = 'fixed';
		area.style.left = '-9999px';
		document.body.appendChild(area);
		area.select();
		var ok = false;
		try { ok = document.execCommand('copy'); } catch (e) {}
		document.body.removeChild(area);
		toast(ok ? 'Скопировано' : 'Не удалось скопировать', ok ? 'ok' : 'error', { timeout: 3000 });
	}

	window.atmCopy = copyText;

	document.addEventListener('click', function (event) {
		var button = closest(event.target, '[data-atm-copy], [data-atm-copy-target]');
		if (!button) return;
		event.preventDefault();
		var value = button.getAttribute('data-atm-copy') || '';
		var selector = button.getAttribute('data-atm-copy-target');
		if (selector) {
			var target = document.querySelector(selector);
			if (target) value = target.value !== undefined ? target.value : target.textContent;
		}
		copyText(value);
	});

	/* ------------------------------------------------------------------
	 * Unsaved form changes
	 * ------------------------------------------------------------------ */

	var dirtyForms = new Set();

	function formIsTrackable(form) {
		if (!form || form.nodeName !== 'FORM') return false;
		if (form.hasAttribute('data-atm-no-dirty')) return false;
		if (form.hasAttribute('data-no-dirty')) return false;
		var method = (form.getAttribute('method') || 'get').toLowerCase();
		return method === 'post';
	}

	function markFormDirty(form) {
		if (!formIsTrackable(form)) return;
		dirtyForms.add(form);
		form.setAttribute('data-atm-dirty', '1');
	}

	function markFormClean(form) {
		dirtyForms.delete(form);
		form.removeAttribute('data-atm-dirty');
	}

	document.addEventListener('input', function (event) {
		var form = closest(event.target, 'form');
		if (form) markFormDirty(form);
	}, true);

	document.addEventListener('change', function (event) {
		var form = closest(event.target, 'form');
		if (form) markFormDirty(form);
	}, true);

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!formIsTrackable(form)) return;
		form.setAttribute('data-atm-submitting', '1');
		markFormClean(form);
	}, false);

	window.addEventListener('beforeunload', function (event) {
		var submitting = document.querySelector('form[data-atm-submitting="1"]');
		if (submitting || dirtyForms.size === 0) return;
		event.preventDefault();
		event.returnValue = '';
		return '';
	});

	window.atmMarkFormClean = markFormClean;
	window.atmMarkFormDirty = markFormDirty;
})();
