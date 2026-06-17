/**
 * Voice Activity Detection (VAD) AudioWorklet Processor
 *
 * Replaces the setInterval-based VAD in chat-audio-service.js with a proper
 * AudioWorklet-based implementation for sub-10ms latency voice detection.
 *
 * This processor:
 * - Down samples audio to 16kHz mono
 * - Calculates RMS energy per frame
 * - Reports speech/silence state to the main thread via messages
 *
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

class VADProcessor extends AudioWorkletProcessor {
	/**
	 * Energy threshold for speech detection (RMS).
	 * Values below this are considered silence.
	 */
	static get DEFAULT_THRESHOLD() { return 0.01; }

	/**
	 * Minimum consecutive speech frames before reporting speech started.
	 */
	static get MIN_SPEECH_FRAMES() { return 10; }

	/**
	 * Minimum consecutive silence frames before reporting speech ended.
	 */
	static get MIN_SILENCE_FRAMES() { return 30; }

	constructor(options) {
		super(options);

		// Configurable thresholds from processor options.
		const params = options.processorOptions || {};

		this.energyThreshold = params.energyThreshold || VADProcessor.DEFAULT_THRESHOLD;
		this.minSpeechFrames = params.minSpeechFrames || VADProcessor.MIN_SPEECH_FRAMES;
		this.minSilenceFrames = params.minSilenceFrames || VADProcessor.MIN_SILENCE_FRAMES;

		// Internal state.
		this.isSpeech = false;
		this.speechFrameCount = 0;
		this.silenceFrameCount = 0;
		this.sampleRate = params.sampleRate || sampleRate;
	}

	/**
	 * Calculate RMS energy of a buffer.
	 *
	 * @param {Float32Array} buffer - Audio samples.
	 * @return {number} RMS energy.
	 */
	calculateRMS(buffer) {
		let sum = 0;
		for (let i = 0; i < buffer.length; i++) {
			sum += buffer[i] * buffer[i];
		}
		return Math.sqrt(sum / buffer.length);
	}

	/**
	 * Process audio frames.
	 *
	 * @param {Array<Float32Array[]>} inputs - Input audio data.
	 * @param {Array<Float32Array[]>} outputs - Output audio data.
	 * @param {Object} parameters - Audio parameters.
	 * @return {boolean} True to keep processor alive.
	 */
	process(inputs, _outputs, _parameters) {
		const input = inputs[0];
		if (!input || !input.length || !input[0]) {
			return true;
		}

		// Use first channel for energy detection.
		const channel = input[0];
		const rms = this.calculateRMS(channel);
		const isCurrentlySpeech = rms > this.energyThreshold;

		if (isCurrentlySpeech) {
			this.speechFrameCount++;
			this.silenceFrameCount = 0;

			// Report speech start on rising edge.
			if (!this.isSpeech && this.speechFrameCount >= this.minSpeechFrames) {
				this.isSpeech = true;
				this.port.postMessage({
					type: 'speech_started',
					rms: rms,
					timestamp: Date.now(),
				});
			}
		} else {
			this.silenceFrameCount++;
			this.speechFrameCount = 0;

			// Report speech end after sustained silence.
			if (this.isSpeech && this.silenceFrameCount >= this.minSilenceFrames) {
				this.isSpeech = false;
				this.port.postMessage({
					type: 'speech_ended',
					rms: rms,
					timestamp: Date.now(),
				});
			}
		}

		// Send periodic energy level updates for visualization.
		if (this.frameCount === undefined) {
			this.frameCount = 0;
		}
		this.frameCount++;

		// Send energy level every 10 frames (~100ms at typical frame rates).
		if (this.frameCount % 10 === 0) {
			this.port.postMessage({
				type: 'energy_level',
				rms: rms,
				isSpeech: this.isSpeech,
			});
		}

		return true;
	}
}

registerProcessor('vad-processor', VADProcessor);
