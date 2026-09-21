/**
 * Página de configurações: sub-opções acompanham a funcionalidade-mãe e o
 * banco de dados fica preso enquanto o shortcode ou a REST estiverem ligados
 * (o servidor aplica a mesma regra em Features::sanitize()).
 *
 * `inert` (e não `disabled`) para que os valores continuem sendo enviados.
 */
(function () {
	'use strict';

	var TABLE_KEY = 'presentations_table';
	var NEEDS_TABLE = ['presentations_shortcode', 'presentations_rest'];

	function byKey(root, key) {
		return root.querySelector('input[data-key="' + key + '"]');
	}

	function setInert(el, inert) {
		if (!el) {
			return;
		}
		if (inert) {
			el.setAttribute('inert', '');
		} else {
			el.removeAttribute('inert');
		}
	}

	function syncChildren(group) {
		var parent = byKey(document, group.getAttribute('data-parent'));
		var inactive = !!parent && !parent.checked;
		group.classList.toggle('is-inactive', inactive);
		setInert(group, inactive);
	}

	function syncTable(root) {
		var table = byKey(root, TABLE_KEY);
		if (!table) {
			return;
		}
		var needed = NEEDS_TABLE.some(function (key) {
			var input = byKey(root, key);
			return input && input.checked;
		});
		if (needed) {
			table.checked = true;
		}
		setInert(table.closest('.tmsp-feature'), needed);
	}

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.querySelector('.tmsp-settings');
		if (!root) {
			return;
		}

		var groups = root.querySelectorAll('.tmsp-children');

		Array.prototype.forEach.call(groups, function (group) {
			syncChildren(group);
		});
		syncTable(root);

		root.addEventListener('change', function (event) {
			var input = event.target;
			if (!input || input.type !== 'checkbox') {
				return;
			}

			if (input.classList.contains('tmsp-parent')) {
				var group = root.querySelector('.tmsp-children[data-parent="' + input.getAttribute('data-key') + '"]');
				if (group) {
					var children = group.querySelectorAll('input');
					var anyOn = Array.prototype.some.call(children, function (child) {
						return child.checked;
					});
					// Ligou a mãe sem nenhuma parte escolhida: começa com tudo ligado.
					if (input.checked && !anyOn) {
						Array.prototype.forEach.call(children, function (child) {
							child.checked = true;
						});
					}
					syncChildren(group);
				}
			}

			syncTable(root);
		});
	});
})();
