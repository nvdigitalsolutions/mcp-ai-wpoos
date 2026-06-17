/**
 * VADEngine — Standalone Voice Activity Detection
 *
 * Extracted from assets/js/chat-audio-service.js (NV oOS).
 * Uses the Web Audio API AnalyserNode to detect speech vs. silence
 * in real-time from a MediaStream.
 *
 * Self-contained IIFE; exposes window.VADEngine.
 * Zero external dependencies.
 *
 * @license MIT
 */

	var DEFAULTS = {
		silenceThresholdMs: 700,
		minSpeechDurationMs: 300,
		audioLevelThreshold: -50,
		checkIntervalMs: 100,
	};

	/**
	 * @param {Object} [opts]
	 * @param {number} [opts.silenceThresholdMs=700]
	 * @param {number} [opts.minSpeechDurationMs=300]
	 * @param {number} [opts.audioLevelThreshold=-50]
	 * @param {number} [opts.checkIntervalMs=100]
	 * @param {Function} [opts.onSpeechStart]
	 * @param {Function} [opts.onSpeechEnd]
	 * @param {Function} [opts.onAutoStop]
	 * @param {Function} [opts.onDbLevel]  - receives { average, dB, isSpeech }
	 * @param {Function} [opts.onError]
	 */
	function VADEngine(opts) {
		var o = Object.assign({}, DEFAULTS, opts || {});
		this.silenceThresholdMs  = o.silenceThresholdMs;
		this.minSpeechDurationMs = o.minSpeechDurationMs;
		this.audioLevelThreshold = o.audioLevelThreshold;
		this._checkIntervalMs     = o.checkIntervalMs;

		this._onSpeechStart = o.onSpeechStart || null;
		this._onSpeechEnd   = o.onSpeechEnd   || null;
		this._onAutoStop    = o.onAutoStop    || null;
		this._onDbLevel     = o.onDbLevel     || null;
		this._onError       = o.onError       || null;

		this._audioContext  = null;
		this._analyser      = null;
		this._monitorTimer  = null;
		this._silenceStart   = null;
		this._speechStart    = null;
		this._lastSpeechTime = null;
		this._active         = false;
		this._wasSpeaking    = false;
	}

	VADEngine.prototype.init = function (stream) {
		if (this._active) return false;
		if (!stream) { this._warn('VADEngine.init: no MediaStream'); return false; }

		var AC = window.AudioContext || window.webkitAudioContext;
		if (!AC) { this._warn('VADEngine: Web Audio API not available'); return false; }

		try {
			this._audioContext = new AC();
			this._analyser = this._audioContext.createAnalyser();
			this._analyser.fftSize = 2048;
			this._analyser.smoothingTimeConstant = 0.8;

			var source = this._audioContext.createMediaStreamSource(stream);
			source.connect(this._analyser);

			this._speechStart    = Date.now();
			this._lastSpeechTime = Date.now();
			this._silenceStart   = null;
			this._wasSpeaking    = false;
			this._active         = true;

			var self = this;
			this._monitorTimer = setInterval(function () {
				self._check();
			}, this._checkIntervalMs);

			this._log('VADEngine: initialised', {
				silenceThresholdMs:  this.silenceThresholdMs,
				minSpeechDurationMs: this.minSpeechDurationMs,
				audioLevelThreshold: this.audioLevelThreshold,
			});
			return true;
		} catch (err) {
			this._warn('VADEngine: init failed', err);
			this._cleanup();
			return false;
		}
	};

	VADEngine.prototype.stop = function () {
		this._active = false;
		this._cleanup();
		if (this._wasSpeaking && this._onSpeechEnd) {
			try { this._onSpeechEnd(); } catch (e) { /* ignore */ }
			this._wasSpeaking = false;
		}
	};

	VADEngine.prototype._check = function () {
		if (!this._active || !this._analyser) return;

		try {
			var bufferLength = this._analyser.frequencyBinCount;
			var dataArray    = new Uint8Array(bufferLength);
			this._analyser.getByteFrequencyData(dataArray);

			var sum = 0;
			for (var i = 0; i < bufferLength; i++) sum += dataArray[i];
			var average  = sum / bufferLength;
			var dB       = 20 * Math.log10(average / 255);
			var isSpeech = dB > this.audioLevelThreshold;

			var now            = Date.now();
			var speechDuration = now - this._speechStart;

			if (this._onDbLevel) {
				try { this._onDbLevel({ average: average, dB: dB, isSpeech: isSpeech }); } catch (e) {}
			}

			if (isSpeech) {
				this._lastSpeechTime = now;
				this._silenceStart   = null;
				if (!this._wasSpeaking) {
					this._wasSpeaking = true;
					if (this._onSpeechStart) { try { this._onSpeechStart(); } catch (e) {} }
				}
			} else {
				if (this._silenceStart === null) this._silenceStart = now;
				var silenceDuration = now - this._silenceStart;

				if (this._wasSpeaking) {
					this._wasSpeaking = false;
					if (this._onSpeechEnd) { try { this._onSpeechEnd(); } catch (e) {} }
				}

				if (speechDuration >= this.minSpeechDurationMs && silenceDuration >= this.silenceThresholdMs) {
					this._log('VADEngine: auto-stop after ' + silenceDuration + 'ms silence');
					if (this._onAutoStop) { try { this._onAutoStop(); } catch (e) {} }
					this.stop();
				}
			}
		} catch (err) {
			this._warn('VADEngine._check error', err);
		}
	};

	VADEngine.prototype._cleanup = function () {
		if (this._monitorTimer) { clearInterval(this._monitorTimer); this._monitorTimer = null; }
		if (this._audioContext) { try { this._audioContext.close(); } catch (e) {} this._audioContext = null; }
		this._analyser = null;
		this._silenceStart = null;
		this._speechStart = null;
		this._lastSpeechTime = null;
	};

	VADEngine.prototype._log = function (msg, data) {
		if (console && console.log) console.log(msg, data || '');
	};

	VADEngine.prototype._warn = function (msg, err) {
		if (console && console.warn) console.warn(msg, err || '');
		if (this._onError && err) { try { this._onError(err); } catch (e) {} }
	};

	Object.defineProperty(VADEngine.prototype, 'active', {
		get: function () { return this._active; },
		enumerable: true,
	});

	window.VADEngine = VADEngine;

export { VADEngine };
export default VADEngine;
