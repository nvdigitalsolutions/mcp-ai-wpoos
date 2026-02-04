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
			this.debugMode = window.wpMcpAiDebug || false;
		}

		/**
		 * Log debug message
		 */
		debug(...args) {
			if (this.debugMode && window.console && console.log) {
				console.log('[SlashCommands:DEBUG]', ...args);
			}
		}

		/**
		 * Initialize slash commands
		 */
		init() {
			if (this.initialized) {
				this.debug('Already initialized, skipping');
				return;
			}

			this.debug('Starting initialization...', {
				readyState: document.readyState,
				timestamp: new Date().toISOString()
			});

			// Check if mcpAiData is available
			if (!window.mcpAiData) {
				console.warn('[SlashCommands] mcpAiData not available - REST API calls may fail');
			} else {
				this.debug('mcpAiData available:', {
					hasRestUrl: !!window.mcpAiData.restUrl,
					hasNonce: !!window.mcpAiData.nonce
				});
			}

			// Find chat input elements - support multiple class name conventions
			this.chatInput = document.querySelector('.wp-mcp-ai-chat__input, .mcp-chat-input, #mcp-chat-input, textarea[name="message"]');
			if (!this.chatInput) {
				console.warn('[SlashCommands] Chat input not found - slash commands will not work');
				return;
			}

			this.debug('Chat input found:', this.chatInput.className);

			this.chatForm = this.chatInput.closest('form');
			if (!this.chatForm) {
				console.warn('[SlashCommands] Chat form not found - slash commands will not work');
				return;
			}

			this.debug('Chat form found:', this.chatForm.className);

			// Attach event listeners
			this.attachListeners();

			// Initialize autocomplete if available
			if (window.CommandAutocomplete) {
				this.autocomplete = new window.CommandAutocomplete(this.chatInput);
				this.autocomplete.init();
				this.debug('Autocomplete initialized');
			} else {
				this.debug('CommandAutocomplete not available');
			}

			this.initialized = true;
			console.log('[SlashCommands] ✅ Initialized successfully', {
				debugMode: this.debugMode,
				hasAutocomplete: !!this.autocomplete,
				timestamp: new Date().toISOString()
			});
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
			if (value.startsWith('/')) {
				this.debug('Slash command detected in input:', value.substring(0, 20));
				if (this.autocomplete) {
					this.autocomplete.show(value);
				}
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
				this.debug('Normal message detected (not a slash command), allowing default handling');
				return; // Let normal chat handling proceed
			}

			console.log('[SlashCommands] 🚀 Slash command detected on submit:', value);
			this.debug('Preventing default form submission and handling slash command');

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
			const startTime = Date.now();
			console.log('[SlashCommands] ⚙️ Executing command:', command);
			this.debug('Execution started at', new Date().toISOString());

			// Show loading state
			this.setLoading(true);

			try {
				this.debug('Sending command to REST API...');
				const response = await this.sendCommand(command);
				const duration = Date.now() - startTime;

				this.debug('Command response received', {
					duration: duration + 'ms',
					success: response.success,
					hasResult: !!response.result
				});

				if (response.success) {
					console.log('[SlashCommands] ✅ Command executed successfully in ' + duration + 'ms');
					this.displayResult(response.result, command);
				} else {
					console.error('[SlashCommands] ❌ Command failed:', response.message);
					this.displayError(response.message || 'Command execution failed');
				}
			} catch (error) {
				const duration = Date.now() - startTime;
				console.error('[SlashCommands] ❌ Error after ' + duration + 'ms:', error);
				this.debug('Error details:', {
					message: error.message,
					stack: error.stack,
					name: error.name
				});
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

			this.debug('REST API request:', {
				endpoint: endpoint,
				hasNonce: !!nonce,
				command: command
			});

			if (!endpoint || !nonce) {
				throw new Error('REST API configuration missing (restUrl or nonce)');
			}

			const requestPayload = { command };
			this.debug('Request payload:', requestPayload);

			const response = await fetch(endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				},
				body: JSON.stringify(requestPayload)
			});

			this.debug('Response status:', {
				status: response.status,
				statusText: response.statusText,
				ok: response.ok
			});

			if (!response.ok) {
				const error = await response.json().catch(() => ({ message: 'API request failed' }));
				this.debug('Error response:', error);
				throw new Error(error.message || 'API request failed');
			}

			const data = await response.json();
			this.debug('Response data:', data);
			return data;
		}

		/**
		 * Display command result in chat
		 */
		displayResult(result, command) {
			const chatMessages = document.querySelector('.wp-mcp-ai-chat__messages, .mcp-chat-messages, #mcp-chat-messages');
			if (!chatMessages) {
				console.warn('[SlashCommands] ⚠️ Chat messages container not found - cannot display result');
				this.debug('Attempted selectors: .wp-mcp-ai-chat__messages, .mcp-chat-messages, #mcp-chat-messages');
				alert('Result: ' + result); // Fallback display
				return;
			}

			this.debug('Displaying result in chat:', {
				commandLength: command.length,
				resultLength: result.length,
				messagesContainer: chatMessages.className
			});

			// Create message elements
			const userMessage = this.createMessage('user', command);
			const assistantMessage = this.createMessage('assistant', result);

			// Append to chat
			chatMessages.appendChild(userMessage);
			chatMessages.appendChild(assistantMessage);

			// Scroll to bottom
			chatMessages.scrollTop = chatMessages.scrollHeight;

			this.debug('Result displayed successfully');
		}

		/**
		 * Display error message
		 */
		displayError(message) {
			console.error('[SlashCommands] Displaying error:', message);
			this.debug('Error details:', {
				message: message,
				timestamp: new Date().toISOString()
			});

			const chatMessages = document.querySelector('.wp-mcp-ai-chat__messages, .mcp-chat-messages, #mcp-chat-messages');
			if (!chatMessages) {
				console.warn('[SlashCommands] ⚠️ Chat messages container not found - showing alert instead');
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
			messageDiv.className = `wp-mcp-ai-chat__message wp-mcp-ai-chat__message--${type}`;

			const contentDiv = document.createElement('div');
			contentDiv.className = 'wp-mcp-ai-chat__message-content';

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
