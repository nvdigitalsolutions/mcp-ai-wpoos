/**
 * UI Utilities Service for WP oOS Chat
 * 
 * Handles UI utility functions including formatting, status management, and DOM batching.
 * This is a self-contained service that can be used independently.
 * 
 * @since 1.0.0
 */

(function(window) {
	'use strict';

	// Performance optimization settings
	const DEBUG_MODE = window.wpMcpAiChatDebugMode === true;
	const OPTIMIZATIONS_ENABLED = !DEBUG_MODE;

	/**
	 * DOM update batcher for preventing forced reflows.
	 * Uses requestAnimationFrame to batch multiple DOM updates.
	 */
	const domUpdateBatcher = (function() {
		let pendingUpdates = [];
		let rafScheduled = false;

		function performUpdates() {
			rafScheduled = false;
			const updates = pendingUpdates.slice();
			pendingUpdates = [];

			// Execute all updates in a single animation frame
			updates.forEach(function(updateFn) {
				try {
					updateFn();
				} catch (error) {
					if (window.console && console.error) {
						console.error('Error in batched DOM update:', error);
					}
				}
			});
		}

		return {
			/**
			 * Schedule a DOM update to be executed in the next animation frame.
			 * 
			 * @param {Function} updateFn - Function to execute
			 */
			schedule: function(updateFn) {
				if (!OPTIMIZATIONS_ENABLED || typeof updateFn !== 'function') {
					// Execute immediately if optimizations disabled
					if (typeof updateFn === 'function') {
						updateFn();
					}
					return;
				}

				pendingUpdates.push(updateFn);

				if (!rafScheduled) {
					rafScheduled = true;
					requestAnimationFrame(performUpdates);
				}
			}
		};
	})();

	/**
	 * Scroll to bottom batcher for preventing forced reflows.
	 * Uses requestAnimationFrame to batch multiple scroll requests.
	 */
	const scrollBatcher = (function() {
		let pendingScrolls = new Map();
		let rafScheduled = false;

		function performScrolls() {
			rafScheduled = false;
			// Read all scroll heights first (batch reads)
			const scrollOperations = new Map();
			pendingScrolls.forEach(function(_, element) {
				if (element && element.parentNode) {
					scrollOperations.set(element, element.scrollHeight);
				}
			});
			
			// Then perform all writes (batch writes)
			scrollOperations.forEach(function(scrollHeight, element) {
				element.scrollTop = scrollHeight;
			});
			
			pendingScrolls.clear();
		}

		return {
			/**
			 * Schedule a scroll to bottom operation.
			 * 
			 * @param {Element} element - The element to scroll
			 */
			scrollToBottom: function(element) {
				if (!element || !OPTIMIZATIONS_ENABLED) {
					// Fallback to immediate scroll if optimizations disabled
					if (element) {
						element.scrollTop = element.scrollHeight;
					}
					return;
				}

				// Store the element for batched scrolling
				pendingScrolls.set(element, 'bottom');

				if (!rafScheduled) {
					rafScheduled = true;
					requestAnimationFrame(performScrolls);
				}
			}
		};
	})();

	/**
	 * Escape HTML to prevent XSS.
	 * 
	 * @param {string} text - Text to escape
	 * @return {string} Escaped text
	 */
	function escapeHtml(text) {
		return String(text).replace(/[&<>"']/g, function (character) {
			switch (character) {
				case '&':
					return '&amp;';
				case '<':
					return '&lt;';
				case '>':
					return '&gt;';
				case '"':
					return '&quot;';
				case '\'':
					return '&#39;';
				default:
					return character;
			}
		});
	}

	/**
	 * Format bytes to human-readable string.
	 * 
	 * @param {number} bytes - Number of bytes
	 * @return {string} Formatted string (e.g., "1.5 KB", "2.3 MB")
	 */
	function formatBytes(bytes) {
		if (bytes === 0) {
			return '0 Bytes';
		}

		const k = 1024;
		const sizes = ['Bytes', 'KB', 'MB', 'GB'];
		const i = Math.floor(Math.log(bytes) / Math.log(k));
		
		return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
	}

	/**
	 * Format duration in seconds to MM:SS or HH:MM:SS format.
	 * 
	 * @param {number} value - Duration in seconds
	 * @return {string} Formatted duration (e.g., "1:30", "1:05:30")
	 */
	function formatDuration(value) {
		const seconds = Number(value);
		if (!isFinite(seconds) || seconds < 0) {
			return '';
		}

		const totalSeconds = Math.round(seconds);
		const hours = Math.floor(totalSeconds / 3600);
		const minutes = Math.floor((totalSeconds % 3600) / 60);
		const secs = totalSeconds % 60;

		const parts = [];
		if (hours) {
			parts.push(hours);
		}

		parts.push(hours ? String(minutes).padStart(2, '0') : String(minutes));
		parts.push(String(secs).padStart(2, '0'));

		return parts.join(':');
	}

	/**
	 * Format elapsed time in seconds to human-readable string.
	 * 
	 * @param {number} seconds - Elapsed seconds
	 * @return {string} Formatted time (e.g., "5s", "1m 30s", "2m")
	 */
	function formatElapsedTime(seconds) {
		if (seconds < 60) {
			return seconds + 's';
		}
		
		const minutes = Math.floor(seconds / 60);
		const remainingSeconds = seconds % 60;
		
		if (remainingSeconds === 0) {
			return minutes + 'm';
		}
		
		return minutes + 'm ' + remainingSeconds + 's';
	}

	/**
	 * Set status message in a chat container.
	 * 
	 * @param {Element} container - Chat container element
	 * @param {string|Object} message - Status message or options object
	 * @param {Object} options - Additional options (if message is string)
	 */
	function setStatus(container, message, options) {
		const statusEl = container.querySelector('.wp-mcp-ai-chat__status');
		if (!statusEl) {
			return;
		}

		// Handle both string and object parameters for backward compatibility
		let messageText = '';
		let opts = options || {};
		
		if (typeof message === 'object' && message !== null) {
			opts = message;
			messageText = opts.message || '';
		} else {
			messageText = message || '';
		}

		if (!messageText) {
			statusEl.innerHTML = '';
			statusEl.hidden = true;
			statusEl.className = 'wp-mcp-ai-chat__status';
			// Clear any time tracking
			if (statusEl._timeInterval) {
				clearInterval(statusEl._timeInterval);
				statusEl._timeInterval = null;
			}
			return;
		}

		// Clear existing time interval if any
		if (statusEl._timeInterval) {
			clearInterval(statusEl._timeInterval);
			statusEl._timeInterval = null;
		}

		// Determine indicator type
		const type = opts.type || 'default';
		const showTime = opts.showTime !== false; // Show time by default
		const startTime = opts.startTime || Date.now();
		
		// Build status HTML with indicator
		let indicatorHTML = '';
		let statusClass = 'wp-mcp-ai-chat__status';
		
		if (type === 'thinking') {
			statusClass += ' wp-mcp-ai-chat__status--thinking';
			indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator">' +
				'<span class="wp-mcp-ai-chat__status-spinner"></span>' +
				'</span>';
		} else if (type === 'processing') {
			statusClass += ' wp-mcp-ai-chat__status--processing';
			indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator">' +
				'<span class="wp-mcp-ai-chat__status-spinner"></span>' +
				'</span>';
		} else if (type === 'streaming') {
			statusClass += ' wp-mcp-ai-chat__status--streaming';
			indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator">' +
				'<svg class="wp-mcp-ai-chat__status-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false">' +
				'<path d="M2 10a8 8 0 0116 0H2zm8-8a8 8 0 010 16V2z" opacity="0.3"/>' +
				'<path d="M10 2a8 8 0 018 8h-2a6 6 0 00-6-6V2z">' +
				'<animateTransform attributeName="transform" type="rotate" from="0 10 10" to="360 10 10" dur="1s" repeatCount="indefinite"/>' +
				'</path>' +
				'</svg>' +
				'</span>';
		} else if (type === 'text-stream') {
			statusClass += ' wp-mcp-ai-chat__status--text-stream';
			indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator">' +
				'<svg class="wp-mcp-ai-chat__status-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false">' +
				'<path d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h6a1 1 0 110 2H4a1 1 0 01-1-1z"/>' +
				'</svg>' +
				'</span>';
		} else if (type === 'tool') {
			statusClass += ' wp-mcp-ai-chat__status--tool';
			indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator">' +
				'<span class="wp-mcp-ai-chat__status-spinner"></span>' +
				'</span>';
		} else if (type === 'success') {
			statusClass += ' wp-mcp-ai-chat__status--success';
			indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator">' +
				'<svg class="wp-mcp-ai-chat__status-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false">' +
				'<path fill="currentColor" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/>' +
				'</svg>' +
				'</span>';
		}
		
		// Build time display
		let timeHTML = '';
		if (showTime && (type === 'thinking' || type === 'processing' || type === 'tool')) {
			timeHTML = '<span class="wp-mcp-ai-chat__status-time" data-start-time="' + startTime + '">0s</span>';
		}
		
		// Escape message text
		const escapedMessage = escapeHtml(messageText);
		
		// Set status content
		statusEl.className = statusClass;
		statusEl.innerHTML = indicatorHTML + 
			'<span class="wp-mcp-ai-chat__status-text">' + escapedMessage + '</span>' + 
			timeHTML;
		statusEl.hidden = false;
		
		// Start time tracking if enabled
		if (timeHTML) {
			const timeEl = statusEl.querySelector('.wp-mcp-ai-chat__status-time');
			if (timeEl) {
				// Use batched DOM updates to prevent setTimeout violations
				statusEl._timeInterval = setInterval(function() {
					const elapsed = Math.floor((Date.now() - startTime) / 1000);
					
					// Schedule DOM update in next animation frame to prevent forced reflow
					domUpdateBatcher.schedule(function() {
						if (timeEl && timeEl.parentNode) {
							timeEl.textContent = formatElapsedTime(elapsed);
						} else {
							// Element removed, clear interval
							if (statusEl._timeInterval) {
								clearInterval(statusEl._timeInterval);
								statusEl._timeInterval = null;
							}
						}
					});
				}, 1000);
			}
		}
	}

	/**
	 * Clear status message in a chat container.
	 * 
	 * @param {Element} container - Chat container element
	 */
	function clearStatus(container) {
		setStatus(container, '');
	}

	/**
	 * Toggle a CSS class on a button element.
	 * 
	 * @param {Element} button - Button element
	 * @param {string} className - CSS class name to toggle
	 * @param {boolean} force - Optional force parameter (true=add, false=remove)
	 */
	function toggleButtonClass(button, className, force) {
		if (!button || !button.classList || !className) {
			return;
		}

		if (typeof force === 'boolean') {
			if (force) {
				button.classList.add(className);
			} else {
				button.classList.remove(className);
			}
		} else {
			button.classList.toggle(className);
		}
	}

	/**
	 * Set button state (enabled/disabled) with optional class toggling.
	 * 
	 * @param {Element} button - Button element
	 * @param {Object} options - State options
	 * @param {boolean} options.disabled - Whether button should be disabled
	 * @param {boolean} options.hidden - Whether button should be hidden
	 * @param {string} options.addClass - CSS class to add
	 * @param {string} options.removeClass - CSS class to remove
	 */
	function setButtonState(button, options) {
		if (!button) {
			return;
		}

		const opts = options || {};

		// Set disabled state
		if (typeof opts.disabled === 'boolean') {
			button.disabled = opts.disabled;
		}

		// Set hidden state
		if (typeof opts.hidden === 'boolean') {
			button.hidden = opts.hidden;
		}

		// Add CSS class
		if (opts.addClass && button.classList) {
			button.classList.add(opts.addClass);
		}

		// Remove CSS class
		if (opts.removeClass && button.classList) {
			button.classList.remove(opts.removeClass);
		}
	}

	/**
	 * Update button icon/content.
	 * 
	 * @param {Element} button - Button element
	 * @param {string} iconHTML - HTML content for the icon
	 * @param {string} selector - Optional selector for icon element within button (defaults to first child)
	 */
	function setButtonIcon(button, iconHTML, selector) {
		if (!button || typeof iconHTML !== 'string') {
			return;
		}

		let iconElement;
		
		if (selector) {
			iconElement = button.querySelector(selector);
		} else {
			// Default to first child element
			iconElement = button.firstElementChild;
		}

		if (iconElement) {
			iconElement.innerHTML = iconHTML;
		}
	}

	/**
	 * Update button accessibility labels (aria-label and title).
	 * 
	 * @param {Element} button - Button element
	 * @param {string} label - Label text for aria-label and title
	 */
	function updateButtonLabel(button, label) {
		if (!button || typeof label !== 'string') {
			return;
		}

		button.setAttribute('aria-label', label);
		button.setAttribute('title', label);
	}

	// Export public API
	window.wpMcpAiChatUIUtils = {
		domUpdateBatcher: domUpdateBatcher,
		scrollBatcher: scrollBatcher,
		escapeHtml: escapeHtml,
		formatBytes: formatBytes,
		formatDuration: formatDuration,
		formatElapsedTime: formatElapsedTime,
		setStatus: setStatus,
		clearStatus: clearStatus,
		toggleButtonClass: toggleButtonClass,
		setButtonState: setButtonState,
		setButtonIcon: setButtonIcon,
		updateButtonLabel: updateButtonLabel
	};

})(window);
