/**
 * Audio Capture Service
 *
 * Provides browser-side microphone capture using AudioWorklet for
 * low-latency audio processing. Falls back to ScriptProcessorNode
 * when AudioWorklet is unavailable.
 *
 * Exposes a stream of raw Float32 PCM samples for downstream STT
 * processing. Audio data never leaves the browser — privacy-first.
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
	 * AudioWorklet processor script (inlined as a Blob URL).
	 *
	 * Runs in a dedicated real-time audio thread for minimal latency.
	 * Captures raw PCM float samples and posts them to the main thread.
	 */
	const AUDIO_PROCESSOR_CODE = `
		class NV_oOS_Audio_Capture_Processor extends AudioWorkletProcessor {
			static get parameterDescriptors() {
				return [{ name: 'gain', defaultValue: 1.0, minValue: 0.0, maxValue: 2.0 }];
			}

			process(inputs, outputs, parameters) {
				const input = inputs[0];
				if (!input || !input.length || !input[0]) {
					return true;
				}

				const channelData = input[0];
				const gain = parameters.gain.length > 0 ? parameters.gain[0] : 1.0;

				// Copy and apply gain.
				const buffer = new Float32Array(channelData.length);
				for (let i = 0; i < channelData.length; i++) {
					buffer[i] = channelData[i] * gain;
				}

				this.port.postMessage({ type: 'audio', data: buffer }, [buffer.buffer]);
				return true;
			}
		}

		registerProcessor('nvoos-audio-capture', NV_oOS_Audio_Capture_Processor);
	`;

	/** Blob URL for the AudioWorklet processor — created once. */
	let processorBlobUrl = null;

	/**
	 * Create a Blob URL for the AudioWorklet processor.
	 *
	 * @return {string} Blob URL.
	 */
	function getProcessorUrl() {
		if (!processorBlobUrl) {
			const blob = new Blob([AUDIO_PROCESSOR_CODE], { type: 'application/javascript' });
			processorBlobUrl = URL.createObjectURL(blob);
		}
		return processorBlobUrl;
	}

	/**
	 * Audio Capture Service.
	 *
	 * Usage:
	 *   const capture = new AudioCaptureService({ sampleRate: 16000 });
	 *   capture.onAudio = (samples) => { ... };
	 *   await capture.start();
	 *   // ... recording ...
	 *   capture.stop();
	 */
	class AudioCaptureService {
		/**
		 * @param {Object} options
		 * @param {number}  [options.sampleRate=16000]   Target sample rate (Hz).
		 * @param {number}  [options.channelCount=1]     Number of channels (1 = mono).
		 * @param {number}  [options.bufferSize=4096]    Processing buffer size in samples.
		 * @param {number}  [options.gain=1.0]           Input gain multiplier.
		 * @param {boolean} [options.echoCancellation=true] Enable AEC.
		 * @param {boolean} [options.noiseSuppression=true] Enable noise suppression.
		 * @param {boolean} [options.autoGainControl=true]  Enable auto gain.
		 */
		constructor(options) {
			options = options || {};

			this.sampleRate = options.sampleRate || 16000;
			this.channelCount = options.channelCount || 1;
			this.bufferSize = options.bufferSize || 4096;
			this.gain = typeof options.gain === 'number' ? options.gain : 1.0;
			this.echoCancellation = options.echoCancellation !== false;
			this.noiseSuppression = options.noiseSuppression !== false;
			this.autoGainControl = options.autoGainControl !== false;

			/** @type {AudioContext|null} */
			this.audioContext = null;

			/** @type {MediaStream|null} */
			this.microphoneStream = null;

			/** @type {AudioWorkletNode|null} */
			this.workletNode = null;

			/** @type {ScriptProcessorNode|null} */
			this.scriptNode = null;

			/** @type {MediaStreamAudioSourceNode|null} */
			this.sourceNode = null;

			/** Whether capture is currently active. */
			this.isActive = false;

			/** Callback: (Float32Array) => void — raw PCM samples. */
			this.onAudio = null;

			/** Callback: (Error) => void — capture error. */
			this.onError = null;

			/** Callback: () => void — capture started. */
			this.onStart = null;

			/** Callback: () => void — capture stopped. */
			this.onStop = null;

			/** Accumulated audio buffer for chunk-based processing. */
			this._buffer = [];
			this._bufferLength = 0;
		}

		/**
		 * Check if AudioWorklet is supported.
		 *
		 * @return {boolean}
		 */
		static isAudioWorkletSupported() {
			return (
				typeof AudioContext !== 'undefined' &&
				typeof AudioWorkletNode !== 'undefined' &&
				typeof AudioContext.prototype.audioWorklet !== 'undefined'
			);
		}

		/**
		 * Check if getUserMedia is available.
		 *
		 * @return {boolean}
		 */
		static isMicrophoneSupported() {
			return !!(
				navigator.mediaDevices &&
				navigator.mediaDevices.getUserMedia
			);
		}

		/**
		 * Start capturing audio from the microphone.
		 *
		 * @return {Promise<void>}
		 */
		async start() {
			if (this.isActive) {
				return;
			}

			try {
				await this._ensureAudioContext();
				await this._requestMicrophone();
				await this._setupProcessor();
				this.isActive = true;

				if (typeof this.onStart === 'function') {
					this.onStart();
				}
			} catch (err) {
				if (typeof this.onError === 'function') {
					this.onError(err);
				}
				throw err;
			}
		}

		/**
		 * Stop capturing audio.
		 */
		stop() {
			if (!this.isActive) {
				return;
			}

			this.isActive = false;

			// Disconnect and clean up the processor node.
			if (this.workletNode) {
				this.workletNode.disconnect();
				this.workletNode.port.onmessage = null;
				this.workletNode = null;
			}

			if (this.scriptNode) {
				this.scriptNode.disconnect();
				this.scriptNode.onaudioprocess = null;
				this.scriptNode = null;
			}

			// Stop microphone tracks.
			if (this.microphoneStream) {
				this.microphoneStream.getTracks().forEach(function (track) {
					track.stop();
				});
				this.microphoneStream = null;
			}

			// Close AudioContext on next idle to avoid creation limits.
			if (this.audioContext && this.audioContext.state !== 'closed') {
				const ctx = this.audioContext;
				this.audioContext = null;
				ctx.close().catch(function () {
					// Ignore close errors.
				});
			}

			this.sourceNode = null;
			this._buffer = [];
			this._bufferLength = 0;

			if (typeof this.onStop === 'function') {
				this.onStop();
			}
		}

		/**
		 * Flush any buffered audio and return it as a single Float32Array.
		 *
		 * @return {Float32Array} Combined audio buffer (may be empty).
		 */
		flush() {
			const result = this._combineBuffers();
			this._buffer = [];
			this._bufferLength = 0;
			return result;
		}

		/**
		 * Get the total number of samples captured so far.
		 *
		 * @return {number}
		 */
		get totalSamples() {
			return this._bufferLength;
		}

		/**
		 * Get the duration of captured audio in seconds.
		 *
		 * @return {number}
		 */
		get duration() {
			return this.sampleRate > 0 ? this._bufferLength / this.sampleRate : 0;
		}

		// ── Private helpers ────────────────────────────────────────────

		/**
		 * Ensure an AudioContext exists and is in 'running' state.
		 *
		 * @return {Promise<void>}
		 */
		async _ensureAudioContext() {
			if (this.audioContext && this.audioContext.state !== 'closed') {
				if (this.audioContext.state === 'suspended') {
					await this.audioContext.resume();
				}
				return;
			}

			const AudioCtx = window.AudioContext || window.webkitAudioContext;
			if (!AudioCtx) {
				throw new Error('AudioContext is not supported in this browser.');
			}

			this.audioContext = new AudioCtx({ sampleRate: this.sampleRate });

			if (this.audioContext.state === 'suspended') {
				await this.audioContext.resume();
			}
		}

		/**
		 * Request microphone access via getUserMedia.
		 *
		 * @return {Promise<void>}
		 */
		async _requestMicrophone() {
			if (this.microphoneStream) {
				return;
			}

			try {
				this.microphoneStream = await navigator.mediaDevices.getUserMedia({
					audio: {
						sampleRate: { ideal: this.sampleRate },
						channelCount: { ideal: this.channelCount },
						echoCancellation: this.echoCancellation,
						noiseSuppression: this.noiseSuppression,
						autoGainControl: this.autoGainControl,
					},
					video: false,
				});
			} catch (err) {
				throw new Error(
					'Microphone access denied or unavailable: ' + (err.message || 'Unknown error')
				);
			}
		}

		/**
		 * Set up the audio processing pipeline (AudioWorklet or fallback).
		 *
		 * @return {Promise<void>}
		 */
		async _setupProcessor() {
			if (!this.audioContext) {
				throw new Error('AudioContext not initialized.');
			}

			this.sourceNode = this.audioContext.createMediaStreamSource(this.microphoneStream);

			const self = this;

			if (AudioCaptureService.isAudioWorkletSupported()) {
				await this._setupAudioWorklet();
			} else {
				this._setupScriptProcessor();
			}
		}

		/**
		 * Set up AudioWorklet-based processing (preferred).
		 *
		 * @return {Promise<void>}
		 */
		async _setupAudioWorklet() {
			const self = this;

			try {
				await this.audioContext.audioWorklet.addModule(getProcessorUrl());
			} catch (err) {
				console.warn(
					'[NV oOS Embedded] AudioWorklet module load failed, falling back to ScriptProcessorNode:',
					err.message
				);
				this._setupScriptProcessor();
				return;
			}

			this.workletNode = new AudioWorkletNode(this.audioContext, 'nvoos-audio-capture', {
				numberOfInputs: 1,
				numberOfOutputs: 0,
				channelCount: this.channelCount,
				processorOptions: {},
			});

			// Set gain parameter.
			const gainParam = this.workletNode.parameters.get('gain');
			if (gainParam) {
				gainParam.value = this.gain;
			}

			this.workletNode.port.onmessage = function (event) {
				if (!self.isActive) {
					return;
				}
				if (event.data && event.data.type === 'audio' && event.data.data) {
					self._handleAudioChunk(event.data.data);
				}
			};

			this.workletNode.onprocessorerror = function (err) {
				console.error('[NV oOS Embedded] AudioWorklet processor error:', err);
			};

			this.sourceNode.connect(this.workletNode);
		}

		/**
		 * Set up ScriptProcessorNode fallback (legacy browsers).
		 */
		_setupScriptProcessor() {
			const self = this;

			// Create with a reasonable buffer size.
			const bufferSize = Math.min(this.bufferSize, 16384);
			this.scriptNode = this.audioContext.createScriptProcessor(bufferSize, this.channelCount, 1);

			this.scriptNode.onaudioprocess = function (event) {
				if (!self.isActive) {
					return;
				}
				const inputData = event.inputBuffer.getChannelData(0);
				if (inputData && inputData.length) {
					// Apply gain manually since ScriptProcessorNode has no parameters.
					const copy = new Float32Array(inputData.length);
					for (let i = 0; i < inputData.length; i++) {
						copy[i] = inputData[i] * self.gain;
					}
					self._handleAudioChunk(copy);
				}
			};

			this.sourceNode.connect(this.scriptNode);
			this.scriptNode.connect(this.audioContext.destination);
		}

		/**
		 * Handle an incoming audio chunk.
		 *
		 * @param {Float32Array} chunk PCM samples.
		 */
		_handleAudioChunk(chunk) {
			this._buffer.push(chunk);
			this._bufferLength += chunk.length;

			if (typeof this.onAudio === 'function') {
				try {
					this.onAudio(chunk);
				} catch (err) {
					console.error('[NV oOS Embedded] Audio callback error:', err);
				}
			}
		}

		/**
		 * Combine all buffered chunks into a single Float32Array.
		 *
		 * @return {Float32Array}
		 */
		_combineBuffers() {
			if (this._buffer.length === 0) {
				return new Float32Array(0);
			}
			if (this._buffer.length === 1) {
				return this._buffer[0];
			}

			const result = new Float32Array(this._bufferLength);
			let offset = 0;
			for (let i = 0; i < this._buffer.length; i++) {
				result.set(this._buffer[i], offset);
				offset += this._buffer[i].length;
			}
			return result;
		}

		/**
		 * Convert Float32 PCM samples to WAV format (Int16).
		 *
		 * @param {Float32Array} samples PCM float samples (-1.0 to 1.0).
		 * @param {number}       sampleRate Sample rate in Hz.
		 * @return {ArrayBuffer} WAV-encoded buffer.
		 */
		static float32ToWav(samples, sampleRate) {
			sampleRate = sampleRate || 16000;
			const numChannels = 1;
			const bitsPerSample = 16;
			const byteRate = sampleRate * numChannels * (bitsPerSample / 8);
			const blockAlign = numChannels * (bitsPerSample / 8);
			const dataSize = samples.length * (bitsPerSample / 8);
			const headerSize = 44;
			const buffer = new ArrayBuffer(headerSize + dataSize);
			const view = new DataView(buffer);

			// RIFF header.
			writeString(view, 0, 'RIFF');
			view.setUint32(4, 36 + dataSize, true);
			writeString(view, 8, 'WAVE');

			// fmt chunk.
			writeString(view, 12, 'fmt ');
			view.setUint32(16, 16, true);          // chunk size
			view.setUint16(20, 1, true);           // PCM format
			view.setUint16(22, numChannels, true);
			view.setUint32(24, sampleRate, true);
			view.setUint32(28, byteRate, true);
			view.setUint16(32, blockAlign, true);
			view.setUint16(34, bitsPerSample, true);

			// data chunk.
			writeString(view, 36, 'data');
			view.setUint32(40, dataSize, true);

			// Write samples.
			let offset = 44;
			for (let i = 0; i < samples.length; i++) {
				const s = Math.max(-1, Math.min(1, samples[i]));
				view.setInt16(offset, s < 0 ? s * 0x8000 : s * 0x7FFF, true);
				offset += 2;
			}

			return buffer;
		}

		/**
		 * Convert Float32 PCM samples to base64-encoded WAV string.
		 *
		 * @param {Float32Array} samples    PCM float samples.
		 * @param {number}       sampleRate Sample rate in Hz.
		 * @return {string} Base64-encoded WAV data URI.
		 */
		static float32ToBase64Wav(samples, sampleRate) {
			const wavBuffer = AudioCaptureService.float32ToWav(samples, sampleRate);
			const bytes = new Uint8Array(wavBuffer);
			let binary = '';
			for (let i = 0; i < bytes.byteLength; i++) {
				binary += String.fromCharCode(bytes[i]);
			}
			return 'data:audio/wav;base64,' + btoa(binary);
		}
	}

	/**
	 * Write a string to a DataView at the given offset.
	 *
	 * @param {DataView} view   Target DataView.
	 * @param {number}   offset Byte offset.
	 * @param {string}   str    ASCII string.
	 */
	function writeString(view, offset, str) {
		for (let i = 0; i < str.length; i++) {
			view.setUint8(offset + i, str.charCodeAt(i));
		}
	}

	// Export to global scope.
	window.NV_oOS_AudioCaptureService = AudioCaptureService;
})();
