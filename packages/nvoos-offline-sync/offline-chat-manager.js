/**
 * Offline Chat Manager
 *
 * Manages chat persistence and synchronization for offline-first functionality.
 * Uses IndexedDB for local storage and syncs to server when online.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @version 1.0.0
 */

(function() {
	'use strict';

	/**
	 * Offline Chat Manager Class
	 *
	 * Handles offline-first chat with automatic sync when online.
	 */
	class OfflineChatManager {
		constructor() {
			this.db = null;
			this.syncQueue = [];
			this.isOnline = navigator.onLine;
			this.dbName = 'wp-mcp-ai-offline';
			this.dbVersion = 1;

			// Listen for online/offline events
			window.addEventListener('online', () => this.handleOnline());
			window.addEventListener('offline', () => this.handleOffline());
		}

		/**
		 * Initialize the offline chat manager
		 *
		 * @return {Promise<void>}
		 */
		async initialize() {
			this.db = await this.openDatabase();
		}

		/**
		 * Open IndexedDB database
		 *
		 * @return {Promise<IDBDatabase>}
		 */
		openDatabase() {
			return new Promise((resolve, reject) => {
				const request = indexedDB.open(this.dbName, this.dbVersion);

				request.onerror = () => reject(request.error);
				request.onsuccess = () => resolve(request.result);

				request.onupgradeneeded = (event) => {
					const db = event.target.result;

					// Create messages store
					if (!db.objectStoreNames.contains('messages')) {
						const messagesStore = db.createObjectStore('messages', {
							keyPath: 'id',
							autoIncrement: true
						});
						messagesStore.createIndex('timestamp', 'timestamp', { unique: false });
						messagesStore.createIndex('synced', 'synced', { unique: false });
					}

					// Create conversations store
					if (!db.objectStoreNames.contains('conversations')) {
						const conversationsStore = db.createObjectStore('conversations', {
							keyPath: 'id',
							autoIncrement: true
						});
						conversationsStore.createIndex('last_updated', 'last_updated', { unique: false });
					}
				};
			});
		}

		/**
		 * Save message locally
		 *
		 * @param {Object} message - Message object
		 * @return {Promise<void>}
		 */
		async saveMessage(message) {
			// Always save locally first
			await this.saveToLocal(message);

			// Queue for sync when online
			if (!this.isOnline) {
				this.syncQueue.push(message);
			} else {
				await this.syncToServer(message);
			}
		}

		/**
		 * Save message to local IndexedDB
		 *
		 * @param {Object} message - Message object
		 * @return {Promise<number>} Message ID
		 */
		async saveToLocal(message) {
			return new Promise((resolve, reject) => {
				const transaction = this.db.transaction(['messages'], 'readwrite');
				const store = transaction.objectStore('messages');

				// Add metadata
				const messageData = {
					...message,
					timestamp: Date.now(),
					synced: false
				};

				const request = store.add(messageData);

				request.onsuccess = () => resolve(request.result);
				request.onerror = () => reject(request.error);
			});
		}

		/**
		 * Sync message to server
		 *
		 * @param {Object} message - Message object
		 * @return {Promise<void>}
		 */
		async syncToServer(message) {
			try {
				// Send to WordPress REST API
				const response = await fetch(window.wpMcpAi?.restUrl + '/chat/save', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': window.wpMcpAi?.nonce || ''
					},
					body: JSON.stringify(message)
				});

				if (!response.ok) {
					throw new Error('Failed to sync message');
				}

				// Mark as synced in local database
				await this.markAsSynced(message.id);

			} catch (error) {
				console.error('[Offline Chat] Sync failed:', error);
				// Re-queue for later
				if (!this.syncQueue.includes(message)) {
					this.syncQueue.push(message);
				}
			}
		}

		/**
		 * Mark message as synced
		 *
		 * @param {number} messageId - Message ID
		 * @return {Promise<void>}
		 */
		async markAsSynced(messageId) {
			return new Promise((resolve, reject) => {
				const transaction = this.db.transaction(['messages'], 'readwrite');
				const store = transaction.objectStore('messages');
				const request = store.get(messageId);

				request.onsuccess = () => {
					const message = request.result;
					if (message) {
						message.synced = true;
						const updateRequest = store.put(message);
						updateRequest.onsuccess = () => resolve();
						updateRequest.onerror = () => reject(updateRequest.error);
					} else {
						resolve();
					}
				};

				request.onerror = () => reject(request.error);
			});
		}

		/**
		 * Handle coming online
		 *
		 * @return {Promise<void>}
		 */
		async handleOnline() {
			this.isOnline = true;

			console.log('[Offline Chat] Back online, syncing messages...');

			// Sync queued messages
			while (this.syncQueue.length > 0) {
				const message = this.syncQueue.shift();
				try {
					await this.syncToServer(message);
				} catch (error) {
					// Re-queue on failure
					this.syncQueue.unshift(message);
					break;
				}
			}

			// Hide offline notice if showing
			const notice = document.querySelector('.wp-mcp-ai-offline-notice');
			if (notice) {
				notice.remove();
			}
		}

		/**
		 * Handle going offline
		 */
		handleOffline() {
			this.isOnline = false;
			this.showOfflineNotice();
		}

		/**
		 * Show offline notification
		 */
		showOfflineNotice() {
			// Remove existing notice if present
			const existing = document.querySelector('.wp-mcp-ai-offline-notice');
			if (existing) {
				existing.remove();
			}

			const notice = document.createElement('div');
			notice.className = 'wp-mcp-ai-offline-notice';
			notice.innerHTML = `
				<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
					<path d="M8 1.5c3.61 0 6.5 2.89 6.5 6.5s-2.89 6.5-6.5 6.5S1.5 11.61 1.5 8 4.39 1.5 8 1.5zM8 0C3.58 0 0 3.58 0 8s3.58 8 8 8 8-3.58 8-8-3.58-8-8-8zm1 13H7v-2h2v2zm0-3H7V4h2v6z"/>
				</svg>
				<span>You're offline. Messages will sync when online.</span>
			`;
			document.body.appendChild(notice);

			// Auto-remove after 5 seconds
			setTimeout(() => {
				if (notice.parentNode) {
					notice.remove();
				}
			}, 5000);
		}

		/**
		 * Get all local messages
		 *
		 * @return {Promise<Array>} Array of messages
		 */
		async getAllMessages() {
			return new Promise((resolve, reject) => {
				const transaction = this.db.transaction(['messages'], 'readonly');
				const store = transaction.objectStore('messages');
				const request = store.getAll();

				request.onsuccess = () => resolve(request.result);
				request.onerror = () => reject(request.error);
			});
		}

		/**
		 * Clear all local data
		 *
		 * @return {Promise<void>}
		 */
		async clearAllData() {
			return new Promise((resolve, reject) => {
				const transaction = this.db.transaction(['messages', 'conversations'], 'readwrite');

				const messagesStore = transaction.objectStore('messages');
				const conversationsStore = transaction.objectStore('conversations');

				messagesStore.clear();
				conversationsStore.clear();

				transaction.oncomplete = () => resolve();
				transaction.onerror = () => reject(transaction.error);
			});
		}
	}

	// Export to global scope
	if (typeof window !== 'undefined') {
		window.WP_MCP_AI_OfflineChatManager = OfflineChatManager;
	}

	// Also export as module if available
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = OfflineChatManager;
	}
})();
