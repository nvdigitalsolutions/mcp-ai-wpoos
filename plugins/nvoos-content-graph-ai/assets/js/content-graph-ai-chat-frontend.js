/**
 * NV oOS Content Graph AI — Frontend chat widget.
 *
 * Lean, framework-free chat widget for the [nvoos_content_graph_chat]
 * shortcode. Speaks the same REST + SSE contract as the admin Chat
 * Tester and the Pro SPA-v2: POST /nvoos-content-graph/v1/ai/chat with
 * stream=true and parses the shared SSE frame protocol via the
 * `NvoosContentGraphAiSse` module (content-graph-ai-sse.js).
 *
 * Config arrives via `window.NvoosContentGraphChat` (array of config
 * objects pushed by the shortcode inline script, one per widget).
 * Transcripts persist per-widget in sessionStorage.
 *
 * Security: all text is rendered through the shared escape + markdown
 * pipeline; no raw user/AI content is inserted into the DOM.
 *
 * @since 1.1.0
 */
(function (root) {
	'use strict';

	const sse = root.NvoosContentGraphAiSse;

	function ChatWidget(config) {
		this.config = config || {};
		this.messages = [];
		this.streaming = false;
		this.container = document.getElementById(this.config.container);
	}

	ChatWidget.prototype.init = function () {
		if (!this.container) {
			return;
		}
		this.buildDom();
		this.restoreHistory();
		this.bindEvents();
	};

	ChatWidget.prototype.buildDom = function () {
		const i18n = this.config.i18n || {};

		this.container.classList.add('nvoos-cg-chat');
		this.container.innerHTML =
			'<div class="nvoos-cg-chat__messages" role="log" aria-live="polite"></div>' +
			'<div class="nvoos-cg-chat__toolbar">' +
			'<span class="nvoos-cg-chat__cost" hidden></span>' +
			'<button type="button" class="nvoos-cg-chat__clear">' +
			sse.escapeHtml(i18n.clear || 'Clear') +
			'</button>' +
			'</div>' +
			'<div class="nvoos-cg-chat__input-row">' +
			'<textarea class="nvoos-cg-chat__input" rows="1" placeholder="' +
			sse.escapeHtml(this.config.placeholder || '') +
			'"></textarea>' +
			'<button type="button" class="nvoos-cg-chat__send">' +
			sse.escapeHtml(i18n.send || 'Send') +
			'</button>' +
			'</div>';

		this.messageList = this.container.querySelector('.nvoos-cg-chat__messages');
		this.input = this.container.querySelector('.nvoos-cg-chat__input');
		this.sendButton = this.container.querySelector('.nvoos-cg-chat__send');
		this.clearButton = this.container.querySelector('.nvoos-cg-chat__clear');
		this.costBadge = this.container.querySelector('.nvoos-cg-chat__cost');
	};

	ChatWidget.prototype.storageKey = function () {
		return 'nvoos_cg_chat_' + this.config.container;
	};

	ChatWidget.prototype.restoreHistory = function () {
		let i;
		try {
			const raw = root.sessionStorage.getItem(this.storageKey());
			this.messages = raw ? JSON.parse(raw) : [];
		} catch (e) {
			this.messages = [];
		}
		if (!Array.isArray(this.messages)) {
			this.messages = [];
		}
		for (i = 0; i < this.messages.length; i++) {
			this.addMessage(this.messages[i].role, this.messages[i].content);
		}
	};

	ChatWidget.prototype.saveHistory = function () {
		try {
			root.sessionStorage.setItem(this.storageKey(), JSON.stringify(this.messages));
		} catch (e) {
			// Storage may be unavailable (private mode) — degrade silently.
		}
	};

	ChatWidget.prototype.bindEvents = function () {
		const self = this;

		this.sendButton.addEventListener('click', function () {
			self.sendMessage();
		});

		this.input.addEventListener('keydown', function (event) {
			if (event.key === 'Enter' && !event.shiftKey) {
				event.preventDefault();
				self.sendMessage();
			}
		});

		this.clearButton.addEventListener('click', function () {
			self.clearChat();
		});
	};

	ChatWidget.prototype.addMessage = function (role, content) {
		const wrapper = document.createElement('div');
		wrapper.className = 'nvoos-cg-chat__message nvoos-cg-chat__message--' + role;

		const html = sse.renderMarkdownLite(content);
		if (html !== '') {
			wrapper.innerHTML = html;
		} else {
			wrapper.textContent = content;
		}

		this.messageList.appendChild(wrapper);
		this.scrollToBottom();
		return wrapper;
	};

	ChatWidget.prototype.addToolCard = function (name, result) {
		const i18n = this.config.i18n || {};
		const card = document.createElement('div');
		card.className = 'nvoos-cg-chat__tool';

		let summary = '';
		if (result && typeof result === 'object') {
			summary = JSON.stringify(result);
		} else if (result != null) {
			summary = String(result);
		}
		if (summary.length > 240) {
			summary = summary.slice(0, 240) + '…';
		}

		card.innerHTML =
			'<div class="nvoos-cg-chat__tool-title">' +
			sse.escapeHtml(i18n.graphQuery || 'Queried your knowledge graph') +
			'</div>' +
			'<div class="nvoos-cg-chat__tool-name">' +
			sse.escapeHtml(name || '') +
			'</div>' +
			(summary
				? '<div class="nvoos-cg-chat__tool-summary">' + sse.escapeHtml(summary) + '</div>'
				: '');

		this.messageList.appendChild(card);
		this.scrollToBottom();
		return card;
	};

	ChatWidget.prototype.showThinking = function () {
		const i18n = this.config.i18n || {};
		this.thinkingEl = document.createElement('div');
		this.thinkingEl.className = 'nvoos-cg-chat__message nvoos-cg-chat__message--assistant nvoos-cg-chat__thinking';
		this.thinkingEl.textContent = i18n.thinking || 'Thinking…';
		this.messageList.appendChild(this.thinkingEl);
		this.scrollToBottom();
	};

	ChatWidget.prototype.removeThinking = function () {
		if (this.thinkingEl && this.thinkingEl.parentNode) {
			this.thinkingEl.parentNode.removeChild(this.thinkingEl);
			this.thinkingEl = null;
		}
	};

	ChatWidget.prototype.showError = function (message) {
		const i18n = this.config.i18n || {};
		const banner = document.createElement('div');
		banner.className = 'nvoos-cg-chat__error';
		banner.textContent = message || i18n.error || 'Something went wrong.';
		this.messageList.appendChild(banner);
		this.scrollToBottom();
	};

	ChatWidget.prototype.updateCost = function (costSummary) {
		const i18n = this.config.i18n || {};
		if (!this.config.showCost || !costSummary || costSummary.usd === null) {
			this.costBadge.hidden = true;
			return;
		}
		let label = (i18n.cost || 'Cost') + ': $' + costSummary.usd.toFixed(4);
		if (costSummary.estimated) {
			label += ' ~';
		}
		this.costBadge.textContent = label;
		this.costBadge.hidden = false;
	};

	ChatWidget.prototype.sendMessage = function () {
		if (this.streaming) {
			return;
		}

		const text = this.input.value.trim();
		if (text === '') {
			return;
		}

		this.input.value = '';
		this.messages.push({ role: 'user', content: text });
		this.addMessage('user', text);
		this.saveHistory();

		const apiMessages = this.messages.slice();
		const body = {
			messages: apiMessages,
			stream: true,
		};
		if (this.config.provider) {
			body.provider = this.config.provider;
		}
		if (this.config.model) {
			body.model = this.config.model;
		}

		const headers = { 'Content-Type': 'application/json' };
		if (this.config.nonce) {
			headers['X-WP-Nonce'] = this.config.nonce;
		}
		if (this.config.guestToken) {
			headers['X-WP-MCP-AI-Guest'] = this.config.guestToken;
		}

		this.streaming = true;
		this.sendButton.disabled = true;
		this.showThinking();

		this.callChatApi(body, headers);
	};

	ChatWidget.prototype.callChatApi = function (body, headers) {
		const self = this;
		const i18n = this.config.i18n || {};

		root
			.fetch(this.config.restUrl + '/ai/chat', {
				method: 'POST',
				headers: headers,
				body: JSON.stringify(body),
			})
			.then(function (response) {
				if (!response.ok) {
					return response
						.json()
						.catch(function () {
							return { message: i18n.error || 'Something went wrong.' };
						})
						.then(function (err) {
							throw new Error(err.message || (i18n.error || 'Something went wrong.'));
						});
				}
				return self.consumeStream(response);
			})
			.then(function () {
				self.finishTurn();
			})
			.catch(function (err) {
				self.removeThinking();
				self.showError(err && err.message ? err.message : i18n.error || 'Something went wrong.');
				self.finishTurn();
			});
	};

	ChatWidget.prototype.consumeStream = function (response) {
		const self = this;
		const reader = response.body.getReader();
		const decoder = new TextDecoder();
		let buffer = '';
		const pendingTools = {};

		return reader.read().then(function processChunk(result) {
			if (result.done) {
				return;
			}

			buffer += decoder.decode(result.value, { stream: true });
			const parsed = sse.parseSseBuffer(buffer);
			buffer = parsed.rest;

			parsed.frames.forEach(function (frame) {
				self.routeFrame(frame, pendingTools);
			});

			return reader.read().then(processChunk);
		});
	};

	ChatWidget.prototype.routeFrame = function (frame, pendingTools) {
		if (!frame || typeof frame !== 'object') {
			return;
		}

		switch (frame.type) {
			case 'delta':
			case 'text':
			case 'message_delta': {
				const token = sse.extractDelta(frame);
				if (token !== '') {
					if (!this.assistantEl || !this.assistantEl.parentNode) {
						this.removeThinking();
						this.assistantEl = this.addMessage('assistant', '');
						this.assistantEl.classList.add('nvoos-cg-chat__streaming');
					}
					this.assistantEl.textContent += token;
					this.scrollToBottom();
				}
				break;
			}

			case 'tool_start': {
				const id = frame.id || frame.tool_call_id || '';
				const toolCard = this.addToolCard(frame.name || frame.tool || '', null);
				pendingTools[id] = { card: toolCard, name: frame.name || '' };
				break;
			}

			case 'tool_result': {
				const toolId = frame.id || frame.tool_call_id || '';
				const pending = pendingTools[toolId];
				if (pending) {
					let summary = '';
					const result = frame.result;
					if (result && typeof result === 'object') {
						summary = JSON.stringify(result);
					} else if (result != null) {
						summary = String(result);
					}
					if (summary.length > 240) {
						summary = summary.slice(0, 240) + '…';
					}
					let summaryEl = pending.card.querySelector('.nvoos-cg-chat__tool-summary');
					if (!summaryEl) {
						summaryEl = document.createElement('div');
						summaryEl.className = 'nvoos-cg-chat__tool-summary';
						pending.card.appendChild(summaryEl);
					}
					summaryEl.textContent = summary;
				}
				break;
			}

			case 'done': {
				const cost = sse.summarizeCost(frame.cost || frame.data || null);
				this.updateCost(cost);
				if (this.assistantEl) {
					this.assistantEl.classList.remove('nvoos-cg-chat__streaming');
				}
				break;
			}

			case 'error': {
				this.removeThinking();
				this.showError(
					frame.message || frame.error || this.config.i18n.error || 'Something went wrong.'
				);
				break;
			}

			default:
				// Ignore unknown frame types (forward compatibility).
				break;
		}
	};

	ChatWidget.prototype.finishTurn = function () {
		let content = '';
		if (this.assistantEl) {
			content = this.assistantEl.textContent || '';
			this.assistantEl.classList.remove('nvoos-cg-chat__streaming');
		}
		this.removeThinking();

		if (content !== '') {
			this.messages.push({ role: 'assistant', content: content });
			this.saveHistory();
		}
		this.assistantEl = null;
		this.streaming = false;
		this.sendButton.disabled = false;
	};

	ChatWidget.prototype.scrollToBottom = function () {
		this.messageList.scrollTop = this.messageList.scrollHeight;
	};

	ChatWidget.prototype.clearChat = function () {
		this.messages = [];
		try {
			root.sessionStorage.removeItem(this.storageKey());
		} catch (e) {
			// Degrade silently when storage is unavailable.
		}
		this.messageList.innerHTML = '';
		this.costBadge.hidden = true;
		this.assistantEl = null;
	};

	// ─── Bootstrap ──────────────────────────────────────────────────

	function boot() {
		const configs = root.NvoosContentGraphChat || [];
		configs.forEach(function (config) {
			new ChatWidget(config).init();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})(typeof window !== 'undefined' ? window : this);
