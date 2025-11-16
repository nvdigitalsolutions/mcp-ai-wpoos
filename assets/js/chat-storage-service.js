/**
 * Storage Service for WP oOS Chat
 * 
 * Handles localStorage management, conversation persistence, and quota monitoring.
 * This is a self-contained service that can be used independently.
 * 
 * @since 1.0.0
 */

(function(window) {
	'use strict';

	// Storage constants
	const STORAGE_KEY_PREFIX = 'wp_mcp_ai_chat_';
	const STORAGE_EXPIRY_MS = 24 * 60 * 60 * 1000; // 24 hours
	const STORAGE_SAVE_DEBOUNCE_MS = 300;
	const DEBUG_MODE = window.wpMcpAiChatDebugMode === true;
	const OPTIMIZATIONS_ENABLED = !DEBUG_MODE;

	// Debounced storage save timers
	const storageSaveTimers = {};

	/**
	 * Quota monitor cache for async calculation.
	 */
	const quotaMonitorCache = {
		lastCalculated: 0,
		cachedQuota: { used: 0, total: 0, percentage: 0, available: false },
		calculating: false,
		CACHE_DURATION: 30000, // Cache for 30 seconds
		
		/**
		 * Get cached quota or trigger async calculation.
		 * 
		 * @param {Function} callback - Called with quota data when available
		 */
		getQuota: function(callback) {
			const now = Date.now();
			const cacheValid = (now - this.lastCalculated) < this.CACHE_DURATION;
			
			if (cacheValid && this.cachedQuota.available) {
				if (callback) {
					callback(this.cachedQuota);
				}
				return;
			}
			
			if (this.calculating) {
				if (callback) {
					callback(this.cachedQuota);
				}
				return;
			}
			
			this.calculating = true;
			
			const performCalculation = function() {
				try {
					const quota = this.calculateQuotaSync();
					this.cachedQuota = quota;
					this.lastCalculated = Date.now();
					this.calculating = false;
					
					if (callback) {
						callback(quota);
					}
				} catch (error) {
					this.calculating = false;
					if (window.console && console.error) {
						console.error('Error calculating localStorage quota:', error);
					}
				}
			}.bind(this);
			
			if (OPTIMIZATIONS_ENABLED && window.requestIdleCallback) {
				window.requestIdleCallback(performCalculation, { timeout: 2000 });
			} else {
				setTimeout(performCalculation, 0);
			}
		},
		
		/**
		 * Synchronous quota calculation (called in idle callback).
		 * 
		 * @return {Object} Quota data object
		 */
		calculateQuotaSync: function() {
			if (!window.localStorage) {
				return { used: 0, total: 0, percentage: 0, available: false };
			}

			let totalSize = 0;
			let wpMcpAiSize = 0;

			for (let i = 0; i < window.localStorage.length; i++) {
				const key = window.localStorage.key(i);
				if (!key) {
					continue;
				}

				const value = window.localStorage.getItem(key);
				if (value) {
					const itemSize = key.length + value.length;
					totalSize += itemSize;

					if (key.startsWith(STORAGE_KEY_PREFIX)) {
						wpMcpAiSize += itemSize;
					}
				}
			}

			const estimatedQuota = 5 * 1024 * 1024; // 5MB
			const percentage = (totalSize / estimatedQuota) * 100;

			return {
				used: totalSize,
				wpMcpAiUsed: wpMcpAiSize,
				total: estimatedQuota,
				percentage: Math.min(percentage, 100),
				available: true,
				formattedUsed: formatBytes(totalSize),
				formattedWpMcpAiUsed: formatBytes(wpMcpAiSize),
				formattedTotal: formatBytes(estimatedQuota)
			};
		}
	};

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
	 * Get localStorage key for a specific assistant.
	 * 
	 * @param {string} assistantId - The assistant ID.
	 * @return {string} The storage key.
	 */
	function getStorageKey(assistantId) {
		return STORAGE_KEY_PREFIX + assistantId;
	}

	/**
	 * Sanitize a session key to remove whitespace and invalid characters.
	 * Matches the PHP-side normalization in WP_MCP_AI_REST::normalise_transcript_session_key
	 * which uses: preg_replace('/[^a-zA-Z0-9_-]/', '', $value)
	 * 
	 * @param {string} sessionKey - The session key to sanitize.
	 * @return {string} The sanitized session key.
	 */
	function sanitizeSessionKey(sessionKey) {
		if (!sessionKey || typeof sessionKey !== 'string') {
			return '';
		}
		
		// Remove all characters except alphanumeric, underscore, and hyphen
		// This matches the PHP sanitization to ensure consistency
		return sessionKey.replace(/[^a-zA-Z0-9_-]/g, '');
	}

	/**
	 * Get localStorage usage statistics (async).
	 * 
	 * @param {Function} callback - Called with quota data
	 */
	function getLocalStorageQuota(callback) {
		quotaMonitorCache.getQuota(callback);
	}

	/**
	 * Clean up old localStorage entries to free up space.
	 * 
	 * @return {number} Number of entries cleaned up
	 */
	function cleanupOldStorageEntries() {
		if (!window.localStorage) {
			return 0;
		}
		
		let cleaned = 0;
		const now = Date.now();
		const keysToRemove = [];
		
		try {
			for (let i = 0; i < window.localStorage.length; i++) {
				const key = window.localStorage.key(i);
				
				if (!key || !key.startsWith(STORAGE_KEY_PREFIX)) {
					continue;
				}
				
				try {
					const stored = window.localStorage.getItem(key);
					if (!stored) {
						keysToRemove.push(key);
						continue;
					}
					
					const data = JSON.parse(stored);
					
					if (data && data.timestamp && (now - data.timestamp) > STORAGE_EXPIRY_MS) {
						keysToRemove.push(key);
					}
				} catch (error) {
					keysToRemove.push(key);
				}
			}
			
			keysToRemove.forEach(function(key) {
				try {
					window.localStorage.removeItem(key);
					cleaned++;
				} catch (error) {
					// Ignore errors during cleanup
				}
			});
			
			if (cleaned > 0 && window.console && console.info) {
				console.info('Cleaned up ' + cleaned + ' old conversation(s) from localStorage');
			}
		} catch (error) {
			if (window.console && console.warn) {
				console.warn('Error during localStorage cleanup:', error);
			}
		}
		
		return cleaned;
	}

	/**
	 * Save conversation to localStorage with quota management.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {Object} options - Optional settings
	 * @return {Object} Result object with success status
	 */
	function saveConversationToStorage(state, options) {
		if (!state || !state.config) {
			return { success: false, skipped: true };
		}

		// Use originalAssistantId if available (for widgets with fixed assistant)
		// Fall back to config.assistantId for backwards compatibility
		const assistantId = state.originalAssistantId || state.config.assistantId;

		if (!assistantId) {
			return { success: false, skipped: true };
		}

		if (!window.localStorage) {
			return { success: false, error: 'localStorage not available' };
		}

		const opts = options || {};
		const forceImmediate = opts.immediate === true;
		
		function performSave() {
			try {
				const storageKey = getStorageKey(assistantId);
				const data = {
					conversation: state.conversation || [],
					sessionKey: sanitizeSessionKey(state.config.sessionKey || ''),
					timestamp: Date.now(),
					assistantId: assistantId
				};
				
				window.localStorage.setItem(storageKey, JSON.stringify(data));
				return { success: true };
			} catch (error) {
				const isQuotaError = error.name === 'QuotaExceededError' || 
								   error.code === 22 || 
								   error.code === 1014;
				
				if (isQuotaError) {
					const cleaned = cleanupOldStorageEntries();
					
					if (cleaned > 0) {
						try {
							const storageKey = getStorageKey(assistantId);
							const data = {
								conversation: state.conversation || [],
								sessionKey: sanitizeSessionKey(state.config.sessionKey || ''),
								timestamp: Date.now(),
								assistantId: assistantId
							};
							
							window.localStorage.setItem(storageKey, JSON.stringify(data));
							return { success: true, cleaned: cleaned };
						} catch (retryError) {
							if (window.console && console.warn) {
								console.warn('Failed to save conversation even after cleanup:', retryError);
							}
							return { success: false, error: 'localStorage quota exceeded', cleaned: cleaned };
						}
					}
					
					return { success: false, error: 'localStorage quota exceeded' };
				}
				
				if (window.console && console.warn) {
					console.warn('Error saving conversation to localStorage:', error);
				}
				
				return { success: false, error: error.message || 'localStorage error' };
			}
		}
		
		if (!OPTIMIZATIONS_ENABLED || forceImmediate) {
			return performSave();
		}

		if (storageSaveTimers[assistantId]) {
			clearTimeout(storageSaveTimers[assistantId]);
		}

		storageSaveTimers[assistantId] = setTimeout(function() {
			performSave();
			delete storageSaveTimers[assistantId];
		}, STORAGE_SAVE_DEBOUNCE_MS);
		
		return { success: true, debounced: true };
	}

	/**
	 * Load conversation from localStorage.
	 * 
	 * @param {Object} state - Chat state object
	 * @return {Object|null} Loaded conversation data or null
	 */
	function loadConversationFromStorage(state) {
		if (!state || !state.config) {
			return null;
		}

		// Use originalAssistantId if available (for widgets with fixed assistant)
		// Fall back to config.assistantId for backwards compatibility
		const assistantId = state.originalAssistantId || state.config.assistantId;

		if (!assistantId) {
			return null;
		}

		if (!window.localStorage) {
			return null;
		}

		try {
			const storageKey = getStorageKey(assistantId);
			const stored = window.localStorage.getItem(storageKey);

			if (!stored) {
				return null;
			}

			const data = JSON.parse(stored);

			if (!data || typeof data !== 'object') {
				return null;
			}

			const age = Date.now() - (data.timestamp || 0);
			if (age > STORAGE_EXPIRY_MS) {
				window.localStorage.removeItem(storageKey);
				return null;
			}

			// Note: We no longer validate that data.assistantId matches state.config.assistantId
			// because state.config.assistantId should never change from the widget's original config.
			// The storage key is based on originalAssistantId, so we trust the data.

			return {
				conversation: Array.isArray(data.conversation) ? data.conversation : [],
				sessionKey: sanitizeSessionKey(data.sessionKey || ''),
				assistantId: data.assistantId || assistantId
			};
		} catch (error) {
			return null;
		}
	}

	/**
	 * Clear conversation from localStorage.
	 * 
	 * @param {Object} state - Chat state object
	 */
	function clearConversationFromStorage(state) {
		if (!state || !state.config) {
			return;
		}

		// Use originalAssistantId if available (for widgets with fixed assistant)
		// Fall back to config.assistantId for backwards compatibility
		const assistantId = state.originalAssistantId || state.config.assistantId;

		if (!assistantId) {
			return;
		}

		if (!window.localStorage) {
			return;
		}

		try {
			const storageKey = getStorageKey(assistantId);
			window.localStorage.removeItem(storageKey);
		} catch (error) {
			// Silently fail
		}
	}

	/**
	 * Export conversation to various formats.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {string} format - Export format ('json', 'markdown', 'text')
	 * @return {Object} Export result with content and filename
	 */
	function exportConversation(state, format) {
		if (!state || !state.conversation || !Array.isArray(state.conversation)) {
			return { success: false, error: 'No conversation to export' };
		}

		const conversation = state.conversation;
		const assistantId = state.config ? state.config.assistantId : 'unknown';
		const sessionKey = state.config ? state.config.sessionKey : '';
		const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
		
		let content = '';
		let filename = '';
		let mimeType = 'text/plain';

		try {
			if (format === 'json') {
				const exportData = {
					assistant_id: assistantId,
					session_key: sessionKey,
					exported_at: new Date().toISOString(),
					messages: conversation
				};
				content = JSON.stringify(exportData, null, 2);
				filename = 'chat-' + assistantId + '-' + timestamp + '.json';
				mimeType = 'application/json';
			} else if (format === 'markdown') {
				const lines = ['# Chat Conversation'];
				lines.push('');
				lines.push('**Assistant ID:** ' + assistantId);
				if (sessionKey) {
					lines.push('**Session Key:** ' + sessionKey);
				}
				lines.push('**Exported:** ' + new Date().toLocaleString());
				lines.push('');
				lines.push('---');
				lines.push('');

				conversation.forEach(function(message) {
					const role = message.role || 'unknown';
					const content = message.content || '';
					
					lines.push('## ' + role.charAt(0).toUpperCase() + role.slice(1));
					lines.push('');
					lines.push(content);
					lines.push('');
				});

				content = lines.join('\n');
				filename = 'chat-' + assistantId + '-' + timestamp + '.md';
				mimeType = 'text/markdown';
			} else {
				const lines = ['Chat Conversation'];
				lines.push('');
				lines.push('Assistant ID: ' + assistantId);
				if (sessionKey) {
					lines.push('Session Key: ' + sessionKey);
				}
				lines.push('Exported: ' + new Date().toLocaleString());
				lines.push('');
				lines.push('----------------------------------------');
				lines.push('');

				conversation.forEach(function(message) {
					const role = message.role || 'unknown';
					const content = message.content || '';
					
					lines.push(role.toUpperCase() + ':');
					lines.push(content);
					lines.push('');
				});

				content = lines.join('\n');
				filename = 'chat-' + assistantId + '-' + timestamp + '.txt';
				mimeType = 'text/plain';
			}

			return {
				success: true,
				content: content,
				filename: filename,
				mimeType: mimeType
			};
		} catch (error) {
			if (window.console && console.error) {
				console.error('Error exporting conversation:', error);
			}
			return { success: false, error: error.message || 'Export failed' };
		}
	}

	// Export public API
	window.wpMcpAiChatStorage = {
		getStorageKey: getStorageKey,
		sanitizeSessionKey: sanitizeSessionKey,
		getLocalStorageQuota: getLocalStorageQuota,
		formatBytes: formatBytes,
		cleanupOldStorageEntries: cleanupOldStorageEntries,
		saveConversationToStorage: saveConversationToStorage,
		loadConversationFromStorage: loadConversationFromStorage,
		clearConversationFromStorage: clearConversationFromStorage,
		exportConversation: exportConversation
	};

})(window);
