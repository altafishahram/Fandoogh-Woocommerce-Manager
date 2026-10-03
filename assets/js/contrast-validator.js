/*
 * Fandoogh Manager — live WCAG contrast feedback for the theme color fields.
 *
 * Reads the same color inputs the server sanitizes, recomputes the same five
 * WCAG 2.x ratios as validate_colors_contrast() in PHP, and updates the
 * report table rendered by render_theme_section(). Purely advisory: the
 * server-side rejection on save remains the source of truth.
 */
(function () {
	'use strict';

	function clamp01(value) {
		return Math.min(1, Math.max(0, value));
	}

	function channelFromHex(hex, offset) {
		return parseInt(hex.substr(offset, 2), 16) / 255;
	}

	/* WCAG 2.x relative luminance — mirrors color_relative_luminance() in PHP. */
	function relativeLuminance(hexColor) {
		var hex = String(hexColor || '').replace('#', '');
		if (hex.length !== 6 || /[^0-9a-fA-F]/.test(hex)) {
			return 0;
		}
		var channels = [0, 2, 4].map(function (offset) {
			var value = clamp01(channelFromHex(hex, offset));
			return value <= 0.03928 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
		});
		return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
	}

	/* WCAG 2.x contrast ratio — mirrors color_contrast_ratio() in PHP. */
	function contrastRatio(firstColor, secondColor) {
		var first = relativeLuminance(firstColor);
		var second = relativeLuminance(secondColor);
		var lighter = Math.max(first, second);
		var darker = Math.min(first, second);
		return (lighter + 0.05) / (darker + 0.05);
	}

	function formatRatio(ratio) {
		return ratio.toFixed(2).replace('.', '٫');
	}

	function readColor(key) {
		var input = document.querySelector('input[name="fandoogh_manager_settings[colors][' + key + ']"]');
		return input ? input.value : '';
	}

	function updateRow(row) {
		var first = row.getAttribute('data-contrast-first');
		var second = row.getAttribute('data-contrast-second');

		/* "#FFFFFF" rows are literal server-side constants, not field keys. */
		if (first.charAt(0) !== '#') {
			first = readColor(first) || first;
		}
		if (second.charAt(0) !== '#') {
			second = readColor(second) || second;
		}

		var minimum = parseFloat(row.getAttribute('data-contrast-minimum')) || 3;
		var ratio = contrastRatio(first, second);
		var ok = ratio + 0.005 >= minimum;

		var ratioCell = row.querySelector('[data-contrast-ratio]');
		if (ratioCell) {
			ratioCell.textContent = formatRatio(ratio);
		}
		var statusCell = row.querySelector('[data-contrast-status]');
		if (statusCell) {
			statusCell.textContent = ok ? '✅' : '❌';
		}
		var swatch = row.querySelector('.fandoogh-contrast-swatch');
		if (swatch) {
			swatch.style.background = second;
			swatch.style.color = first;
		}
	}

	function updateAll() {
		var rows = document.querySelectorAll('tr[data-contrast-pair]');
		Array.prototype.forEach.call(rows, updateRow);
	}

	function init() {
		if (!document.querySelector('tr[data-contrast-pair]')) {
			return;
		}
		var inputs = document.querySelectorAll('input[name^="fandoogh_manager_settings[colors]"]');
		Array.prototype.forEach.call(inputs, function (input) {
			input.addEventListener('input', updateAll);
			input.addEventListener('change', updateAll);
		});
		updateAll();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init, { once: true });
	} else {
		init();
	}
})();
