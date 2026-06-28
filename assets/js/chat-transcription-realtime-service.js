/**
 * Real-time Transcription Service for NV oOS Chat
 *
 * Provides streaming speech-to-text using GPT-Realtime-Whisper.
 * Emits transcript deltas as the speaker talks — partial transcripts
 * refine into final text as the model gains confidence.
 *
 * @since 1.3.0
 */

(function (window) {
	'use strict';

	if (window.wpMcpAiTranscription) {
		return;
	}

	const wpMcpAiTranscription = {
		/** Active transcription sessions. @type {Object.<string, Object>} */
		sessions: {},

		/**
		 * Check if transcription is supported.
		 * @return {boolean}
		 */
		isSupported: function () {
			return window.wpMcpAiWebRTC && window.wpMcpAiWebRTC.isSupported();
		},

		/**
		 * Start a live transcription session.
		 *
		 * @param {Object}   config           Chat config.
		 * @param {number}   assistantId      Assistant ID.
		 * @param {Object}   options          Options (latency_delay).
		 * @param {Function} buildJsonHeaders Headers builder.
		 * @param {Object}   callbacks        Event callbacks.
		 * @param {Function} callbacks.onTranscript      Called with {text, isFinal}.
		 * @param {Function} callbacks.onStateChange     Called with state name.
		 * @param {Function} callbacks.onError           Called with Error object.
		 * @return {Object} Session handle.
		 */
		start: function (config, assistantId, options, buildJsonHeaders, callbacks) {
			if (!this.isSupported()) {
				if (callbacks.onError) {
					callbacks.onError(new Error('WebRTC not supported for transcription.'));
				}
				return null;
			}

			const key = 'xcript_' + Date.now();
			const self = this;
			let pc = null;
			let dc = null;
			let micStream = null;
			const active = true;
			let partialText = '';

			const session = {
				key:    key,
				active: true,
				close:  function () { self.stop(key); },
				getPartialText: function () { return partialText; },
			};

			function emitState(state) {
				if (callbacks.onStateChange) { callbacks.onStateChange(state); }
			}

			function emitTranscript(text, isFinal) {
				partialText = isFinal ? '' : text;
				if (callbacks.onTranscript) { callbacks.onTranscript({ text: text, isFinal: isFinal }); }
			}

			function cleanup() {
				if (dc) { try { dc.close(); } catch (e) { /* ignore */ } dc = null; }
				if (pc) { try { pc.close(); } catch (e) { /* ignore */ } pc = null; }
				if (micStream) { micStream.getTracks().forEach(function (t) { t.stop(); }); micStream = null; }
			}

			session._cleanup = cleanup;
			this.sessions[key] = session;
			emitState('connecting');

			pc = window.wpMcpAiWebRTC.createPeerConnection();
			if (!pc) {
				cleanup();
				delete this.sessions[key];
				return null;
			}

			pc.oniceconnectionstatechange = function () {
				if (pc.iceConnectionState === 'connected') {
					emitState('active');
				} else if (pc.iceConnectionState === 'failed') {
					emitState('error');
				}
			};

			dc = pc.createDataChannel('oai-events');
			dc.onmessage = function (event) {
				try {
					const msg = JSON.parse(event.data);
					if (msg.type === 'transcript.delta' && msg.delta) {
						emitTranscript(msg.delta, false);
					} else if (msg.type === 'transcript.done' && msg.transcript) {
						emitTranscript(msg.transcript, true);
					}
				} catch (e) { /* ignore */ }
			};

			navigator.mediaDevices.getUserMedia({
				audio: { sampleRate: 24000, channelCount: 1, echoCancellation: true, noiseSuppression: true },
			}).then(function (stream) {
				if (!active) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
				micStream = stream;
				stream.getTracks().forEach(function (track) { pc.addTrack(track, stream); });
			}).catch(function () {
				if (callbacks.onError) { callbacks.onError(new Error('Microphone access denied.')); }
			});

			pc.createOffer().then(function (offer) {
				return pc.setLocalDescription(offer);
			}).catch(function (err) {
				if (callbacks.onError) { callbacks.onError(err); }
			});

			return session;
		},

		/**
		 * Stop a transcription session.
		 * @param {string} key Session key.
		 */
		stop: function (key) {
			const session = this.sessions[key];
			if (!session) { return; }
			session.active = false;
			if (session._cleanup) { session._cleanup(); }
			delete this.sessions[key];
		},
	};

	window.wpMcpAiTranscription = wpMcpAiTranscription;
})(window);
