/**
 * NV oOS Content Graph AI — Assistant builder block set (frontend).
 *
 * Lean vanilla JS for the server-rendered assistant blocks:
 *   - assistant-selector: enable the start button + emit the start event
 *   - tools-grid: search, category filter, select/deselect-all, counts
 *   - knowledge-base: validated uploads to wp/v2/media + file IDs input
 *   - assistant-builder: section reveal, build flow (AJAX create)
 *
 * No build step; ships as-is in the plugin ZIP.
 *
 * @since 1.1.0
 */
(function () {
	'use strict';

	/**
	 * Find the closest ancestor matching a selector.
	 *
	 * @param {Element} node     Start node.
	 * @param {string}  selector CSS selector.
	 * @return {Element|null}
	 */
	function closest(node, selector) {
		if (!node || !node.closest) {
			return null;
		}
		return node.closest(selector);
	}

	// ─── Assistant selector ────────────────────────────────────────

	function bindSelectors() {
		const roots = document.querySelectorAll('.nvoos-cg-selector');
		for (let i = 0; i < roots.length; i++) {
			bindSelector(roots[i]);
		}
	}

	function bindSelector(root) {
		const select = root.querySelector('.nvoos-cg-selector__select');
		const start = root.querySelector('.nvoos-cg-selector__start');
		if (!select) {
			return;
		}

		select.addEventListener('change', function () {
			if (start) {
				start.disabled = !select.value;
			}
			root.dispatchEvent(
				new CustomEvent('nvoos-cg-selector:change', {
					detail: { assistantId: select.value ? Number(select.value) : 0 },
					bubbles: true,
				})
			);
		});

		if (start) {
			start.addEventListener('click', function () {
				if (!select.value) {
					return;
				}
				root.dispatchEvent(
					new CustomEvent('nvoos-cg-selector:start', {
						detail: { assistantId: Number(select.value) },
						bubbles: true,
					})
				);
			});
		}
	}

	// ─── Tools grid ────────────────────────────────────────────────

	function bindToolsGrids() {
		const roots = document.querySelectorAll('.nvoos-cg-tools-grid');
		for (let i = 0; i < roots.length; i++) {
			bindToolsGrid(roots[i]);
		}
	}

	function refreshCounts(root) {
		const boxes = root.querySelectorAll('.nvoos-cg-tools-grid__checkbox');
		let total = 0;
		for (let i = 0; i < boxes.length; i++) {
			if (boxes[i].checked) {
				total += 1;
			}
		}

		const count = root.querySelector('.nvoos-cg-tools-grid__selected-count');
		if (count) {
			count.textContent = String(total);
		}

		const groups = root.querySelectorAll('.nvoos-cg-tools-grid__group');
		for (let g = 0; g < groups.length; g++) {
			const groupBoxes = groups[g].querySelectorAll('.nvoos-cg-tools-grid__checkbox');
			let selected = 0;
			for (let b = 0; b < groupBoxes.length; b++) {
				if (groupBoxes[b].checked) {
					selected += 1;
				}
			}
			const groupCount = groups[g].querySelector('.nvoos-cg-tools-grid__group-selected');
			if (groupCount) {
				groupCount.textContent = String(selected);
			}
		}

		emitToolChange(root);
	}

	function emitToolChange(root) {
		const boxes = root.querySelectorAll('.nvoos-cg-tools-grid__checkbox');
		const slugs = [];
		for (let i = 0; i < boxes.length; i++) {
			if (boxes[i].checked) {
				slugs.push(boxes[i].value);
			}
		}
		root.dispatchEvent(
			new CustomEvent('nvoos-cg-tools-grid:change', { detail: { tools: slugs }, bubbles: true })
		);
	}

	function applyFilters(root) {
		const searchInput = root.querySelector('.nvoos-cg-tools-grid__search-input');
		const groupSelect = root.querySelector('.nvoos-cg-tools-grid__group-select');
		const clearButton = root.querySelector('.nvoos-cg-tools-grid__clear-filters');
		const search = searchInput ? searchInput.value.toLowerCase() : '';
		const group = groupSelect ? groupSelect.value : '';
		let visible = 0;

		const items = root.querySelectorAll('.nvoos-cg-tools-grid__item');
		for (let i = 0; i < items.length; i++) {
			const item = items[i];
			const name = item.querySelector('.nvoos-cg-tools-grid__item-name');
			const nameText = name ? name.textContent.toLowerCase() : '';
			const groupEl = closest(item, '.nvoos-cg-tools-grid__group');
			const groupId = groupEl ? groupEl.getAttribute('data-group-id') || '' : '';

			const matchesSearch = !search || nameText.indexOf(search) !== -1;
			const matchesGroup = !group || groupId === group;

			if (matchesSearch && matchesGroup) {
				item.style.display = '';
				visible += 1;
			} else {
				item.style.display = 'none';
			}
		}

		const visibleCount = root.querySelector('.nvoos-cg-tools-grid__visible-count-text');
		if (visibleCount) {
			visibleCount.textContent = String(visible);
		}

		if (clearButton) {
			clearButton.style.display = search || group ? '' : 'none';
		}
	}

	function bindToolsGrid(root) {
		const searchInput = root.querySelector('.nvoos-cg-tools-grid__search-input');
		const groupSelect = root.querySelector('.nvoos-cg-tools-grid__group-select');
		const clearButton = root.querySelector('.nvoos-cg-tools-grid__clear-filters');
		const selectAll = root.querySelector('.nvoos-cg-tools-grid__select-all');
		const deselectAll = root.querySelector('.nvoos-cg-tools-grid__deselect-all');

		const groups = root.querySelectorAll('.nvoos-cg-tools-grid__group');
		const groupOptions = root.querySelectorAll('.nvoos-cg-tools-grid__group-select option');
		const groupOrder = [];
		for (let o = 0; o < groupOptions.length; o++) {
			groupOrder.push(groupOptions[o].value);
		}

		// The server emits data-group-id on each <details>; if missing (older
		// markup), fall back to the filter-option order.
		for (let g2 = 0; g2 < groups.length; g2++) {
			if (!groups[g2].getAttribute('data-group-id')) {
				groups[g2].setAttribute('data-group-id', groupOrder[g2 + 1] || '');
			}
		}

		if (searchInput) {
			searchInput.addEventListener('input', function () {
				applyFilters(root);
			});
		}
		if (groupSelect) {
			groupSelect.addEventListener('change', function () {
				applyFilters(root);
			});
		}
		if (clearButton) {
			clearButton.addEventListener('click', function () {
				if (searchInput) {
					searchInput.value = '';
				}
				if (groupSelect) {
					groupSelect.value = '';
				}
				applyFilters(root);
			});
		}
		if (selectAll) {
			selectAll.addEventListener('click', function () {
				const boxes = root.querySelectorAll('.nvoos-cg-tools-grid__checkbox');
				for (let i = 0; i < boxes.length; i++) {
					boxes[i].checked = true;
				}
				refreshCounts(root);
			});
		}
		if (deselectAll) {
			deselectAll.addEventListener('click', function () {
				const boxes = root.querySelectorAll('.nvoos-cg-tools-grid__checkbox');
				for (let i = 0; i < boxes.length; i++) {
					boxes[i].checked = false;
				}
				refreshCounts(root);
			});
		}

		const boxes = root.querySelectorAll('.nvoos-cg-tools-grid__checkbox');
		for (let i = 0; i < boxes.length; i++) {
			boxes[i].addEventListener('change', function () {
				refreshCounts(root);
			});
		}
	}

	// ─── Knowledge base upload ─────────────────────────────────────

	function bindKnowledgeBases() {
		const roots = document.querySelectorAll('.nvoos-cg-kb');
		for (let i = 0; i < roots.length; i++) {
			bindKnowledgeBase(roots[i]);
		}
	}

	function kbState(root) {
		const input = root.querySelector('.nvoos-cg-kb__file-ids');
		const raw = input && input.value ? input.value.split(',').filter(Boolean) : [];
		return { input: input, ids: raw.map(Number) };
	}

	function refreshKbCounts(root) {
		const state = kbState(root);
		const count = root.querySelector('.nvoos-cg-kb__count');
		if (count) {
			count.textContent = String(state.ids.length);
		}
		const clearAll = root.querySelector('.nvoos-cg-kb__clear-all');
		if (clearAll) {
			clearAll.style.display = state.ids.length ? '' : 'none';
		}
		if (state.input) {
			state.input.value = state.ids.join(',');
		}
		root.dispatchEvent(
			new CustomEvent('nvoos-cg-kb:change', { detail: { fileIds: state.ids }, bubbles: true })
		);
	}

	function addFileRow(root, id, name) {
		const list = root.querySelector('.nvoos-cg-kb__file-list');
		if (!list) {
			return;
		}

		const li = document.createElement('li');
		li.className = 'nvoos-cg-kb__file-item';
		li.setAttribute('data-file-id', String(id));

		const nameSpan = document.createElement('span');
		nameSpan.className = 'nvoos-cg-kb__file-name';
		nameSpan.textContent = name;

		const remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'nvoos-cg-kb__file-remove button-link';
		remove.textContent = 'Remove';
		remove.addEventListener('click', function () {
			const state = kbState(root);
			state.ids = state.ids.filter(function (fileId) {
				return fileId !== id;
			});
			if (state.input) {
				state.input.value = state.ids.join(',');
			}
			li.parentNode.removeChild(li);
			refreshKbCounts(root);
		});

		li.appendChild(nameSpan);
		li.appendChild(remove);
		list.appendChild(li);
	}

	function setProgress(root, active, label) {
		const progress = root.querySelector('.nvoos-cg-kb__progress');
		const text = root.querySelector('.nvoos-cg-kb__progress-text');
		if (progress) {
			progress.style.display = active ? '' : 'none';
		}
		if (text && label) {
			text.textContent = label;
		}
	}

	function validateFile(root, file) {
		const allowed = (root.getAttribute('data-allowed-types') || '').split(',').map(function (t) {
			return t.trim().toLowerCase();
		});
		const maxSize = Number(root.getAttribute('data-max-size')) || 0;
		const maxFiles = Number(root.getAttribute('data-max-files')) || 10;

		const state = kbState(root);
		if (state.ids.length >= maxFiles) {
			return 'Maximum number of files reached.';
		}

		const dot = file.name.lastIndexOf('.');
		const ext = dot === -1 ? '' : file.name.slice(dot).toLowerCase();
		if (allowed.indexOf(ext) === -1) {
			return 'Invalid file type: ' + file.name;
		}

		if (maxSize && file.size > maxSize) {
			return 'File is too large: ' + file.name;
		}

		return null;
	}

	function uploadFiles(root, files) {
		const url = root.getAttribute('data-upload-url') || '';
		const nonce = root.getAttribute('data-nonce') || '';
		const queue = Array.prototype.slice.call(files);
		let index = 0;

		function next() {
			if (index >= queue.length) {
				setProgress(root, false, '');
				refreshKbCounts(root);
				return;
			}

			const file = queue[index];
			index += 1;

			const error = validateFile(root, file);
			if (error) {
				const state = kbState(root);
				if (state.input && state.input.value) {
					// Skip silently? No — surface the error near the dropzone.
					const zone = root.querySelector('.nvoos-cg-kb__dropzone');
					if (zone) {
						zone.setAttribute('title', error);
					}
				}
				next();
				return;
			}

			setProgress(root, true, 'Uploading ' + file.name + '...');

			const body = new FormData();
			body.append('file', file);
			body.append('title', file.name);

			fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': nonce },
				body: body,
			})
				.then(function (response) {
					return response.json().then(function (json) {
						return { ok: response.ok, data: json };
					});
				})
				.then(function (result) {
					if (!result.ok || !result.data || !result.data.id) {
						throw new Error('Upload failed');
					}
					const state = kbState(root);
					state.ids.push(Number(result.data.id));
					if (state.input) {
						state.input.value = state.ids.join(',');
					}
					addFileRow(root, Number(result.data.id), file.name);
					next();
				})
				.catch(function () {
					setProgress(root, false, '');
					const zone = root.querySelector('.nvoos-cg-kb__dropzone');
					if (zone) {
						zone.setAttribute('title', 'Upload failed');
					}
					next();
				});
		}

		next();
	}

	function bindKnowledgeBase(root) {
		const dropzone = root.querySelector('.nvoos-cg-kb__dropzone');
		const fileInput = root.querySelector('.nvoos-cg-kb__file-input');
		const clearAll = root.querySelector('.nvoos-cg-kb__clear-all');

		if (dropzone && fileInput) {
			dropzone.addEventListener('click', function () {
				fileInput.click();
			});
			dropzone.addEventListener('keydown', function (event) {
				if (event.key === 'Enter' || event.key === ' ') {
					event.preventDefault();
					fileInput.click();
				}
			});
			fileInput.addEventListener('change', function () {
				if (fileInput.files && fileInput.files.length) {
					uploadFiles(root, fileInput.files);
					fileInput.value = '';
				}
			});
			dropzone.addEventListener('dragover', function (event) {
				event.preventDefault();
			});
			dropzone.addEventListener('drop', function (event) {
				event.preventDefault();
				if (event.dataTransfer && event.dataTransfer.files.length) {
					uploadFiles(root, event.dataTransfer.files);
				}
			});
		}

		if (clearAll) {
			clearAll.addEventListener('click', function () {
				const rows = root.querySelectorAll('.nvoos-cg-kb__file-item');
				for (let i = 0; i < rows.length; i++) {
					rows[i].parentNode.removeChild(rows[i]);
				}
				const state = kbState(root);
				state.ids = [];
				if (state.input) {
					state.input.value = '';
				}
				refreshKbCounts(root);
			});
		}
	}

	// ─── Assistant builder composite ───────────────────────────────

	function bindBuilders() {
		const roots = document.querySelectorAll('.nvoos-cg-builder');
		for (let i = 0; i < roots.length; i++) {
			bindBuilder(roots[i]);
		}
	}

	function readConfig(root) {
		const node = root.querySelector('.nvoos-cg-builder-config');
		if (!node || !node.textContent) {
			return null;
		}
		try {
			return JSON.parse(node.textContent);
		} catch (e) {
			return null;
		}
	}

	function bindBuilder(root) {
		const config = readConfig(root);
		const sections = (config && config.sections) || { selector: true, tools: true, kb: true, build: true };

		const reveal = function () {
			['tools', 'kb', 'build', 'chat'].forEach(function (name) {
				const el = root.querySelector('.nvoos-cg-builder__' + name);
				if (el) {
					el.style.display = '';
				}
			});
		};

		root.addEventListener('nvoos-cg-selector:change', function (event) {
			if (event.detail && event.detail.assistantId) {
				reveal();
			}
		});

		const buildBtn = root.querySelector('.nvoos-cg-builder__build-btn');
		if (buildBtn && config) {
			buildBtn.addEventListener('click', function () {
				const titleInput = root.querySelector('.nvoos-cg-builder__title');
				const errorBox = root.querySelector('.nvoos-cg-builder__error');
				const title = titleInput ? titleInput.value.trim() : '';

				if (!title) {
					if (errorBox) {
						errorBox.textContent = 'Please enter an assistant title.';
						errorBox.style.display = '';
					}
					return;
				}

				const tools = [];
				const boxes = root.querySelectorAll('.nvoos-cg-tools-grid__checkbox');
				for (let i = 0; i < boxes.length; i++) {
					if (boxes[i].checked) {
						tools.push(boxes[i].value);
					}
				}

				const kbInput = root.querySelector('.nvoos-cg-kb__file-ids');
				const fileIds = kbInput && kbInput.value ? kbInput.value.split(',').filter(Boolean) : [];

				const body = new FormData();
				body.append('action', config.createAction);
				body.append('nonce', config.createNonce);
				body.append('title', title);
				body.append('tools', JSON.stringify(tools));
				body.append('memory_files', JSON.stringify(fileIds));
				body.append('async', '0');

				buildBtn.disabled = true;

				fetch(config.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body,
				})
					.then(function (response) {
						return response.json().then(function (json) {
							return { ok: response.ok, data: json };
						});
					})
					.then(function (result) {
						const data = result.data && result.data.data ? result.data.data : result.data;
						if (result.ok && data && data.success !== false) {
							if (config.redirectUrl) {
								window.location.href = config.redirectUrl;
							}
							return;
						}
						const message =
							(data && data.message) ||
							(data && data.data && data.data.message) ||
							'Build failed. Please try again.';
						if (errorBox) {
							errorBox.textContent = message;
							errorBox.style.display = '';
						}
						buildBtn.disabled = false;
					})
					.catch(function () {
						if (errorBox) {
							errorBox.textContent = 'Build failed. Please try again.';
							errorBox.style.display = '';
						}
						buildBtn.disabled = false;
					});
			});
		}

		if (!sections.selector) {
			reveal();
		}
	}

	// ─── Boot ──────────────────────────────────────────────────────

	function boot() {
		bindSelectors();
		bindToolsGrids();
		bindKnowledgeBases();
		bindBuilders();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
