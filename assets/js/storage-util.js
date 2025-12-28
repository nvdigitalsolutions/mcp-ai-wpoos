/**
 * Storage utility for handling localStorage operations with Web Worker support.
 * 
 * Provides async JSON parsing/stringifying using Web Workers to prevent
 * blocking the main thread and causing performance violations.
 * 
 * Separation of concerns:
 * - This module handles ONLY storage-related operations
 * - Web Worker handles ONLY heavy JSON operations
 * - Main chat code handles ONLY UI and business logic
 * 
 * @package WP_MCP_AI
 */

(function(window) {
	'use strict';

	const StorageUtil = {
		worker: null,
		workerSupported: typeof Worker !== 'undefined',
		pendingOperations: {},
		operationId: 0,
		
		// Threshold for using Web Worker (in bytes)
		// Below this size, we use synchronous operations for better performance
		WORKER_THRESHOLD: 10000, // 10KB

		/**
		 * Initialize the Web Worker.
		 * Called lazily only when needed.
		 */
		initWorker: function() {
			if (!this.workerSupported || this.worker) {
				return;
			}

			try {
				// Get the worker script URL from the global config
				const workerUrl = window.wpMcpAiChat && window.wpMcpAiChat.storageWorkerUrl;
				if (!workerUrl) {
					console.warn('NV oOS: Storage worker URL not configured');
					return;
				}

				this.worker = new Worker(workerUrl);
				this.worker.addEventListener('message', this.handleWorkerMessage.bind(this));
				this.worker.addEventListener('error', this.handleWorkerError.bind(this));
			} catch (error) {
				console.error('NV oOS: Failed to initialize storage worker:', error);
				this.workerSupported = false;
			}
		},

		/**
		 * Handle message from Web Worker.
		 */
		handleWorkerMessage: function(e) {
			const { id, success, result, error } = e.data;
			const pending = this.pendingOperations[id];

			if (!pending) {
				return;
			}

			delete this.pendingOperations[id];

			if (success) {
				pending.resolve(result);
			} else {
				pending.reject(new Error(error || 'Worker operation failed'));
			}
		},

		/**
		 * Handle worker error.
		 */
		handleWorkerError: function(error) {
			console.error('NV oOS: Storage worker error:', error);
			
			// Reject all pending operations
			Object.keys(this.pendingOperations).forEach(function(id) {
				const pending = this.pendingOperations[id];
				delete this.pendingOperations[id];
				pending.reject(new Error('Worker error: ' + error.message));
			}.bind(this));
		},

		/**
		 * Post message to worker and return a promise.
		 */
		postToWorker: function(action, data) {
			this.initWorker();

			if (!this.worker) {
				// Fallback to synchronous operation
				return Promise.reject(new Error('Worker not available'));
			}

			const id = ++this.operationId;
			
			return new Promise(function(resolve, reject) {
				this.pendingOperations[id] = { resolve: resolve, reject: reject };
				this.worker.postMessage({ action: action, data: data, id: id });
			}.bind(this));
		},

		/**
		 * Parse JSON asynchronously using Web Worker for large data.
		 * 
		 * @param {string} jsonString - JSON string to parse
		 * @return {Promise} Promise that resolves with parsed object
		 */
		parseJSON: function(jsonString) {
			if (!jsonString) {
				return Promise.resolve(null);
			}

			const size = jsonString.length;

			// Use synchronous parsing for small data
			if (size < this.WORKER_THRESHOLD || !this.workerSupported) {
				try {
					return Promise.resolve(JSON.parse(jsonString));
				} catch (error) {
					return Promise.reject(error);
				}
			}

			// Use Web Worker for large data
			return this.postToWorker('parse', jsonString).catch(function(error) {
				// Fallback to synchronous if worker fails
				console.warn('NV oOS: Worker parse failed, using fallback:', error);
				return JSON.parse(jsonString);
			});
		},

		/**
		 * Stringify object to JSON asynchronously using Web Worker for large data.
		 * 
		 * @param {*} obj - Object to stringify
		 * @return {Promise} Promise that resolves with JSON string
		 */
		stringifyJSON: function(obj) {
			if (obj === null || obj === undefined) {
				return Promise.resolve('');
			}

			// Estimate size (rough approximation)
			const estimatedSize = JSON.stringify(obj).length;

			// Use synchronous stringifying for small data
			if (estimatedSize < this.WORKER_THRESHOLD || !this.workerSupported) {
				try {
					return Promise.resolve(JSON.stringify(obj));
				} catch (error) {
					return Promise.reject(error);
				}
			}

			// Use Web Worker for large data
			return this.postToWorker('stringify', obj).catch(function(error) {
				// Fallback to synchronous if worker fails
				console.warn('NV oOS: Worker stringify failed, using fallback:', error);
				return JSON.stringify(obj);
			});
		},

		/**
		 * Cleanup - terminate worker if active.
		 */
		cleanup: function() {
			if (this.worker) {
				this.worker.terminate();
				this.worker = null;
			}
			this.pendingOperations = {};
		}
	};

	// Expose to global scope
	window.wpMcpAiStorageUtil = StorageUtil;

})(window);
