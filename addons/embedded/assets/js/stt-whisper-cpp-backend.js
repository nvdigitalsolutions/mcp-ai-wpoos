/**
 * whisper.cpp WASM Backend
 *
 * Implements the STTServiceAPI interface using whisper.cpp compiled
 * to WebAssembly. Runs entirely in the browser — audio never leaves
 * the device.
 *
 * This backend spawns a Web Worker (stt-whisper-cpp-worker.js) that
 * loads the WASM binary and GGML model. Communication is async via
 * postMessage.
 *
 * Model files are served from the NV oOS plugin's assets directory
 * or a configured CDN. Recommended models:
 *   - tiny.en  (75 MB)  — Fastest, English-only, 2-3x real-time
 *   - base.en  (142 MB) — Good balance, English-only
 *   - small.en (466 MB) — Best quality, English-only
 *
 * @package NV_oOS_Embedded
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  Proprietary
 */

(function () {
	'use strict';

	/**
	 * Model metadata registry.
	 */
	const MODELS = {
		'tiny.en': {
			name: 'Whisper Tiny (English)',
			size: 75 * 1024 * 1024,
			language: 'en',
			url: null, // Set dynamically from config.
		},
		'base.en': {
			name: 'Whisper Base (English)',
			size: 142 * 1024 * 1024,
			language: 'en',
			url: null,
		},
		'small.en': {
			name: 'Whisper Small (English)',
			size: 466 * 1024 * 1024,
			language: 'en',
			url: null,
		},
		'tiny': {
			name: 'Whisper Tiny (Multilingual)',
			size: 75 * 1024 * 1024,
			language: 'multi',
			url: null,
		},
		'base': {
			name: 'Whisper Base (Multilingual)',
			size: 142 * 1024 * 1024,
			language: 'multi',
			url: null,
		},
		'small': {
			name: 'Whisper Small (Multilingual)',
			size: 466 * 1024 * 1024,
			language: 'multi',
			url: null,
		},
	};

	/**
	 * whisper.cpp WASM STT Backend.
	 */
	class STTWhisperCppWasm extends window.STTServiceAPI {
		constructor() {
			super();

			/** @type {Worker|null} */
			this._worker = null;

			/** Whether the backend has been initialized. */
			this._initialized = false;

			/** Current model identifier. */
			this._model = 'tiny.en';

			/** Pending transcription requests (id → { resolve, reject }). */
			this._pendingRequests = {};

			/** Request ID counter. */
			this._requestId = 0;

			/** Configured base URL for model and WASM files. */
			this._baseUrl = '';

			/** Resolved WASM JS URL. */
			this._wasmJsUrl = '';
		}

		/**
		 * Set the base URL for model and WASM file resolution.
		 *
		 * @param {string} baseUrl e.g. '/wp-content/plugins/nvoos-embedded/assets/stt/'
		 */
		setBaseUrl(baseUrl) {
			this._baseUrl = baseUrl.replace(/\/$/, '');
		}

		/**
		 * Get the URL for a model file.
		 *
		 * @param {string} model Model identifier.
		 * @return {string}
		 */
		getModelUrl(model) {
			if (MODELS[model] && MODELS[model].url) {
				return MODELS[model].url;
			}
			return this._baseUrl + '/models/ggml-' + model + '.bin';
		}

		getSlug() {
			return 'whisper_cpp_wasm';
		}

		getName() {
			return 'whisper.cpp (WASM)';
		}

		async isAvailable() {
			// Check for basic WASM + Worker support.
			if (typeof WebAssembly === 'undefined') {
				return false;
			}
			if (typeof Worker === 'undefined') {
				return false;
			}

			// Verify SharedArrayBuffer is available (needed for WASM threads in some builds).
			// Single-threaded WASM builds don't require it, but we check for general WASM support.
			try {
				const mod = new WebAssembly.Module(
					new Uint8Array([0, 97, 115, 109, 1, 0, 0, 0])
				);
				return mod instanceof WebAssembly.Module;
			} catch (e) {
				return false;
			}
		}

		async initialize(options) {
			options = options || {};
			this._model = options.model || 'tiny.en';

			if (this._initialized) {
				return;
			}

			const self = this;

			// Resolve WASM URLs from config.
			const config = options.config || {};
			this._wasmJsUrl = config.wasmJsUrl || this._baseUrl + '/whisper.js';
			const modelUrl = this.getModelUrl(this._model);

			// Create the worker.
			const workerUrl = config.workerUrl || this._baseUrl + '/stt-whisper-cpp-worker.js';
			this._worker = new Worker(workerUrl);

			// Set up message handling.
			this._worker.onmessage = function (event) {
				self._handleWorkerMessage(event.data);
			};

			this._worker.onerror = function (err) {
				console.error('[NV oOS Embedded] whisper.cpp worker error:', err);
				self._rejectAllPending('Worker error: ' + (err.message || 'Unknown'));
				if (typeof self.onError === 'function') {
					self.onError(new Error('whisper.cpp worker error: ' + (err.message || 'Unknown')));
				}
			};

			// Initialize the worker with model URL.
			return new Promise(function (resolve, reject) {
				self._pendingRequests['__init__'] = { resolve: resolve, reject: reject };

				self._worker.postMessage({
					type: 'init',
					modelUrl: modelUrl,
					options: {
						wasmUrl: self._wasmJsUrl,
					},
				});
			});
		}

		async transcribe(audioData, options) {
			options = options || {};

			if (!this._worker || !this._initialized) {
				throw new Error('whisper.cpp backend not initialized. Call initialize() first.');
			}

			const id = 'req_' + (++this._requestId);
			const self = this;

			return new Promise(function (resolve, reject) {
				self._pendingRequests[id] = { resolve: resolve, reject: reject };

				self._worker.postMessage({
					type: 'transcribe',
					audio: audioData,
					sampleRate: options.sampleRate || 16000,
					id: id,
				});
			});
		}

		createStream(callbacks, options) {
			const self = this;

			/**
			 * Stream controller returned by createStream.
			 */
			class WhisperStreamController {
				constructor() {
					this._closed = false;
				}

				push(audioData) {
					if (this._closed) {
						return;
					}
					// whisper.cpp doesn't support true streaming.
					// Audio is buffered and transcribed on flush().
					self._streamBuffer = self._streamBuffer || [];
					self._streamBuffer.push(audioData);
				}

				async flush() {
					if (this._closed || !self._streamBuffer || self._streamBuffer.length === 0) {
						return;
					}

					const combined = self._combineBuffers(self._streamBuffer);
					self._streamBuffer = [];

					try {
						const result = await self.transcribe(combined, options);
						if (typeof callbacks.onFinalResult === 'function') {
							callbacks.onFinalResult({
								text: result.text,
								confidence: result.confidence,
								language: options.language || 'en',
							});
						}
					} catch (err) {
						if (typeof callbacks.onError === 'function') {
							callbacks.onError(err);
						}
					}
				}

				close() {
					this._closed = true;
					self._streamBuffer = [];
				}
			}

			return new WhisperStreamController();
		}

		async destroy() {
			if (this._worker) {
				this._worker.postMessage({ type: 'destroy' });
				this._worker.terminate();
				this._worker = null;
			}
			this._initialized = false;
			this._pendingRequests = {};
		}

		getModelSize(model) {
			model = model || this._model;
			return MODELS[model] ? MODELS[model].size : 0;
		}

		// ── Private helpers ────────────────────────────────────────────

		/**
		 * Handle a message from the worker.
		 *
		 * @param {Object} msg
		 */
		_handleWorkerMessage(msg) {
			if (!msg || !msg.type) {
				return;
			}

			switch (msg.type) {
				case 'progress':
					if (typeof this.onProgress === 'function') {
						this.onProgress({
							loaded: msg.loaded,
							total: msg.total,
							status: msg.status,
							message: msg.message || '',
						});
					}

					if (msg.status === 'initializing' && msg.loaded === msg.total) {
						// Model loaded and initialized.
					}
					break;

				case 'ready':
					this._initialized = true;
					this._resolveRequest('__init__');
					break;

				case 'result':
					this._resolveRequest(msg.id, {
						text: msg.text || '',
						confidence: msg.confidence || 0,
					});
					break;

				case 'error':
					this._rejectRequest(msg.id, new Error(msg.message || 'Worker error'));
					if (typeof this.onError === 'function') {
						this.onError(new Error(msg.message || 'Worker error'));
					}
					break;

				default:
					break;
			}
		}

		/**
		 * Resolve a pending request by ID.
		 *
		 * @param {string} id
		 * @param {*}      [value]
		 */
		_resolveRequest(id, value) {
			const pending = this._pendingRequests[id];
			if (pending) {
				delete this._pendingRequests[id];
				pending.resolve(value);
			}
		}

		/**
		 * Reject a pending request by ID.
		 *
		 * @param {string} id
		 * @param {Error}  error
		 */
		_rejectRequest(id, error) {
			const pending = this._pendingRequests[id];
			if (pending) {
				delete this._pendingRequests[id];
				pending.reject(error);
			}
		}

		/**
		 * Reject all pending requests.
		 *
		 * @param {string} reason
		 */
		_rejectAllPending(reason) {
			Object.keys(this._pendingRequests).forEach(function (id) {
				const pending = this._pendingRequests[id];
				if (pending) {
					pending.reject(new Error(reason));
				}
			}, this);
			this._pendingRequests = {};
		}

		/**
		 * Combine multiple Float32Arrays into one.
		 *
		 * @param {Float32Array[]} buffers
		 * @return {Float32Array}
		 */
		_combineBuffers(buffers) {
			if (buffers.length === 0) {
				return new Float32Array(0);
			}
			if (buffers.length === 1) {
				return buffers[0];
			}

			let totalLength = 0;
			for (let i = 0; i < buffers.length; i++) {
				totalLength += buffers[i].length;
			}

			const result = new Float32Array(totalLength);
			let offset = 0;
			for (let i = 0; i < buffers.length; i++) {
				result.set(buffers[i], offset);
				offset += buffers[i].length;
			}
			return result;
		}
	}

	// Export.
	window.STTWhisperCppWasm = STTWhisperCppWasm;

	// Auto-register with the backend registry if available.
	if (window.STTBackendRegistry) {
		window.STTBackendRegistry.register(STTWhisperCppWasm);
	}
})();
