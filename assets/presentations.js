/**
 * Progressive enhancement do shortcode [teatro_apresentacoes].
 *
 * A tabela já vem renderizada do servidor; este script substitui a navegação
 * (busca, filtros por coluna, agrupamento e paginação) por chamadas à rota REST
 * pública, sem recarregar a página. Cada filtro é um `<details>` com uma
 * `<input type="checkbox">` por valor — várias caixas marcadas na mesma coluna
 * filtram em OR (`col IN (...)`, resolvido no backend); nenhum campo dispara
 * busca sozinho, só o submit do formulário.
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

	// tap_f[<col>][]  ->  <col>  (um <input type="checkbox"> por valor)
	function filterColumnName(checkboxName) {
		var match = /^tap_f\[(.+)\]\[\]$/.exec(checkboxName || '');
		return match ? match[1] : '';
	}

	// Comparação sem acento e sem caixa, para a busca interna dos filtros longos.
	var DIACRITICS = /[̀-ͯ]/g;
	function normalizeText(value) {
		var str = String(value == null ? '' : value);
		str = str.normalize ? str.normalize('NFD').replace(DIACRITICS, '') : str;
		return str.toLowerCase();
	}

	/**
	 * Cada filtro já é uma "caixa tipo select" nativa (`<details>` com uma
	 * `<input type="checkbox">` por valor — abre/fecha e marca/desmarca sem
	 * JS). Aqui só ligamos dois acabamentos que dependem de JS:
	 *  - nos filtros `--searchable`, o campo de busca filtra (mostra/esconde)
	 *    as linhas da lista — NÃO dispara busca na tabela;
	 *  - o contador ao lado do rótulo reflete quantas caixas estão marcadas.
	 * Nenhum dos dois altera o valor que vai no submit — só a apresentação.
	 */
	function enhanceFilterField(fieldEl) {
		var search = fieldEl.querySelector('.teatro-apresentacoes__filter-search');
		var rows = fieldEl.querySelectorAll('.teatro-apresentacoes__filter-option');
		var countEl = fieldEl.querySelector('.teatro-apresentacoes__filter-count');
		var boxes = fieldEl.querySelectorAll('input[type="checkbox"]');

		if (search) {
			search.addEventListener('input', function () {
				var q = normalizeText(search.value);
				Array.prototype.forEach.call(rows, function (row) {
					row.hidden = q !== '' && normalizeText(row.textContent).indexOf(q) === -1;
				});
			});
			// Enter no campo de busca não deve submeter o formulário inteiro.
			search.addEventListener('keydown', function (event) {
				if (event.key === 'Enter') {
					event.preventDefault();
				}
			});
		}

		if (countEl) {
			var updateCount = function () {
				var n = fieldEl.querySelectorAll('input[type="checkbox"]:checked').length;
				countEl.textContent = String(n);
				countEl.hidden = n === 0;
			};
			Array.prototype.forEach.call(boxes, function (box) {
				box.addEventListener('change', updateCount);
			});
		}

		// Esc fecha o painel e devolve o foco ao rótulo (o <details> nativo não
		// faz isso sozinho).
		fieldEl.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && fieldEl.open) {
				fieldEl.open = false;
				var summary = fieldEl.querySelector('summary');
				if (summary) {
					summary.focus();
				}
			}
		});
	}

	// Clicar fora de um filtro aberto fecha o painel (o <details> nativo, sem
	// isso, só fecha reclicando no próprio <summary>).
	function closeFilterFieldsOutside(root) {
		document.addEventListener('click', function (event) {
			var open = root.querySelectorAll('details.teatro-apresentacoes__filter-field[open]');
			Array.prototype.forEach.call(open, function (det) {
				if (!det.contains(event.target)) {
					det.open = false;
				}
			});
		});
	}

	function enhance(root) {
		var rest = root.getAttribute('data-rest');
		if (!rest || typeof window.fetch !== 'function') {
			return;
		}

		var perPage = parseInt(root.getAttribute('data-per-page'), 10) || 25;
		var DEFAULT_ORDERBY = 'presentationDate';

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

		// Opções de "Ordenado por:" por modo de agrupamento ('' = modo plano),
		// para repopular o <select> quando o agrupamento muda sem recarregar.
		var sortColumns = {};
		try {
			sortColumns = JSON.parse(root.getAttribute('data-sort-columns') || '{}');
		} catch (e) {
			sortColumns = {};
		}

		var form = root.querySelector('.teatro-apresentacoes__search');
		var searchInput = root.querySelector('#teatro-apresentacoes-s');
		var groupSelect = root.querySelector('#teatro-apresentacoes-group');
		var orderbySelect = root.querySelector('#teatro-apresentacoes-orderby');
		var orderSelect = root.querySelector('#teatro-apresentacoes-order');
		var wrap = root.querySelector('.teatro-apresentacoes__table-wrap');
		var countEl = root.querySelector('.teatro-apresentacoes__count');
		if (!wrap) {
			return;
		}

		// Formulário de submit único: nenhum campo é listener de ação imediata.
		// O estado só é lido (readFields) e a tabela só recarrega no submit.
		var state = {
			page: 1,
			submitted: root.getAttribute('data-submitted') === '1',
			search: '',
			group: '',
			orderby: DEFAULT_ORDERBY,
			order: 'ASC',
			filters: {}
		};

		// Lê todos os campos do formulário para o `state` (chamado no submit e uma
		// vez no início, para refletir o que o servidor já renderizou como `selected`).
		function readFields() {
			state.search = searchInput ? searchInput.value.trim() : '';
			state.group = groupSelect ? groupSelect.value : '';
			state.orderby = orderbySelect ? orderbySelect.value : DEFAULT_ORDERBY;
			state.order = (orderSelect ? orderSelect.value : 'ASC').toUpperCase() === 'DESC' ? 'DESC' : 'ASC';
			state.filters = {};
			var checked = root.querySelectorAll('input[type="checkbox"][name^="tap_f["]:checked');
			Array.prototype.forEach.call(checked, function (box) {
				var col = filterColumnName(box.name);
				if (!col) {
					return;
				}
				if (!state.filters[col]) {
					state.filters[col] = [];
				}
				state.filters[col].push(box.value);
			});
		}
		readFields();

		// Ao trocar o agrupamento, as colunas ordenáveis mudam: repopula o
		// <select> "Ordenado por:" a partir de data-sort-columns e descarta uma
		// seleção que não exista no novo modo. Só mexe nas <option> — não busca.
		function syncOrderbyOptions() {
			if (!orderbySelect) {
				return;
			}
			var opts = sortColumns[state.group] || sortColumns[''] || {};
			var keys = Object.keys(opts);
			if (!keys.length) {
				return;
			}
			if (keys.indexOf(state.orderby) === -1) {
				state.orderby = keys[0];
			}
			orderbySelect.innerHTML = '';
			keys.forEach(function (key) {
				var opt = document.createElement('option');
				opt.value = key;
				opt.textContent = opts[key];
				if (key === state.orderby) {
					opt.selected = true;
				}
				orderbySelect.appendChild(opt);
			});
		}

		// Mantém as <option> de "Ordenado por:" coerentes com o agrupamento
		// escolhido (troca visual apenas, sem consultar a tabela).
		if (groupSelect) {
			groupSelect.addEventListener('change', function () {
				state.group = groupSelect.value;
				syncOrderbyOptions();
			});
		}

		// Acabamentos de JS nos filtros (busca interna + contador); o
		// funcionamento base (abrir/marcar/desmarcar) já é nativo do <details>.
		Array.prototype.forEach.call(
			root.querySelectorAll('.teatro-apresentacoes__filter-field'),
			enhanceFilterField
		);
		closeFilterFieldsOutside(root);

		function requestHeaders() {
			var headers = { Accept: 'application/json' };
			if (cfg.nonce) {
				headers['X-WP-Nonce'] = cfg.nonce;
			}
			return headers;
		}

		// Vários valores por coluna são combinados em OR pelo backend
		// (`col IN (...)`) — cada um vira sua própria entrada `filters[col][]`.
		function applyFilterParams(url) {
			Object.keys(state.filters).forEach(function (col) {
				(state.filters[col] || []).forEach(function (value) {
					if (value !== null && value !== undefined && value !== '') {
						url.searchParams.append('filters[' + col + '][]', value);
					}
				});
			});
		}

		function buildUrl() {
			var url = new URL(rest, window.location.origin);
			url.searchParams.set('page', state.page);
			url.searchParams.set('per_page', perPage);
			url.searchParams.set('orderby', state.orderby);
			url.searchParams.set('order', state.order);
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
			// tap_go é o marcador de "já houve submit" que o servidor lê para
			// decidir se renderiza a tabela.
			if (state.submitted) {
				url.searchParams.set('tap_go', '1');
			} else {
				url.searchParams.delete('tap_go');
			}
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
			if (state.orderby && state.orderby !== DEFAULT_ORDERBY) {
				url.searchParams.set('tap_orderby', state.orderby);
			} else {
				url.searchParams.delete('tap_orderby');
			}
			if (state.order === 'DESC') {
				url.searchParams.set('tap_order', 'DESC');
			} else {
				url.searchParams.delete('tap_order');
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
				(state.filters[col] || []).forEach(function (value) {
					if (value) {
						url.searchParams.append('tap_f[' + col + '][]', value);
					}
				});
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
			url.searchParams.set('orderby', state.orderby);
			url.searchParams.set('order', state.order);
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
						countEl.hidden = false;
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
						countEl.hidden = false;
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

		// Único ponto de disparo de busca: o submit do formulário.
		if (form) {
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				readFields();
				syncOrderbyOptions();
				state.submitted = true;
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
