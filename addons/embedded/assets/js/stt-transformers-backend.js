/**
 * Transformers.js Whisper Backend
 *
 * Implements STTServiceAPI using Hugging Face Transformers.js
 * with whisper models. Supports WebGPU acceleration for 3-5x
 * faster inference on compatible browsers (Chrome 113+).
 *
 * Falls back to WASM execution when WebGPU is unavailable.
 * Uses the same Transformers.js dependency already integrated
 * for WebLLM.
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
	 * Transformers.js Whisper STT Backend.
	 */
	class STTTransformersJs extends window.STTServiceAPI {
		constructor() {
			super();

			/** @type {Object|null} Transformers.js pipeline. */
			this._pipeline = null;

			/** Whether initialized. */
			this._initialized = false;

			/** Current model. */
			this._model = 'Xenova/whisper-tiny.en';

			/** Whether WebGPU is being used. */
			this._usingWebGPU = false;
		}

		getSlug() {
			return 'transformers_js';
		}

		getName() {
			return 'Transformers.js Whisper';
		}

		async isAvailable() {
			// Check that Transformers.js can be imported.
			try {
				// Dynamic import test.
				const mod = await import('https://cdn.jsdelivr.net/npm/@xenova/transformers@2.17.2/dist/transformers.min.js');
				return !!mod;
			} catch (e) {
				return false;
			}
		}

		async initialize(options) {
			options = options || {};
			this._model = options.model || 'Xenova/whisper-tiny.en';

			if (this._initialized) {
				return;
			}

			const self = this;

			// Dynamic import of Transformers.js.
			const transformers = await import(
				'https://cdn.jsdelivr.net/npm/@xenova/transformers@2.17.2/dist/transformers.min.js'
			);

			const pipeline = transformers.pipeline;
			const env = transformers.env;

			// Configure for performance.
			env.allowLocalModels = false;
			env.useBrowserCache = true;

			// Detect WebGPU support.
			if (typeof navigator !== 'undefined' && navigator.gpu) {
				try {
					// Attempt WebGPU adapter request.
					self._usingWebGPU = true;
				} catch (e) {
					self._usingWebGPU = false;
				}
			}

			// Create pipeline.
			const device = self._usingWebGPU ? 'webgpu' : 'wasm';

			if (typeof self.onProgress === 'function') {
				self.onProgress({
					loaded: 0,
					total: 100,
					status: 'loading_model',
					message: 'Loading Transformers.js Whisper model (' + device + ')...',
				});
			}

			try {
				self._pipeline = await pipeline('automatic-speech-recognition', self._model, {
					device: device,
					quantized: true,
					progress_callback: function (progress) {
						if (typeof self.onProgress === 'function' && progress) {
							self.onProgress({
								loaded: progress.loaded || 0,
								total: progress.total || 100,
								status: progress.status || 'loading_model',
								message: progress.file || '',
							});
						}
					},
				});
			} catch (err) {
				// If WebGPU fails, retry with WASM.
				if (self._usingWebGPU) {
					console.warn('[NV oOS Embedded] WebGPU init failed, falling back to WASM:', err.message);
					self._usingWebGPU = false;
					self._pipeline = await pipeline('automatic-speech-recognition', self._model, {
						device: 'wasm',
						quantized: true,
					});
				} else {
					throw err;
				}
			}

			self._initialized = true;
		}

		async transcribe(audioData, options) {
			options = options || {};

			if (!this._initialized || !this._pipeline) {
				throw new Error('Transformers.js backend not initialized. Call initialize() first.');
			}

			const sampleRate = options.sampleRate || 16000;

			// Transformers.js expects audio as a Float32Array or a URL.
			// We pass the raw samples.
			const result = await this._pipeline(audioData, {
				sampling_rate: sampleRate,
				return_timestamps: false,
				language: options.language || 'en',
			});

			return {
				text: result.text || '',
				confidence: 0,
			};
		}

		createStream(callbacks, options) {
			const self = this;

			class TransformersStreamController {
				constructor() {
					this._closed = false;
				}

				push(audioData) {
					if (this._closed) {
						return;
					}
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
								confidence: 0,
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

			return new TransformersStreamController();
		}

		async destroy() {
			this._pipeline = null;
			this._initialized = false;
		}

		getModelSize(model) {
			const sizes = {
				'Xenova/whisper-tiny.en': 75 * 1024 * 1024,
				'Xenova/whisper-base.en': 142 * 1024 * 1024,
				'Xenova/whisper-small.en': 466 * 1024 * 1024,
			};
			return sizes[model] || sizes['Xenova/whisper-tiny.en'];
		}

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
	window.STTTransformersJs = STTTransformersJs;

	// Auto-register.
	if (window.STTBackendRegistry) {
		window.STTBackendRegistry.register(STTTransformersJs);
	}
})();
