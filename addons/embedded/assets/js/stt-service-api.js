/**
 * STT Service API — Abstract Interface
 *
 * Defines the contract that all speech-to-text backends must implement.
 * Mirrors the server-side Voice_Provider interface pattern.
 *
 * Supported backends:
 *   - whisper_cpp_wasm  (browser-side, WASM, offline, P0)
 *   - gemma4_audio      (server-side, REST, Gemma 4 E2B/E4B audio, P1)
 *   - transformers_js   (browser-side, WebGPU, offline, P2)
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
	 * Abstract STT Backend interface.
	 *
	 * All STT backends MUST implement this contract. The voice mode
	 * manager calls these methods and receives results via callbacks.
	 *
	 * @interface
	 */
	class STTServiceAPI {
		/**
		 * Get the unique slug for this backend.
		 *
		 * @return {string} e.g. 'whisper_cpp_wasm', 'gemma4_audio', 'transformers_js'.
		 */
		getSlug() {
			throw new Error('STTServiceAPI.getSlug() must be implemented by subclass');
		}

		/**
		 * Get the human-readable backend name.
		 *
		 * @return {string}
		 */
		getName() {
			throw new Error('STTServiceAPI.getName() must be implemented by subclass');
		}

		/**
		 * Check if this backend is available in the current browser.
		 *
		 * Example checks: WebAssembly support, WebGPU availability,
		 * required APIs present, model files accessible.
		 *
		 * @return {Promise<boolean>}
		 */
		async isAvailable() {
			throw new Error('STTServiceAPI.isAvailable() must be implemented by subclass');
		}

		/**
		 * Initialize the backend (load models, warm up, etc.).
		 *
		 * Called once when the backend is selected. May be expensive.
		 * Reports progress via the onProgress callback.
		 *
		 * @param {Object} options
		 * @param {string} options.model          Model identifier (e.g. 'tiny.en', 'base').
		 * @param {string} [options.language='en'] Language code for transcription.
		 * @return {Promise<void>}
		 */
		async initialize(options) {
			throw new Error('STTServiceAPI.initialize() must be implemented by subclass');
		}

		/**
		 * Transcribe an audio buffer.
		 *
		 * @param {Float32Array} audioData PCM float samples (-1.0 to 1.0).
		 * @param {Object}       options
		 * @param {number}       options.sampleRate     Sample rate of the audio data.
		 * @param {string}       [options.language='en'] Language code.
		 * @param {boolean}      [options.partial=false] Whether this is a partial/interim result.
		 * @return {Promise<Object>} Transcription result.
		 *   { text: string, partial: boolean, confidence: number, language: string }
		 */
		async transcribe(audioData, options) {
			throw new Error('STTServiceAPI.transcribe() must be implemented by subclass');
		}

		/**
		 * Stream audio chunks for real-time transcription.
		 *
		 * The backend processes chunks incrementally and calls
		 * onPartialResult and/or onFinalResult as transcriptions complete.
		 *
		 * @param {Object}   callbacks
		 * @param {Function} callbacks.onPartialResult  ({ text, confidence }) => void
		 * @param {Function} callbacks.onFinalResult    ({ text, confidence, language }) => void
		 * @param {Function} callbacks.onError          (Error) => void
		 * @param {Object}   [options]
		 * @param {number}   [options.sampleRate=16000]
		 * @param {string}   [options.language='en']
		 * @return {Object} Stream controller with { push(Float32Array), flush(), close() }.
		 */
		createStream(callbacks, options) {
			throw new Error('STTServiceAPI.createStream() must be implemented by subclass');
		}

		/**
		 * Release resources held by this backend.
		 *
		 * Called when switching backends or tearing down voice mode.
		 *
		 * @return {Promise<void>}
		 */
		async destroy() {
			// Default no-op; override if needed.
		}

		/**
		 * Get the estimated model download size in bytes.
		 *
		 * @param {string} model Model identifier.
		 * @return {number} Size in bytes, or 0 if unknown.
		 */
		getModelSize(model) {
			return 0;
		}

		/**
		 * Get supported audio formats.
		 *
		 * @return {Array<{mimeType: string, sampleRate: number}>}
		 */
		getSupportedFormats() {
			return [{ mimeType: 'audio/x-float32', sampleRate: 16000 }];
		}

		// ── Callback registration ──────────────────────────────────────

		/**
		 * Progress callback for model loading.
		 *
		 * @type {Function|null} ({ loaded: number, total: number, status: string }) => void
		 */
		onProgress = null;

		/**
		 * Error callback.
		 *
		 * @type {Function|null} (Error) => void
		 */
		onError = null;
	}

	// ── Backend Registry ────────────────────────────────────────────

	/**
	 * Registry of available STT backends.
	 *
	 * Usage:
	 *   STTBackendRegistry.register(STTWhisperCppWasm);
	 *   const backend = STTBackendRegistry.create('whisper_cpp_wasm');
	 */
	const STTBackendRegistry = {
		/** @type {Object<string, typeof STTServiceAPI>} */
		_backends: {},

		/**
		 * Register a backend class.
		 *
		 * @param {typeof STTServiceAPI} BackendClass
		 */
		register(BackendClass) {
			const instance = new BackendClass();
			const slug = instance.getSlug();
			this._backends[slug] = BackendClass;
		},

		/**
		 * Create a backend instance by slug.
		 *
		 * @param {string} slug Backend slug.
		 * @return {STTServiceAPI|null} Instance or null if not found.
		 */
		create(slug) {
			const BackendClass = this._backends[slug];
			if (!BackendClass) {
				console.warn('[NV oOS Embedded] Unknown STT backend slug:', slug);
				return null;
			}
			return new BackendClass();
		},

		/**
		 * Get all registered backend slugs.
		 *
		 * @return {string[]}
		 */
		getSlugs() {
			return Object.keys(this._backends);
		},

		/**
		 * Auto-detect the best available backend.
		 *
		 * Priority: Transformers.js WebGPU > whisper.cpp WASM > Gemma 4 REST.
		 *
		 * @return {Promise<string|null>} Best backend slug, or null if none available.
		 */
		async detectBest() {
			const slugs = this.getSlugs();
			for (let i = 0; i < slugs.length; i++) {
				try {
					const backend = this.create(slugs[i]);
					if (backend && (await backend.isAvailable())) {
						return slugs[i];
					}
				} catch (err) {
					console.warn('[NV oOS Embedded] Backend check failed for', slugs[i], ':', err.message);
				}
			}
			return null;
		},
	};

	// Export to global scope.
	window.STTServiceAPI = STTServiceAPI;
	window.STTBackendRegistry = STTBackendRegistry;
})();
