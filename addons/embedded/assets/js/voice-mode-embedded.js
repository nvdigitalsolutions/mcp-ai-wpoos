/**
 * Embedded Voice Mode Manager
 *
 * Provides voice mode for embedded/WebLLM assistants, mirroring the
 * cloud voice UX from chat-voice-mode-integration.js (PR #5479).
 *
 * Features:
 *   - Push-to-Talk (PTT) button with hold/release behavior
 *   - Real-time waveform visualization
 *   - Live transcription overlay
 *   - Multi-backend STT (whisper.cpp WASM, Transformers.js, Gemma 4)
 *   - Voice Activity Detection for auto-start/stop
 *   - Transcript injection into the embedded LLM chat pipeline
 *
 * @package NV_oOS_Embedded
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  Proprietary
 */

(function () {
	'use strict';

	// ── Constants ──────────────────────────────────────────────────

	const MODE_OFF = 'off';
	const MODE_LISTENING = 'listening';
	const MODE_PROCESSING = 'processing';

	/**
	 * Embedded Voice Mode Manager.
	 *
	 * Singleton per chat instance. Created when voice mode is
	 * enabled for an embedded assistant.
	 */
	class EmbeddedVoiceMode {
		/**
		 * @param {Object} options
		 * @param {HTMLElement} options.container     Chat container element.
		 * @param {Object}      options.config        Embedded client configuration.
		 * @param {Function}    options.onTranscript  (text) => void — transcript ready.
		 * @param {Function}    options.onStateChange (state) => void — state changed.
		 * @param {Function}    options.onError       (error) => void — error occurred.
		 */
		constructor(options) {
			options = options || {};

			this.container = options.container;
			this.config = options.config || {};
			this.onTranscript = options.onTranscript || null;
			this.onStateChange = options.onStateChange || null;
			this.onError = options.onError || null;

			/** Current voice mode state. */
			this.mode = MODE_OFF;

			/** Audio capture service instance. */
			this.capture = null;

			/** STT backend instance. */
			this.sttBackend = null;

			/** VAD instance. */
			this.vad = null;

			/** UI elements. */
			this.ui = {};

			/** Whether initialized. */
			this._initialized = false;

			/** PTT button pressed state. */
			this._pttPressed = false;

			/** Transcription buffer for interim results. */
			this._transcriptionBuffer = '';

			/** Waveform animation ID. */
			this._waveformAnimationId = null;
		}

		/**
		 * Initialize voice mode — load STT backend, build UI.
		 *
		 * @return {Promise<void>}
		 */
		async init() {
			if (this._initialized) {
				return;
			}

			const self = this;

			// Determine which STT backend to use from config.
			const backendSlug = this.config.sttBackend || 'whisper_cpp_wasm';

			// Build the UI.
			this._buildUI();

			// Load the STT backend.
			try {
				await this._loadBackend(backendSlug);
			} catch (err) {
				console.error('[NV oOS Embedded] Failed to load STT backend:', err);
				this._showError('Failed to load speech recognition: ' + err.message);
				return;
			}

			// Set up VAD.
			this.vad = new window.NV_oOS_VoiceActivityDetector({
				sampleRate: 16000,
				energyThreshold: this.config.vadThreshold || 0.01,
				silenceDurationMs: this.config.vadSilenceDuration || 800,
			});

			this.vad.onSpeechStart = function () {
				self._setMode(MODE_LISTENING);
			};

			this.vad.onSpeechEnd = async function () {
				self._setMode(MODE_PROCESSING);
				await self._finalizeTranscription();
				self._setMode(MODE_OFF);
			};

			// Set up audio capture.
			this.capture = new window.NV_oOS_AudioCaptureService({
				sampleRate: 16000,
			});

			this.capture.onAudio = function (chunk) {
				self._onAudioChunk(chunk);
			};

			this.capture.onError = function (err) {
				console.error('[NV oOS Embedded] Audio capture error:', err);
				self._showError('Microphone error: ' + err.message);
			};

			this._initialized = true;
		}

		/**
		 * Start push-to-talk recording.
		 */
		async startPTT() {
			if (this._pttPressed) {
				return;
			}

			this._pttPressed = true;
			this._transcriptionBuffer = '';

			try {
				await this.capture.start();
				this._setMode(MODE_LISTENING);
			} catch (err) {
				this._pttPressed = false;
				this._showError('Could not start recording: ' + err.message);
			}
		}

		/**
		 * Stop push-to-talk recording and transcribe.
		 */
		async stopPTT() {
			if (!this._pttPressed) {
				return;
			}

			this._pttPressed = false;

			this.capture.stop();

			if (this.capture.totalSamples > 0) {
				this._setMode(MODE_PROCESSING);
				await this._finalizeTranscription();
			}

			this._setMode(MODE_OFF);
		}

		/**
		 * Destroy the voice mode instance — clean up resources.
		 */
		async destroy() {
			if (this.capture) {
				this.capture.stop();
				this.capture = null;
			}

			if (this.sttBackend) {
				await this.sttBackend.destroy();
				this.sttBackend = null;
			}

			this.vad = null;
			this._removeUI();
			this._initialized = false;
			this.mode = MODE_OFF;
		}

		// ── UI Building ────────────────────────────────────────────────

		/**
		 * Build the voice mode UI inside the chat container.
		 */
		_buildUI() {
			if (!this.container) {
				return;
			}

			const self = this;

			// Status bar.
			const statusBar = document.createElement('div');
			statusBar.className = 'nvoos-embedded-voice-status';
			statusBar.setAttribute('aria-live', 'polite');
			statusBar.textContent = 'Voice ready';
			this.ui.statusBar = statusBar;
			this.container.appendChild(statusBar);

			// Transcription overlay.
			const transcription = document.createElement('div');
			transcription.className = 'nvoos-embedded-voice-transcription';
			transcription.style.display = 'none';
			this.ui.transcription = transcription;
			this.container.appendChild(transcription);

			// Waveform canvas.
			const canvas = document.createElement('canvas');
			canvas.className = 'nvoos-embedded-voice-waveform';
			canvas.width = 200;
			canvas.height = 40;
			canvas.style.display = 'none';
			this.ui.waveform = canvas;
			this.container.appendChild(canvas);

			// PTT button.
			const pttWrap = document.createElement('div');
			pttWrap.className = 'nvoos-embedded-voice-controls';

			const pttButton = document.createElement('button');
			pttButton.className = 'nvoos-embedded-ptt-button';
			pttButton.type = 'button';
			pttButton.setAttribute('aria-label', 'Push to Talk');

			const pttIcon = document.createElement('span');
			pttIcon.className = 'nvoos-embedded-ptt-icon';
			pttIcon.innerHTML = '&#x1F399;'; // Microphone emoji
			pttButton.appendChild(pttIcon);

			// PTT: hold to record, release to transcribe.
			pttButton.addEventListener('mousedown', function (e) {
				e.preventDefault();
				self.startPTT();
			});

			pttButton.addEventListener('mouseup', function (e) {
				e.preventDefault();
				self.stopPTT();
			});

			pttButton.addEventListener('mouseleave', function () {
				if (self._pttPressed) {
					self.stopPTT();
				}
			});

			// Touch events for mobile.
			pttButton.addEventListener('touchstart', function (e) {
				e.preventDefault();
				self.startPTT();
			});

			pttButton.addEventListener('touchend', function (e) {
				e.preventDefault();
				self.stopPTT();
			});

			pttWrap.appendChild(pttButton);
			this.container.appendChild(pttWrap);

			this.ui.pttButton = pttButton;
			this.ui.pttWrap = pttWrap;
		}

		/**
		 * Remove voice UI elements.
		 */
		_removeUI() {
			Object.keys(this.ui).forEach(function (key) {
				const el = this.ui[key];
				if (el && el.parentNode) {
					el.parentNode.removeChild(el);
				}
			}, this);
			this.ui = {};
		}

		// ── Audio processing ──────────────────────────────────────────

		/**
		 * Handle an audio chunk from the capture service.
		 *
		 * @param {Float32Array} chunk
		 */
		_onAudioChunk(chunk) {
			// Run VAD.
			if (this.vad) {
				this.vad.process(chunk);
			}

			// Update waveform.
			this._updateWaveform(chunk);
		}

		/**
		 * Finalize transcription — send captured audio to STT backend.
		 *
		 * @return {Promise<void>}
		 */
		async _finalizeTranscription() {
			if (!this.sttBackend || !this.capture) {
				return;
			}

			const audioData = this.capture.flush();
			if (audioData.length === 0) {
				return;
			}

			try {
				const result = await this.sttBackend.transcribe(audioData, {
					sampleRate: 16000,
					language: this.config.sttLanguage || 'en',
				});

				const text = result.text || '';
				this._transcriptionBuffer = text;

				// Show transcription in overlay.
				this._showTranscription(text);

				// Fire callback.
				if (typeof this.onTranscript === 'function') {
					this.onTranscript(text);
				}
			} catch (err) {
				console.error('[NV oOS Embedded] Transcription failed:', err);
				this._showError('Transcription failed: ' + err.message);
			}
		}

		// ── Backend loading ───────────────────────────────────────────

		/**
		 * Load and initialize the STT backend.
		 *
		 * @param {string} slug Backend slug.
		 * @return {Promise<void>}
		 */
		async _loadBackend(slug) {
			const backend = window.STTBackendRegistry
				? window.STTBackendRegistry.create(slug)
				: null;

			if (!backend) {
				throw new Error('STT backend not found: ' + slug);
			}

			// Check availability.
			const available = await backend.isAvailable();
			if (!available) {
				throw new Error('STT backend is not available in this browser: ' + slug);
			}

			const self = this;

			backend.onProgress = function (progress) {
				self._setStatus(progress.message || 'Loading...');
			};

			backend.onError = function (err) {
				self._showError(err.message);
			};

			// Initialize with model and base URL.
			await backend.initialize({
				model: this.config.sttModel || 'tiny.en',
				config: this.config.sttConfig || {},
				endpoint: this.config.sttEndpoint || '',
				nonce: this.config.restNonce || '',
			});

			this.sttBackend = backend;
		}

		// ── UI updates ────────────────────────────────────────────────

		/**
		 * Set the voice mode and update UI.
		 *
		 * @param {string} mode
		 */
		_setMode(mode) {
			const previousMode = this.mode;
			this.mode = mode;

			// Update PTT button state.
			if (this.ui.pttButton) {
				this.ui.pttButton.classList.remove('active', 'processing');
				if (mode === MODE_LISTENING) {
					this.ui.pttButton.classList.add('active');
				} else if (mode === MODE_PROCESSING) {
					this.ui.pttButton.classList.add('processing');
				}
			}

			// Update waveform visibility.
			if (this.ui.waveform) {
				this.ui.waveform.style.display = mode === MODE_LISTENING ? 'block' : 'none';
			}

			// Update status.
			const statusMessages = {
				off: 'Voice ready',
				listening: 'Listening...',
				processing: 'Processing...',
			};
			this._setStatus(statusMessages[mode] || '');

			// Fire state change.
			if (mode !== previousMode && typeof this.onStateChange === 'function') {
				this.onStateChange(mode);
			}
		}

		/**
		 * Set the status bar text.
		 *
		 * @param {string} text
		 */
		_setStatus(text) {
			if (this.ui.statusBar) {
				this.ui.statusBar.textContent = text;
			}
		}

		/**
		 * Show a transcription in the overlay.
		 *
		 * @param {string} text
		 */
		_showTranscription(text) {
			if (this.ui.transcription) {
				this.ui.transcription.textContent = text;
				this.ui.transcription.style.display = text ? 'block' : 'none';

				// Auto-hide after 3 seconds.
				if (text) {
					clearTimeout(this._transcriptionTimeout);
					this._transcriptionTimeout = setTimeout(() => {
						if (this.ui.transcription) {
							this.ui.transcription.style.display = 'none';
						}
					}, 3000);
				}
			}
		}

		/**
		 * Display an error message.
		 *
		 * @param {string} message
		 */
		_showError(message) {
			this._setStatus('Error: ' + message);
			if (typeof this.onError === 'function') {
				this.onError(new Error(message));
			}
		}

		// ── Waveform animation ────────────────────────────────────────

		/**
		 * Update the waveform visualization.
		 *
		 * @param {Float32Array} samples Latest audio chunk.
		 */
		_updateWaveform(samples) {
			if (!this.ui.waveform || this.mode !== MODE_LISTENING) {
				return;
			}

			const canvas = this.ui.waveform;
			const ctx = canvas.getContext('2d');
			const w = canvas.width;
			const h = canvas.height;

			ctx.clearRect(0, 0, w, h);

			// Draw a simple bar visualization.
			const barCount = 20;
			const barWidth = w / barCount - 1;
			const step = Math.max(1, Math.floor(samples.length / barCount));

			ctx.fillStyle = '#2271b1';

			for (let i = 0; i < barCount; i++) {
				let sum = 0;
				const start = i * step;
				const end = Math.min(start + step, samples.length);
				for (let j = start; j < end; j++) {
					sum += Math.abs(samples[j]);
				}
				const avg = sum / (end - start);
				const barHeight = Math.min(h - 4, avg * h * 5);
				const x = i * (barWidth + 1);
				const y = h - barHeight - 2;
				ctx.fillRect(x, y, barWidth, barHeight);
			}
		}
	}

	// Export.
	window.NV_oOS_EmbeddedVoiceMode = EmbeddedVoiceMode;
})();
