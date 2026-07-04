/**
 * Voice Activity Detection (VAD) Processor
 *
 * Simple energy-based VAD for browser-side speech detection.
 * Detects speech vs silence in real-time audio streams to:
 * - Trigger STT only when speech is present
 * - Auto-stop capture after sustained silence
 * - Reduce false transcriptions from background noise
 *
 * Uses RMS (Root Mean Square) energy measurement with adaptive
 * thresholding. Runs synchronously on the main thread for
 * minimal latency — audio processing is cheap compared to STT.
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
	 * VAD states.
	 */
	const VAD_STATE = {
		SILENCE: 'silence',
		SPEECH: 'speech',
		TRANSITION: 'transition',
	};

	/**
	 * Voice Activity Detector.
	 *
	 * Usage:
	 *   const vad = new VoiceActivityDetector({ sampleRate: 16000 });
	 *   vad.onSpeechStart = () => { console.log('Speaking...'); };
	 *   vad.onSpeechEnd = () => { console.log('Silence.'); };
	 *   // Feed audio chunks:
	 *   vad.process(chunk);
	 */
	class VoiceActivityDetector {
		/**
		 * @param {Object} options
		 * @param {number} [options.sampleRate=16000]       Audio sample rate.
		 * @param {number} [options.energyThreshold=0.01]   RMS energy threshold for speech.
		 * @param {number} [options.silenceDurationMs=800]   Ms of silence before speech end.
		 * @param {number} [options.speechPadMs=200]         Ms of audio to include before speech start.
		 * @param {number} [options.minSpeechDurationMs=300] Minimum ms of speech to trigger start.
		 */
		constructor(options) {
			options = options || {};

			this.sampleRate = options.sampleRate || 16000;
			this.energyThreshold = options.energyThreshold || 0.01;
			this.silenceDurationMs = options.silenceDurationMs || 800;
			this.speechPadMs = options.speechPadMs || 200;
			this.minSpeechDurationMs = options.minSpeechDurationMs || 300;

			/** Current VAD state. */
			this.state = VAD_STATE.SILENCE;

			/** Timestamp of last speech detection (ms, based on sample count). */
			this._lastSpeechSample = 0;

			/** Total samples processed since last speech start. */
			this._speechSampleCount = 0;

			/** Total samples processed since last silence start. */
			this._silenceSampleCount = 0;

			/** Total samples processed. */
			this._totalSamples = 0;

			/** Adaptive noise floor (running average of silence energy). */
			this._noiseFloor = 0.001;

			/** Callback: () => void — speech started. */
			this.onSpeechStart = null;

			/** Callback: () => void — speech ended. */
			this.onSpeechEnd = null;

			/** Callback: (state: string) => void — state changed. */
			this.onStateChange = null;
		}

		/**
		 * Process an audio chunk and update VAD state.
		 *
		 * @param {Float32Array} samples PCM float audio samples.
		 */
		process(samples) {
			if (!samples || samples.length === 0) {
				return;
			}

			const energy = this._computeRMS(samples);
			const isSpeech = energy > this.energyThreshold;
			const chunkDurationSamples = samples.length;
			const previousState = this.state;

			this._totalSamples += chunkDurationSamples;

			if (isSpeech) {
				this._speechSampleCount += chunkDurationSamples;
				this._silenceSampleCount = 0;

				// Update noise floor when speech is detected (cap at speech energy / 10).
				this._noiseFloor = Math.max(this._noiseFloor, energy * 0.1);

				if (this.state === VAD_STATE.SILENCE) {
					this.state = VAD_STATE.TRANSITION;
				}
			} else {
				this._silenceSampleCount += chunkDurationSamples;

				// Update noise floor with silence energy.
				this._noiseFloor = 0.9 * this._noiseFloor + 0.1 * energy;

				if (this.state === VAD_STATE.SPEECH || this.state === VAD_STATE.TRANSITION) {
					const silenceMs = (this._silenceSampleCount / this.sampleRate) * 1000;
					if (silenceMs >= this.silenceDurationMs) {
						this.state = VAD_STATE.SILENCE;
					}
				}
			}

			// Check if we should transition from TRANSITION to SPEECH.
			if (this.state === VAD_STATE.TRANSITION) {
				const speechMs = (this._speechSampleCount / this.sampleRate) * 1000;
				if (speechMs >= this.minSpeechDurationMs) {
					this.state = VAD_STATE.SPEECH;
				}
			}

			// Fire callbacks.
			if (this.state !== previousState) {
				if (typeof this.onStateChange === 'function') {
					this.onStateChange(this.state);
				}

				if (this.state === VAD_STATE.SPEECH && typeof this.onSpeechStart === 'function') {
					this.onSpeechStart();
				} else if (this.state === VAD_STATE.SILENCE && previousState !== VAD_STATE.SILENCE) {
					if (typeof this.onSpeechEnd === 'function') {
						this.onSpeechEnd();
					}
					this._speechSampleCount = 0;
				}
			}
		}

		/**
		 * Reset VAD state.
		 */
		reset() {
			this.state = VAD_STATE.SILENCE;
			this._speechSampleCount = 0;
			this._silenceSampleCount = 0;
			this._totalSamples = 0;
		}

		/**
		 * Check if the detector currently thinks speech is happening.
		 *
		 * @return {boolean}
		 */
		get isSpeech() {
			return this.state === VAD_STATE.SPEECH || this.state === VAD_STATE.TRANSITION;
		}

		/**
		 * Get the current energy threshold (adaptive).
		 *
		 * @return {number}
		 */
		get currentThreshold() {
			return Math.max(this.energyThreshold, this._noiseFloor * 3);
		}

		// ── Private helpers ────────────────────────────────────────────

		/**
		 * Compute Root Mean Square energy of audio samples.
		 *
		 * @param {Float32Array} samples
		 * @return {number} RMS energy (0 to 1).
		 */
		_computeRMS(samples) {
			let sum = 0;
			for (let i = 0; i < samples.length; i++) {
				sum += samples[i] * samples[i];
			}
			return Math.sqrt(sum / samples.length);
		}
	}

	// Export states as static properties.
	VoiceActivityDetector.SILENCE = VAD_STATE.SILENCE;
	VoiceActivityDetector.SPEECH = VAD_STATE.SPEECH;
	VoiceActivityDetector.TRANSITION = VAD_STATE.TRANSITION;

	// Export to global scope.
	window.NV_oOS_VoiceActivityDetector = VoiceActivityDetector;
})();
