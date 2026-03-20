/**
 * Command Autocomplete for Slash Commands
 *
 * Provides autocomplete dropdown with fuzzy search and keyboard navigation.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

(function() {
	'use strict';

	/**
	 * Command Autocomplete Class
	 */
	class CommandAutocomplete {
		constructor(inputElement) {
			this.input = inputElement;
			this.dropdown = null;
			this.commands = [];
			this.filteredCommands = [];
			this.selectedIndex = -1;
			this.visible = false;
			this.clickOutsideHandler = this.handleClickOutside.bind(this);
			this.inputBlurHandler = this.handleInputBlur.bind(this);
			
			// Timing constants for event handling
			this.BLUR_DELAY_MS = 200; // Delay to allow mousedown events to fire before blur closes dropdown
			this.CLICK_LISTENER_DELAY_MS = 100; // Delay to prevent the click that opened dropdown from immediately closing it
		}

		/**
		 * Initialize autocomplete
		 */
		init() {
			this.createDropdown();
			this.loadCommands();
			this.attachEventListeners();
			console.log('[Autocomplete] Initialized');
		}

		/**
		 * Attach event listeners for closing dropdown
		 */
		attachEventListeners() {
			// Add blur handler to input
			this.input.addEventListener('blur', this.inputBlurHandler);
		}

		/**
		 * Handle input blur event
		 */
		handleInputBlur() {
			// Use setTimeout to allow mousedown events on dropdown items to fire first
			// Without this delay, blur would hide dropdown before click/mousedown registers
			setTimeout(() => {
				if (this.visible && !this.dropdown.contains(document.activeElement)) {
					this.hide();
				}
			}, this.BLUR_DELAY_MS);
		}

		/**
		 * Handle clicks outside the dropdown
		 */
		handleClickOutside(e) {
			if (this.visible && !this.dropdown.contains(e.target) && e.target !== this.input) {
				this.hide();
			}
		}

		/**
		 * Create dropdown element
		 */
		createDropdown() {
			this.dropdown = document.createElement('div');
			this.dropdown.className = 'mcp-slash-autocomplete';
			this.dropdown.style.cssText = `
				position: absolute;
				display: none;
				background: white;
				border: 1px solid #ccc;
				border-radius: 4px;
				box-shadow: 0 2px 8px rgba(0,0,0,0.1);
				max-height: 300px;
				overflow-y: auto;
				z-index: 10000;
				min-width: 300px;
			`;

			// Insert after input
			this.input.parentNode.insertBefore(this.dropdown, this.input.nextSibling);
		}

		/**
		 * Load commands from cache or API
		 */
		async loadCommands() {
			if (window.slashCommands) {
				this.commands = await window.slashCommands.fetchCommands();
			} else {
				// Fallback to direct API call
				try {
					const endpoint = window.mcpAiData?.slashCommandListEndpoint;
					const nonce = window.mcpAiData?.nonce;

					const response = await fetch(endpoint, {
						headers: {
							'X-WP-Nonce': nonce
						}
					});

					if (response.ok) {
						const data = await response.json();
						this.commands = data.commands || [];
					}
				} catch (error) {
					console.error('[Autocomplete] Failed to load commands:', error);
				}
			}
		}

		/**
		 * Show autocomplete dropdown
		 */
		show(input) {
			if (this.commands.length === 0) {
				this.loadCommands();
				return;
			}

			const query = input.slice(1).toLowerCase(); // Remove leading slash
			this.filteredCommands = this.fuzzyFilter(query);

			if (this.filteredCommands.length === 0) {
				this.hide();
				return;
			}

			this.render();
			this.position();
			this.dropdown.style.display = 'block';
			this.visible = true;
			this.selectedIndex = 0;

			// Add click outside listener with delay to prevent the click that opened
			// the dropdown (e.g., typing "/") from immediately triggering the close handler
			setTimeout(() => {
				document.addEventListener('click', this.clickOutsideHandler);
			}, this.CLICK_LISTENER_DELAY_MS);
		}

		/**
		 * Hide autocomplete dropdown
		 */
		hide() {
			this.dropdown.style.display = 'none';
			this.visible = false;
			this.selectedIndex = -1;

			// Remove click outside listener when dropdown is hidden
			document.removeEventListener('click', this.clickOutsideHandler);
		}

		/**
		 * Check if dropdown is visible
		 */
		isVisible() {
			return this.visible;
		}

		/**
		 * Fuzzy filter commands
		 */
		fuzzyFilter(query) {
			if (!query) {
				return this.commands.slice(0, 10); // Show first 10 if no query
			}

			return this.commands.filter(cmd => {
				const name = cmd.name.toLowerCase();
				const desc = (cmd.description || '').toLowerCase();
				const aliases = (cmd.aliases || []).join(' ').toLowerCase();

				// Simple fuzzy match
				const searchText = name + ' ' + desc + ' ' + aliases;
				return searchText.includes(query) || this.fuzzyMatch(name, query);
			}).slice(0, 10);
		}

		/**
		 * Fuzzy match algorithm
		 */
		fuzzyMatch(str, pattern) {
			let patternIdx = 0;
			let strIdx = 0;

			while (patternIdx < pattern.length && strIdx < str.length) {
				if (str[strIdx].toLowerCase() === pattern[patternIdx].toLowerCase()) {
					patternIdx++;
				}
				strIdx++;
			}

			return patternIdx === pattern.length;
		}

		/**
		 * Render dropdown content
		 */
		render() {
			this.dropdown.innerHTML = '';

			this.filteredCommands.forEach((cmd, index) => {
				const item = document.createElement('div');
				item.className = 'mcp-slash-autocomplete-item';
				item.style.cssText = `
					padding: 8px 12px;
					cursor: pointer;
					border-bottom: 1px solid #f0f0f0;
				`;

				if (index === this.selectedIndex) {
					item.style.backgroundColor = '#f5f5f5';
				}

				// Command name
				const name = document.createElement('div');
				name.style.fontWeight = 'bold';
				name.textContent = '/' + cmd.name;

				// Description
				const desc = document.createElement('div');
				desc.style.cssText = 'font-size: 12px; color: #666; margin-top: 2px;';
				desc.textContent = cmd.description || '';

				// Aliases
				if (cmd.aliases && cmd.aliases.length > 0) {
					const aliases = document.createElement('div');
					aliases.style.cssText = 'font-size: 11px; color: #999; margin-top: 2px;';
					aliases.textContent = 'Aliases: /' + cmd.aliases.join(', /');
					item.appendChild(name);
					item.appendChild(desc);
					item.appendChild(aliases);
				} else {
					item.appendChild(name);
					item.appendChild(desc);
				}

				// Click handler - use mousedown to fire before blur
				item.addEventListener('mousedown', (e) => {
					// Prevent default to avoid triggering input blur
					e.preventDefault();
					this.selectCommand(cmd);
				});

				// Hover handler
				item.addEventListener('mouseenter', () => {
					this.selectedIndex = index;
					this.render();
				});

				this.dropdown.appendChild(item);
			});
		}

		/**
		 * Position dropdown near input
		 */
		position() {
			const rect = this.input.getBoundingClientRect();
			this.dropdown.style.position = 'fixed';
			this.dropdown.style.left = rect.left + 'px';
			this.dropdown.style.top = (rect.bottom + 4) + 'px';
			this.dropdown.style.width = Math.max(300, rect.width) + 'px';
		}

		/**
		 * Handle keyboard navigation
		 */
		handleKeyDown(e) {
			if (!this.visible) {
				return false;
			}

			switch (e.key) {
				case 'ArrowDown':
					this.selectedIndex = Math.min(this.selectedIndex + 1, this.filteredCommands.length - 1);
					this.render();
					return true;

				case 'ArrowUp':
					this.selectedIndex = Math.max(this.selectedIndex - 1, 0);
					this.render();
					return true;

				case 'Enter':
					if (this.selectedIndex >= 0 && this.selectedIndex < this.filteredCommands.length) {
						this.selectCommand(this.filteredCommands[this.selectedIndex]);
						return true;
					}
					break;

				case 'Tab':
					if (this.selectedIndex >= 0 && this.selectedIndex < this.filteredCommands.length) {
						this.selectCommand(this.filteredCommands[this.selectedIndex]);
						return true;
					}
					break;

				case 'Escape':
					this.hide();
					return true;
			}

			return false;
		}

		/**
		 * Select a command
		 */
		selectCommand(cmd) {
			// Hide dropdown first
			this.hide();

			// Insert command into input
			this.input.value = '/' + cmd.name + ' ';
			this.input.focus();

			// Position cursor at end
			const length = this.input.value.length;
			this.input.setSelectionRange(length, length);
		}

		/**
		 * Cleanup event listeners
		 */
		destroy() {
			this.hide();
			this.input.removeEventListener('blur', this.inputBlurHandler);
			document.removeEventListener('click', this.clickOutsideHandler);
			if (this.dropdown && this.dropdown.parentNode) {
				this.dropdown.parentNode.removeChild(this.dropdown);
			}
		}
	}

/**
 * Module-level config, injected via SlashCommandsHandler.configure() or
 * the constructor options argument. Falls back to _getConfig() for
 * backwards-compatibility in WordPress environments.
 */
let _moduleConfig = null;

/**
 * Helper: return the active config object (instance override → module config → WP global).
 */
function _getConfig() {
	return _moduleConfig || (typeof window !== 'undefined' ? _getConfig() : null) || {};
}

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
		constructor(config) {
			if (config) {
				_moduleConfig = config;
			}
			this.initialized = false;
			this.autocomplete = null;
			this.commandCache = null;
			this.cacheExpiry = 0;
			this.debugMode = window.wpMcpAiDebug || false;
			this.executionTimeout = 30000; // 30 seconds default timeout
			this.initRetryCount = 0;
			this.maxInitRetries = 50; // Maximum 5 seconds of retries (50 * 100ms)
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

			// Check if mcpAiData is available - retry if not
			if (!_getConfig() || !_getConfig().slashCommandListEndpoint) {
				this.initRetryCount++;
				if (this.initRetryCount > this.maxInitRetries) {
					console.error('[SlashCommands] Failed to initialize: mcpAiData not available after ' + this.maxInitRetries + ' retries');
					return;
				}
				this.debug('mcpAiData not yet available, will retry in 100ms (attempt ' + this.initRetryCount + '/' + this.maxInitRetries + ')');
				const self = this;
				setTimeout(function() {
					self.init();
				}, 100);
				return;
			}

			this.debug('Starting initialization...', {
				readyState: document.readyState,
				timestamp: new Date().toISOString(),
				retries: this.initRetryCount
			});

			this.debug('mcpAiData available:', {
				hasRestUrl: !!_getConfig().restUrl,
				hasSlashCommandEndpoint: !!_getConfig().slashCommandEndpoint,
				hasSlashCommandListEndpoint: !!_getConfig().slashCommandListEndpoint,
				hasNonce: !!_getConfig().nonce
			});
			// Always log endpoint URLs (not just in debug mode) to help troubleshoot URL issues in production
			console.log('[SlashCommands] Endpoint URLs:', {
				restUrl: _getConfig().restUrl,
				slashCommandEndpoint: _getConfig().slashCommandEndpoint,
				slashCommandListEndpoint: _getConfig().slashCommandListEndpoint
			});

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
			const endpoint = _getConfig().slashCommandEndpoint;
			const nonce = _getConfig().nonce;

			this.debug('REST API request:', {
				endpoint: endpoint,
				hasNonce: !!nonce,
				command: command,
				correlationId: correlationId
			});

			if (!endpoint || !nonce) {
				throw new Error('REST API configuration missing (slashCommandEndpoint or nonce)');
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
				const rawHtml = marked.parse(content);
				// Sanitize with DOMPurify if available to prevent XSS; fall back to textContent otherwise.
				if (typeof DOMPurify !== 'undefined') {
					contentDiv.innerHTML = DOMPurify.sanitize(rawHtml);
				} else {
					contentDiv.textContent = content;
				}
			} else {
				// Simple formatting: build DOM nodes to avoid any innerHTML XSS risk
				this.appendTextWithLineBreaks(contentDiv, content);
			}

			messageDiv.appendChild(contentDiv);
			return messageDiv;
		}

		/**
		 * Append text content with line breaks as DOM nodes (XSS-safe alternative to innerHTML).
		 *
		 * @param {HTMLElement} el      Target element.
		 * @param {string}      text    Text content to append.
		 */
		appendTextWithLineBreaks(el, text) {
			const lines = String(text).split('\n');
			for (let i = 0; i < lines.length; i++) {
				if (i > 0) {
					el.appendChild(document.createElement('br'));
				}
				el.appendChild(document.createTextNode(lines[i]));
			}
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
				const endpoint = _getConfig().slashCommandListEndpoint;
				const nonce = _getConfig().nonce;

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

/**
 * Convenience helper: initialise a SlashCommandsHandler when the DOM is ready.
 * Returns the handler instance so callers can call .init() themselves if preferred.
 *
 * @param {Object} [config] - Optional config passed to SlashCommandsHandler constructor
 * @returns {SlashCommandsHandler}
 */
function createSlashCommands(config) {
	const handler = new SlashCommandsHandler(config);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() {
			handler.init();
		});
	} else {
		handler.init();
	}
	return handler;
}

// ES Module exports
export { CommandAutocomplete, SlashCommandsHandler, createSlashCommands };
export default { CommandAutocomplete, SlashCommandsHandler, createSlashCommands };
