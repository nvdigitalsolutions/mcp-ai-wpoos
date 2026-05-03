/**
 * Chat Memory Drawer for NV oOS Chat (Phase 3).
 *
 * Self-contained side panel that lets the user view, edit, delete, and
 * scope long-term memories from inside the chat surface. Auto-injects
 * itself into every initialized chat container when the chat-memory
 * bridge (`window.wpMcpAiChatMemory`) reports as available; degrades
 * gracefully (no toggle button rendered) when it isn't.
 *
 * Surface:
 *   - Toggle button injected next to `.wp-mcp-ai-chat__transcript-controls`.
 *   - Side panel (role="dialog", aria-modal="false") with two tabs:
 *       Memories — paginated list of recent records with edit/delete inline.
 *       Scope    — wing/room selector (writes to `state.config.memoryWing/Room`).
 *   - Single page-level ARIA-live toast region (`#wp-mcp-ai-memory-toasts`)
 *     used to announce memory events to assistive tech.
 *
 * Accessibility:
 *   - role="dialog", labelled by an explicit heading, `aria-modal="false"`
 *     (the dialog is non-blocking — chat remains interactive behind it).
 *   - ESC closes the panel and returns focus to the toggle button.
 *   - Focus is moved into the dialog on open and trapped via a tab cycle.
 *   - All strings use `wp.i18n.__()`.
 *   - Respects `prefers-reduced-motion` via CSS.
 *
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

(function(window, document) {
	'use strict';

	const i18n = (window.wp && window.wp.i18n) || {
		__: function(text) { return text; },
		sprintf: function(format) {
			const args = Array.prototype.slice.call(arguments, 1);
			let i = 0;
			return String(format).replace(/%s/g, function() { return args[i++]; });
		}
	};
	const __ = i18n.__;

	const TOAST_REGION_ID = 'wp-mcp-ai-memory-toasts';
	const TOOLS_THAT_RETRIEVE_MEMORY = [
		'recall_memory',
		'wake_up_context',
		'semantic_context_search',
		'retrieve_agent_memory'
	];
	const TOOLS_THAT_STORE_MEMORY = [
		'store_agent_context',
		'update_agent_memory',
		'capture_memory'
	];

	function memoryService() {
		return window.wpMcpAiChatMemory || null;
	}

	function isAvailable() {
		const svc = memoryService();
		return !!(svc && svc.isAvailable && svc.isAvailable());
	}

	/**
	 * Ensure a singleton ARIA-live region exists and return it.
	 *
	 * @return {HTMLElement}
	 */
	function ensureToastRegion() {
		let region = document.getElementById(TOAST_REGION_ID);
		if (region) {
			return region;
		}
		region = document.createElement('div');
		region.id = TOAST_REGION_ID;
		region.className = 'wp-mcp-ai-memory-toasts';
		region.setAttribute('aria-live', 'polite');
		region.setAttribute('aria-atomic', 'true');
		region.setAttribute('role', 'status');
		document.body.appendChild(region);
		return region;
	}

	/**
	 * Announce a transient toast message in the live region.
	 *
	 * @param {string} message
	 * @param {string} variant 'info' | 'success' | 'error'
	 */
	function announceToast(message, variant) {
		if (!message) {
			return;
		}
		const region = ensureToastRegion();
		const toast = document.createElement('div');
		toast.className = 'wp-mcp-ai-memory-toast wp-mcp-ai-memory-toast--' + (variant || 'info');
		toast.setAttribute('data-testid', 'wp-mcp-ai-memory-toast');
		toast.textContent = String(message);
		region.appendChild(toast);
		// Auto-dismiss after 4s; CSS handles the fade.
		window.setTimeout(function() {
			if (toast.parentNode) {
				toast.parentNode.removeChild(toast);
			}
		}, 4000);
	}

	/**
	 * Decorate an assistant message bubble with a "🧠 Memory" badge when
	 * the message's tool calls touched the memory subsystem (G3).
	 *
	 * Idempotent: skips bubbles already decorated.
	 *
	 * @param {HTMLElement} bubble Assistant message DOM node.
	 * @param {Object[]}    toolCalls Array of tool-call descriptors.
	 */
	function decorateMessageWithBadge(bubble, toolCalls) {
		if (!bubble || !Array.isArray(toolCalls) || !toolCalls.length) {
			return;
		}
		if (bubble.querySelector('.wp-mcp-ai-memory-badge')) {
			return;
		}

		let retrieved = false;
		let stored    = false;
		toolCalls.forEach(function(call) {
			const name = call && (call.tool || call.name || (call.function && call.function.name));
			if (!name) {
				return;
			}
			if (TOOLS_THAT_RETRIEVE_MEMORY.indexOf(name) !== -1) {
				retrieved = true;
			}
			if (TOOLS_THAT_STORE_MEMORY.indexOf(name) !== -1) {
				stored = true;
			}
		});
		if (!retrieved && !stored) {
			return;
		}

		const badge = document.createElement('span');
		badge.className = 'wp-mcp-ai-memory-badge';
		badge.setAttribute('data-testid', 'wp-mcp-ai-memory-badge');
		badge.setAttribute('title', __( 'This response used long-term memory.', 'mcp-ai-wpoos' ));
		badge.setAttribute('aria-label', __( 'Memory in use', 'mcp-ai-wpoos' ));
		badge.innerHTML = '<span class="wp-mcp-ai-memory-badge__icon" aria-hidden="true">🧠</span>'
			+ '<span class="wp-mcp-ai-memory-badge__label">' + __( 'Memory', 'mcp-ai-wpoos' ) + '</span>';

		// Prefer attaching alongside the message header / metadata if present;
		// otherwise prepend to the bubble itself.
		const header = bubble.querySelector('.wp-mcp-ai-chat__message-header')
			|| bubble.querySelector('.wp-mcp-ai-chat__message-meta');
		if (header) {
			header.appendChild(badge);
		} else {
			bubble.insertBefore(badge, bubble.firstChild);
		}

		// G8 (user-visible) — fire a single transient toast per bubble so users
		// notice that long-term memory was touched. Idempotent via a data flag
		// so re-decoration on streaming updates never double-announces. The
		// toast rides on `payload.tool_calls` already streamed inline with the
		// assistant message — no SSE plumbing or polling required.
		if (!bubble.getAttribute('data-wp-mcp-ai-memory-toast')) {
			let message;
			if (retrieved && stored) {
				message = __( '🧠 Used and saved long-term memory.', 'mcp-ai-wpoos' );
			} else if (stored) {
				message = __( '🧠 Saved a memory.', 'mcp-ai-wpoos' );
			} else {
				message = __( '🧠 Used long-term memory.', 'mcp-ai-wpoos' );
			}
			bubble.setAttribute('data-wp-mcp-ai-memory-toast', '1');
			announceToast(message, 'info');
		}
	}

	/**
	 * Lightweight focus-trap. Returns a teardown function.
	 *
	 * @param {HTMLElement} root
	 * @return {Function}
	 */
	function trapFocus(root) {
		function focusables() {
			return Array.prototype.slice.call(root.querySelectorAll(
				'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
			));
		}
		function onKey(e) {
			if (e.key !== 'Tab') {
				return;
			}
			const f = focusables();
			if (!f.length) {
				return;
			}
			const first = f[0];
			const last = f[f.length - 1];
			if (e.shiftKey && document.activeElement === first) {
				e.preventDefault();
				last.focus();
			} else if (!e.shiftKey && document.activeElement === last) {
				e.preventDefault();
				first.focus();
			}
		}
		root.addEventListener('keydown', onKey);
		return function teardown() {
			root.removeEventListener('keydown', onKey);
		};
	}

	/**
	 * Build a single memory item element (read-only by default; toggles into
	 * an edit form when the user clicks "Edit").
	 *
	 * @param {Object}   memory   Memory record from recall_memory.
	 * @param {Function} onUpdate Callback after successful edit.
	 * @param {Function} onDelete Callback after successful delete.
	 * @return {HTMLElement}
	 */
	function renderMemoryItem(memory, onUpdate, onDelete) {
		const id = memory.context_id || memory.id || memory.uuid || '';
		const title = memory.title || (memory.context_data && memory.context_data.title) || __( 'Untitled memory', 'mcp-ai-wpoos' );
		const content = memory.content || (memory.context_data && memory.context_data.content) || '';
		const tags = (memory.tags || (memory.context_data && memory.context_data.tags)) || [];
		const tier = memory.tier || memory.memory_tier || '';
		const importance = memory.importance || (memory.context_data && memory.context_data.importance) || '';

		const item = document.createElement('li');
		item.className = 'wp-mcp-ai-memory-item';
		item.setAttribute('data-context-id', id);
		item.setAttribute('data-testid', 'wp-mcp-ai-memory-item');

		const header = document.createElement('div');
		header.className = 'wp-mcp-ai-memory-item__header';

		const titleEl = document.createElement('h4');
		titleEl.className = 'wp-mcp-ai-memory-item__title';
		titleEl.textContent = title;
		header.appendChild(titleEl);

		if (tier || importance) {
			const meta = document.createElement('span');
			meta.className = 'wp-mcp-ai-memory-item__meta';
			meta.textContent = [tier, importance].filter(Boolean).join(' · ');
			header.appendChild(meta);
		}

		item.appendChild(header);

		const body = document.createElement('p');
		body.className = 'wp-mcp-ai-memory-item__content';
		body.textContent = content;
		item.appendChild(body);

		if (Array.isArray(tags) && tags.length) {
			const tagList = document.createElement('div');
			tagList.className = 'wp-mcp-ai-memory-item__tags';
			tags.forEach(function(tag) {
				const chip = document.createElement('span');
				chip.className = 'wp-mcp-ai-memory-item__tag';
				chip.textContent = String(tag);
				tagList.appendChild(chip);
			});
			item.appendChild(tagList);
		}

		const actions = document.createElement('div');
		actions.className = 'wp-mcp-ai-memory-item__actions';

		const editBtn = document.createElement('button');
		editBtn.type = 'button';
		editBtn.className = 'wp-mcp-ai-memory-item__edit';
		editBtn.textContent = __( 'Edit', 'mcp-ai-wpoos' );
		editBtn.setAttribute('data-testid', 'wp-mcp-ai-memory-edit');
		editBtn.addEventListener('click', function() {
			renderEditForm(item, memory, onUpdate);
		});

		const deleteBtn = document.createElement('button');
		deleteBtn.type = 'button';
		deleteBtn.className = 'wp-mcp-ai-memory-item__delete';
		deleteBtn.textContent = __( 'Delete', 'mcp-ai-wpoos' );
		deleteBtn.setAttribute('data-testid', 'wp-mcp-ai-memory-delete');
		deleteBtn.addEventListener('click', function() {
			if (!id) {
				announceToast(__( 'This memory has no ID and cannot be deleted.', 'mcp-ai-wpoos' ), 'error');
				return;
			}
			if (!window.confirm(__( 'Delete this memory? This cannot be undone.', 'mcp-ai-wpoos' ))) {
				return;
			}
			memoryService().remove(id, { agentId: memory.agent_id }).then(function() {
				announceToast(__( 'Memory deleted.', 'mcp-ai-wpoos' ), 'success');
				onDelete(id);
			}).catch(function(err) {
				announceToast(
					(err && err.message) || __( 'Could not delete memory.', 'mcp-ai-wpoos' ),
					'error'
				);
			});
		});

		actions.appendChild(editBtn);
		actions.appendChild(deleteBtn);
		item.appendChild(actions);

		return item;
	}

	/**
	 * Replace a list item's content with an inline edit form.
	 */
	function renderEditForm(item, memory, onUpdate) {
		const id = memory.context_id || memory.id || memory.uuid || '';
		if (!id) {
			announceToast(__( 'This memory has no ID and cannot be edited.', 'mcp-ai-wpoos' ), 'error');
			return;
		}
		const currentTitle = memory.title || (memory.context_data && memory.context_data.title) || '';
		const currentContent = memory.content || (memory.context_data && memory.context_data.content) || '';

		while (item.firstChild) {
			item.removeChild(item.firstChild);
		}

		const form = document.createElement('form');
		form.className = 'wp-mcp-ai-memory-item__edit-form';
		form.setAttribute('data-testid', 'wp-mcp-ai-memory-edit-form');

		const titleLabel = document.createElement('label');
		titleLabel.className = 'wp-mcp-ai-memory-item__edit-label';
		titleLabel.textContent = __( 'Title', 'mcp-ai-wpoos' );
		const titleInput = document.createElement('input');
		titleInput.type = 'text';
		titleInput.className = 'wp-mcp-ai-memory-item__edit-title';
		titleInput.value = currentTitle;
		titleLabel.appendChild(titleInput);

		const contentLabel = document.createElement('label');
		contentLabel.className = 'wp-mcp-ai-memory-item__edit-label';
		contentLabel.textContent = __( 'Content', 'mcp-ai-wpoos' );
		const contentInput = document.createElement('textarea');
		contentInput.className = 'wp-mcp-ai-memory-item__edit-content';
		contentInput.rows = 4;
		contentInput.value = currentContent;
		contentLabel.appendChild(contentInput);

		const buttons = document.createElement('div');
		buttons.className = 'wp-mcp-ai-memory-item__edit-buttons';

		const save = document.createElement('button');
		save.type = 'submit';
		save.className = 'wp-mcp-ai-memory-item__edit-save';
		save.textContent = __( 'Save', 'mcp-ai-wpoos' );

		const cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.className = 'wp-mcp-ai-memory-item__edit-cancel';
		cancel.textContent = __( 'Cancel', 'mcp-ai-wpoos' );
		cancel.addEventListener('click', function() {
			onUpdate(memory, false);
		});

		buttons.appendChild(save);
		buttons.appendChild(cancel);

		form.appendChild(titleLabel);
		form.appendChild(contentLabel);
		form.appendChild(buttons);

		form.addEventListener('submit', function(e) {
			e.preventDefault();
			save.disabled = true;
			memoryService().update(id, {
				agentId: memory.agent_id,
				title: titleInput.value,
				content: contentInput.value
			}).then(function() {
				announceToast(__( 'Memory updated.', 'mcp-ai-wpoos' ), 'success');
				onUpdate(Object.assign({}, memory, {
					title: titleInput.value,
					content: contentInput.value
				}), true);
			}).catch(function(err) {
				save.disabled = false;
				announceToast(
					(err && err.message) || __( 'Could not update memory.', 'mcp-ai-wpoos' ),
					'error'
				);
			});
		});

		item.appendChild(form);
		titleInput.focus();
	}

	/**
	 * Build the drawer DOM, attach to the container, and return a controller.
	 *
	 * @param {HTMLElement} container Chat container element.
	 * @param {Object}      state     Chat widget state object.
	 * @return {{open:Function, close:Function, isOpen:Function, root:HTMLElement}}
	 */
	function buildDrawer(container, state) {
		const config = (state && state.config) || {};
		const agentId = config.embeddedAssistantId || config.assistantId || 0;

		const drawer = document.createElement('aside');
		drawer.className = 'wp-mcp-ai-memory-drawer';
		drawer.setAttribute('role', 'dialog');
		drawer.setAttribute('aria-modal', 'false');
		drawer.setAttribute('aria-hidden', 'true');
		drawer.setAttribute('data-testid', 'wp-mcp-ai-memory-drawer');
		drawer.hidden = true;

		const heading = document.createElement('h3');
		heading.className = 'wp-mcp-ai-memory-drawer__heading';
		heading.id = 'wp-mcp-ai-memory-drawer-heading-' + Math.floor(Math.random() * 1e9);
		heading.textContent = __( 'Long-term memory', 'mcp-ai-wpoos' );
		drawer.setAttribute('aria-labelledby', heading.id);

		const closeBtn = document.createElement('button');
		closeBtn.type = 'button';
		closeBtn.className = 'wp-mcp-ai-memory-drawer__close';
		closeBtn.setAttribute('aria-label', __( 'Close memory drawer', 'mcp-ai-wpoos' ));
		closeBtn.textContent = '×';

		const tabs = document.createElement('div');
		tabs.className = 'wp-mcp-ai-memory-drawer__tabs';
		tabs.setAttribute('role', 'tablist');

		const memoriesTab = document.createElement('button');
		memoriesTab.type = 'button';
		memoriesTab.className = 'wp-mcp-ai-memory-drawer__tab is-active';
		memoriesTab.setAttribute('role', 'tab');
		memoriesTab.setAttribute('aria-selected', 'true');
		memoriesTab.textContent = __( 'Memories', 'mcp-ai-wpoos' );

		const scopeTab = document.createElement('button');
		scopeTab.type = 'button';
		scopeTab.className = 'wp-mcp-ai-memory-drawer__tab';
		scopeTab.setAttribute('role', 'tab');
		scopeTab.setAttribute('aria-selected', 'false');
		scopeTab.textContent = __( 'Scope', 'mcp-ai-wpoos' );

		const auditTab = document.createElement('button');
		auditTab.type = 'button';
		auditTab.className = 'wp-mcp-ai-memory-drawer__tab';
		auditTab.setAttribute('role', 'tab');
		auditTab.setAttribute('aria-selected', 'false');
		auditTab.setAttribute('data-testid', 'wp-mcp-ai-memory-audit-tab');
		auditTab.textContent = __( 'Audit', 'mcp-ai-wpoos' );

		tabs.appendChild(memoriesTab);
		tabs.appendChild(scopeTab);
		tabs.appendChild(auditTab);

		// Memories panel.
		const memoriesPanel = document.createElement('div');
		memoriesPanel.className = 'wp-mcp-ai-memory-drawer__panel';
		memoriesPanel.setAttribute('role', 'tabpanel');

		const filterRow = document.createElement('div');
		filterRow.className = 'wp-mcp-ai-memory-drawer__filter';

		const queryInput = document.createElement('input');
		queryInput.type = 'search';
		queryInput.className = 'wp-mcp-ai-memory-drawer__query';
		queryInput.placeholder = __( 'Filter memories…', 'mcp-ai-wpoos' );
		queryInput.setAttribute('aria-label', __( 'Search memories', 'mcp-ai-wpoos' ));
		queryInput.setAttribute('data-testid', 'wp-mcp-ai-memory-query');
		filterRow.appendChild(queryInput);

		const refreshBtn = document.createElement('button');
		refreshBtn.type = 'button';
		refreshBtn.className = 'wp-mcp-ai-memory-drawer__refresh';
		refreshBtn.textContent = __( 'Refresh', 'mcp-ai-wpoos' );
		filterRow.appendChild(refreshBtn);

		const exportBtn = document.createElement('button');
		exportBtn.type = 'button';
		exportBtn.className = 'wp-mcp-ai-memory-drawer__export';
		exportBtn.setAttribute('data-testid', 'wp-mcp-ai-memory-export');
		exportBtn.textContent = __( 'Export', 'mcp-ai-wpoos' );
		filterRow.appendChild(exportBtn);

		const list = document.createElement('ul');
		list.className = 'wp-mcp-ai-memory-drawer__list';
		list.setAttribute('data-testid', 'wp-mcp-ai-memory-list');

		const emptyState = document.createElement('p');
		emptyState.className = 'wp-mcp-ai-memory-drawer__empty';
		emptyState.hidden = true;
		emptyState.textContent = __( 'No memories yet.', 'mcp-ai-wpoos' );

		const errorState = document.createElement('p');
		errorState.className = 'wp-mcp-ai-memory-drawer__error';
		errorState.setAttribute('role', 'alert');
		errorState.hidden = true;

		memoriesPanel.appendChild(filterRow);
		memoriesPanel.appendChild(emptyState);
		memoriesPanel.appendChild(errorState);
		memoriesPanel.appendChild(list);

		// Scope panel.
		const scopePanel = document.createElement('div');
		scopePanel.className = 'wp-mcp-ai-memory-drawer__panel';
		scopePanel.setAttribute('role', 'tabpanel');
		scopePanel.hidden = true;

		const scopeForm = document.createElement('form');
		scopeForm.className = 'wp-mcp-ai-memory-drawer__scope-form';
		scopeForm.setAttribute('data-testid', 'wp-mcp-ai-memory-scope-form');

		const wingLabel = document.createElement('label');
		wingLabel.textContent = __( 'Wing (project / matter)', 'mcp-ai-wpoos' );
		const wingInput = document.createElement('input');
		wingInput.type = 'text';
		wingInput.className = 'wp-mcp-ai-memory-drawer__wing';
		wingInput.value = config.memoryWing || '';
		wingLabel.appendChild(wingInput);

		const roomLabel = document.createElement('label');
		roomLabel.textContent = __( 'Room (topic)', 'mcp-ai-wpoos' );
		const roomInput = document.createElement('input');
		roomInput.type = 'text';
		roomInput.className = 'wp-mcp-ai-memory-drawer__room';
		roomInput.value = config.memoryRoom || '';
		roomLabel.appendChild(roomInput);

		const scopeSaveBtn = document.createElement('button');
		scopeSaveBtn.type = 'submit';
		scopeSaveBtn.textContent = __( 'Apply scope', 'mcp-ai-wpoos' );

		scopeForm.appendChild(wingLabel);
		scopeForm.appendChild(roomLabel);
		scopeForm.appendChild(scopeSaveBtn);

		scopeForm.addEventListener('submit', function(e) {
			e.preventDefault();
			config.memoryWing = wingInput.value || '';
			config.memoryRoom = roomInput.value || '';
			announceToast(
				config.memoryWing
					? i18n.sprintf(__( 'Scope set to wing "%s".', 'mcp-ai-wpoos' ), config.memoryWing)
					: __( 'Scope cleared.', 'mcp-ai-wpoos' ),
				'success'
			);
			loadMemories(); // refresh list under new scope
		});

		scopePanel.appendChild(scopeForm);

		// Audit panel — lazy-loaded from /chat-memory/audit on first activation.
		const auditPanel = document.createElement('div');
		auditPanel.className = 'wp-mcp-ai-memory-drawer__panel';
		auditPanel.setAttribute('role', 'tabpanel');
		auditPanel.setAttribute('data-testid', 'wp-mcp-ai-memory-audit-panel');
		auditPanel.hidden = true;

		const auditFilterRow = document.createElement('div');
		auditFilterRow.className = 'wp-mcp-ai-memory-drawer__filter';

		const auditActionFilter = document.createElement('select');
		auditActionFilter.className = 'wp-mcp-ai-memory-drawer__audit-filter';
		auditActionFilter.setAttribute('aria-label', __( 'Filter audit log by action type', 'mcp-ai-wpoos' ));
		auditActionFilter.setAttribute('data-testid', 'wp-mcp-ai-memory-audit-filter');
		const auditFilterOptions = [
			{ value: '', label: __( 'All actions', 'mcp-ai-wpoos' ) },
			{ value: 'create', label: __( 'Created', 'mcp-ai-wpoos' ) },
			{ value: 'update', label: __( 'Updated', 'mcp-ai-wpoos' ) },
			{ value: 'delete', label: __( 'Deleted', 'mcp-ai-wpoos' ) },
			{ value: 'access', label: __( 'Accessed', 'mcp-ai-wpoos' ) },
		];
		auditFilterOptions.forEach(function(opt) {
			const o = document.createElement('option');
			o.value = opt.value;
			o.textContent = opt.label;
			auditActionFilter.appendChild(o);
		});
		auditFilterRow.appendChild(auditActionFilter);

		const auditRefreshBtn = document.createElement('button');
		auditRefreshBtn.type = 'button';
		auditRefreshBtn.className = 'wp-mcp-ai-memory-drawer__refresh';
		auditRefreshBtn.textContent = __( 'Refresh', 'mcp-ai-wpoos' );
		auditFilterRow.appendChild(auditRefreshBtn);

		const auditList = document.createElement('ul');
		auditList.className = 'wp-mcp-ai-memory-drawer__audit-list';
		auditList.setAttribute('data-testid', 'wp-mcp-ai-memory-audit-list');

		const auditEmpty = document.createElement('p');
		auditEmpty.className = 'wp-mcp-ai-memory-drawer__empty';
		auditEmpty.hidden = true;
		auditEmpty.textContent = __( 'No audit entries yet.', 'mcp-ai-wpoos' );

		const auditError = document.createElement('p');
		auditError.className = 'wp-mcp-ai-memory-drawer__error';
		auditError.setAttribute('role', 'alert');
		auditError.hidden = true;

		auditPanel.appendChild(auditFilterRow);
		auditPanel.appendChild(auditEmpty);
		auditPanel.appendChild(auditError);
		auditPanel.appendChild(auditList);

		drawer.appendChild(closeBtn);
		drawer.appendChild(heading);
		drawer.appendChild(tabs);
		drawer.appendChild(memoriesPanel);
		drawer.appendChild(scopePanel);
		drawer.appendChild(auditPanel);

		container.appendChild(drawer);

		let auditLoaded = false;

		function setTab(name) {
			const isMemories = name === 'memories';
			const isScope = name === 'scope';
			const isAudit = name === 'audit';
			memoriesTab.classList.toggle('is-active', isMemories);
			memoriesTab.setAttribute('aria-selected', isMemories ? 'true' : 'false');
			scopeTab.classList.toggle('is-active', isScope);
			scopeTab.setAttribute('aria-selected', isScope ? 'true' : 'false');
			auditTab.classList.toggle('is-active', isAudit);
			auditTab.setAttribute('aria-selected', isAudit ? 'true' : 'false');
			memoriesPanel.hidden = !isMemories;
			scopePanel.hidden = !isScope;
			auditPanel.hidden = !isAudit;
			if (isAudit && !auditLoaded) {
				auditLoaded = true;
				loadAudit();
			}
		}

		memoriesTab.addEventListener('click', function() { setTab('memories'); });
		scopeTab.addEventListener('click', function() { setTab('scope'); });
		auditTab.addEventListener('click', function() { setTab('audit'); });

		function clearList() {
			while (list.firstChild) {
				list.removeChild(list.firstChild);
			}
		}

		function showError(message) {
			errorState.textContent = message;
			errorState.hidden = false;
		}

		function loadMemories() {
			if (!isAvailable()) {
				return;
			}
			errorState.hidden = true;
			emptyState.hidden = true;
			clearList();

			const loading = document.createElement('li');
			loading.className = 'wp-mcp-ai-memory-drawer__loading';
			loading.textContent = __( 'Loading memories…', 'mcp-ai-wpoos' );
			list.appendChild(loading);

			const filters = {
				agentId: agentId,
				wing: config.memoryWing || '',
				room: config.memoryRoom || '',
				limit: 25
			};

			memoryService().recall(queryInput.value || '', filters).then(function(response) {
				clearList();
				const records = extractRecords(response);
				if (!records.length) {
					emptyState.hidden = false;
					return;
				}
				records.forEach(function(record) {
					attachItem(record);
				});
			}).catch(function(err) {
				clearList();
				showError((err && err.message) || __( 'Could not load memories.', 'mcp-ai-wpoos' ));
			});
		}

		function removeItemById(id) {
			// Filter children rather than building a selector to avoid any
			// selector-string concatenation. CSS.escape would also be safe,
			// but iterating is simpler and survives any future schema change.
			const children = Array.prototype.slice.call(list.children);
			for (let i = 0; i < children.length; i++) {
				if (children[i].getAttribute('data-context-id') === String(id)) {
					list.removeChild(children[i]);
					break;
				}
			}
			if (!list.children.length) {
				emptyState.hidden = false;
			}
		}

		/**
		 * Render and append (or replace) a memory list item with handlers
		 * that re-render itself in place after edit/cancel.
		 */
		function attachItem(record, replaceNode) {
			let item;
			const onUpdate = function(updated, applied) {
				const next = applied ? updated : record;
				const replacement = renderMemoryItem(next, function(u, a) {
					// Recurse on the replacement node.
					attachItem(a ? u : next, replacement);
				}, function(deletedId) {
					removeItemById(deletedId);
				});
				if (item && item.parentNode) {
					item.parentNode.replaceChild(replacement, item);
				}
				item = replacement;
				record = next;
			};
			const onDelete = function(deletedId) {
				removeItemById(deletedId);
			};
			item = renderMemoryItem(record, onUpdate, onDelete);
			if (replaceNode && replaceNode.parentNode) {
				replaceNode.parentNode.replaceChild(item, replaceNode);
			} else {
				list.appendChild(item);
			}
		}

		function extractRecords(response) {
			if (!response || typeof response !== 'object') {
				return [];
			}
			if (Array.isArray(response.contexts)) { return response.contexts; }
			if (Array.isArray(response.results))  { return response.results; }
			if (Array.isArray(response.memories)) { return response.memories; }
			if (response.data && Array.isArray(response.data.contexts)) { return response.data.contexts; }
			if (response.data && Array.isArray(response.data.results))  { return response.data.results; }
			if (response.data && Array.isArray(response.data.memories)) { return response.data.memories; }
			return [];
		}

		/**
		 * Pull `entries` out of the audit response. The proxy returns the
		 * `memory_audit_trail` tool's payload either at the top level (success
		 * shape) or nested under `data` (REST envelope).
		 */
		function extractAuditEntries(response) {
			if (!response || typeof response !== 'object') {
				return [];
			}
			if (Array.isArray(response.entries)) { return response.entries; }
			if (response.data && Array.isArray(response.data.entries)) { return response.data.entries; }
			return [];
		}

		function clearAuditList() {
			while (auditList.firstChild) {
				auditList.removeChild(auditList.firstChild);
			}
		}

		function renderAuditEntry(entry) {
			const li = document.createElement('li');
			li.className = 'wp-mcp-ai-memory-drawer__audit-item';

			const action = String((entry && entry.action) || '').toLowerCase();
			if (action) {
				li.setAttribute('data-action', action);
			}

			const timestamp = document.createElement('time');
			timestamp.className = 'wp-mcp-ai-memory-drawer__audit-time';
			const ts = entry && entry.timestamp ? String(entry.timestamp) : '';
			if (ts) {
				timestamp.setAttribute('datetime', ts);
				timestamp.textContent = ts;
			} else {
				timestamp.textContent = __( '(no timestamp)', 'mcp-ai-wpoos' );
			}

			const actionLabel = document.createElement('span');
			actionLabel.className = 'wp-mcp-ai-memory-drawer__audit-action';
			actionLabel.textContent = action || __( 'unknown', 'mcp-ai-wpoos' );

			const meta = document.createElement('span');
			meta.className = 'wp-mcp-ai-memory-drawer__audit-meta';
			const contextId = entry && entry.context_id ? String(entry.context_id) : '';
			if (contextId) {
				meta.textContent = contextId;
			}

			li.appendChild(timestamp);
			li.appendChild(document.createTextNode(' '));
			li.appendChild(actionLabel);
			if (contextId) {
				li.appendChild(document.createTextNode(' — '));
				li.appendChild(meta);
			}

			return li;
		}

		function loadAudit() {
			if (!isAvailable()) {
				return;
			}
			auditError.hidden = true;
			auditEmpty.hidden = true;
			clearAuditList();

			const loading = document.createElement('li');
			loading.className = 'wp-mcp-ai-memory-drawer__loading';
			loading.textContent = __( 'Loading audit log…', 'mcp-ai-wpoos' );
			auditList.appendChild(loading);

			const opts = {
				agentId: agentId,
				limit: 50
			};
			if (auditActionFilter.value) {
				opts.actionType = auditActionFilter.value;
			}

			memoryService().audit(opts).then(function(response) {
				clearAuditList();
				const entries = extractAuditEntries(response);
				if (!entries.length) {
					auditEmpty.hidden = false;
					return;
				}
				entries.forEach(function(entry) {
					auditList.appendChild(renderAuditEntry(entry));
				});
			}).catch(function(err) {
				clearAuditList();
				auditError.textContent = (err && err.message) || __( 'Could not load audit log.', 'mcp-ai-wpoos' );
				auditError.hidden = false;
			});
		}

		auditRefreshBtn.addEventListener('click', loadAudit);
		auditActionFilter.addEventListener('change', loadAudit);

		// Debounced filter.
		let filterTimer = null;
		queryInput.addEventListener('input', function() {
			window.clearTimeout(filterTimer);
			filterTimer = window.setTimeout(loadMemories, 250);
		});
		refreshBtn.addEventListener('click', loadMemories);

		/**
		 * Drawer-driven export (G11).
		 *
		 * Calls memoryService.recall() with the active scope (wing/room/query)
		 * and a high limit so users can take a snapshot of the slice they're
		 * currently looking at. The result is wrapped in a small envelope with
		 * an exported_at timestamp + scope, serialised to JSON, and offered as
		 * a download via a single-shot anchor click. The button is disabled
		 * while the request is in flight to prevent duplicate downloads.
		 *
		 * No new REST route is needed — the recall endpoint already enforces
		 * permission, the kill-switch and the per-user toggle.
		 */
		let exportInFlight = false;
		exportBtn.addEventListener('click', function() {
			if (exportInFlight) {
				return;
			}
			if (!isAvailable()) {
				announceToast(__( 'Memory is not available right now.', 'mcp-ai-wpoos' ), 'error');
				return;
			}
			exportInFlight = true;
			exportBtn.disabled = true;

			const filters = {
				agentId: agentId,
				wing: config.memoryWing || '',
				room: config.memoryRoom || '',
				limit: 200
			};

			memoryService().recall(queryInput.value || '', filters).then(function(response) {
				const records = extractRecords(response);
				const payload = {
					exported_at: new Date().toISOString(),
					agent_id: agentId,
					scope: {
						wing: filters.wing || null,
						room: filters.room || null,
						query: queryInput.value || ''
					},
					count: records.length,
					memories: records
				};
				triggerDownload(payload);
				announceToast(
					i18n.sprintf(
						/* translators: %d: number of memories exported */
						__( 'Exported %d memor(y/ies).', 'mcp-ai-wpoos' ),
						records.length
					),
					'success'
				);
			}).catch(function(err) {
				announceToast(
					(err && err.message) || __( 'Could not export memories.', 'mcp-ai-wpoos' ),
					'error'
				);
			}).then(function() {
				exportInFlight = false;
				exportBtn.disabled = false;
			});
		});

		/**
		 * Trigger a one-shot JSON download for the supplied payload.
		 *
		 * Uses URL.createObjectURL + a synthetic anchor click + revokeObjectURL
		 * so we don't leave the URL alive in the document. Filename embeds the
		 * agent_id and a compact ISO timestamp.
		 *
		 * @param {Object} payload
		 */
		function triggerDownload(payload) {
			const json = JSON.stringify(payload, null, 2);
			const blob = new Blob([ json ], { type: 'application/json' });
			const url = URL.createObjectURL(blob);
			const safeAgent = String(agentId || 'unknown').replace(/[^A-Za-z0-9_-]/g, '_');
			const stamp = new Date().toISOString().replace(/[:.]/g, '-');
			const filename = 'mcp-ai-memory-' + safeAgent + '-' + stamp + '.json';
			const a = document.createElement('a');
			a.href = url;
			a.download = filename;
			a.style.display = 'none';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			window.setTimeout(function() {
				URL.revokeObjectURL(url);
			}, 0);
		}

		let opened = false;
		let releaseTrap = null;
		let lastFocus = null;

		function open(returnTarget) {
			if (opened) { return; }
			drawer.hidden = false;
			drawer.setAttribute('aria-hidden', 'false');
			drawer.classList.add('is-open');
			opened = true;
			lastFocus = returnTarget || document.activeElement;
			loadMemories();
			releaseTrap = trapFocus(drawer);
			window.setTimeout(function() {
				memoriesTab.focus();
			}, 0);
		}

		function close() {
			if (!opened) { return; }
			drawer.classList.remove('is-open');
			drawer.setAttribute('aria-hidden', 'true');
			drawer.hidden = true;
			opened = false;
			if (releaseTrap) { releaseTrap(); releaseTrap = null; }
			if (lastFocus && typeof lastFocus.focus === 'function') {
				lastFocus.focus();
			}
		}

		closeBtn.addEventListener('click', close);
		drawer.addEventListener('keydown', function(e) {
			if (e.key === 'Escape') {
				close();
			}
		});

		return {
			open: open,
			close: close,
			isOpen: function() { return opened; },
			root: drawer,
			refresh: loadMemories
		};
	}

	/**
	 * Inject the toggle button into the container's transcript controls.
	 *
	 * @param {HTMLElement} container
	 * @param {Object}      controller Drawer controller from buildDrawer.
	 */
	function injectToggle(container, controller) {
		const controls = container.querySelector('.wp-mcp-ai-chat__transcript-controls');
		if (!controls) {
			return;
		}
		if (controls.querySelector('.wp-mcp-ai-memory-toggle')) {
			return;
		}
		const toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'wp-mcp-ai-memory-toggle';
		toggle.setAttribute('aria-haspopup', 'dialog');
		toggle.setAttribute('aria-expanded', 'false');
		toggle.setAttribute('aria-label', __( 'Open long-term memory drawer', 'mcp-ai-wpoos' ));
		toggle.setAttribute('data-testid', 'wp-mcp-ai-memory-toggle');
		toggle.innerHTML = '<span aria-hidden="true">🧠</span><span class="screen-reader-text">'
			+ __( 'Memory', 'mcp-ai-wpoos' ) + '</span>';
		toggle.addEventListener('click', function() {
			if (controller.isOpen()) {
				controller.close();
				toggle.setAttribute('aria-expanded', 'false');
			} else {
				controller.open(toggle);
				toggle.setAttribute('aria-expanded', 'true');
			}
		});
		controls.appendChild(toggle);
	}

	/**
	 * Initialize the drawer for one chat container. Idempotent.
	 *
	 * @param {HTMLElement} container
	 */
	function attach(container) {
		if (!container || container.__wpMcpAiMemoryDrawer) {
			return;
		}
		if (!isAvailable()) {
			return;
		}
		const state = container.__wpMcpAiChatState;
		if (!state) {
			return;
		}
		const controller = buildDrawer(container, state);
		container.__wpMcpAiMemoryDrawer = controller;
		injectToggle(container, controller);
		registerAutoSummary(container, state);
	}

	/**
	 * Wire end-of-conversation transcript auto-capture (G6 Phase 1).
	 *
	 * Listens for `pagehide` (and `visibilitychange→hidden` as a fallback for
	 * Safari/iOS) and, when the per-user `autosummarize` preference is on,
	 * fires a single `storeBeacon()` carrying the localStorage transcript
	 * as a `transcript_summary` memory. One-shot per page session via
	 * sessionStorage so reload/back-forward navigation never double-captures.
	 *
	 * Preferences are pre-fetched once at attach time so the unload-time
	 * handler can decide synchronously — `pagehide` cannot await network calls.
	 *
	 * @param {HTMLElement} container Chat container.
	 * @param {Object}      state     Chat state (provides config + assistantId).
	 */
	function registerAutoSummary(container, state) {
		const config = (state && state.config) || {};
		const agentId = config.embeddedAssistantId || config.assistantId || 0;
		if (!agentId) {
			return;
		}
		const flagKey = 'wp-mcp-ai-memory-autosummary:' + agentId;
		let cachedPrefs = null;

		// Pre-fetch prefs so the unload handler can decide synchronously.
		try {
			memoryService().getPreferences().then(function(prefs) {
				cachedPrefs = prefs || null;
			}).catch(function() {
				cachedPrefs = null;
			});
		} catch (_e) {
			cachedPrefs = null;
		}

		function fireOnce() {
			// One-shot per tab session.
			try {
				if (window.sessionStorage && window.sessionStorage.getItem(flagKey)) {
					return;
				}
			} catch (_e) { /* sessionStorage may be unavailable */ }
			if (!cachedPrefs || !cachedPrefs.enabled || !cachedPrefs.autosummarize) {
				return;
			}
			const transcript = readTranscript(agentId);
			if (!transcript || !transcript.text) {
				return;
			}
			try {
				if (window.sessionStorage) {
					window.sessionStorage.setItem(flagKey, '1');
				}
			} catch (_e) { /* ignore */ }

			const payload = {
				agentId: agentId,
				wing: config.memoryWing || '',
				room: config.memoryRoom || '',
				title: i18n.sprintf(
					/* translators: %s: ISO date of the captured conversation */
					__( 'Conversation summary — %s', 'mcp-ai-wpoos' ),
					new Date().toISOString().slice(0, 10)
				),
				content: transcript.text,
				tags: [ 'transcript-summary', 'autosummary' ],
				contextType: 'transcript_summary',
				importance: 'medium',
				verbatim: true
			};
			try {
				memoryService().storeBeacon(payload).catch(function() { /* fire-and-forget */ });
			} catch (_e) { /* ignore */ }
		}

		// `pagehide` is the canonical browser-leave event and survives bfcache.
		window.addEventListener('pagehide', fireOnce);
		// Safari/iOS occasionally fires only `visibilitychange→hidden`.
		document.addEventListener('visibilitychange', function() {
			if (document.visibilityState === 'hidden') {
				fireOnce();
			}
		});
	}

	/**
	 * Read the transcript from chat-storage-service.js and produce a compact,
	 * truncated text dump suitable for storage as a single memory record.
	 *
	 * Truncates from the *front* (keeping the most recent turns) at 4 KB so
	 * very long sessions still produce a useful summary.
	 *
	 * @param {string|number} agentId
	 * @return {{text:string, count:number}|null}
	 */
	function readTranscript(agentId) {
		const storage = window.wpMcpAiChatStorage;
		if (!storage || typeof storage.loadConversationFromStorage !== 'function') {
			return null;
		}
		let conv;
		try {
			conv = storage.loadConversationFromStorage(agentId);
		} catch (_e) {
			return null;
		}
		const turns = ( conv && Array.isArray(conv.conversation) ) ? conv.conversation : [];
		if (turns.length < 2) {
			return null;
		}
		const lines = [];
		for (let i = 0; i < turns.length; i++) {
			const t = turns[i] || {};
			if (t.role !== 'user' && t.role !== 'assistant') {
				continue;
			}
			const content = typeof t.content === 'string' ? t.content : '';
			if (!content.trim()) {
				continue;
			}
			lines.push((t.role === 'user' ? 'User: ' : 'Assistant: ') + content);
		}
		if (lines.length === 0) {
			return null;
		}
		let text = lines.join('\n\n');
		const MAX_BYTES = 4096;
		// Encode-aware truncation: chop from the front (keep most recent).
		if (text.length > MAX_BYTES) {
			text = '…\n\n' + text.slice(text.length - MAX_BYTES);
		}
		return { text: text, count: lines.length };
	}

	/**
	 * Scan the document for initialised chat containers and attach the drawer.
	 */
	function attachAll() {
		const containers = document.querySelectorAll('[data-wp-mcp-ai-chat][data-wp-mcp-ai-initialized="true"]');
		for (let i = 0; i < containers.length; i++) {
			attach(containers[i]);
		}
	}

	// Public surface.
	window.wpMcpAiChatMemoryDrawer = {
		attach: attach,
		attachAll: attachAll,
		decorateMessageWithBadge: decorateMessageWithBadge,
		announceToast: announceToast,
		ensureToastRegion: ensureToastRegion,
		isAvailable: isAvailable,
		registerAutoSummary: registerAutoSummary,
		readTranscript: readTranscript
	};

	// Auto-attach on DOMContentLoaded and on a short interval thereafter to
	// catch chat containers that initialise asynchronously.
	function bootstrap() {
		ensureToastRegion();
		attachAll();
		// Observe future chat containers.
		const observer = new window.MutationObserver(function(mutations) {
			for (let i = 0; i < mutations.length; i++) {
				const m = mutations[i];
				if (m.type === 'attributes' && m.target && m.target.getAttribute && m.target.getAttribute('data-wp-mcp-ai-initialized') === 'true') {
					attach(m.target);
				}
			}
		});
		observer.observe(document.body, {
			subtree: true,
			attributes: true,
			attributeFilter: ['data-wp-mcp-ai-initialized']
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bootstrap);
	} else {
		// Already past DOMContentLoaded — schedule on next tick.
		window.setTimeout(bootstrap, 0);
	}
})(window, document);
