/**
 * NV oOS Content Graph AI — Admin Chat Tester.
 *
 * Dependency-free chat tester. Talks to:
 *   GET  /nvoos-content-graph/v1/ai/chat/config
 *   POST /nvoos-content-graph/v1/ai/chat  (SSE, stream=true)
 *
 * Mirrors the SPA-v2 SSE contract via window.NvoosContentGraphAiSse.
 * Features: live provider dropdown (credential state), model override,
 * graph-tool presets, system-prompt toggle, streaming markdown, tool
 * cards, cost badge, per-message meta, copy/raw actions, stop button,
 * sessionStorage persistence, and a raw SSE debug log.
 *
 * @since 1.0.0
 */
(function () {
	'use strict';

	var Sse = window.NvoosContentGraphAiSse;
	var cfg = window.NvoosContentGraphAiChat;
	if (!Sse || !cfg) {
		return;
	}

	var i18n = cfg.i18n || {};

	var STORAGE_KEY = 'nvoosContentGraphAiChatHistory';
	var PREFS_KEY = 'nvoosContentGraphAiChatPrefs';

	// ─── DOM refs ────────────────────────────────────────────────────
	var messages = document.getElementById('nvoos-chat-messages');
	var input = document.getElementById('nvoos-chat-input');
	var sendBtn = document.getElementById('nvoos-chat-send');
	var stopBtn = document.getElementById('nvoos-chat-stop');
	var clearBtn = document.getElementById('nvoos-chat-clear');
	var providerSel = document.getElementById('nvoos-chat-provider');
	var modelInput = document.getElementById('nvoos-chat-model');
	var modelList = document.getElementById('nvoos-chat-model-list');
	var toolsSel = document.getElementById('nvoos-chat-tools');
	var systemPromptChk = document.getElementById('nvoos-chat-system-prompt');
	var contextChk = document.getElementById('nvoos-chat-context');
	var costEl = document.getElementById('nvoos-chat-cost');
	var debugLog = document.getElementById('nvoos-chat-debug-log');

	// ─── State ───────────────────────────────────────────────────────
	var config = null;
	var history = [];      // [{role, content, meta?}]
	var prefs = {};
	var streaming = false;
	var controller = null;
	var turnStart = 0;
	var modelsLoaded = {}; // provider slug → true once fetched this session

	// Current-turn stream state.
	var currentThinkingEl = null;
	var currentAssembled = '';
	var currentReceivedContent = false;
	var currentErrorShown = false;
	var currentFinalMeta = null;
	var streamedToolIds = {};   // tool_call_id/name → true for this turn
	var currentTurnCards = [];  // tool cards created during this turn

	// ─── Init ────────────────────────────────────────────────────────
	function init() {
		loadPrefs();
		bindEvents();
		restoreHistory();
		fetchConfig();
	}

	function bindEvents() {
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				onSend();
			} else if (e.key === 'Escape' && streaming) {
				onStop();
			}
		});

		input.addEventListener('input', function () {
			input.style.height = 'auto';
			input.style.height = Math.min(input.scrollHeight, 200) + 'px';
		});

		sendBtn.addEventListener('click', onSend);
		stopBtn.addEventListener('click', onStop);
		clearBtn.addEventListener('click', onClear);

		providerSel.addEventListener('change', function () {
			savePrefs();
			loadModelsFor(providerSel.value);
		});
		modelInput.addEventListener('change', savePrefs);
		modelInput.addEventListener('focus', function () {
			loadModelsFor(providerSel.value);
		});
		toolsSel.addEventListener('change', savePrefs);
	}

	// ─── Config loading ──────────────────────────────────────────────
	function fetchConfig() {
		fetch(cfg.restUrl + '/ai/chat/config', {
			headers: { 'X-WP-Nonce': cfg.nonce },
		})
			.then(function (res) {
				if (!res.ok) {
					throw new Error('HTTP ' + res.status);
				}
				return res.json();
			})
			.then(function (data) {
				config = data;
				debug('config loaded', JSON.stringify(data));
				populateProviders(data.providers);
				populateModel(data.default_model);
				populateTools(data.tool_presets);

				if (data.system_prompt_configured === false) {
					systemPromptChk.checked = false;
					systemPromptChk.disabled = true;
					systemPromptChk.title = i18n.configError;
				}

				if (contextChk && data.graph_context_available === false) {
					contextChk.checked = false;
					contextChk.disabled = true;
					contextChk.title = i18n.contextUnavailable || '';
					debug('graph context unavailable — checkbox disabled');
				}
			})
			.catch(function (err) {
				debug('config error — using fallbacks', String(err));
				populateProviders(cfg.providersFallback || []);
				populateModel(cfg.defaultModel || '');
			});
	}

	function populateProviders(list) {
		providerSel.innerHTML = '';
		(list || []).forEach(function (p) {
			var opt = document.createElement('option');
			opt.value = p.slug;
			opt.textContent = p.label || p.slug;
			if (p.configured === false) {
				opt.textContent += ' (' + i18n.missingKey + ')';
			}
			providerSel.appendChild(opt);
		});

		var desired = prefs.provider ||
			(config && config.default_provider) ||
			cfg.defaultProvider ||
			'';
		if (desired && hasOption(providerSel, desired)) {
			providerSel.value = desired;
		}
	}

	function populateModel(defaultModel) {
		modelInput.placeholder = i18n.model + ': ' + (defaultModel || 'Default');
		if (prefs.model) {
			modelInput.value = prefs.model;
		}
	}

	/**
	 * Lazily fetch the model catalogue for a provider and populate the
	 * datalist. Server-side results are transient-cached for an hour; we
	 * additionally fetch at most once per provider per page session.
	 * Failures never block — the input still accepts manual model ids.
	 *
	 * @param {string} provider Provider slug.
	 */
	function loadModelsFor(provider) {
		if (!provider || !modelList || modelsLoaded[provider]) {
			return;
		}
		modelsLoaded[provider] = true;

		debug('GET /ai/models', 'provider=' + provider);

		fetch(cfg.restUrl + '/ai/models?provider=' + encodeURIComponent(provider), {
			headers: { 'X-WP-Nonce': cfg.nonce },
		})
			.then(function (res) {
				if (!res.ok) {
					throw new Error('HTTP ' + res.status);
				}
				return res.json();
			})
			.then(function (data) {
				if (!data || !Array.isArray(data.models)) {
					return;
				}

				// Clear options belonging to other providers.
				modelList.innerHTML = '';
				data.models.forEach(function (modelId) {
					var opt = document.createElement('option');
					opt.value = modelId;
					modelList.appendChild(opt);
				});

				debug(
					'models loaded',
					'provider=' + provider,
					'count=' + data.models.length,
					'cached=' + (data.cached ? 'yes' : 'no')
				);
			})
			.catch(function (err) {
				modelsLoaded[provider] = false; // Allow a retry on next focus.
				debug('models fetch failed', String(err), i18n.modelsFailed || '');
			});
	}

	function populateTools(presets) {
		toolsSel.innerHTML = '';
		(presets || [{ slug: 'none', label: i18n.noTools || i18n.none, tools: [] }]).forEach(function (p) {
			var opt = document.createElement('option');
			opt.value = p.slug;
			opt.textContent = p.label || p.slug;
			toolsSel.appendChild(opt);
		});

		if (prefs.tools && hasOption(toolsSel, prefs.tools)) {
			toolsSel.value = prefs.tools;
		}
	}

	function findPreset(slug) {
		if (!config || !Array.isArray(config.tool_presets)) {
			return null;
		}
		for (var i = 0; i < config.tool_presets.length; i++) {
			if (config.tool_presets[i].slug === slug) {
				return config.tool_presets[i];
			}
		}
		return null;
	}

	// ─── Events ──────────────────────────────────────────────────────
	function onSend() {
		if (streaming) {
			return;
		}
		var text = input.value.trim();
		if (!text) {
			return;
		}
		input.value = '';
		input.style.height = '';
		sendMessage(text);
	}

	function onStop() {
		if (streaming && controller) {
			controller.abort();
		}
	}

	function onClear() {
		history = [];
		saveHistory();
		messages.innerHTML = '<div class="nvoos-chat-empty">' +
			Sse.escapeHtml(i18n.empty) + '</div>';
		costEl.textContent = '';
	}

	// ─── Send + stream ───────────────────────────────────────────────
	function sendMessage(text) {
		history.push({ role: 'user', content: text });
		saveHistory();
		appendUserBubble(text);

		currentThinkingEl = appendAssistantBubble(i18n.thinking, true);
		currentAssembled = '';
		currentReceivedContent = false;
		currentErrorShown = false;
		currentFinalMeta = null;
		streamedToolIds = {};
		currentTurnCards = [];

		setStreaming(true);
		clearDebug();
		turnStart = performance.now();

		var body = buildRequestBody();
		debug('POST /ai/chat', JSON.stringify(body));

		controller = new AbortController();

		fetch(cfg.restUrl + '/ai/chat', {
			method: 'POST',
			signal: controller.signal,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			body: JSON.stringify(body),
		})
			.then(function (res) {
				if (!res.ok) {
					return res.json()
						.catch(function () {
							return null;
						})
						.then(function (json) {
							var message = (json && json.message) ? json.message : 'HTTP ' + res.status;
							throw new Error(message);
						});
				}
				return streamResponse(res);
			})
			.catch(function (err) {
				if (err && err.name === 'AbortError') {
					finalizeAborted();
					return;
				}
				debug('chat error', String(err && err.message ? err.message : err));
				renderError(String(err && err.message ? err.message : err), false);
			})
			.finally(function () {
				setStreaming(false);
			});
	}

	function buildRequestBody() {
		var body = {
			messages: outgoingMessages(),
			stream: true,
			system_prompt: systemPromptChk.checked,
		};

		if (contextChk && !contextChk.disabled && contextChk.checked) {
			body.include_context = true;
		}

		if (providerSel.value) {
			body.provider = providerSel.value;
		}
		if (modelInput.value.trim()) {
			body.model = modelInput.value.trim();
		}

		var preset = findPreset(toolsSel.value);
		if (preset && Array.isArray(preset.tools) && preset.tools.length > 0) {
			body.tools = preset.tools;
		}

		return body;
	}

	/**
	 * Outgoing payload — user/assistant turns only (tool entries are
	 * tester-side display artifacts, not part of the model contract).
	 *
	 * @return {Array<{role: string, content: string}>}
	 */
	function outgoingMessages() {
		var out = [];
		history.forEach(function (m) {
			if (m.role === 'user' || m.role === 'assistant') {
				out.push({ role: m.role, content: m.content });
			}
		});
		return out.slice(-40);
	}

	function streamResponse(response) {
		var reader = response.body.getReader();
		var decoder = new TextDecoder();
		var buffer = '';

		function pump() {
			return reader.read().then(function (result) {
				if (result.done) {
					buffer += decoder.decode();
					var tail = Sse.parseSseBuffer(buffer);
					tail.frames.forEach(handleFrame);
					buffer = '';
					finishTurn();
					return;
				}

				buffer += decoder.decode(result.value, { stream: true });
				var parsed = Sse.parseSseBuffer(buffer);
				buffer = parsed.rest;
				parsed.frames.forEach(handleFrame);
				return pump();
			});
		}

		return pump();
	}

	// ─── Frame routing ───────────────────────────────────────────────
	function handleFrame(frame) {
		if (!frame || typeof frame !== 'object') {
			return;
		}

		debug(
			'frame',
			'+' + Math.round(performance.now() - turnStart) + 'ms',
			JSON.stringify(frame)
		);

		// Error / rejected.
		if (frame.type === 'error' || (frame.code && frame.message)) {
			renderError(frame.message || frame.code || i18n.error, false);
			return;
		}
		if (frame.type === 'rejected') {
			renderError(i18n.rejected, true);
			return;
		}

		// Status frames.
		if (frame.type === 'thinking' || frame.type === 'generating') {
			if (currentThinkingEl && !currentReceivedContent && frame.message) {
				var statusContent = currentThinkingEl.querySelector('.nvoos-chat-msg__content');
				if (statusContent && statusContent.dataset.raw !== '1') {
					// textContent — no entity escaping needed.
					statusContent.textContent = frame.message + '…';
				}
			}
			return;
		}
		if (frame.type === 'max_iterations') {
			return;
		}

		// Tool execution.
		if (frame.type === 'start' && Array.isArray(frame.tools)) {
			return;
		}
		if (frame.type === 'tool_start') {
			var startedName = frame.tool_name || frame.name || 'tool';
			var startedId = frame.tool_id || frame.id || '';
			markToolStreamed(startedId, startedName);
			addToolCard(startedName, null, startedId);
			return;
		}
		if (frame.type === 'tool_result') {
			var resultName = frame.tool_name || frame.name || '';
			markToolStreamed(frame.tool_id || frame.id || '', resultName);
			var result = frame.result;
			if (typeof result === 'string') {
				try {
					var parsed = JSON.parse(result);
					if (parsed && typeof parsed === 'object') {
						result = parsed;
					}
				} catch (e) {
					// Keep the raw string.
				}
			}
			updateToolCard(resultName, result);
			return;
		}

		// Token deltas.
		var token = Sse.extractDelta(frame);
		if (token) {
			appendToken(token);
			return;
		}
		if (frame.type === 'message_delta' && typeof frame.delta === 'string') {
			appendToken(frame.delta);
			return;
		}

		// Final frame — authoritative content, tool results, cost.
		if (frame.data) {
			currentFinalMeta = {
				cost: frame.cost || null,
				tool_results: frame.tool_results || [],
			};

			var finalContent = Sse.extractFinalContent(frame);
			if (finalContent !== null && finalContent !== '') {
				if (!currentReceivedContent) {
					onFirstContent();
				}
				currentAssembled = finalContent;
				renderStreamingContent(currentAssembled);
			}

			if (Array.isArray(frame.tool_results)) {
				frame.tool_results.forEach(function (tr) {
					// Skip tool invocations already streamed live as
					// tool_start/tool_result frames (mirrors SPA-v2's
					// emittedIds deduplication).
					if (isToolStreamed(tr)) {
						return;
					}
					addToolCard(tr.name || 'tool', tr.content, tr.tool_call_id || '');
				});
			}
			return;
		}
	}

	function appendToken(token) {
		if (!currentReceivedContent) {
			onFirstContent();
		}
		currentAssembled += token;
		renderStreamingContent(currentAssembled);
	}

	function onFirstContent() {
		currentReceivedContent = true;
		if (!currentThinkingEl) {
			return;
		}
		currentThinkingEl.classList.remove('nvoos-chat-thinking');
		var content = currentThinkingEl.querySelector('.nvoos-chat-msg__content');
		if (content) {
			content.textContent = '';
			content.dataset.raw = '0';
		}
	}

	function renderStreamingContent(text) {
		if (!currentThinkingEl) {
			return;
		}
		var content = currentThinkingEl.querySelector('.nvoos-chat-msg__content');
		if (!content || content.dataset.raw === '1') {
			return;
		}
		content.innerHTML = Sse.renderMarkdownLite(text);
		scrollToBottom();
	}

	// ─── Turn finalisation ───────────────────────────────────────────
	function finishTurn() {
		if (!currentThinkingEl) {
			return;
		}

		currentThinkingEl.classList.remove('nvoos-chat-thinking');

		if (!currentReceivedContent && !currentErrorShown) {
			renderError(i18n.noResponse, true);
			return;
		}
		if (currentErrorShown) {
			return;
		}

		var meta = {
			cost: (currentFinalMeta && currentFinalMeta.cost) || null,
		};
		var summary = Sse.summarizeCost(meta.cost);
		meta.provider = (summary && summary.provider) || providerSel.value || '';
		meta.model = (summary && summary.model) || modelInput.value.trim() || '';

		history.push({ role: 'assistant', content: currentAssembled, meta: meta });
		saveHistory();

		attachActions(currentThinkingEl, currentAssembled);
		attachMeta(currentThinkingEl, meta);

		if (meta.cost) {
			showCost(meta.cost);
		}
	}

	function finalizeAborted() {
		if (!currentThinkingEl) {
			return;
		}
		currentThinkingEl.classList.remove('nvoos-chat-thinking');
		var content = currentThinkingEl.querySelector('.nvoos-chat-msg__content');

		if (currentReceivedContent) {
			history.push({
				role: 'assistant',
				content: currentAssembled,
				meta: { stopped: true },
			});
			saveHistory();
			attachActions(currentThinkingEl, currentAssembled);
			attachMeta(currentThinkingEl, { stopped: true });
		} else if (content) {
			content.textContent = i18n.stopped;
			content.classList.add('nvoos-chat-notice');
		}
	}

	function renderError(message, isNotice) {
		currentErrorShown = true;
		if (!currentThinkingEl) {
			return;
		}
		currentThinkingEl.classList.remove('nvoos-chat-thinking');
		var content = currentThinkingEl.querySelector('.nvoos-chat-msg__content');
		if (!content) {
			return;
		}

		content.textContent = '';
		var err = document.createElement('div');
		err.className = isNotice ? 'nvoos-chat-notice' : 'nvoos-chat-error';
		err.textContent = message;
		content.appendChild(err);

		if (!isNotice) {
			var retry = document.createElement('button');
			retry.type = 'button';
			retry.className = 'button-link nvoos-chat-retry';
			retry.textContent = i18n.retry;
			retry.addEventListener('click', function () {
				sendMessage(lastUserMessage());
			});
			content.appendChild(retry);
		}
	}

	/**
	 * Text of the most recent user message (for Retry).
	 *
	 * @return {string}
	 */
	function lastUserMessage() {
		for (var i = history.length - 1; i >= 0; i--) {
			if (history[i].role === 'user') {
				return history[i].content;
			}
		}
		return '';
	}

	// ─── Rendering ───────────────────────────────────────────────────
	function removeEmptyState() {
		var empty = messages.querySelector('.nvoos-chat-empty');
		if (empty) {
			empty.remove();
		}
	}

	function appendUserBubble(text) {
		removeEmptyState();
		var el = document.createElement('div');
		el.className = 'nvoos-chat-msg nvoos-chat-msg--user';
		el.innerHTML = '<div class="nvoos-chat-msg__content">' +
			Sse.escapeHtml(text) + '</div>';
		messages.appendChild(el);
		scrollToBottom();
	}

	function appendAssistantBubble(text, isThinking) {
		removeEmptyState();
		var el = document.createElement('div');
		el.className = 'nvoos-chat-msg nvoos-chat-msg--assistant';
		if (isThinking) {
			el.classList.add('nvoos-chat-thinking');
		}
		var content = document.createElement('div');
		content.className = 'nvoos-chat-msg__content';
		content.dataset.raw = '0';
		content.textContent = isThinking ? text : '';
		if (!isThinking) {
			content.innerHTML = Sse.renderMarkdownLite(text);
		}
		el.appendChild(content);
		messages.appendChild(el);
		scrollToBottom();
		return el;
	}

	function attachActions(bubbleEl, rawText) {
		var actions = document.createElement('div');
		actions.className = 'nvoos-chat-msg__actions';

		var copyBtn = makeActionButton(i18n.copy, function () {
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(rawText).then(function () {
					copyBtn.textContent = i18n.copied;
					setTimeout(function () {
						copyBtn.textContent = i18n.copy;
					}, 1200);
				}).catch(function () {
					// Clipboard unavailable — ignore.
				});
			}
		});

		var rawBtn = makeActionButton(i18n.raw, function () {
			var content = bubbleEl.querySelector('.nvoos-chat-msg__content');
			if (!content) {
				return;
			}
			if (content.dataset.raw === '1') {
				content.innerHTML = Sse.renderMarkdownLite(rawText);
				content.dataset.raw = '0';
				rawBtn.textContent = i18n.raw;
			} else {
				content.textContent = rawText;
				content.dataset.raw = '1';
				rawBtn.textContent = i18n.rendered;
			}
		});

		actions.appendChild(copyBtn);
		actions.appendChild(rawBtn);
		bubbleEl.appendChild(actions);
	}

	function makeActionButton(label, onClick) {
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'nvoos-chat-msg__action';
		btn.textContent = label;
		btn.addEventListener('click', onClick);
		return btn;
	}

	function attachMeta(bubbleEl, meta) {
		if (!meta) {
			return;
		}
		var parts = [];

		if (meta.stopped) {
			parts.push(i18n.stopped);
		}
		if (meta.provider) {
			parts.push(meta.provider);
		}
		if (meta.model) {
			parts.push(meta.model);
		}

		var summary = Sse.summarizeCost(meta.cost);
		if (summary) {
			if (summary.usd !== null) {
				parts.push((summary.estimated ? '~' : '') + '$' + summary.usd.toFixed(6));
			}
			var tokens = (summary.promptTokens || 0) + (summary.completionTokens || 0);
			if (tokens > 0) {
				parts.push(tokens + ' tokens');
			}
			if (summary.iterations !== null && summary.iterations > 0) {
				parts.push(summary.iterations + ' iter');
			}
		}

		if (parts.length === 0) {
			return;
		}

		var metaEl = document.createElement('div');
		metaEl.className = 'nvoos-chat-msg__meta';
		metaEl.textContent = parts.join(' · ');
		bubbleEl.appendChild(metaEl);
	}

	function showCost(cost) {
		var summary = Sse.summarizeCost(cost);
		if (!summary) {
			costEl.textContent = '';
			return;
		}
		var parts = [];
		if (summary.usd !== null) {
			parts.push((summary.estimated ? '~' : '') + '$' + summary.usd.toFixed(6));
		}
		var tokens = (summary.promptTokens || 0) + (summary.completionTokens || 0);
		if (tokens > 0) {
			parts.push(tokens + ' tokens');
		}
		if (parts.length === 0) {
			costEl.textContent = '';
			return;
		}
		costEl.textContent = i18n.cost + ': ' + parts.join(' · ');
	}

	// ─── Tool cards ───────────────────────────────────────────────
	function markToolStreamed(toolId, name) {
		if (toolId) {
			streamedToolIds[toolId] = true;
		} else if (name) {
			streamedToolIds['name:' + name] = true;
		}
	}

	function isToolStreamed(entry) {
		if (entry.tool_call_id && streamedToolIds[entry.tool_call_id]) {
			return true;
		}
		if (entry.name && streamedToolIds['name:' + entry.name]) {
			return true;
		}
		return false;
	}

	function addToolCard(name, result, toolId) {
		removeEmptyState();

		var el = document.createElement('div');
		el.className = 'nvoos-chat-tool-result';
		el.dataset.toolName = name || 'tool';
		if (toolId) {
			el.dataset.toolId = toolId;
		}

		el.innerHTML =
			'<details class="nvoos-chat-tool-details">' +
			'<summary class="nvoos-chat-tool-summary">' +
			Sse.escapeHtml(i18n.toolsUsed) + ': <code>' +
			Sse.escapeHtml(name || 'tool') + '</code>' +
			'</summary>' +
			'<pre class="nvoos-chat-tool-body"></pre>' +
			'</details>';

		var body = el.querySelector('.nvoos-chat-tool-body');
		if (result !== null && result !== undefined) {
			body.textContent = stringifyResult(result);
		} else {
			body.textContent = '…';
		}

		currentTurnCards.push(el);
		messages.appendChild(el);
		scrollToBottom();
		return el;
	}

	function updateToolCard(name, result) {
		// Only match cards opened during the current turn.
		for (var i = currentTurnCards.length - 1; i >= 0; i--) {
			if (currentTurnCards[i].dataset.toolName === (name || 'tool')) {
				var body = currentTurnCards[i].querySelector('.nvoos-chat-tool-body');
				if (body && body.textContent === '…') {
					body.textContent = stringifyResult(result);
					return;
				}
			}
		}
		// No matching open card — create one.
		addToolCard(name, result, '');
	}

	function stringifyResult(result) {
		if (typeof result === 'string') {
			return result;
		}
		try {
			return JSON.stringify(result, null, 2);
		} catch (e) {
			return String(result);
		}
	}

	// ─── Persistence ─────────────────────────────────────────────────
	function saveHistory() {
		try {
			sessionStorage.setItem(
				STORAGE_KEY,
				JSON.stringify(history.map(function (m) {
					return {
						role: m.role,
						content: m.content,
						meta: m.meta || null,
					};
				}))
			);
		} catch (e) {
			// Storage unavailable — non-fatal.
		}
	}

	function restoreHistory() {
		try {
			var raw = sessionStorage.getItem(STORAGE_KEY);
			if (!raw) {
				return;
			}
			var stored = JSON.parse(raw);
			if (!Array.isArray(stored)) {
				return;
			}
			stored.forEach(function (m) {
				if (m.role === 'user') {
					appendUserBubble(m.content);
				} else if (m.role === 'assistant') {
					var el = appendAssistantBubble(m.content, false);
					attachActions(el, m.content);
					if (m.meta) {
						attachMeta(el, m.meta);
					}
				}
				history.push(m);
			});
		} catch (e) {
			try {
				sessionStorage.removeItem(STORAGE_KEY);
			} catch (e2) {
				// Ignore.
			}
		}
	}

	function loadPrefs() {
		try {
			prefs = JSON.parse(sessionStorage.getItem(PREFS_KEY)) || {};
		} catch (e) {
			prefs = {};
		}
	}

	function savePrefs() {
		prefs.provider = providerSel.value;
		prefs.model = modelInput.value.trim();
		prefs.tools = toolsSel.value;
		try {
			sessionStorage.setItem(PREFS_KEY, JSON.stringify(prefs));
		} catch (e) {
			// Ignore.
		}
	}

	// ─── Debug log ───────────────────────────────────────────────────
	function clearDebug() {
		debugLog.textContent = '';
	}

	function debug() {
		var parts = Array.prototype.slice.call(arguments);
		var now = performance.now();
		var prefix;
		if (debugLog.textContent === '') {
			debugLog.dataset.t0 = String(now);
			prefix = '+0ms';
		} else {
			prefix = '+' + Math.round(now - Number(debugLog.dataset.t0 || now)) + 'ms';
		}
		debugLog.textContent += '[' + prefix + '] ' + parts.join(' ') + '\n';
	}

	// ─── Helpers ─────────────────────────────────────────────────────
	function setStreaming(on) {
		streaming = on;
		sendBtn.disabled = on;
		stopBtn.disabled = !on;
		input.disabled = on;
		if (!on) {
			controller = null;
			input.focus();
		}
	}

	function scrollToBottom() {
		messages.scrollTop = messages.scrollHeight;
	}

	function hasOption(select, value) {
		for (var i = 0; i < select.options.length; i++) {
			if (select.options[i].value === value) {
				return true;
			}
		}
		return false;
	}

	// ─── Bootstrap ───────────────────────────────────────────────────
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
