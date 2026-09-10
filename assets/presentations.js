/**
 * Progressive enhancement do shortcode [teatro_apresentacoes].
 *
 * A tabela já vem renderizada do servidor; este script substitui a navegação
 * (busca, filtros por coluna, agrupamento e paginação) por chamadas à rota REST
 * pública, sem recarregar a página.
 *
 * O modo agrupado não reimplementa o algoritmo de agrupamento em JS: a rota
 * `.../presentations/grouped` roda PresentationGrouper + GroupedTableRenderer
 * no servidor e devolve o HTML da tabela pronto (`{ html, total }`); este
 * script só troca o innerHTML do wrapper. A lista de colunas filtráveis
 * (`filterKeys`) vem de `window.TeatroApresentacoes`, localizada pelo PHP a
 * partir de PresentationsSchema.
 */
(function () {
	'use strict';

	var cfg = window.TeatroApresentacoes || {};
	var i18n = cfg.i18n || {};

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	function escapeHtml(value) {
		return String(value).replace(/[&<>"']/g, function (chr) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;'
			}[chr];
		});
	}

	// tap_f[<col>]  ->  <col>
	function filterColumnName(selectName) {
		var match = /^tap_f\[(.+)\]$/.exec(selectName || '');
		return match ? match[1] : '';
	}

	function enhance(root) {
		var rest = root.getAttribute('data-rest');
		if (!rest || typeof window.fetch !== 'function') {
			return;
		}

		var perPage = parseInt(root.getAttribute('data-per-page'), 10) || 25;
		var orderby = root.getAttribute('data-orderby') || 'presentationDate';
		var order = root.getAttribute('data-order') || 'ASC';

		var columns = {};
		try {
			columns = JSON.parse(root.getAttribute('data-columns') || '{}');
		} catch (e) {
			columns = {};
		}
		var colKeys = Object.keys(columns);
		if (!colKeys.length) {
			return;
		}

		var form = root.querySelector('.teatro-apresentacoes__search');
		var searchInput = root.querySelector('#teatro-apresentacoes-s');
		var groupSelect = root.querySelector('#teatro-apresentacoes-group');
		var filterSelects = root.querySelectorAll('select[name^="tap_f["]');
		var wrap = root.querySelector('.teatro-apresentacoes__table-wrap');
		var countEl = root.querySelector('.teatro-apresentacoes__count');
		if (!wrap) {
			return;
		}

		// Com o script ativo, mudanças passam a ir via REST; sem ele, os <select>
		// recarregam a página (onchange no HTML do servidor).
		if (groupSelect) {
			groupSelect.removeAttribute('onchange');
		}

		var state = {
			page: 1,
			search: (searchInput && searchInput.value.trim()) ||
				root.getAttribute('data-preset-search') || '',
			group: (groupSelect && groupSelect.value) ||
				root.getAttribute('data-group') || '',
			filters: {}
		};

		// Estado inicial dos filtros: lê o valor selecionado de cada <select>
		// (que o servidor já renderizou com o `selected` correto).
		Array.prototype.forEach.call(filterSelects, function (sel) {
			var col = filterColumnName(sel.name);
			if (!col) {
				return;
			}
			if (sel.value) {
				state.filters[col] = sel.value;
			}
			sel.removeAttribute('onchange');
			sel.addEventListener('change', function () {
				if (sel.value) {
					state.filters[col] = sel.value;
				} else {
					delete state.filters[col];
				}
				state.page = 1;
				load();
			});
		});

		function requestHeaders() {
			var headers = { Accept: 'application/json' };
			if (cfg.nonce) {
				headers['X-WP-Nonce'] = cfg.nonce;
			}
			return headers;
		}

		function applyFilterParams(url) {
			Object.keys(state.filters).forEach(function (col) {
				var value = state.filters[col];
				if (value !== null && value !== undefined && value !== '') {
					url.searchParams.set('filters[' + col + ']', value);
				}
			});
		}

		function buildUrl() {
			var url = new URL(rest, window.location.origin);
			url.searchParams.set('page', state.page);
			url.searchParams.set('per_page', perPage);
			url.searchParams.set('orderby', orderby);
			url.searchParams.set('order', order);
			if (state.search) {
				url.searchParams.set('search', state.search);
			}
			applyFilterParams(url);
			return url.toString();
		}

		function setBusy(busy) {
			if (busy) {
				wrap.setAttribute('aria-busy', 'true');
				wrap.setAttribute('data-loading-text', i18n.loading || '');
			} else {
				wrap.removeAttribute('aria-busy');
			}
		}

		function ensureTable() {
			var table = wrap.querySelector('.teatro-apresentacoes__table');
			if (table && !table.classList.contains('teatro-apresentacoes__table--group')) {
				return table;
			}
			wrap.innerHTML = '';
			table = document.createElement('table');
			table.className = 'teatro-apresentacoes__table';

			var thead = document.createElement('thead');
			var headRow = document.createElement('tr');
			colKeys.forEach(function (key) {
				var th = document.createElement('th');
				th.scope = 'col';
				th.textContent = columns[key];
				headRow.appendChild(th);
			});
			thead.appendChild(headRow);
			table.appendChild(thead);
			table.appendChild(document.createElement('tbody'));
			wrap.appendChild(table);
			return table;
		}

		function renderRows(rows) {
			if (!rows.length) {
				wrap.innerHTML = '<p class="teatro-apresentacoes__empty">' +
					escapeHtml(i18n.empty || '') + '</p>';
				return;
			}

			var tbody = ensureTable().querySelector('tbody');
			tbody.innerHTML = '';
			rows.forEach(function (row) {
				var tr = document.createElement('tr');
				colKeys.forEach(function (key) {
					var td = document.createElement('td');
					td.setAttribute('data-label', columns[key]);
					var value = row[key];
					if (key === 'presentationDate' && typeof value === 'string') {
						value = value.replace(/\s+00:00:00$/, '');
					}
					td.textContent = (value === null || value === undefined || value === '') ?
						'—' : String(value);
					tr.appendChild(td);
				});
				tbody.appendChild(tr);
			});
		}

		function goTo(page) {
			state.page = Math.max(1, page);
			load();
		}

		function renderPagination(totalPages) {
			var nav = root.querySelector('.teatro-apresentacoes__pagination');

			if (totalPages <= 1) {
				if (nav) {
					nav.parentNode.removeChild(nav);
				}
				return;
			}

			if (!nav) {
				nav = document.createElement('nav');
				nav.className = 'teatro-apresentacoes__pagination';
				nav.setAttribute('aria-label', 'Paginação');
				if (countEl) {
					root.insertBefore(nav, countEl);
				} else {
					root.appendChild(nav);
				}
			}
			nav.innerHTML = '';

			function addItem(label, page, extraClass, disabled) {
				var node;
				if (disabled) {
					node = document.createElement('span');
				} else {
					node = document.createElement('a');
					node.href = '#';
					node.addEventListener('click', function (event) {
						event.preventDefault();
						goTo(page);
					});
				}
				node.className = 'page-numbers' +
					(extraClass ? ' ' + extraClass : '') +
					(page === state.page && !extraClass ? ' current' : '');
				node.innerHTML = label;
				nav.appendChild(node);
			}

			function addDots() {
				var dots = document.createElement('span');
				dots.className = 'page-numbers dots';
				dots.textContent = '…';
				nav.appendChild(dots);
			}

			addItem(i18n.prev || '&laquo;', state.page - 1, 'prev', state.page <= 1);

			var start = Math.max(1, state.page - 2);
			var end = Math.min(totalPages, state.page + 2);
			if (start > 1) {
				addItem('1', 1);
			}
			if (start > 2) {
				addDots();
			}
			for (var page = start; page <= end; page++) {
				addItem(String(page), page, page === state.page ? 'current' : '');
			}
			if (end < totalPages - 1) {
				addDots();
			}
			if (end < totalPages) {
				addItem(String(totalPages), totalPages);
			}

			addItem(i18n.next || '&raquo;', state.page + 1, 'next', state.page >= totalPages);
		}

		function syncHistory() {
			if (!window.history || !window.history.replaceState) {
				return;
			}
			var url = new URL(window.location.href);
			if (state.search) {
				url.searchParams.set('tap_s', state.search);
			} else {
				url.searchParams.delete('tap_s');
			}
			if (state.page > 1) {
				url.searchParams.set('tap_paged', String(state.page));
			} else {
				url.searchParams.delete('tap_paged');
			}
			if (state.group) {
				url.searchParams.set('tap_group', state.group);
			} else {
				url.searchParams.delete('tap_group');
			}
			var staleFilterKeys = [];
			url.searchParams.forEach(function (value, key) {
				if (key.indexOf('tap_f[') === 0) {
					staleFilterKeys.push(key);
				}
			});
			staleFilterKeys.forEach(function (key) {
				url.searchParams.delete(key);
			});
			Object.keys(state.filters).forEach(function (col) {
				if (state.filters[col]) {
					url.searchParams.set('tap_f[' + col + ']', state.filters[col]);
				}
			});
			window.history.replaceState({}, '', url.toString());
		}

		// Agrupamento ativo: o servidor monta a árvore e devolve o HTML da
		// tabela pronto (`{ html, total }`); aqui só trocamos o innerHTML do
		// wrapper (a paginação some).
		function loadGrouped() {
			setBusy(true);
			wrap.classList.add('teatro-apresentacoes__table-wrap--grouped');

			var url = new URL(rest + '/grouped', window.location.origin);
			url.searchParams.set('group', state.group);
			url.searchParams.set('orderby', orderby);
			url.searchParams.set('order', order);
			if (state.search) {
				url.searchParams.set('search', state.search);
			}
			applyFilterParams(url);

			window.fetch(url.toString(), { headers: requestHeaders(), credentials: 'same-origin' })
				.then(function (response) {
					if (!response.ok) {
						throw new Error('HTTP ' + response.status);
					}
					return response.json();
				})
				.then(function (payload) {
					wrap.innerHTML = payload.total ?
						payload.html :
						'<p class="teatro-apresentacoes__empty">' + escapeHtml(i18n.empty || '') + '</p>';
					renderPagination(1);
					if (countEl && i18n.results) {
						countEl.textContent = i18n.results.replace('%s', (payload.total || 0).toLocaleString());
					}
					syncHistory();
				})
				.catch(function () {
					wrap.innerHTML = '<p class="teatro-apresentacoes__empty">' +
						escapeHtml(i18n.error || '') + '</p>';
				})
				.then(function () {
					setBusy(false);
				});
		}

		function load() {
			if (state.group) {
				loadGrouped();
				return;
			}

			wrap.classList.remove('teatro-apresentacoes__table-wrap--grouped');
			setBusy(true);

			window.fetch(buildUrl(), { headers: requestHeaders(), credentials: 'same-origin' })
				.then(function (response) {
					if (!response.ok) {
						throw new Error('HTTP ' + response.status);
					}
					var total = parseInt(response.headers.get('X-WP-Total') || '0', 10);
					var totalPages = parseInt(response.headers.get('X-WP-TotalPages') || '1', 10);
					return response.json().then(function (data) {
						return { rows: data, total: total, totalPages: totalPages };
					});
				})
				.then(function (payload) {
					renderRows(payload.rows || []);
					renderPagination(payload.totalPages);
					if (countEl && i18n.results) {
						countEl.textContent = i18n.results.replace('%s', payload.total.toLocaleString());
					}
					syncHistory();
				})
				.catch(function () {
					wrap.innerHTML = '<p class="teatro-apresentacoes__empty">' +
						escapeHtml(i18n.error || '') + '</p>';
				})
				.then(function () {
					setBusy(false);
				});
		}

		if (form) {
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				state.search = searchInput ? searchInput.value.trim() : '';
				state.group = groupSelect ? groupSelect.value : '';
				state.page = 1;
				load();
			});
		}

		if (groupSelect) {
			groupSelect.addEventListener('change', function () {
				state.group = groupSelect.value;
				state.page = 1;
				load();
			});
		}

		// Intercepta os links de paginação renderizados pelo servidor.
		root.addEventListener('click', function (event) {
			var target = event.target;
			var link = target && target.closest ?
				target.closest('.teatro-apresentacoes__pagination a') : null;
			if (!link) {
				return;
			}
			var match = /[?&]tap_paged=(\d+)/.exec(link.getAttribute('href') || '');
			if (!match) {
				return;
			}
			event.preventDefault();
			goTo(parseInt(match[1], 10));
		});
	}

	ready(function () {
		var roots = document.querySelectorAll('.teatro-apresentacoes');
		Array.prototype.forEach.call(roots, enhance);
	});
})();
