/**
 * Progressive enhancement do shortcode [teatro_apresentacoes].
 *
 * A tabela já vem renderizada do servidor; este script substitui a navegação
 * por chamadas à rota REST pública, sem recarregar a página.
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

	// Espelha PresentationsShortcode::GROUPS (PHP). Hierarquia de 4 níveis:
	// l1 (faixa) > l2 (bloco de identidade + Total) > teatro > tipo + ano.
	var GROUP_MODES = {
		company: {
			l1: 'companyName',
			l2: 'playName',
			countLabel: 'Nº Peças',
			identity: [
				{ key: 'playName', label: 'Título da Peça' },
				{ key: 'genre', label: 'Gênero' },
				{ key: 'playNationality', label: 'Nacionalidade' },
				{ key: 'playLanguage', label: 'Idioma' }
			],
			l1LabelFields: [
				{ label: 'Companhia', key: 'companyName' },
				{ label: 'Nacionalidade', key: 'companyNationality' }
			]
		},
		play: {
			l1: 'playName',
			l2: 'companyName',
			countLabel: 'Nº Companhias',
			identity: [
				{ key: 'companyName', label: 'Nome da Companhia' },
				{ key: 'companyNationality', label: 'Nacionalidade da Companhia' },
				{ key: 'settingLanguage', label: 'Idioma' }
			],
			l1LabelFields: [
				{ label: 'Peça', key: 'playName' },
				{ label: 'Gênero', key: 'genre' },
				{ label: 'Nacionalidade', key: 'playNationality' }
			]
		}
	};
	var GROUP_LEAF_LABELS = ['Teatro', 'Tipo de Espetáculo', 'Ano', 'Nº de Sessões', 'Total'];

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

	function enhance(root) {
		var rest = root.getAttribute('data-rest');
		if (!rest || typeof window.fetch !== 'function') {
			return;
		}

		var perPage = parseInt(root.getAttribute('data-per-page'), 10) || 25;
		var orderby = root.getAttribute('data-orderby') || 'presentationDate';
		var order = root.getAttribute('data-order') || 'ASC';
		var theater = root.getAttribute('data-theater') || '';
		var year = root.getAttribute('data-year') || '';

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
		var wrap = root.querySelector('.teatro-apresentacoes__table-wrap');
		var countEl = root.querySelector('.teatro-apresentacoes__count');
		if (!wrap) {
			return;
		}

		// Com o script ativo, a mudança do agrupamento é feita via REST; sem ele,
		// o <select> recarrega a página (onchange no HTML do servidor).
		if (groupSelect) {
			groupSelect.removeAttribute('onchange');
		}

		var state = {
			page: 1,
			search: (searchInput && searchInput.value.trim()) ||
				root.getAttribute('data-preset-search') || '',
			group: (groupSelect && groupSelect.value) ||
				root.getAttribute('data-group') || ''
		};

		function requestHeaders() {
			var headers = { Accept: 'application/json' };
			if (cfg.nonce) {
				headers['X-WP-Nonce'] = cfg.nonce;
			}
			return headers;
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
			if (theater) {
				url.searchParams.set('theater', theater);
			}
			if (year) {
				url.searchParams.set('year', year);
			}
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

		function str(value) {
			return String(value == null ? '' : value).trim();
		}

		function fallback(value) {
			return str(value) || '—';
		}

		function yearOf(date) {
			var match = String(date == null ? '' : date).match(/(\d{4})/);
			return match ? match[1] : '—';
		}

		function toInt(value) {
			var n = parseInt(value, 10);
			return isNaN(n) ? 0 : n;
		}

		function natCmp(a, b) {
			return String(a).localeCompare(String(b), undefined, {
				sensitivity: 'base',
				numeric: true
			});
		}

		function td(label, text, rowSpan) {
			var cell = document.createElement('td');
			cell.setAttribute('data-label', label);
			cell.textContent = text;
			if (rowSpan) {
				cell.className = 'teatro-apresentacoes__group-cell';
				if (rowSpan > 1) {
					cell.rowSpan = rowSpan;
				}
			}
			return cell;
		}

		function renderGroups(rows) {
			if (!rows.length) {
				wrap.innerHTML = '<p class="teatro-apresentacoes__empty">' +
					escapeHtml(i18n.empty || '') + '</p>';
				return;
			}

			var cfg = GROUP_MODES[state.group];
			var tree = {};
			var l1Order = [];

			rows.forEach(function (row) {
				var l1Name = fallback(row[cfg.l1]);
				var l2Name = fallback(row[cfg.l2]);
				var theater = fallback(row.theaterName);
				var kind = fallback(row.settingKind);
				var year = yearOf(row.presentationDate);
				var sessions = toInt(row.sessionsNumber);

				if (!tree[l1Name]) {
					var label = cfg.l1LabelFields.map(function (f) {
						return f.label + ': ' + fallback(row[f.key]);
					}).join(' - ');
					tree[l1Name] = { label: label, total: 0, l2: {}, l2Order: [] };
					l1Order.push(l1Name);
				}
				var l1 = tree[l1Name];
				l1.total += sessions;

				if (!l1.l2[l2Name]) {
					var identity = {};
					cfg.identity.forEach(function (c) {
						identity[c.key] = str(row[c.key]);
					});
					l1.l2[l2Name] = { identity: identity, total: 0, leaves: {}, leafOrder: [] };
					l1.l2Order.push(l2Name);
				}
				var l2 = l1.l2[l2Name];
				l2.total += sessions;

				var leafKey = theater + '|' + kind + '|' + year;
				if (!l2.leaves[leafKey]) {
					l2.leaves[leafKey] = { theater: theater, kind: kind, year: year, sessions: 0 };
					l2.leafOrder.push(leafKey);
				}
				l2.leaves[leafKey].sessions += sessions;
			});

			var identityLabels = cfg.identity.map(function (c) { return c.label; });
			var headLabels = identityLabels.concat(GROUP_LEAF_LABELS);
			var ncols = headLabels.length;

			l1Order.sort(natCmp);

			// Um único <table>: cabeçalho de colunas uma vez, um <tbody> por grupo.
			var table = document.createElement('table');
			table.className = 'teatro-apresentacoes__table teatro-apresentacoes__table--group';

			var thead = document.createElement('thead');
			var headRow = document.createElement('tr');
			headLabels.forEach(function (lbl) {
				var th = document.createElement('th');
				th.scope = 'col';
				th.textContent = lbl;
				headRow.appendChild(th);
			});
			thead.appendChild(headRow);
			table.appendChild(thead);

			l1Order.forEach(function (l1Name) {
				var l1 = tree[l1Name];
				var tbody = document.createElement('tbody');
				tbody.className = 'teatro-apresentacoes__group';

				var bandRow = document.createElement('tr');
				bandRow.className = 'teatro-apresentacoes__group-row';
				var bandTh = document.createElement('th');
				bandTh.setAttribute('scope', 'colgroup');
				bandTh.colSpan = ncols;
				bandTh.textContent = l1.label +
					' - ' + cfg.countLabel + ': ' + l1.l2Order.length.toLocaleString() +
					' - Total de Sessões: ' + l1.total.toLocaleString();
				bandRow.appendChild(bandTh);
				tbody.appendChild(bandRow);

				l1.l2Order.sort(natCmp);
				l1.l2Order.forEach(function (l2Name) {
					var l2 = l1.l2[l2Name];
					l2.leafOrder.sort(function (a, b) {
						var x = l2.leaves[a];
						var y = l2.leaves[b];
						return natCmp(x.theater, y.theater) ||
							natCmp(x.kind, y.kind) ||
							natCmp(x.year, y.year);
					});

					var span = l2.leafOrder.length;
					l2.leafOrder.forEach(function (leafKey, idx) {
						var leaf = l2.leaves[leafKey];
						var tr = document.createElement('tr');

						if (idx === 0) {
							cfg.identity.forEach(function (c) {
								tr.appendChild(td(c.label, fallback(l2.identity[c.key]), span));
							});
						}
						tr.appendChild(td('Teatro', leaf.theater));
						tr.appendChild(td('Tipo de Espetáculo', leaf.kind));
						tr.appendChild(td('Ano', leaf.year));
						tr.appendChild(td('Nº de Sessões', leaf.sessions.toLocaleString()));
						if (idx === 0) {
							tr.appendChild(td('Total', l2.total.toLocaleString(), span));
						}

						tbody.appendChild(tr);
					});
				});

				table.appendChild(tbody);
			});

			wrap.innerHTML = '';
			wrap.appendChild(table);
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
			window.history.replaceState({}, '', url.toString());
		}

		// Agrupamento ativo: varre todas as páginas da rota REST e monta as
		// micro-tabelas por grupo (a paginação some).
		function loadGrouped() {
			setBusy(true);
			wrap.classList.add('teatro-apresentacoes__table-wrap--grouped');
			var collected = [];

			function fetchPage(page) {
				var url = new URL(rest, window.location.origin);
				url.searchParams.set('page', page);
				url.searchParams.set('per_page', 200);
				url.searchParams.set('orderby', orderby);
				url.searchParams.set('order', order);
				if (state.search) {
					url.searchParams.set('search', state.search);
				}
				if (theater) {
					url.searchParams.set('theater', theater);
				}
				if (year) {
					url.searchParams.set('year', year);
				}

				return window.fetch(url.toString(), {
					headers: requestHeaders(),
					credentials: 'same-origin'
				}).then(function (response) {
					if (!response.ok) {
						throw new Error('HTTP ' + response.status);
					}
					var totalPages = parseInt(response.headers.get('X-WP-TotalPages') || '1', 10);
					return response.json().then(function (data) {
						collected = collected.concat(data || []);
						if (page < totalPages && page < 50) {
							return fetchPage(page + 1);
						}
						return collected;
					});
				});
			}

			fetchPage(1)
				.then(function (rows) {
					renderGroups(rows);
					renderPagination(1);
					if (countEl && i18n.results) {
						countEl.textContent = i18n.results.replace('%s', rows.length.toLocaleString());
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
			if (state.group && GROUP_MODES[state.group]) {
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
