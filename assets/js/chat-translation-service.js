/**
 * Real-time Translation Service for NV oOS Chat
 *
 * Provides live speech translation using GPT-Realtime-Translate.
 * Supports 70+ input languages → 13 output languages. Uses WebRTC
 * for browser audio capture/playback.
 *
 * @since 1.3.0
 */

(function (window) {
	'use strict';

	if (window.wpMcpAiTranslation) {
		return;
	}

	const wpMcpAiTranslation = {
		/** Active translation sessions. @type {Object.<string, Object>} */
		sessions: {},

		/**
		 * Check if translation is supported.
		 * @return {boolean}
		 */
		isSupported: function () {
			return window.wpMcpAiWebRTC && window.wpMcpAiWebRTC.isSupported();
		},

		/**
		 * Start a live translation session.
		 *
		 * @param {Object}   config           Chat config.
		 * @param {string}   inputLang        Source language code.
		 * @param {string}   outputLang       Target language code.
		 * @param {Function} buildJsonHeaders Headers builder.
		 * @param {Object}   callbacks        Event callbacks.
		 * @param {Function} callbacks.onTranslatedText  Called with translated transcript.
		 * @param {Function} callbacks.onStateChange     Called with state name.
		 * @param {Function} callbacks.onError           Called with Error object.
		 * @return {Object} Session handle.
		 */
		start: function (config, inputLang, outputLang, buildJsonHeaders, callbacks) {
			if (!this.isSupported()) {
				if (callbacks.onError) {
					callbacks.onError(new Error('WebRTC not supported for translation.'));
				}
				return null;
			}

			const key = 'trans_' + Date.now();
			const self = this;
			let pc = null;
			let dc = null;
			let micStream = null;
			let audioEl = null;
			const active = true;

			const session = {
				key:    key,
				active: true,
				close:  function () { self.stop(key); },
			};

			function emitState(state) {
				if (callbacks.onStateChange) { callbacks.onStateChange(state); }
			}

			function cleanup() {
				if (dc) { try { dc.close(); } catch (e) { /* ignore */ } dc = null; }
				if (pc) { try { pc.close(); } catch (e) { /* ignore */ } pc = null; }
				if (micStream) { micStream.getTracks().forEach(function (t) { t.stop(); }); micStream = null; }
				if (audioEl) { audioEl.srcObject = null; audioEl = null; }
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

			pc.ontrack = function (event) {
				if (!audioEl) {
					audioEl = document.createElement('audio');
					audioEl.autoplay = true;
				}
				audioEl.srcObject = event.streams[0];
			};

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
					if (msg.type === 'transcript.delta' && callbacks.onTranslatedText && msg.delta) {
						callbacks.onTranslatedText(msg.delta);
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
		 * Stop a translation session.
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

	window.wpMcpAiTranslation = wpMcpAiTranslation;
})(window);
