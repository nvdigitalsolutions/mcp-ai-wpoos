/**
 * whisper.cpp WASM Web Worker
 *
 * Runs Whisper speech-to-text inference in a dedicated Web Worker
 * to avoid blocking the main thread. Communicates with the main
 * thread via a structured message protocol.
 *
 * The worker loads a whisper.cpp WASM binary + a GGML model file.
 * Audio stays entirely within the browser — zero server round-trips.
 *
 * Protocol:
 *   Main → Worker:
 *     { type: 'init',    modelUrl: string, options: object }
 *     { type: 'transcribe', audio: Float32Array, sampleRate: number, id: string }
 *     { type: 'destroy' }
 *
 *   Worker → Main:
 *     { type: 'progress', loaded: number, total: number, status: string }
 *     { type: 'ready' }
 *     { type: 'result',  id: string, text: string, confidence: number }
 *     { type: 'error',   id: string, message: string }
 *
 * Model files are fetched from the NV oOS server (or CDN) and cached
 * in IndexedDB via a simple cache layer for subsequent loads.
 *
 * @package NV_oOS_Embedded
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  Proprietary
 */

/* global importScripts, self, caches, Response, Promise */

'use strict';

// ── Message handler ────────────────────────────────────────────

self.onmessage = async function (event) {
	const msg = event.data;
	if (!msg || !msg.type) {
		return;
	}

	try {
		switch (msg.type) {
			case 'init':
				await handleInit(msg.modelUrl, msg.options);
				break;
			case 'transcribe':
				await handleTranscribe(msg.audio, msg.sampleRate, msg.id);
				break;
			case 'destroy':
				handleDestroy();
				break;
			default:
				postError('unknown', 'Unknown message type: ' + msg.type);
		}
	} catch (err) {
		postError(msg.id || 'init', err.message || 'Worker error');
	}
};

// ── Worker state ───────────────────────────────────────────────

/** @type {Object|null} whisper.cpp module instance. */
let whisperModule = null;

/** Whether the model is loaded and ready. */
let isReady = false;

/** IndexedDB cache name for model files. */
const MODEL_CACHE_NAME = 'nvoos-stt-models';

/**
 * Initialize the whisper.cpp WASM module and load the model.
 *
 * @param {string} modelUrl URL to the GGML model file.
 * @param {Object} options  Initialization options.
 */
async function handleInit(modelUrl, options) {
	options = options || {};

	if (isReady) {
		postMessage({ type: 'ready' });
		return;
	}

	postProgress('loading_wasm', 0, 100, 'Loading whisper.cpp WASM runtime...');

	// Import the whisper.cpp WASM module.
	// The module URL should be provided by the backend that creates this worker.
	const wasmUrl = options.wasmUrl || resolveWasmUrl();
	await loadWasmModule(wasmUrl);

	postProgress('loading_model', 0, 100, 'Downloading model...');

	// Download and cache the model file.
	const modelData = await fetchWithCache(modelUrl, function (loaded, total) {
		postProgress('loading_model', loaded, total, 'Downloading model...');
	});

	postProgress('initializing', 0, 100, 'Initializing model in WASM...');

	// Initialize the model from the downloaded data.
	await initModel(modelData, options);

	isReady = true;
	postMessage({ type: 'ready' });
}

/**
 * Transcribe an audio buffer.
 *
 * @param {Float32Array} audioData  PCM float samples.
 * @param {number}       sampleRate Audio sample rate.
 * @param {string}       id         Unique request ID for correlating response.
 */
async function handleTranscribe(audioData, sampleRate, id) {
	if (!isReady || !whisperModule) {
		postError(id, 'Model not initialized. Call init first.');
		return;
	}

	try {
		const result = await runInference(audioData, sampleRate);
		postMessage({
			type: 'result',
			id: id,
			text: result.text,
			confidence: result.confidence || 0,
		});
	} catch (err) {
		postError(id, 'Transcription failed: ' + (err.message || 'Unknown error'));
	}
}

/**
 * Tear down the worker, freeing WASM memory.
 */
function handleDestroy() {
	if (whisperModule && typeof whisperModule.free === 'function') {
		try {
			whisperModule.free();
		} catch (e) {
			// Ignore.
		}
	}
	whisperModule = null;
	isReady = false;
	self.close();
}

// ── WASM loading ───────────────────────────────────────────────

/**
 * Load the whisper.cpp WASM module.
 *
 * @param {string} wasmUrl URL to the whisper.wasm file.
 * @return {Promise<void>}
 */
async function loadWasmModule(wasmUrl) {
	// The whisper.cpp WASM example uses a wrapper JS file that
	// exports a factory function. We import it and call it.
	//
	// In the browser Worker context, we use importScripts for
	// the JS wrapper, which in turn loads the .wasm binary.
	return new Promise(function (resolve, reject) {
		try {
			// The whisper.cpp WASM build produces whisper.js + whisper.wasm.
			// whisper.js sets up Module globally when loaded.
			importScripts(wasmUrl);

			// The module factory is typically available as Module or createWhisperModule.
			const factory = self.Module || self.createWhisperModule;

			if (typeof factory !== 'function') {
				// Some builds auto-initialize and set a ready promise.
				if (self.Module && typeof self.Module.then === 'function') {
					self.Module.then(function (mod) {
						whisperModule = mod;
						resolve();
					}).catch(reject);
					return;
				}
				reject(new Error('whisper.cpp WASM module factory not found'));
				return;
			}

			factory({
				// Locate the .wasm binary relative to the JS file.
				locateFile: function (path) {
					if (path.endsWith('.wasm')) {
						return wasmUrl.replace(/\.js$/, '.wasm');
					}
					return path;
				},
				onRuntimeInitialized: function () {
					// Module is ready.
					resolve();
				},
				onAbort: function (msg) {
					reject(new Error('WASM abort: ' + msg));
				},
			}).then(function (mod) {
				whisperModule = mod;
			}).catch(reject);
		} catch (err) {
			reject(err);
		}
	});
}

/**
 * Initialize the Whisper model with the loaded GGML data.
 *
 * @param {ArrayBuffer} modelData GGML model binary data.
 * @param {Object}      options   Model initialization options.
 * @return {Promise<void>}
 */
async function initModel(modelData, options) {
	if (!whisperModule) {
		throw new Error('WASM module not loaded');
	}

	// Copy model data into WASM heap.
	const modelPtr = copyToHeap(modelData);

	// Initialize whisper context.
	const cparams = whisperModule.ccall
		? whisperModule.ccall(
				'whisper_context_default_params',
				'number',
				[],
				[]
			)
		: whisperModule._whisper_context_default_params
		? whisperModule._whisper_context_default_params()
		: 0;

	// Initialize from buffer.
	const ctx = whisperModule.ccall
		? whisperModule.ccall(
				'whisper_init_from_buffer',
				'number',
				['number', 'number'],
				[modelPtr, modelData.byteLength]
			)
		: whisperModule._whisper_init_from_buffer
		? whisperModule._whisper_init_from_buffer(modelPtr, modelData.byteLength)
		: null;

	if (!ctx) {
		throw new Error('Failed to initialize whisper model from buffer');
	}

	// Store context for later use.
	whisperModule._ctx = ctx;
}

/**
 * Run inference on audio data.
 *
 * @param {Float32Array} audioData  PCM float samples.
 * @param {number}       sampleRate Audio sample rate.
 * @return {Promise<{text: string, confidence: number}>}
 */
async function runInference(audioData, sampleRate) {
	if (!whisperModule || !whisperModule._ctx) {
		throw new Error('Model context not available');
	}

	const ctx = whisperModule._ctx;

	// Get default full parameters.
	const wparams = whisperModule.ccall
		? whisperModule.ccall('whisper_full_default_params', 'number', ['number'], [2]) // 2 = WHISPER_SAMPLING_GREEDY
		: whisperModule._whisper_full_default_params
		? whisperModule._whisper_full_default_params(2)
		: 0;

	// Copy audio to WASM heap as float samples.
	const audioPtr = copyFloat32ToHeap(audioData);

	// Run inference.
	const result = whisperModule.ccall
		? whisperModule.ccall(
				'whisper_full',
				'number',
				['number', 'number', 'number', 'number'],
				[ctx, wparams, audioPtr, audioData.length]
			)
		: whisperModule._whisper_full
		? whisperModule._whisper_full(ctx, wparams, audioPtr, audioData.length)
		: -1;

	if (result !== 0) {
		throw new Error('whisper_full returned error code: ' + result);
	}

	// Extract text segments.
	const nSegments = whisperModule.ccall
		? whisperModule.ccall('whisper_full_n_segments', 'number', ['number'], [ctx])
		: whisperModule._whisper_full_n_segments
		? whisperModule._whisper_full_n_segments(ctx)
		: 0;

	let text = '';
	let totalConfidence = 0;
	let confidenceCount = 0;

	for (let i = 0; i < nSegments; i++) {
		const segmentText = whisperModule.ccall
			? whisperModule.ccall('whisper_full_get_segment_text', 'string', ['number', 'number'], [ctx, i])
			: whisperModule._whisper_full_get_segment_text
			? UTF8ToString(whisperModule._whisper_full_get_segment_text(ctx, i))
			: '';

		if (segmentText) {
			text += (text ? ' ' : '') + segmentText.trim();
		}

		// Get segment tokens for confidence.
		const nTokens = whisperModule.ccall
			? whisperModule.ccall('whisper_full_n_tokens', 'number', ['number', 'number'], [ctx, i])
			: whisperModule._whisper_full_n_tokens
			? whisperModule._whisper_full_n_tokens(ctx, i)
			: 0;

		for (let t = 0; t < nTokens; t++) {
			const tokenProb = whisperModule._whisper_full_get_token_p
				? whisperModule._whisper_full_get_token_p(ctx, i, t)
				: 0;
			if (tokenProb > 0) {
				totalConfidence += tokenProb;
				confidenceCount++;
			}
		}
	}

	const confidence = confidenceCount > 0 ? totalConfidence / confidenceCount : 0;

	return { text: text.trim(), confidence: confidence };
}

// ── WASM memory helpers ────────────────────────────────────────

/**
 * Copy an ArrayBuffer to the WASM heap.
 *
 * @param {ArrayBuffer} data Binary data.
 * @return {number} Pointer in WASM memory.
 */
function copyToHeap(data) {
	const ptr = whisperModule._malloc(data.byteLength);
	whisperModule.HEAPU8.set(new Uint8Array(data), ptr);
	return ptr;
}

/**
 * Copy Float32Array to WASM heap.
 *
 * @param {Float32Array} data PCM samples.
 * @return {number} Pointer in WASM memory.
 */
function copyFloat32ToHeap(data) {
	const byteLength = data.length * Float32Array.BYTES_PER_ELEMENT;
	const ptr = whisperModule._malloc(byteLength);
	whisperModule.HEAPF32.set(data, ptr / Float32Array.BYTES_PER_ELEMENT);
	return ptr;
}

/**
 * Convert a WASM heap C string pointer to a JS string.
 *
 * @param {number} ptr C string pointer.
 * @return {string}
 */
function UTF8ToString(ptr) {
	if (!ptr) {
		return '';
	}
	const heap = whisperModule.HEAPU8;
	let end = ptr;
	while (heap[end]) {
		end++;
	}
	const bytes = heap.slice(ptr, end);
	return new TextDecoder('utf-8').decode(bytes);
}

// ── Model caching (IndexedDB) ──────────────────────────────────

/**
 * Fetch a model file with progress tracking, caching in IndexedDB.
 *
 * @param {string}   url      Model file URL.
 * @param {Function} onProgress ({loaded, total}) => void.
 * @return {Promise<ArrayBuffer>}
 */
async function fetchWithCache(url, onProgress) {
	// Try cache first.
	const cached = await getFromCache(url);
	if (cached) {
		if (typeof onProgress === 'function') {
			onProgress(cached.byteLength, cached.byteLength);
		}
		return cached;
	}

	// Fetch with progress.
	const response = await fetchWithProgress(url, onProgress);
	const buffer = await response.arrayBuffer();

	// Store in cache (fire-and-forget).
	putInCache(url, buffer).catch(function () {
		// Cache write failure is non-fatal.
	});

	return buffer;
}

/**
 * Fetch with progress tracking via ReadableStream.
 *
 * @param {string}   url
 * @param {Function} onProgress
 * @return {Promise<Response>}
 */
async function fetchWithProgress(url, onProgress) {
	const response = await fetch(url);

	if (!response.ok) {
		throw new Error('Failed to fetch model: HTTP ' + response.status);
	}

	const contentLength = parseInt(response.headers.get('content-length') || '0', 10);

	if (!contentLength || !response.body || typeof onProgress !== 'function') {
		return response;
	}

	const reader = response.body.getReader();
	const chunks = [];
	let loaded = 0;

	while (true) {
		const { done, value } = await reader.read();
		if (done) {
			break;
		}
		chunks.push(value);
		loaded += value.length;
		onProgress(loaded, contentLength);
	}

	// Reconstruct response.
	const blob = new Blob(chunks);
	return new Response(blob, {
		status: response.status,
		statusText: response.statusText,
		headers: response.headers,
	});
}

/**
 * Get model data from IndexedDB cache.
 *
 * @param {string} url Cache key.
 * @return {Promise<ArrayBuffer|null>}
 */
function getFromCache(url) {
	return new Promise(function (resolve) {
		if (!self.indexedDB) {
			resolve(null);
			return;
		}

		const request = self.indexedDB.open(MODEL_CACHE_NAME, 1);
		request.onupgradeneeded = function (event) {
			const db = event.target.result;
			if (!db.objectStoreNames.contains('models')) {
				db.createObjectStore('models');
			}
		};
		request.onsuccess = function (event) {
			const db = event.target.result;
			try {
				const tx = db.transaction('models', 'readonly');
				const store = tx.objectStore('models');
				const getReq = store.get(url);
				getReq.onsuccess = function () {
					resolve(getReq.result || null);
				};
				getReq.onerror = function () {
					resolve(null);
				};
			} catch (err) {
				resolve(null);
			}
		};
		request.onerror = function () {
			resolve(null);
		};
	});
}

/**
 * Store model data in IndexedDB cache.
 *
 * @param {string}      url  Cache key.
 * @param {ArrayBuffer} data Model binary data.
 * @return {Promise<void>}
 */
function putInCache(url, data) {
	return new Promise(function (resolve, reject) {
		if (!self.indexedDB) {
			resolve();
			return;
		}

		const request = self.indexedDB.open(MODEL_CACHE_NAME, 1);
		request.onupgradeneeded = function (event) {
			const db = event.target.result;
			if (!db.objectStoreNames.contains('models')) {
				db.createObjectStore('models');
			}
		};
		request.onsuccess = function (event) {
			const db = event.target.result;
			try {
				const tx = db.transaction('models', 'readwrite');
				const store = tx.objectStore('models');
				store.put(data, url);
				tx.oncomplete = function () {
					resolve();
				};
				tx.onerror = function () {
					reject(new Error('Cache write failed'));
				};
			} catch (err) {
				resolve(); // Non-fatal.
			}
		};
		request.onerror = function () {
			resolve(); // Non-fatal.
		};
	});
}

// ── Helpers ────────────────────────────────────────────────────

/**
 * Resolve the URL for the whisper.cpp WASM binary.
 *
 * Uses a configured CDN path or falls back to the server.
 *
 * @return {string}
 */
function resolveWasmUrl() {
	// The backend that creates this worker should pass wasmUrl in options.
	// If not provided, use a default relative to the script location.
	return 'whisper.js';
}

/**
 * Post a progress update to the main thread.
 *
 * @param {string} status
 * @param {number} loaded
 * @param {number} total
 * @param {string} message
 */
function postProgress(status, loaded, total, message) {
	postMessage({
		type: 'progress',
		status: status,
		loaded: loaded,
		total: total,
		message: message,
	});
}

/**
 * Post an error to the main thread.
 *
 * @param {string} id      Request ID or 'init'.
 * @param {string} message Error message.
 */
function postError(id, message) {
	postMessage({
		type: 'error',
		id: id,
		message: message,
	});
}

/**
 * Post a generic message to the main thread.
 *
 * @param {Object} msg
 */
function postMessage(msg) {
	self.postMessage(msg);
}
