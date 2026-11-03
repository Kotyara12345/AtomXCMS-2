/**
 * Unicode emoji picker for AtomXCMS editors.
 *
 * Loads a per-language dataset (Unicode 17.0 + CLDR keywords) from
 * emoji-data-<lang>.json next to this file, renders a searchable palette
 * with categories and skin tones, and inserts the chosen emoji into the
 * focused WysiBB editor.
 *
 * Language follows <html lang>: "ru*" -> ru, everything else -> en.
 */
(function (global, document) {
	'use strict';

	var script = document.currentScript;
	var BASE = script && script.src ? script.src.replace(/[^\/]*$/, '') : '';
	var LANG = /^ru/i.test(document.documentElement.lang || '') ? 'ru' : 'en';

	var STR = {
		en: {
			title: 'Emoji',
			search: 'Search emoji',
			empty: 'Nothing found',
			tone: 'Skin tone',
			tones: ['Default', 'Light', 'Medium light', 'Medium', 'Medium dark', 'Dark'],
			cats: ['Smileys', 'People', 'Nature', 'Food', 'Travel', 'Activities', 'Objects', 'Symbols', 'Flags'],
			icons: ['\uD83D\uDE00', '\uD83E\uDDD1', '\uD83D\uDC3B', '\uD83C\uDF54', '\u2708\uFE0F', '\u26BD', '\uD83D\uDCA1', '\u2764\uFE0F', '\uD83C\uDFF3\uFE0F'],
			close: 'Close'
		},
		ru: {
			title: 'Эмодзи',
			search: 'Поиск эмодзи',
			empty: 'Ничего не найдено',
			tone: 'Оттенок кожи',
			tones: ['По умолчанию', 'Светлый', 'Умеренно светлый', 'Средний', 'Умеренно тёмный', 'Тёмный'],
			cats: ['Смайлы', 'Люди', 'Природа', 'Еда', 'Путешествия', 'Занятия', 'Предметы', 'Символы', 'Флаги'],
			icons: ['\uD83D\uDE00', '\uD83E\uDDD1', '\uD83D\uDC3B', '\uD83C\uDF54', '\u2708\uFE0F', '\u26BD', '\uD83D\uDCA1', '\u2764\uFE0F', '\uD83C\uDFF3\uFE0F'],
			close: 'Закрыть'
		}
	}[LANG];

	var nextFrame = global.requestAnimationFrame || function (fn) { return setTimeout(fn, 16); };

	var dataPromise = null;
	var state = {
		rows: null,
		index: null,
		tone: 0,
		open: false,
		anchor: null,
		onPick: null,
		panel: null,
		grid: null,
		results: null,
		sections: null,
		activeCat: 0
	};

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) node.className = className;
		if (text != null) node.textContent = text;
		return node;
	}

	function normalize(value) {
		return String(value).toLowerCase().replace(/ё/g, 'е').replace(/\s+/g, ' ').trim();
	}

	function load() {
		if (!dataPromise) {
			dataPromise = fetch(BASE + 'emoji-data-' + LANG + '.json', { credentials: 'same-origin' })
				.then(function (response) {
					if (!response.ok) throw new Error('emoji dataset: HTTP ' + response.status);
					return response.json();
				})
				.then(function (data) {
					state.rows = data.e;
					state.index = state.rows.map(function (row) {
						return normalize(row[3] + ' ' + row[4].join(' '));
					});
					return data;
				});
		}
		return dataPromise;
	}

	function glyph(i) {
		var row = state.rows[i];
		if (state.tone > 0 && row[2]) return row[2][state.tone - 1];
		return row[0];
	}

	function buildPanel() {
		var panel = el('div', 'atm-emoji-panel');
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-label', STR.title);
		panel.hidden = true;

		var head = el('div', 'atm-emoji-head');
		var search = el('input', 'atm-emoji-search');
		search.type = 'search';
		search.placeholder = STR.search;
		search.setAttribute('aria-label', STR.search);
		search.autocomplete = 'off';

		var tone = el('select', 'atm-emoji-tone');
		tone.setAttribute('aria-label', STR.tone);
		tone.title = STR.tone;
		STR.tones.forEach(function (label, i) {
			var option = el('option', '', label);
			option.value = String(i);
			tone.appendChild(option);
		});

		var close = el('button', 'atm-emoji-close', '\u00D7');
		close.type = 'button';
		close.title = STR.close;
		close.setAttribute('aria-label', STR.close);

		head.appendChild(search);
		head.appendChild(tone);
		head.appendChild(close);

		var nav = el('div', 'atm-emoji-cats');
		nav.setAttribute('role', 'group');
		nav.setAttribute('aria-label', STR.title);

		var scroll = el('div', 'atm-emoji-body');
		var grid = el('div', 'atm-emoji-grid');
		var results = el('div', 'atm-emoji-results');
		results.hidden = true;
		scroll.appendChild(grid);
		scroll.appendChild(results);

		var empty = el('div', 'atm-emoji-empty', STR.empty);
		empty.hidden = true;

		panel.appendChild(head);
		panel.appendChild(nav);
		panel.appendChild(scroll);
		panel.appendChild(empty);

		state.panel = panel;
		state.grid = grid;
		state.results = results;
		state.empty = empty;
		state.scroll = scroll;
		state.search = search;
		state.nav = nav;
		state.toneSelect = tone;

		close.addEventListener('click', function () { closePanel(true); });
		search.addEventListener('input', function () { renderSearch(search.value); });
		tone.addEventListener('change', function () {
			state.tone = parseInt(tone.value, 10) || 0;
			applyTone();
		});

		nav.addEventListener('click', function (event) {
			var button = event.target.closest('button[data-cat]');
			if (!button) return;
			var section = state.sections[parseInt(button.getAttribute('data-cat'), 10)];
			if (section) state.scroll.scrollTop = section.getBoundingClientRect().top - state.scroll.getBoundingClientRect().top + state.scroll.scrollTop;
		});

		grid.addEventListener('click', function (event) {
			var button = event.target.closest('button[data-i]');
			if (button) choose(parseInt(button.getAttribute('data-i'), 10));
		});
		results.addEventListener('click', function (event) {
			var button = event.target.closest('button[data-i]');
			if (button) choose(parseInt(button.getAttribute('data-i'), 10));
		});

		scroll.addEventListener('scroll', updateActiveCategory);

		document.body.appendChild(panel);
		return panel;
	}

	function buildNav() {
		STR.cats.forEach(function (label, i) {
			var button = el('button', 'atm-emoji-cat', STR.icons[i]);
			button.type = 'button';
			button.title = label;
			button.setAttribute('aria-label', label);
			button.setAttribute('data-cat', String(i));
			if (i === 0) button.className += ' on';
			state.nav.appendChild(button);
		});
	}

	function buildSections() {
		var grid = state.grid;
		var sections = STR.cats.map(function (label, cat) {
			var section = el('div', 'atm-emoji-section');
			section.setAttribute('data-cat', String(cat));
			var header = el('div', 'atm-emoji-section-title', label);
			var items = el('div', 'atm-emoji-items');
			section.appendChild(header);
			section.appendChild(items);
			grid.appendChild(section);
			return section;
		});

		state.rows.forEach(function (row, i) {
			var button = el('button', 'atm-emoji-item', glyph(i));
			button.type = 'button';
			button.title = row[3];
			button.setAttribute('aria-label', row[3]);
			button.setAttribute('data-i', String(i));
			sections[row[1]].lastChild.appendChild(button);
		});

		state.sections = sections;
	}

	function applyTone() {
		var buttons = state.grid.querySelectorAll('button[data-i]');
		for (var i = 0; i < buttons.length; i++) {
			var index = parseInt(buttons[i].getAttribute('data-i'), 10);
			buttons[i].textContent = glyph(index);
		}
		if (!state.results.hidden) renderSearch(state.search.value);
	}

	function updateActiveCategory() {
		if (!state.sections || state.results.hidden === false) return;
		var top = state.scroll.scrollTop;
		var active = 0;
		for (var i = 0; i < state.sections.length; i++) {
			var rect = state.sections[i].getBoundingClientRect();
			if (rect.top - state.scroll.getBoundingClientRect().top <= 4) active = i;
		}
		if (active === state.activeCat) return;
		state.activeCat = active;
		var buttons = state.nav.children;
		for (var j = 0; j < buttons.length; j++) {
			buttons[j].className = 'atm-emoji-cat' + (j === active ? ' on' : '');
		}
	}

	function renderSearch(query) {
		if (!state.index) return;
		var normalized = normalize(query);
		var grid = state.grid;
		var results = state.results;
		var empty = state.empty;

		if (!normalized) {
			results.hidden = true;
			results.innerHTML = '';
			grid.hidden = false;
			empty.hidden = true;
			return;
		}

		var tokens = normalized.split(' ');
		var matches = [];
		for (var i = 0; i < state.index.length; i++) {
			var haystack = state.index[i];
			var ok = true;
			for (var t = 0; t < tokens.length; t++) {
				if (haystack.indexOf(tokens[t]) === -1) { ok = false; break; }
			}
			if (!ok) continue;
			var row = state.rows[i];
			var rank = normalize(row[3]).indexOf(normalized) === 0 ? 0 : (normalize(row[3]).indexOf(normalized) !== -1 ? 1 : 2);
			matches.push([rank, i]);
		}

		matches.sort(function (a, b) { return a[0] - b[0] || a[1] - b[1]; });
		if (matches.length > 240) matches.length = 240;

		grid.hidden = true;
		results.hidden = false;
		results.innerHTML = '';
		empty.hidden = matches.length > 0;

		var fragment = document.createDocumentFragment();
		matches.forEach(function (match) {
			var i = match[1];
			var row = state.rows[i];
			var button = el('button', 'atm-emoji-item', glyph(i));
			button.type = 'button';
			button.title = row[3];
			button.setAttribute('aria-label', row[3]);
			button.setAttribute('data-i', String(i));
			fragment.appendChild(button);
		});
		results.appendChild(fragment);
	}

	function position() {
		var panel = state.panel;
		var anchor = state.anchor;
		if (!anchor || !anchor.getBoundingClientRect) {
			panel.style.left = '50%';
			panel.style.top = '20px';
			panel.style.transform = 'translateX(-50%)';
			return;
		}
		panel.style.transform = 'none';
		var rect = anchor.getBoundingClientRect();
		var width = panel.offsetWidth;
		var left = rect.left;
		if (left + width > window.innerWidth - 8) left = window.innerWidth - width - 8;
		if (left < 8) left = 8;
		panel.style.left = left + 'px';
		panel.style.top = Math.min(rect.bottom + 4, window.innerHeight - 120) + 'px';
	}

	function open(anchor, onPick) {
		if (state.open) closePanel(false);
		state.anchor = anchor || null;
		state.onPick = onPick || null;

		var panel = state.panel || buildPanel();
		panel.hidden = false;
		panel.style.visibility = 'hidden';
		state.open = true;
		resetViews();
		position();
		panel.style.visibility = '';

		load().then(function () {
			if (!state.open) return;
			if (!state.sections) {
				buildNav();
				buildSections();
			}
			nextFrame(function () {
				position();
				state.search.focus();
			});
		}).catch(function () {
			state.empty.hidden = false;
			state.empty.textContent = STR.empty;
		});

		document.addEventListener('mousedown', outside, true);
		document.addEventListener('keydown', onKey, true);
		window.addEventListener('resize', position);
	}

	function closePanel(refocus) {
		if (!state.open) return;
		state.open = false;
		if (state.panel) state.panel.hidden = true;
		document.removeEventListener('mousedown', outside, true);
		document.removeEventListener('keydown', onKey, true);
		window.removeEventListener('resize', position);
		state.onPick = null;
		if (refocus && state.anchor && state.anchor.focus) state.anchor.focus();
	}

	function outside(event) {
		if (!state.panel.contains(event.target)) closePanel(false);
	}

	function onKey(event) {
		if (event.key === 'Escape') {
			event.preventDefault();
			closePanel(true);
		}
	}

	function choose(i) {
		var insert = state.onPick;
		var text = glyph(i);
		closePanel(false);
		if (insert) insert(text);
	}

	/* ---------- WysiBB integration ---------- */

	function insertIntoEditor(editor, text) {
		if (!editor) return;
		if (editor.options && editor.options.bbmode) {
			if (editor.$txtArea) editor.$txtArea.focus();
			editor.insertAtCursor(text);
			return;
		}
		if (editor.selectLastRange) editor.selectLastRange();
		editor.insertAtCursor(text, false);
	}

	function resetViews() {
		var results = state.results;
		var grid = state.grid;
		var empty = state.empty;
		state.search.value = '';
		if (results) {
			results.hidden = true;
			results.innerHTML = '';
		}
		if (grid) grid.hidden = false;
		if (empty) empty.hidden = true;
		state.tone = 0;
		if (state.toneSelect) state.toneSelect.value = '0';
		if (state.scroll) state.scroll.scrollTop = 0;
		state.activeCat = 0;
		if (state.nav) {
			for (var i = 0; i < state.nav.children.length; i++) {
				state.nav.children[i].className = 'atm-emoji-cat' + (i === 0 ? ' on' : '');
			}
		}
		if (state.rows) applyTone();
	}

	function attach(editor, $) {
		if (!editor || editor.__atmEmoji) return;
		var $toolbar = editor.$toolbar || $(editor.$editor).find('.wysibb-toolbar');
		if (!$toolbar || !$toolbar.length) return;
		editor.__atmEmoji = true;

		var container = $('<div class="wysibb-toolbar-container atm-emoji-slot">');
		var button = $('<div class="wysibb-toolbar-btn atm-emoji-btn" role="button" tabindex="0">' +
			'<span class="btn-inner" aria-hidden="true">\uD83D\uDE42</span></div>');
		button.attr('title', STR.title);
		button.attr('aria-label', STR.title);

		button.on('mousedown', function (event) {
			event.preventDefault();
			if (editor.saveRange) editor.saveRange();
			open(button[0], function (text) { insertIntoEditor(editor, text); });
		});
		button.on('keydown', function (event) {
			if (event.key !== 'Enter' && event.key !== ' ') return;
			event.preventDefault();
			if (editor.saveRange) editor.saveRange();
			open(button[0], function (text) { insertIntoEditor(editor, text); });
		});

		container.append(button);
		var $mode = $toolbar.find('.modeSwitch');
		if ($mode.length) container.insertBefore($mode); else $toolbar.append(container);
	}

	function attachAll() {
		var $ = global.jQuery;
		if (!$ || !$.fn.wysibb) return;
		$('textarea').each(function () {
			var editor = $(this).data('wbb');
			if (editor) attach(editor, $);
		});
	}

	function boot() {
		var $ = global.jQuery;
		if ($ && $.fn.wysibb) {
			var original = $.fn.wysibb;
			$.fn.wysibb = function () {
				var result = original.apply(this, arguments);
				attachAll();
				return result;
			};
			$(function () { setTimeout(attachAll, 0); });
		}

		// Warm the dataset while the page is idle, so the first open does not
		// wait for a 300 KB download and JSON parse.
		var warm = function () { load().catch(function () {}); };
		if (global.requestIdleCallback) {
			global.requestIdleCallback(warm, { timeout: 3000 });
		} else {
			setTimeout(warm, 1200);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	global.AtmEmojiPicker = { open: open, close: closePanel, lang: LANG };
})(window, document);
