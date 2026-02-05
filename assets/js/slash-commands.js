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
			this.executionTimeout = 30000; // 30 seconds default timeout
		}

		/**
		 * Generate correlation ID for request tracing
		 */
		generateCorrelationId() {
			return 'slash_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
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
			const correlationId = this.generateCorrelationId();
			
			console.log('[SlashCommands] 🚀 Executing command:', command, '| ID:', correlationId);
			this.debug('Execution started at', new Date().toISOString());

			// Show loading state and ARIA announcement
			this.setLoading(true);
			this.announceToScreenReader('Executing command: ' + command.split(' ')[0]);

			try {
				this.debug('Sending command to REST API...');
				
				// Create timeout promise
				const timeoutPromise = new Promise((_, reject) => {
					setTimeout(() => reject(new Error('Command execution timeout after ' + (this.executionTimeout / 1000) + ' seconds')), this.executionTimeout);
				});
				
				// Race between command execution and timeout
				const response = await Promise.race([
					this.sendCommand(command, correlationId),
					timeoutPromise
				]);
				
				const duration = Date.now() - startTime;

				this.debug('Command response received', {
					correlationId: correlationId,
					duration: duration + 'ms',
					success: response.success,
					hasResult: !!response.result
				});

				if (response.success) {
					console.log('[SlashCommands] ✅ Command executed successfully in ' + duration + 'ms | ID:', correlationId);
					this.displayResult(response.result, command);
					this.announceToScreenReader('Command completed successfully');
					
					// Notify chat.js if available
					this.notifyChatInterface('command-executed', { command, result: response.result, correlationId });
				} else {
					console.error('[SlashCommands] ❌ Command failed:', response.message, '| ID:', correlationId);
					this.displayError(response.message || 'Command execution failed');
					this.announceToScreenReader('Command failed: ' + (response.message || 'Unknown error'));
				}
			} catch (error) {
				const duration = Date.now() - startTime;
				const isTimeout = error.message.includes('timeout');
				
				console.error('[SlashCommands] ❌ Error after ' + duration + 'ms:', error, '| ID:', correlationId);
				this.debug('Error details:', {
					correlationId: correlationId,
					message: error.message,
					stack: error.stack,
					name: error.name,
					isTimeout: isTimeout
				});
				
				const errorMsg = isTimeout ? 
					'Command timed out. Please try again or contact support.' : 
					(error.message || 'Failed to execute command');
				
				this.displayError(errorMsg);
				this.announceToScreenReader('Command error: ' + errorMsg);
			} finally {
				this.setLoading(false);
				this.chatInput.value = '';
			}
		}

		/**
		 * Send command to REST API
		 */
		async sendCommand(command, correlationId) {
			const endpoint = window.mcpAiData?.restUrl + 'slash-command';
			const nonce = window.mcpAiData?.nonce;

			this.debug('REST API request:', {
				endpoint: endpoint,
				hasNonce: !!nonce,
				command: command,
				correlationId: correlationId
			});

			if (!endpoint || !nonce) {
				throw new Error('REST API configuration missing (restUrl or nonce)');
			}

			const requestPayload = { 
				command: command,
				correlation_id: correlationId 
			};
			this.debug('Request payload:', requestPayload);

			const response = await fetch(endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
					'X-Correlation-ID': correlationId
				},
				body: JSON.stringify(requestPayload)
			});

			this.debug('Response status:', {
				status: response.status,
				statusText: response.statusText,
				ok: response.ok,
				correlationId: correlationId
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
		 * Announce message to screen readers
		 */
		announceToScreenReader(message) {
			let announcer = document.getElementById('wp-mcp-ai-slash-announcer');
			
			if (!announcer) {
				announcer = document.createElement('div');
				announcer.id = 'wp-mcp-ai-slash-announcer';
				announcer.className = 'screen-reader-text';
				announcer.setAttribute('role', 'status');
				announcer.setAttribute('aria-live', 'polite');
				announcer.setAttribute('aria-atomic', 'true');
				announcer.style.cssText = 'position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden;';
				document.body.appendChild(announcer);
			}
			
			// Clear and set new message
			announcer.textContent = '';
			setTimeout(() => {
				announcer.textContent = message;
			}, 100);
			
			this.debug('Screen reader announcement:', message);
		}

		/**
		 * Notify chat interface about slash command events
		 */
		notifyChatInterface(eventType, data) {
			this.debug('Notifying chat interface:', eventType, data);
			
			// Dispatch custom event for chat.js to listen to
			const event = new CustomEvent('slash-command-event', {
				detail: {
					type: eventType,
					data: data,
					timestamp: new Date().toISOString()
				}
			});
			
			window.dispatchEvent(event);
			
			// Also store in global state for direct access
			if (!window.wpMcpAiSlashCommandState) {
				window.wpMcpAiSlashCommandState = {
					lastExecution: null,
					history: []
				};
			}
			
			window.wpMcpAiSlashCommandState.lastExecution = {
				type: eventType,
				data: data,
				timestamp: new Date().toISOString()
			};
			
			window.wpMcpAiSlashCommandState.history.push(window.wpMcpAiSlashCommandState.lastExecution);
			
			// Keep only last 50 events
			if (window.wpMcpAiSlashCommandState.history.length > 50) {
				window.wpMcpAiSlashCommandState.history.shift();
			}
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
				const endpoint = window.mcpAiData?.restUrl + 'slash-command/list';
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
