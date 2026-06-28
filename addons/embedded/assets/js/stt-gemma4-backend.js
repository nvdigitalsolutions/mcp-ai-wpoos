/**
 * Gemma 4 Audio Backend
 *
 * Implements STTServiceAPI using Google Gemma 4's native audio
 * capability. Gemma 4 E2B and E4B models support audio input
 * natively — speech is transcribed as part of the multimodal
 * understanding pipeline.
 *
 * This backend sends audio to a server running Gemma 4 (Ollama,
 * vLLM, or NVIDIA NIM) via the NV oOS REST endpoint. Unlike
 * whisper.cpp (browser-only), this backend uses server-side
 * inference but leverages a single model for STT + LLM.
 *
 * When the unified mode is enabled, transcription and reasoning
 * happen in a single model call — the traditional 3-model pipeline
 * (STT → LLM → TTS) collapses to a single multimodal forward pass.
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
	 * Gemma 4 Audio STT Backend.
	 */
	class STTGemma4Audio extends window.STTServiceAPI {
		constructor() {
			super();

			/** REST endpoint for server-side transcription. */
			this._endpoint = '';

			/** Model identifier (e.g. 'gemma4:12b', 'gemma4:e4b'). */
			this._model = 'gemma4:e4b';

			/** Current language for transcription. */
			this._language = 'en';

			/** Whether to use unified mode (STT+LLM in one call). */
			this._unifiedMode = false;

			/** Nonce for WordPress REST authentication. */
			this._nonce = '';

			/** Whether initialized. */
			this._initialized = false;
		}

		getSlug() {
			return 'gemma4_audio';
		}

		getName() {
			return 'Gemma 4 Audio (Server)';
		}

		async isAvailable() {
			// This backend always requires a server endpoint.
			// Check if an endpoint is configured.
			return !!this._endpoint;
		}

		async initialize(options) {
			options = options || {};

			this._endpoint = options.endpoint || this._endpoint;
			this._model = options.model || this._model;
			this._language = options.language || this._language;
			this._unifiedMode = !!options.unifiedMode;
			this._nonce = options.nonce || this._nonce;

			if (!this._endpoint) {
				throw new Error('Gemma 4 audio endpoint not configured. Set in NV oOS Embedded Settings.');
			}

			this._initialized = true;
		}

		async transcribe(audioData, options) {
			options = options || {};

			if (!this._initialized) {
				throw new Error('Gemma 4 backend not initialized. Call initialize() first.');
			}

			const sampleRate = options.sampleRate || 16000;

			// Convert Float32 PCM to base64 WAV.
			const wavDataUrl = window.NV_oOS_AudioCaptureService
				? window.NV_oOS_AudioCaptureService.float32ToBase64Wav(audioData, sampleRate)
				: this._float32ToBase64Wav(audioData, sampleRate);

			// Build request body.
			const body = {
				audio: wavDataUrl,
				sample_rate: sampleRate,
				model: this._model,
				language: options.language || this._language,
				unified_mode: this._unifiedMode,
				prompt: options.prompt || '',
			};

			// Send to server.
			const headers = {
				'Content-Type': 'application/json',
			};

			if (this._nonce) {
				headers['X-WP-Nonce'] = this._nonce;
			}

			try {
				const response = await fetch(this._endpoint, {
					method: 'POST',
					headers: headers,
					credentials: 'same-origin',
					body: JSON.stringify(body),
				});

				if (!response.ok) {
					const errorData = await response.json().catch(function () {
						return { message: 'HTTP ' + response.status };
					});
					throw new Error(
						'Gemma 4 transcription failed: ' +
							(errorData.message || 'HTTP ' + response.status)
					);
				}

				const result = await response.json();

				return {
					text: result.text || result.transcription || '',
					confidence: result.confidence || 0,
					language: result.language || options.language || 'en',
					unified_response: result.unified_response || null,
				};
			} catch (err) {
				if (err.message && err.message.indexOf('Gemma 4') !== -1) {
					throw err;
				}
				throw new Error('Gemma 4 transcription request failed: ' + (err.message || 'Network error'));
			}
		}

		createStream(callbacks, options) {
			const self = this;

			class Gemma4StreamController {
				constructor() {
					this._closed = false;
					this._buffer = [];
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
								confidence: result.confidence,
								language: result.language || 'en',
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

			return new Gemma4StreamController();
		}

		async destroy() {
			this._initialized = false;
		}

		getModelSize(model) {
			// Gemma 4 E2B: ~2.6B params (~5GB)
			// Gemma 4 E4B: ~4.4B params (~8GB)
			// Gemma 4 12B: ~12B params (~24GB)
			const sizes = {
				'gemma4:e2b': 5 * 1024 * 1024 * 1024,
				'gemma4:e4b': 8 * 1024 * 1024 * 1024,
				'gemma4:12b': 24 * 1024 * 1024 * 1024,
			};
			return sizes[model] || 0;
		}

		// ── Private helpers ────────────────────────────────────────────

		_float32ToBase64Wav(samples, sampleRate) {
			// Fallback if AudioCaptureService is not loaded.
			if (window.NV_oOS_AudioCaptureService && window.NV_oOS_AudioCaptureService.float32ToBase64Wav) {
				return window.NV_oOS_AudioCaptureService.float32ToBase64Wav(samples, sampleRate);
			}

			// Simple inline WAV conversion.
			sampleRate = sampleRate || 16000;
			const numChannels = 1;
			const bitsPerSample = 16;
			const byteRate = sampleRate * numChannels * (bitsPerSample / 8);
			const blockAlign = numChannels * (bitsPerSample / 8);
			const dataSize = samples.length * (bitsPerSample / 8);
			const headerSize = 44;
			const buffer = new ArrayBuffer(headerSize + dataSize);
			const view = new DataView(buffer);

			function writeStr(view, offset, str) {
				for (let i = 0; i < str.length; i++) {
					view.setUint8(offset + i, str.charCodeAt(i));
				}
			}

			writeStr(view, 0, 'RIFF');
			view.setUint32(4, 36 + dataSize, true);
			writeStr(view, 8, 'WAVE');
			writeStr(view, 12, 'fmt ');
			view.setUint32(16, 16, true);
			view.setUint16(20, 1, true);
			view.setUint16(22, numChannels, true);
			view.setUint32(24, sampleRate, true);
			view.setUint32(28, byteRate, true);
			view.setUint16(32, blockAlign, true);
			view.setUint16(34, bitsPerSample, true);
			writeStr(view, 36, 'data');
			view.setUint32(40, dataSize, true);

			let offset = 44;
			for (let i = 0; i < samples.length; i++) {
				const s = Math.max(-1, Math.min(1, samples[i]));
				view.setInt16(offset, s < 0 ? s * 0x8000 : s * 0x7FFF, true);
				offset += 2;
			}

			const bytes = new Uint8Array(buffer);
			let binary = '';
			for (let i = 0; i < bytes.byteLength; i++) {
				binary += String.fromCharCode(bytes[i]);
			}
			return 'data:audio/wav;base64,' + btoa(binary);
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
	window.STTGemma4Audio = STTGemma4Audio;

	// Auto-register with the backend registry if available.
	if (window.STTBackendRegistry) {
		window.STTBackendRegistry.register(STTGemma4Audio);
	}
})();
