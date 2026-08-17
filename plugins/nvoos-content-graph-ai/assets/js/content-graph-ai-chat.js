/**
 * NV oOS Content Graph AI — Admin Chat Interface
 *
 * SSE-powered chat tester for the AI addon. Talks to
 * POST /nvoos-content-graph/v1/ai/chat with stream=true.
 *
 * @since 1.0.0
 */
(function () {
	'use strict';

	var cfg = window.NvoosContentGraphAiChat;
	if (!cfg) return;

	// ─── DOM refs ────────────────────────────────────────────────────
	var messages = document.getElementById('nvoos-chat-messages');
	var input    = document.getElementById('nvoos-chat-input');
	var sendBtn  = document.getElementById('nvoos-chat-send');
	var clearBtn = document.getElementById('nvoos-chat-clear');
	var provider = document.getElementById('nvoos-chat-provider');
	var model    = document.getElementById('nvoos-chat-model');
	var costEl   = document.getElementById('nvoos-chat-cost');

	var isStreaming = false;

	// ─── Init ────────────────────────────────────────────────────────
	function init() {
		populateProviders();
		input.addEventListener('keydown', onKeydown);
		sendBtn.addEventListener('click', onSend);
		clearBtn.addEventListener('click', onClear);
	}

	// ─── Provider dropdown ───────────────────────────────────────────
	function populateProviders() {
		(cfg.providers || []).forEach(function (p) {
			var opt = document.createElement('option');
			opt.value = p.slug;
			opt.textContent = p.label;
			provider.appendChild(opt);
		});
	}

	// ─── Events ──────────────────────────────────────────────────────
	function onKeydown(e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			onSend();
		}
	}

	function onSend() {
		if (isStreaming) return;
		var text = input.value.trim();
		if (!text) return;
		input.value = '';
		input.style.height = '';
		sendMessage(text);
	}

	function onClear() {
		messages.innerHTML = '<div class="nvoos-chat-empty">' +
			cfg.i18n.placeholder + '</div>';
		costEl.textContent = '';
	}

	// ─── Core: send + stream ─────────────────────────────────────────
	function sendMessage(text) {
		var hist = buildHistory(text);

		// Add user bubble.
		appendMessage('user', text);

		// Add thinking indicator.
		var thinking = appendMessage('assistant', cfg.i18n.thinking, true);

		isStreaming = true;
		sendBtn.disabled = true;
		input.disabled = true;

		// Build request.
		var body = {
			messages: hist,
			stream: true
		};
		var selProvider = provider.value;
		if (selProvider) body.provider = selProvider;
		var selModel = model.value;
		if (selModel) body.model = selModel;

		fetch(cfg.restUrl + '/ai/chat', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify(body)
		})
		.then(function (res) {
			if (!res.ok) {
				throw new Error('HTTP ' + res.status);
			}
			return streamResponse(res, thinking);
		})
		.catch(function (err) {
			thinking.innerHTML = '<div class="nvoos-chat-error">' +
				cfg.i18n.error + '</div>';
			console.error('Chat error:', err);
		})
		.finally(function () {
			isStreaming = false;
			sendBtn.disabled = false;
			input.disabled = false;
			input.focus();
		});
	}

	// ─── SSE streaming parser ────────────────────────────────────────
	function streamResponse(response, thinkingEl) {
		var reader    = response.body.getReader();
		var decoder   = new TextDecoder();
		var buffer    = '';
		var assembled = '';
		var toolCalls = [];
		var finalPayload = null;

		function pump() {
			return reader.read().then(function (result) {
				if (result.done) return;

				buffer += decoder.decode(result.value, { stream: true });
				var lines = buffer.split('\n');
				// Keep the last (potentially incomplete) line.
				buffer = lines.pop();

				lines.forEach(function (line) {
					line = line.trim();
					if (!line) return;

					// SSE event name.
					if (line.indexOf('event: ') === 0) return;
					// Comments.
					if (line.indexOf(':') === 0 && line.indexOf('data:') !== 0) return;

					if (line.indexOf('data: ') !== 0) return;
					var data = line.substring(6);
					if (data === '[DONE]') return;

					try {
						var payload = JSON.parse(data);
						handleEvent(payload, thinkingEl);
					} catch (e) {
						// Non-JSON line — ignore.
					}
				});

				return pump();
			});
		}

		return pump().then(function () {
			// Remove thinking indicator if still present.
			if (thinkingEl.classList.contains('nvoos-chat-thinking')) {
				thinkingEl.remove();
			}
		});
	}

	// ─── SSE event handler ───────────────────────────────────────────
	function handleEvent(payload, thinkingEl) {
		// Status events.
		if (payload.type === 'thinking') {
			// Just a status update — the thinking indicator already shows.
			return;
		}
		if (payload.type === 'generating') {
			return;
		}
		if (payload.type === 'max_iterations') {
			return;
		}

		// Tool execution events.
		if (payload.type === 'start' && Array.isArray(payload.tools)) {
			return;
		}
		if (payload.type === 'tool_start') {
			return;
		}
		if (payload.type === 'tool_result') {
			appendToolResult(payload.tool_name, payload.result);
			return;
		}

		// Token delta from stream.
		if (payload.choices && payload.choices[0] && payload.choices[0].delta) {
			var token = payload.choices[0].delta.content || '';
			if (token) {
				if (thinkingEl.classList.contains('nvoos-chat-thinking')) {
					thinkingEl.classList.remove('nvoos-chat-thinking');
					thinkingEl.innerHTML = '';
				}
				thinkingEl.innerHTML += escapeHtml(token);
				messages.scrollTop = messages.scrollHeight;
			}
			return;
		}

		// Final message with full payload (data, tool_results, cost).
		if (payload.data && !payload.choices) {
			// Tool results — render any that weren't streamed.
			if (Array.isArray(payload.tool_results)) {
				payload.tool_results.forEach(function (tr) {
					appendToolResult(tr.name, tr.content);
				});
			}
			// Cost.
			if (payload.cost) {
				showCost(payload.cost);
			}
			return;
		}

		// Error event.
		if (payload.code && payload.message) {
			thinkingEl.innerHTML = '<div class="nvoos-chat-error">' +
				escapeHtml(payload.message) + '</div>';
			return;
		}
	}

	// ─── Message rendering ───────────────────────────────────────────
	function appendMessage(role, content, isThinking) {
		// Remove empty state.
		var empty = messages.querySelector('.nvoos-chat-empty');
		if (empty) empty.remove();

		var el = document.createElement('div');
		el.className = 'nvoos-chat-msg nvoos-chat-msg--' + role;
		if (isThinking) {
			el.classList.add('nvoos-chat-thinking');
		}
		el.innerHTML = '<div class="nvoos-chat-msg__content">' +
			escapeHtml(content) + '</div>';
		messages.appendChild(el);
		messages.scrollTop = messages.scrollHeight;
		return el.querySelector('.nvoos-chat-msg__content');
	}

	function appendToolResult(name, result) {
		var el = document.createElement('div');
		el.className = 'nvoos-chat-tool-result';
		var resultStr = typeof result === 'string' ? result : JSON.stringify(result, null, 2);
		el.innerHTML =
			'<details class="nvoos-chat-tool-details">' +
			'<summary class="nvoos-chat-tool-summary">' +
			cfg.i18n.toolsUsed + ': <code>' + escapeHtml(name || 'tool') + '</code>' +
			'</summary>' +
			'<pre class="nvoos-chat-tool-body">' + escapeHtml(resultStr) + '</pre>' +
			'</details>';
		messages.appendChild(el);
		messages.scrollTop = messages.scrollHeight;
	}

	function showCost(cost) {
		if (!cost) return;
		var parts = [];
		if (cost.total_cost != null) {
			parts.push('$' + Number(cost.total_cost).toFixed(6));
		}
		if (cost.total_tokens) {
			parts.push(cost.total_tokens + ' tokens');
		}
		costEl.textContent = cfg.i18n.cost + ': ' + parts.join(' | ');
	}

	// ─── Helpers ─────────────────────────────────────────────────────
	function buildHistory(newText) {
		// Build conversation from visible messages (simple: last 10 exchanges).
		var hist = [];
		var msgEls = messages.querySelectorAll('.nvoos-chat-msg');
		// Limit to last 20 message bubbles to stay within context window.
		var els = Array.from(msgEls).slice(-20);
		els.forEach(function (el) {
			var role = el.classList.contains('nvoos-chat-msg--user') ? 'user' : 'assistant';
			var content = el.querySelector('.nvoos-chat-msg__content');
			if (content) {
				hist.push({ role: role, content: content.textContent });
			}
		});
		// Add current message.
		hist.push({ role: 'user', content: newText });
		return hist;
	}

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	// ─── Bootstrap ───────────────────────────────────────────────────
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
