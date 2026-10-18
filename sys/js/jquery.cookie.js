/*!
 * jQuery Cookie API shim
 *
 * A drop-in replacement for the long-abandoned jquery.cookie plugin.
 * Keeps the legacy $.cookie() / $.removeCookie() API working on top of
 * the native document.cookie interface, so old call sites do not need
 * to be rewritten. No external dependencies besides jQuery itself.
 *
 * Usage is identical to jquery.cookie 1.x:
 *   $.cookie('the_cookie');                      // read
 *   $.cookie('the_cookie', 'the_value', {expires: 7, path: '/'}); // write
 *   $.cookie('the_cookie', null);                // delete
 *   $.removeCookie('the_cookie', {path: '/'});
 */
(function ($) {
	'use strict';

	function encode(value) {
		return encodeURIComponent(value);
	}

	function decode(value) {
		return value.replace(/(%[\dA-F]{2})+/gi, decodeURIComponent);
	}

	$.cookie = function (key, value, options) {
		// Read a cookie: no value argument was passed
		if (arguments.length === 1) {
			var cookies = document.cookie ? document.cookie.split('; ') : [];
			for (var i = 0; i < cookies.length; i++) {
				var parts = cookies[i].split('=');
				var name = decode(parts.shift());
				var rest = parts.join('=');
				if (key === name) {
					return decode(rest);
				}
			}
			return undefined;
		}

		options = $.extend({}, options);
		if (value === null || value === undefined) {
			value = '';
			options.expires = -1;
		}

		if (typeof options.expires === 'number') {
			var days = options.expires;
			var expiresDate = options.expires = new Date();
			expiresDate.setDate(expiresDate.getDate() + days);
		}

		var cookie = [encode(key), '=', encode(String(value))];
		if (options.expires instanceof Date) {
			cookie.push('expires=' + options.expires.toUTCString());
		}
		if (options.path) {
			cookie.push('path=' + options.path);
		}
		if (options.domain) {
			cookie.push('domain=' + options.domain);
		}
		if (options.secure) {
			cookie.push('secure');
		}
		if (options.sameSite) {
			cookie.push('samesite=' + options.sameSite);
		}

		document.cookie = cookie.join('; ');
		return document.cookie.indexOf(encode(key) + '=') !== -1;
	};

	$.removeCookie = function (key, options) {
		options = $.extend({}, options, { expires: -1 });
		$.cookie(key, null, options);
		return $.cookie(key) === undefined;
	};

}(jQuery));
