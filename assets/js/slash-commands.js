/**
 * Slash Commands Integration for Chat Interface
 *
 * Detects slash commands in chat input and provides execution via AJAX.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

(function() {
	'use strict';

	/**
	 * Slash Commands Handler
	 */
	class SlashCommandsHandler {
		constructor() {
			this.initialized = false;
			this.autocomplete = null;
			this.commandCache = null;
			this.cacheExpiry = 0;
		}

		/**
		 * Initialize slash commands
		 */
		init() {
			if (this.initialized) {
				return;
			}

			// Find chat input elements
			this.chatInput = document.querySelector('.mcp-chat-input, #mcp-chat-input, textarea[name="message"]');
			if (!this.chatInput) {
				console.warn('[SlashCommands] Chat input not found');
				return;
			}

			this.chatForm = this.chatInput.closest('form');
			if (!this.chatForm) {
				console.warn('[SlashCommands] Chat form not found');
				return;
			}

			// Attach event listeners
			this.attachListeners();

			// Initialize autocomplete if available
			if (window.CommandAutocomplete) {
				this.autocomplete = new window.CommandAutocomplete(this.chatInput);
				this.autocomplete.init();
			}

			this.initialized = true;
			console.log('[SlashCommands] Initialized');
		}

		/**
		 * Attach event listeners
		 */
		attachListeners() {
			// Intercept form submission
			this.chatForm.addEventListener('submit', this.handleSubmit.bind(this), true);

			// Monitor input for slash prefix
			this.chatInput.addEventListener('input', this.handleInput.bind(this));
			this.chatInput.addEventListener('keydown', this.handleKeyDown.bind(this));
		}

		/**
		 * Handle input changes
		 */
		handleInput(e) {
			const value = e.target.value.trim();

			// Check if starts with slash
			if (value.startsWith('/') && this.autocomplete) {
				this.autocomplete.show(value);
			} else if (this.autocomplete) {
				this.autocomplete.hide();
			}
		}

		/**
		 * Handle keydown events
		 */
		handleKeyDown(e) {
			// If autocomplete is showing, let it handle navigation
			if (this.autocomplete && this.autocomplete.isVisible()) {
				if (['ArrowUp', 'ArrowDown', 'Enter', 'Tab', 'Escape'].includes(e.key)) {
					const handled = this.autocomplete.handleKeyDown(e);
					if (handled) {
						e.preventDefault();
						e.stopPropagation();
					}
				}
			}
		}

		/**
		 * Handle form submission
		 */
		handleSubmit(e) {
			const value = this.chatInput.value.trim();

			// Check if it's a slash command
			if (!value.startsWith('/')) {
				return; // Let normal chat handling proceed
			}

			// Prevent default form submission
			e.preventDefault();
			e.stopPropagation();

			// Execute slash command
			this.executeCommand(value);
		}

		/**
		 * Execute slash command
		 */
		async executeCommand(command) {
			console.log('[SlashCommands] Executing:', command);

			// Show loading state
			this.setLoading(true);

			try {
				const response = await this.sendCommand(command);

				if (response.success) {
					this.displayResult(response.result, command);
				} else {
					this.displayError(response.message || 'Command execution failed');
				}
			} catch (error) {
				console.error('[SlashCommands] Error:', error);
				this.displayError(error.message || 'Failed to execute command');
			} finally {
				this.setLoading(false);
				this.chatInput.value = '';
			}
		}

		/**
		 * Send command to REST API
		 */
		async sendCommand(command) {
			const endpoint = window.mcpAiData?.restUrl + '/mcp-ai/v1/slash-command';
			const nonce = window.mcpAiData?.nonce;

			const response = await fetch(endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				},
				body: JSON.stringify({ command })
			});

			if (!response.ok) {
				const error = await response.json();
				throw new Error(error.message || 'API request failed');
			}

			return await response.json();
		}

		/**
		 * Display command result in chat
		 */
		displayResult(result, command) {
			const chatMessages = document.querySelector('.mcp-chat-messages, #mcp-chat-messages');
			if (!chatMessages) {
				console.warn('[SlashCommands] Chat messages container not found');
				return;
			}

			// Create message elements
			const userMessage = this.createMessage('user', command);
			const assistantMessage = this.createMessage('assistant', result);

			// Append to chat
			chatMessages.appendChild(userMessage);
			chatMessages.appendChild(assistantMessage);

			// Scroll to bottom
			chatMessages.scrollTop = chatMessages.scrollHeight;
		}

		/**
		 * Display error message
		 */
		displayError(message) {
			const chatMessages = document.querySelector('.mcp-chat-messages, #mcp-chat-messages');
			if (!chatMessages) {
				alert('Error: ' + message);
				return;
			}

			const errorMessage = this.createMessage('error', '❌ ' + message);
			chatMessages.appendChild(errorMessage);
			chatMessages.scrollTop = chatMessages.scrollHeight;
		}

		/**
		 * Create message element
		 */
		createMessage(type, content) {
			const messageDiv = document.createElement('div');
			messageDiv.className = `mcp-chat-message mcp-chat-message-${type}`;

			const contentDiv = document.createElement('div');
			contentDiv.className = 'mcp-chat-message-content';

			// Format content (support markdown if available)
			if (typeof marked !== 'undefined' && type === 'assistant') {
				contentDiv.innerHTML = marked.parse(content);
			} else {
				// Simple formatting
				contentDiv.textContent = content;
				contentDiv.innerHTML = contentDiv.innerHTML.replace(/\n/g, '<br>');
			}

			messageDiv.appendChild(contentDiv);
			return messageDiv;
		}

		/**
		 * Set loading state
		 */
		setLoading(loading) {
			this.chatInput.disabled = loading;

			const submitBtn = this.chatForm.querySelector('button[type="submit"]');
			if (submitBtn) {
				submitBtn.disabled = loading;
				submitBtn.textContent = loading ? 'Executing...' : 'Send';
			}
		}

		/**
		 * Fetch available commands
		 */
		async fetchCommands() {
			// Check cache
			if (this.commandCache && Date.now() < this.cacheExpiry) {
				return this.commandCache;
			}

			try {
				const endpoint = window.mcpAiData?.restUrl + '/mcp-ai/v1/slash-command/list';
				const nonce = window.mcpAiData?.nonce;

				const response = await fetch(endpoint, {
					headers: {
						'X-WP-Nonce': nonce
					}
				});

				if (response.ok) {
					const data = await response.json();
					this.commandCache = data.commands || [];
					this.cacheExpiry = Date.now() + (5 * 60 * 1000); // 5 minutes
					return this.commandCache;
				}
			} catch (error) {
				console.error('[SlashCommands] Failed to fetch commands:', error);
			}

			return [];
		}
	}

	// Initialize when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() {
			window.slashCommands = new SlashCommandsHandler();
			window.slashCommands.init();
		});
	} else {
		window.slashCommands = new SlashCommandsHandler();
		window.slashCommands.init();
	}

	// Export for external use
	window.SlashCommandsHandler = SlashCommandsHandler;

})();
