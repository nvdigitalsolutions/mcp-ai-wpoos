/**
 * Configure the memory drawer.
 *
 * @param {Object} options
 * @param {Object} options.memoryClient - nvoos-chat-memory client instance (required)
 * @param {Object} [options.i18n] - i18n functions ({ __, sprintf })
 * @param {string} [options.cssPrefix] - CSS class prefix (default 'wp-mcp-ai')
 */
function configure(options) {
  if (!options) return;
  if (options.memoryClient) _memoryClient = options.memoryClient;
  if (options.i18n) _i18nConfig = options.i18n;
  if (typeof options.cssPrefix === 'string') CSS_PREFIX = options.cssPrefix;
}
var CSS_PREFIX = 'wp-mcp-ai';

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
 *   - Side panel (role="dialog", aria-modal="false") with tabs:
 *       Memories       — paginated list of recent records with edit/delete inline.
 *       Scope          — wing/room selector (writes to `state.config.memoryWing/Room`).
 *       Audit          — recent memory-audit events.
 *       Session Replay — replay feed for a chat session_id.
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

var _i18nConfig = null;
function __(text) {
  if (_i18nConfig && typeof _i18nConfig.__ === 'function') return _i18nConfig.__(text);
  return text;
}
function _sprintf(format) {
  var args = Array.prototype.slice.call(arguments, 1);
  if (_i18nConfig && typeof _i18nConfig.sprintf === 'function') return _i18nConfig.sprintf.apply(null, [format].concat(args));
  var i = 0;
  return String(format).replace(/%s/g, function() { return args[i++]; });
}
	

	const TOAST_REGION_ID = CSS_PREFIX + '-memory-toasts';
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
	const MIN_WATERFALL_BAR_WIDTH_PERCENT = 6;

	// G8 Phase 2 — counter of mid-stream `memory_event` SSE toasts that have
	// already fired for the in-flight assistant turn. The end-of-stream
	// `decorateMessageWithBadge` call drains this counter to skip its own
	// toast (the badge is still drawn) so the same memory op never
	// double-announces. Drained on each call; never grows unbounded.
	let pendingSseToasts = 0;

	/**
	 * Drawers attached to live chat containers, tracked so a server-side
	 * `memory_event` store frame can refresh open drawers in place (fix #4).
	 *
	 * @type {Array<{container: HTMLElement, controller: Object}>}
	 */
	const activeDrawers = [];

	/**
	 * Refresh every open drawer after a memory store event.
	 */
	function refreshOpenDrawers() {
		activeDrawers.forEach(function(entry) {
			try {
				if (entry.controller && entry.controller.isOpen && entry.controller.isOpen()) {
					entry.controller.refresh();
				}
			} catch (e) {
				// Never let a refresh failure break the SSE handler.
			}
		});
	}

	/**
	 * Build the toast copy for a memory op.
	 *
	 * @param {boolean} retrieved
	 * @param {boolean} stored
	 * @return {string}
	 */
	function memoryToastCopy(retrieved, stored) {
		if (retrieved && stored) {
			return __( '🧠 Used and saved long-term memory.' );
		}
		if (stored) {
			return __( '🧠 Saved a memory.' );
		}
		return __( '🧠 Used long-term memory.' );
	}

	/**
	 * Handle a server-side `memory_event` SSE frame (G8 Phase 2).
	 *
	 * Fires a transient toast immediately and bumps the pending-toast
	 * counter so the end-of-stream decorator can suppress its own toast
	 * for the same turn.
	 *
	 * @param {{action:string, tool_name?:string}} payload
	 */
	function handleSseMemoryEvent(payload) {
		if (!payload || typeof payload.action !== 'string') {
			return;
		}
		const retrieved = payload.action === 'retrieved';
		const stored    = payload.action === 'stored';
		if (!retrieved && !stored) {
			return;
		}
		pendingSseToasts++;
		announceToast(memoryToastCopy(retrieved, stored), 'info');

		// Fix #4 — a memory was stored server-side: refresh any open drawer
		// so the new record appears without a manual reload.
		if (stored) {
			refreshOpenDrawers();
		}
	}

	var _memoryClient = null;
function memoryService() { return _memoryClient; }

	function isAvailable() {
		const svc = memoryService();
		return !!(svc && svc.isAvailable && svc.isAvailable());
	}

	function resolveReplaySessionId(config, agentId) {
		const cfg = config || {};
		if (cfg.sessionKey && typeof cfg.sessionKey === 'string' && cfg.sessionKey.trim()) {
			return cfg.sessionKey.trim();
		}
		const assistantId = cfg.assistantId || agentId || 'default';
		const key = 'wp_mcp_ai_chat_session_id_' + assistantId;
		try {
			const stored = window.localStorage ? window.localStorage.getItem(key) : '';
			if (stored && /^[a-zA-Z0-9_-]{1,64}$/.test(stored)) {
				return stored;
			}
		} catch (e) {
			return '';
		}
		return '';
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
		region.className = CSS_PREFIX + '-memory-toasts';
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
		toast.className = CSS_PREFIX + '-memory-toast wp-mcp-ai-memory-toast--' + (variant || 'info');
		toast.setAttribute('data-testid', CSS_PREFIX + '-memory-toast');
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
		badge.className = CSS_PREFIX + '-memory-badge';
		badge.setAttribute('data-testid', CSS_PREFIX + '-memory-badge');
		badge.setAttribute('title', __( 'This response used long-term memory.' ));
		badge.setAttribute('aria-label', __( 'Memory in use' ));
		badge.innerHTML = '<span class="wp-mcp-ai-memory-badge__icon" aria-hidden="true">🧠</span>'
			+ '<span class="wp-mcp-ai-memory-badge__label">' + __( 'Memory' ) + '</span>';

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
		//
		// G8 Phase 2 — when a server-side `memory_event` SSE frame already
		// announced a toast for this turn, drain the pending counter and skip
		// the bubble's own toast (still draw the badge).
		if (!bubble.getAttribute('data-wp-mcp-ai-memory-toast')) {
			bubble.setAttribute('data-wp-mcp-ai-memory-toast', '1');
			if (pendingSseToasts > 0) {
				pendingSseToasts--;
			} else {
				announceToast(memoryToastCopy(retrieved, stored), 'info');
			}
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
		const title = memory.title || (memory.context_data && memory.context_data.title) || __( 'Untitled memory' );
		const content = memory.content || (memory.context_data && memory.context_data.content) || '';
		const tags = (memory.tags || (memory.context_data && memory.context_data.tags)) || [];
		const tier = memory.tier || memory.memory_tier || '';
		const importance = memory.importance || (memory.context_data && memory.context_data.importance) || '';

		const item = document.createElement('li');
		item.className = CSS_PREFIX + '-memory-item';
		item.setAttribute('data-context-id', id);
		item.setAttribute('data-testid', CSS_PREFIX + '-memory-item');

		const header = document.createElement('div');
		header.className = CSS_PREFIX + '-memory-item__header';

		const titleEl = document.createElement('h4');
		titleEl.className = CSS_PREFIX + '-memory-item__title';
		titleEl.textContent = title;
		header.appendChild(titleEl);

		if (tier || importance) {
			const meta = document.createElement('span');
			meta.className = CSS_PREFIX + '-memory-item__meta';
			meta.textContent = [tier, importance].filter(Boolean).join(' · ');
			header.appendChild(meta);
		}

		item.appendChild(header);

		// Wing/room scope — surfaced on every item so memories stored without
		// a scope remain identifiable in the list (explicit "Unscoped" chip)
		// instead of silently blending in with scoped records.
		const wing = memory.wing || (memory.context_data && memory.context_data.wing) || '';
		const room = memory.room || (memory.context_data && memory.context_data.room) || '';
		const scopeList = document.createElement('div');
		scopeList.className = CSS_PREFIX + '-memory-item__scope';
		if (wing || room) {
			if (wing) {
				const wingChip = document.createElement('span');
				wingChip.className = CSS_PREFIX + '-memory-item__scope-chip wp-mcp-ai-memory-item__scope-chip--wing';
				wingChip.setAttribute('data-testid', CSS_PREFIX + '-memory-wing-chip');
				wingChip.textContent = wing;
				scopeList.appendChild(wingChip);
			}
			if (room) {
				const roomChip = document.createElement('span');
				roomChip.className = CSS_PREFIX + '-memory-item__scope-chip wp-mcp-ai-memory-item__scope-chip--room';
				roomChip.setAttribute('data-testid', CSS_PREFIX + '-memory-room-chip');
				roomChip.textContent = room;
				scopeList.appendChild(roomChip);
			}
		} else {
			const unscopedChip = document.createElement('span');
			unscopedChip.className = CSS_PREFIX + '-memory-item__scope-chip wp-mcp-ai-memory-item__scope-chip--unscoped';
			unscopedChip.setAttribute('data-testid', CSS_PREFIX + '-memory-unscoped-chip');
			unscopedChip.textContent = __( 'Unscoped' );
			scopeList.appendChild(unscopedChip);
		}
		// Fix #3 — records merged from a virtual agent bucket are tagged so
		// the user can see they were stored under a different agent key.
		if (memory.stored_under) {
			const storedUnderChip = document.createElement('span');
			storedUnderChip.className = CSS_PREFIX + '-memory-item__scope-chip wp-mcp-ai-memory-item__scope-chip--stored-under';
			storedUnderChip.setAttribute('data-testid', CSS_PREFIX + '-memory-stored-under');
			storedUnderChip.textContent = __( 'stored under' ) + ': ' + memory.stored_under;
			scopeList.appendChild(storedUnderChip);
		}
		item.appendChild(scopeList);

		const body = document.createElement('p');
		body.className = CSS_PREFIX + '-memory-item__content';
		body.textContent = content;
		item.appendChild(body);

		if (Array.isArray(tags) && tags.length) {
			const tagList = document.createElement('div');
			tagList.className = CSS_PREFIX + '-memory-item__tags';
			tags.forEach(function(tag) {
				const chip = document.createElement('span');
				chip.className = CSS_PREFIX + '-memory-item__tag';
				chip.textContent = String(tag);
				tagList.appendChild(chip);
			});
			item.appendChild(tagList);
		}

		const actions = document.createElement('div');
		actions.className = CSS_PREFIX + '-memory-item__actions';

		const editBtn = document.createElement('button');
		editBtn.type = 'button';
		editBtn.className = CSS_PREFIX + '-memory-item__edit';
		editBtn.textContent = __( 'Edit' );
		editBtn.setAttribute('data-testid', CSS_PREFIX + '-memory-edit');
		editBtn.addEventListener('click', function() {
			renderEditForm(item, memory, onUpdate);
		});

		const deleteBtn = document.createElement('button');
		deleteBtn.type = 'button';
		deleteBtn.className = CSS_PREFIX + '-memory-item__delete';
		deleteBtn.textContent = __( 'Delete' );
		deleteBtn.setAttribute('data-testid', CSS_PREFIX + '-memory-delete');
		deleteBtn.addEventListener('click', function() {
			if (!id) {
				announceToast(__( 'This memory has no ID and cannot be deleted.' ), 'error');
				return;
			}
			if (!window.confirm(__( 'Delete this memory? This cannot be undone.' ))) {
				return;
			}
			memoryService().remove(id, { agentId: memory.agent_id }).then(function() {
				announceToast(__( 'Memory deleted.' ), 'success');
				onDelete(id);
			}).catch(function(err) {
				announceToast(
					(err && err.message) || __( 'Could not delete memory.' ),
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
			announceToast(__( 'This memory has no ID and cannot be edited.' ), 'error');
			return;
		}
		const currentTitle = memory.title || (memory.context_data && memory.context_data.title) || '';
		const currentContent = memory.content || (memory.context_data && memory.context_data.content) || '';

		while (item.firstChild) {
			item.removeChild(item.firstChild);
		}

		const form = document.createElement('form');
		form.className = CSS_PREFIX + '-memory-item__edit-form';
		form.setAttribute('data-testid', CSS_PREFIX + '-memory-edit-form');

		const titleLabel = document.createElement('label');
		titleLabel.className = CSS_PREFIX + '-memory-item__edit-label';
		titleLabel.textContent = __( 'Title' );
		const titleInput = document.createElement('input');
		titleInput.type = 'text';
		titleInput.className = CSS_PREFIX + '-memory-item__edit-title';
		titleInput.value = currentTitle;
		titleLabel.appendChild(titleInput);

		const contentLabel = document.createElement('label');
		contentLabel.className = CSS_PREFIX + '-memory-item__edit-label';
		contentLabel.textContent = __( 'Content' );
		const contentInput = document.createElement('textarea');
		contentInput.className = CSS_PREFIX + '-memory-item__edit-content';
		contentInput.rows = 4;
		contentInput.value = currentContent;
		contentLabel.appendChild(contentInput);

		const buttons = document.createElement('div');
		buttons.className = CSS_PREFIX + '-memory-item__edit-buttons';

		const save = document.createElement('button');
		save.type = 'submit';
		save.className = CSS_PREFIX + '-memory-item__edit-save';
		save.textContent = __( 'Save' );

		const cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.className = CSS_PREFIX + '-memory-item__edit-cancel';
		cancel.textContent = __( 'Cancel' );
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
				announceToast(__( 'Memory updated.' ), 'success');
				onUpdate(Object.assign({}, memory, {
					title: titleInput.value,
					content: contentInput.value
				}), true);
			}).catch(function(err) {
				save.disabled = false;
				announceToast(
					(err && err.message) || __( 'Could not update memory.' ),
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
		drawer.className = CSS_PREFIX + '-memory-drawer';
		drawer.setAttribute('role', 'dialog');
		drawer.setAttribute('aria-modal', 'false');
		drawer.setAttribute('aria-hidden', 'true');
		drawer.setAttribute('data-testid', CSS_PREFIX + '-memory-drawer');
		drawer.hidden = true;

		const heading = document.createElement('h3');
		heading.className = CSS_PREFIX + '-memory-drawer__heading';
		heading.id = CSS_PREFIX + '-memory-drawer-heading-' + Math.floor(Math.random() * 1e9);
		heading.textContent = __( 'Long-term memory' );
		drawer.setAttribute('aria-labelledby', heading.id);

		const closeBtn = document.createElement('button');
		closeBtn.type = 'button';
		closeBtn.className = CSS_PREFIX + '-memory-drawer__close';
		closeBtn.setAttribute('aria-label', __( 'Close memory drawer' ));
		closeBtn.textContent = '×';

		const tabs = document.createElement('div');
		tabs.className = CSS_PREFIX + '-memory-drawer__tabs';
		tabs.setAttribute('role', 'tablist');

		const memoriesTab = document.createElement('button');
		memoriesTab.type = 'button';
		memoriesTab.className = CSS_PREFIX + '-memory-drawer__tab is-active';
		memoriesTab.setAttribute('role', 'tab');
		memoriesTab.setAttribute('aria-selected', 'true');
		memoriesTab.textContent = __( 'Memories' );

		const scopeTab = document.createElement('button');
		scopeTab.type = 'button';
		scopeTab.className = CSS_PREFIX + '-memory-drawer__tab';
		scopeTab.setAttribute('role', 'tab');
		scopeTab.setAttribute('aria-selected', 'false');
		scopeTab.textContent = __( 'Scope' );

		const auditTab = document.createElement('button');
		auditTab.type = 'button';
		auditTab.className = CSS_PREFIX + '-memory-drawer__tab';
		auditTab.setAttribute('role', 'tab');
		auditTab.setAttribute('aria-selected', 'false');
		auditTab.setAttribute('data-testid', CSS_PREFIX + '-memory-audit-tab');
		auditTab.textContent = __( 'Audit' );

		const replayTab = document.createElement('button');
		replayTab.type = 'button';
		replayTab.className = CSS_PREFIX + '-memory-drawer__tab';
		replayTab.setAttribute('role', 'tab');
		replayTab.setAttribute('aria-selected', 'false');
		replayTab.setAttribute('data-testid', CSS_PREFIX + '-memory-replay-tab');
		replayTab.textContent = __( 'Session Replay' );

		tabs.appendChild(memoriesTab);
		tabs.appendChild(scopeTab);
		tabs.appendChild(auditTab);
		tabs.appendChild(replayTab);

		// Memories panel.
		const memoriesPanel = document.createElement('div');
		memoriesPanel.className = CSS_PREFIX + '-memory-drawer__panel';
		memoriesPanel.setAttribute('role', 'tabpanel');

		// Diagnostic: the exact agent_id this drawer recalls under. Memories
		// are indexed per-agent server-side, so an ID mismatch between store
		// and recall makes records invisible — surfacing the ID makes that
		// diagnosable without opening DevTools.
		const agentMeta = document.createElement('p');
		agentMeta.className = CSS_PREFIX + '-memory-drawer__agent';
		agentMeta.setAttribute('data-testid', CSS_PREFIX + '-memory-agent-id');
		agentMeta.textContent = _sprintf(
			__( 'Memory for agent %s' ),
			agentId && agentId !== '0' ? '#' + agentId : __( '(user scope)' )
		);
		memoriesPanel.appendChild(agentMeta);

		const filterRow = document.createElement('div');
		filterRow.className = CSS_PREFIX + '-memory-drawer__filter';

		const queryInput = document.createElement('input');
		queryInput.type = 'search';
		queryInput.className = CSS_PREFIX + '-memory-drawer__query';
		queryInput.placeholder = __( 'Filter memories…' );
		queryInput.setAttribute('aria-label', __( 'Search memories' ));
		queryInput.setAttribute('data-testid', CSS_PREFIX + '-memory-query');
		filterRow.appendChild(queryInput);

		const refreshBtn = document.createElement('button');
		refreshBtn.type = 'button';
		refreshBtn.className = CSS_PREFIX + '-memory-drawer__refresh';
		refreshBtn.textContent = __( 'Refresh' );
		filterRow.appendChild(refreshBtn);

		const exportBtn = document.createElement('button');
		exportBtn.type = 'button';
		exportBtn.className = CSS_PREFIX + '-memory-drawer__export';
		exportBtn.setAttribute('data-testid', CSS_PREFIX + '-memory-export');
		exportBtn.textContent = __( 'Export' );
		filterRow.appendChild(exportBtn);

		const list = document.createElement('ul');
		list.className = CSS_PREFIX + '-memory-drawer__list';
		list.setAttribute('data-testid', CSS_PREFIX + '-memory-list');

		const waterfall = document.createElement('section');
		waterfall.className = CSS_PREFIX + '-memory-drawer__waterfall';
		waterfall.setAttribute('data-testid', CSS_PREFIX + '-memory-waterfall');
		waterfall.hidden = true;

		const emptyState = document.createElement('p');
		emptyState.className = CSS_PREFIX + '-memory-drawer__empty';
		emptyState.hidden = true;
		emptyState.textContent = __( 'No memories yet.' );

		const errorState = document.createElement('p');
		errorState.className = CSS_PREFIX + '-memory-drawer__error';
		errorState.setAttribute('role', 'alert');
		errorState.hidden = true;

		memoriesPanel.appendChild(filterRow);
		memoriesPanel.appendChild(waterfall);
		memoriesPanel.appendChild(emptyState);
		memoriesPanel.appendChild(errorState);
		memoriesPanel.appendChild(list);

		// Scope panel.
		const scopePanel = document.createElement('div');
		scopePanel.className = CSS_PREFIX + '-memory-drawer__panel';
		scopePanel.setAttribute('role', 'tabpanel');
		scopePanel.hidden = true;

		const scopeForm = document.createElement('form');
		scopeForm.className = CSS_PREFIX + '-memory-drawer__scope-form';
		scopeForm.setAttribute('data-testid', CSS_PREFIX + '-memory-scope-form');

		const wingLabel = document.createElement('label');
		wingLabel.textContent = __( 'Wing (project / matter)' );
		const wingInput = document.createElement('input');
		wingInput.type = 'text';
		wingInput.className = CSS_PREFIX + '-memory-drawer__wing';
		wingInput.value = config.memoryWing || '';
		wingLabel.appendChild(wingInput);

		const roomLabel = document.createElement('label');
		roomLabel.textContent = __( 'Room (topic)' );
		const roomInput = document.createElement('input');
		roomInput.type = 'text';
		roomInput.className = CSS_PREFIX + '-memory-drawer__room';
		roomInput.value = config.memoryRoom || '';
		roomLabel.appendChild(roomInput);

		const scopeSaveBtn = document.createElement('button');
		scopeSaveBtn.type = 'submit';
		scopeSaveBtn.textContent = __( 'Apply scope' );

		scopeForm.appendChild(wingLabel);
		scopeForm.appendChild(roomLabel);
		scopeForm.appendChild(scopeSaveBtn);

		// Fix #6 — one-click way to view every memory regardless of the
		// active wing/room scope: recall runs unscoped while checked.
		const allScopesLabel = document.createElement('label');
		allScopesLabel.className = CSS_PREFIX + '-memory-drawer__all-scopes';
		const allScopesInput = document.createElement('input');
		allScopesInput.type = 'checkbox';
		allScopesInput.setAttribute('data-testid', CSS_PREFIX + '-memory-all-scopes');
		allScopesLabel.appendChild(allScopesInput);
		allScopesLabel.appendChild(document.createTextNode(' ' + __( 'Show all scopes (ignore wing/room)' )));
		scopeForm.appendChild(allScopesLabel);
		allScopesInput.addEventListener('change', function() {
			loadMemories();
		});

		scopeForm.addEventListener('submit', function(e) {
			e.preventDefault();
			config.memoryWing = wingInput.value || '';
			config.memoryRoom = roomInput.value || '';
			announceToast(
				config.memoryWing
					? _sprintf(__( 'Scope set to wing "%s".' ), config.memoryWing)
					: __( 'Scope cleared.' ),
				'success'
			);
			loadMemories(); // refresh list under new scope
		});

		scopePanel.appendChild(scopeForm);

		// Audit panel — lazy-loaded from /chat-memory/audit on first activation.
		const auditPanel = document.createElement('div');
		auditPanel.className = CSS_PREFIX + '-memory-drawer__panel';
		auditPanel.setAttribute('role', 'tabpanel');
		auditPanel.setAttribute('data-testid', CSS_PREFIX + '-memory-audit-panel');
		auditPanel.hidden = true;

		const auditFilterRow = document.createElement('div');
		auditFilterRow.className = CSS_PREFIX + '-memory-drawer__filter';

		const auditActionFilter = document.createElement('select');
		auditActionFilter.className = CSS_PREFIX + '-memory-drawer__audit-filter';
		auditActionFilter.setAttribute('aria-label', __( 'Filter audit log by action type' ));
		auditActionFilter.setAttribute('data-testid', CSS_PREFIX + '-memory-audit-filter');
		const auditFilterOptions = [
			{ value: '', label: __( 'All actions' ) },
			{ value: 'create', label: __( 'Created' ) },
			{ value: 'update', label: __( 'Updated' ) },
			{ value: 'delete', label: __( 'Deleted' ) },
			{ value: 'access', label: __( 'Accessed' ) },
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
		auditRefreshBtn.className = CSS_PREFIX + '-memory-drawer__refresh';
		auditRefreshBtn.textContent = __( 'Refresh' );
		auditFilterRow.appendChild(auditRefreshBtn);

		const auditList = document.createElement('ul');
		auditList.className = CSS_PREFIX + '-memory-drawer__audit-list';
		auditList.setAttribute('data-testid', CSS_PREFIX + '-memory-audit-list');

		const auditEmpty = document.createElement('p');
		auditEmpty.className = CSS_PREFIX + '-memory-drawer__empty';
		auditEmpty.hidden = true;
		auditEmpty.textContent = __( 'No audit entries yet.' );

		const auditError = document.createElement('p');
		auditError.className = CSS_PREFIX + '-memory-drawer__error';
		auditError.setAttribute('role', 'alert');
		auditError.hidden = true;

		auditPanel.appendChild(auditFilterRow);
		auditPanel.appendChild(auditEmpty);
		auditPanel.appendChild(auditError);
		auditPanel.appendChild(auditList);

		const replayPanel = document.createElement('div');
		replayPanel.className = CSS_PREFIX + '-memory-drawer__panel';
		replayPanel.setAttribute('role', 'tabpanel');
		replayPanel.setAttribute('data-testid', CSS_PREFIX + '-memory-replay-panel');
		replayPanel.hidden = true;

		const replayFilterRow = document.createElement('div');
		replayFilterRow.className = CSS_PREFIX + '-memory-drawer__filter';

		const replaySessionInput = document.createElement('input');
		replaySessionInput.type = 'text';
		replaySessionInput.className = CSS_PREFIX + '-memory-drawer__query';
		replaySessionInput.placeholder = __( 'Session ID…' );
		replaySessionInput.setAttribute('aria-label', __( 'Session ID for replay' ));
		replaySessionInput.setAttribute('data-testid', CSS_PREFIX + '-memory-replay-session');
		replaySessionInput.value = resolveReplaySessionId(config, agentId);
		replayFilterRow.appendChild(replaySessionInput);

		const replayRefreshBtn = document.createElement('button');
		replayRefreshBtn.type = 'button';
		replayRefreshBtn.className = CSS_PREFIX + '-memory-drawer__refresh';
		replayRefreshBtn.textContent = __( 'Load' );
		replayFilterRow.appendChild(replayRefreshBtn);

		const replayList = document.createElement('ul');
		replayList.className = CSS_PREFIX + '-memory-drawer__audit-list';
		replayList.setAttribute('data-testid', CSS_PREFIX + '-memory-replay-list');

		const replayEmpty = document.createElement('p');
		replayEmpty.className = CSS_PREFIX + '-memory-drawer__empty';
		replayEmpty.hidden = true;
		replayEmpty.textContent = __( 'No session replay events yet.' );

		const replayError = document.createElement('p');
		replayError.className = CSS_PREFIX + '-memory-drawer__error';
		replayError.setAttribute('role', 'alert');
		replayError.hidden = true;

		replayPanel.appendChild(replayFilterRow);
		replayPanel.appendChild(replayEmpty);
		replayPanel.appendChild(replayError);
		replayPanel.appendChild(replayList);

		drawer.appendChild(closeBtn);
		drawer.appendChild(heading);
		drawer.appendChild(tabs);
		drawer.appendChild(memoriesPanel);
		drawer.appendChild(scopePanel);
		drawer.appendChild(auditPanel);
		drawer.appendChild(replayPanel);

		container.appendChild(drawer);

		let auditLoaded = false;
		let replayLoaded = false;

		function setTab(name) {
			const isMemories = name === 'memories';
			const isScope = name === 'scope';
			const isAudit = name === 'audit';
			const isReplay = name === 'replay';
			memoriesTab.classList.toggle('is-active', isMemories);
			memoriesTab.setAttribute('aria-selected', isMemories ? 'true' : 'false');
			scopeTab.classList.toggle('is-active', isScope);
			scopeTab.setAttribute('aria-selected', isScope ? 'true' : 'false');
			auditTab.classList.toggle('is-active', isAudit);
			auditTab.setAttribute('aria-selected', isAudit ? 'true' : 'false');
			replayTab.classList.toggle('is-active', isReplay);
			replayTab.setAttribute('aria-selected', isReplay ? 'true' : 'false');
			memoriesPanel.hidden = !isMemories;
			scopePanel.hidden = !isScope;
			auditPanel.hidden = !isAudit;
			replayPanel.hidden = !isReplay;
			if (isAudit && !auditLoaded) {
				auditLoaded = true;
				loadAudit();
			}
			if (isReplay && !replayLoaded) {
				replayLoaded = true;
				loadReplay();
			}
		}

		memoriesTab.addEventListener('click', function() { setTab('memories'); });
		scopeTab.addEventListener('click', function() { setTab('scope'); });
		auditTab.addEventListener('click', function() { setTab('audit'); });
		replayTab.addEventListener('click', function() { setTab('replay'); });

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
			waterfall.hidden = true;
			clearList();

			const loading = document.createElement('li');
			loading.className = CSS_PREFIX + '-memory-drawer__loading';
			loading.textContent = __( 'Loading memories…' );
			list.appendChild(loading);

			const ignoreScope = allScopesInput.checked;
			const filters = {
				agentId: agentId,
				wing: ignoreScope ? '' : (config.memoryWing || ''),
				room: ignoreScope ? '' : (config.memoryRoom || ''),
				limit: 25
			};

			memoryService().recall(queryInput.value || '', filters).then(function(response) {
				clearList();
				const records = extractRecords(response);
				renderRetrievalWaterfall(response, records);
				if (!records.length) {
					emptyState.hidden = false;
					return;
				}
				records.forEach(function(record) {
					attachItem(record);
				});
			}).catch(function(err) {
				clearList();
				waterfall.hidden = true;
				showError((err && err.message) || __( 'Could not load memories.' ));
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

		function extractRrfWaterfall(records) {
			const safeRecords = Array.isArray(records) ? records : [];
			const rrfHits = { bm25: 0, vector: 0, graph: 0 };
			let hasRrfBreakdown = false;

			safeRecords.forEach(function(record) {
				const rrfBreakdown = record && record.rrf_breakdown;
				if (rrfBreakdown && typeof rrfBreakdown === 'object') {
					hasRrfBreakdown = true;
					if (rrfBreakdown.bm25_rank !== null && rrfBreakdown.bm25_rank !== undefined) {
						rrfHits.bm25++;
					}
					if (rrfBreakdown.vector_rank !== null && rrfBreakdown.vector_rank !== undefined) {
						rrfHits.vector++;
					}
					if (rrfBreakdown.graph_rank !== null && rrfBreakdown.graph_rank !== undefined) {
						rrfHits.graph++;
					}
				}
			});

			if (!hasRrfBreakdown) {
				return null;
			}

			return {
				label: __( 'RRF hybrid retrieval' ),
				rows: [
					{ label: __( 'BM25' ), count: rrfHits.bm25 },
					{ label: __( 'Vector' ), count: rrfHits.vector },
					{ label: __( 'Graph' ), count: rrfHits.graph }
				]
			};
		}

		function extractLegacyWaterfall(records) {
			const safeRecords = Array.isArray(records) ? records : [];
			const legacyHits = { keyword: 0, temporal: 0, exact_match: 0 };
			let hasLegacyBreakdown = false;

			safeRecords.forEach(function(record) {
				const boostBreakdown = record && record.boost_breakdown;
				if (boostBreakdown && typeof boostBreakdown === 'object') {
					hasLegacyBreakdown = true;
					if (Number(boostBreakdown.keyword || 0) > 0) {
						legacyHits.keyword++;
					}
					if (Number(boostBreakdown.temporal || 0) > 0) {
						legacyHits.temporal++;
					}
					if (Number(boostBreakdown.exact_match || 0) > 0) {
						legacyHits.exact_match++;
					}
				}
			});

			if (!hasLegacyBreakdown) {
				return null;
			}

			return {
				label: __( 'Legacy booster retrieval' ),
				rows: [
					{ label: __( 'Keyword' ), count: legacyHits.keyword },
					{ label: __( 'Temporal' ), count: legacyHits.temporal },
					{ label: __( 'Exact' ), count: legacyHits.exact_match }
				]
			};
		}

		function extractPathWaterfall(response, totalRecords) {
			const retrievalPath = response && response.retrieval_path ? String(response.retrieval_path) : '';
			if (!retrievalPath) {
				return null;
			}
			return {
				label: __( 'Retrieval path' ),
				rows: [
					{ label: retrievalPath, count: totalRecords }
				]
			};
		}

		function extractRetrievalWaterfall(response, records) {
			const totalRecords = Array.isArray(records) ? records.length : 0;
			return extractRrfWaterfall(records) || extractLegacyWaterfall(records) || extractPathWaterfall(response, totalRecords);
		}

		function renderRetrievalWaterfall(response, records) {
			const data = extractRetrievalWaterfall(response, records);
			while (waterfall.firstChild) {
				waterfall.removeChild(waterfall.firstChild);
			}
			if (!data || !Array.isArray(data.rows) || !data.rows.length) {
				waterfall.hidden = true;
				return;
			}

			const heading = document.createElement('h4');
			heading.className = CSS_PREFIX + '-memory-drawer__waterfall-heading';
			heading.textContent = __( 'Retrieval waterfall' );
			waterfall.appendChild(heading);

			const subheading = document.createElement('p');
			subheading.className = CSS_PREFIX + '-memory-drawer__waterfall-label';
			subheading.textContent = data.label;
			waterfall.appendChild(subheading);

			const maxCount = data.rows.reduce(function(max, row) {
				const count = Number(row.count || 0);
				return count > max ? count : max;
			}, 0);

			const rows = document.createElement('ul');
			rows.className = CSS_PREFIX + '-memory-drawer__waterfall-rows';

			data.rows.forEach(function(row) {
				const count = Number(row.count || 0);
				const width = maxCount > 0
					? Math.max(MIN_WATERFALL_BAR_WIDTH_PERCENT, Math.round((count / maxCount) * 100))
					: MIN_WATERFALL_BAR_WIDTH_PERCENT;

				const li = document.createElement('li');
				li.className = CSS_PREFIX + '-memory-drawer__waterfall-row';
				li.setAttribute('data-testid', CSS_PREFIX + '-memory-waterfall-row');

				const label = document.createElement('span');
				label.className = CSS_PREFIX + '-memory-drawer__waterfall-row-label';
				label.textContent = row.label;

				const meter = document.createElement('span');
				meter.className = CSS_PREFIX + '-memory-drawer__waterfall-meter';
				const fill = document.createElement('span');
				fill.className = CSS_PREFIX + '-memory-drawer__waterfall-meter-fill';
				fill.style.width = width + '%';
				meter.appendChild(fill);

				const value = document.createElement('span');
				value.className = CSS_PREFIX + '-memory-drawer__waterfall-row-value';
				value.textContent = String(count);

				li.appendChild(label);
				li.appendChild(meter);
				li.appendChild(value);
				rows.appendChild(li);
			});

			waterfall.appendChild(rows);
			waterfall.hidden = false;
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
			li.className = CSS_PREFIX + '-memory-drawer__audit-item';

			const action = String((entry && entry.action) || '').toLowerCase();
			if (action) {
				li.setAttribute('data-action', action);
			}

			const timestamp = document.createElement('time');
			timestamp.className = CSS_PREFIX + '-memory-drawer__audit-time';
			const ts = entry && entry.timestamp ? String(entry.timestamp) : '';
			if (ts) {
				timestamp.setAttribute('datetime', ts);
				timestamp.textContent = ts;
			} else {
				timestamp.textContent = __( '(no timestamp)' );
			}

			const actionLabel = document.createElement('span');
			actionLabel.className = CSS_PREFIX + '-memory-drawer__audit-action';
			actionLabel.textContent = action || __( 'unknown' );

			const meta = document.createElement('span');
			meta.className = CSS_PREFIX + '-memory-drawer__audit-meta';
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
			loading.className = CSS_PREFIX + '-memory-drawer__loading';
			loading.textContent = __( 'Loading audit log…' );
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
				auditError.textContent = (err && err.message) || __( 'Could not load audit log.' );
				auditError.hidden = false;
			});
		}

		function extractReplayEvents(response) {
			if (!response || typeof response !== 'object') {
				return [];
			}
			if (Array.isArray(response.events)) { return response.events; }
			if (response.data && Array.isArray(response.data.events)) { return response.data.events; }
			return [];
		}

		function clearReplayList() {
			while (replayList.firstChild) {
				replayList.removeChild(replayList.firstChild);
			}
		}

		function renderReplayEvent(entry) {
			const li = document.createElement('li');
			li.className = CSS_PREFIX + '-memory-drawer__audit-item';
			li.setAttribute('data-testid', CSS_PREFIX + '-memory-replay-item');

			const eventName = entry && entry.event ? String(entry.event) : '';
			if (eventName) {
				li.setAttribute('data-event', eventName);
			}

			const timestamp = document.createElement('time');
			timestamp.className = CSS_PREFIX + '-memory-drawer__audit-time';
			const ts = entry && entry.timestamp ? String(entry.timestamp) : '';
			if (ts) {
				timestamp.setAttribute('datetime', ts);
				timestamp.textContent = ts;
			} else {
				timestamp.textContent = __( '(no timestamp)' );
			}

			const actionLabel = document.createElement('span');
			actionLabel.className = CSS_PREFIX + '-memory-drawer__audit-action';
			actionLabel.textContent = eventName || __( 'event' );

			const meta = document.createElement('span');
			meta.className = CSS_PREFIX + '-memory-drawer__audit-meta';
			const data = entry && entry.data && typeof entry.data === 'object' ? entry.data : {};
			const message = data.message || data.error || data.action || '';
			meta.textContent = message ? String(message) : '';

			li.appendChild(timestamp);
			li.appendChild(document.createTextNode(' '));
			li.appendChild(actionLabel);
			if (meta.textContent) {
				li.appendChild(document.createTextNode(' — '));
				li.appendChild(meta);
			}

			return li;
		}

		function loadReplay() {
			if (!isAvailable()) {
				return;
			}
			replayError.hidden = true;
			replayEmpty.hidden = true;
			clearReplayList();

			const sessionId = (replaySessionInput.value || '').trim();
			if (!sessionId) {
				replayEmpty.textContent = __( 'Enter a session ID to replay events.' );
				replayEmpty.hidden = false;
				return;
			}

			const loading = document.createElement('li');
			loading.className = CSS_PREFIX + '-memory-drawer__loading';
			loading.textContent = __( 'Loading session replay…' );
			replayList.appendChild(loading);

			if (!memoryService().sessionReplay || typeof memoryService().sessionReplay !== 'function') {
				clearReplayList();
				replayError.textContent = __( 'Session replay endpoint is unavailable.' );
				replayError.hidden = false;
				return;
			}

			memoryService().sessionReplay(sessionId, { limit: 100 }).then(function(response) {
				clearReplayList();
				const events = extractReplayEvents(response);
				if (!events.length) {
					replayEmpty.textContent = __( 'No session replay events yet.' );
					replayEmpty.hidden = false;
					return;
				}
				events.forEach(function(event) {
					replayList.appendChild(renderReplayEvent(event));
				});
			}).catch(function(err) {
				clearReplayList();
				replayError.textContent = (err && err.message) || __( 'Could not load session replay.' );
				replayError.hidden = false;
			});
		}

		auditRefreshBtn.addEventListener('click', loadAudit);
		auditActionFilter.addEventListener('change', loadAudit);
		replayRefreshBtn.addEventListener('click', loadReplay);

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
				announceToast(__( 'Memory is not available right now.' ), 'error');
				return;
			}
			exportInFlight = true;
			exportBtn.disabled = true;

			const ignoreScope = allScopesInput.checked;
			const filters = {
				agentId: agentId,
				wing: ignoreScope ? '' : (config.memoryWing || ''),
				room: ignoreScope ? '' : (config.memoryRoom || ''),
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
					_sprintf(
						/* translators: %d: number of memories exported */
						__( 'Exported %d memor(y/ies).' ),
						records.length
					),
					'success'
				);
			}).catch(function(err) {
				announceToast(
					(err && err.message) || __( 'Could not export memories.' ),
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
		toggle.className = CSS_PREFIX + '-memory-toggle';
		toggle.setAttribute('aria-haspopup', 'dialog');
		toggle.setAttribute('aria-expanded', 'false');
		toggle.setAttribute('aria-label', __( 'Open long-term memory drawer' ));
		toggle.setAttribute('data-testid', CSS_PREFIX + '-memory-toggle');
		toggle.innerHTML = '<span aria-hidden="true">🧠</span><span class="screen-reader-text">'
			+ __( 'Memory' ) + '</span>';
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

		if (!activeDrawers.some(function(entry) { return entry.container === container; })) {
			activeDrawers.push({ container: container, controller: controller });
		}
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
		const flagKey = CSS_PREFIX + '-memory-autosummary:' + agentId;
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
				title: _sprintf(
					/* translators: %s: ISO date of the captured conversation */
					__( 'Conversation summary — %s' ),
					new Date().toISOString().slice(0, 10)
				),
				content: transcript.text,
				tags: [ 'transcript-summary', 'autosummary' ],
				contextType: 'transcript_summary',
				importance: 'medium',
				verbatim: true,
				// G6 Phase 2 — ask the server to LLM-summarise the
				// verbatim transcript before persisting. Server falls
				// back to verbatim on any failure (no API key, HTTP
				// error, malformed response) so data is never lost.
				summarize: true
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
		readTranscript: readTranscript,
		handleSseMemoryEvent: handleSseMemoryEvent
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

// ─── ES Module exports ───────────────────────────────────────────────────────
var MemoryDrawer = {
  attach: attach,
  buildDrawer: buildDrawer,
  injectToggle: injectToggle,
  decorateMessageWithBadge: decorateMessageWithBadge,
  handleSseMemoryEvent: handleSseMemoryEvent,
  configure: configure,
};

export { MemoryDrawer, configure, attach, buildDrawer, decorateMessageWithBadge, handleSseMemoryEvent };
export default MemoryDrawer;
