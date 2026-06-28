/**
 * WebRTC Voice Service for NV oOS Chat
 *
 * Provides WebRTC-based realtime speech-to-speech (S2S) voice chat using
 * OpenAI's GA Realtime API. Replaces the legacy WebSocket approach with
 * native browser WebRTC for sub-200ms latency, automatic echo cancellation,
 * and simpler audio handling.
 *
 * Supports two connection patterns:
 * - Ephemeral token: server mints a short-lived key; browser connects directly
 * - Unified interface: browser sends SDP to NV oOS server; server relays to OpenAI
 *
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

(function (window) {
	'use strict';

	if (window.wpMcpAiWebRTC) {
		return;
	}

	// ── Constants ──────────────────────────────────────────────────────

	/* eslint-disable no-unused-vars */
	const WEBRTC_ACTIVE_CLASS       = 'wp-mcp-ai-chat__webrtc--active';
	const WEBRTC_CONNECTING_CLASS   = 'wp-mcp-ai-chat__webrtc--connecting';
	const WEBRTC_SPEAKING_CLASS     = 'wp-mcp-ai-chat__webrtc--speaking';
	const WEBRTC_LISTENING_CLASS    = 'wp-mcp-ai-chat__webrtc--listening';
	const WEBRTC_ERROR_CLASS        = 'wp-mcp-ai-chat__webrtc--error';
	const WEBRTC_RECONNECTING_CLASS = 'wp-mcp-ai-chat__webrtc--reconnecting';
	/* eslint-enable no-unused-vars */

	const MAX_RECONNECT_ATTEMPTS  = 3;
	const RECONNECT_BASE_DELAY_MS = 1000;
	const DATA_CHANNEL_LABEL      = 'oai-events';

	/**
	 * Default ICE server configuration.
	 * Users can override via window.wpMcpAiWebRTCConfig.iceServers.
	 */
	const DEFAULT_ICE_SERVERS = [
		{ urls: 'stun:stun.l.google.com:19302' },
	];

	// ── Service ─────────────────────────────────────────────────────────

	const wpMcpAiWebRTC = {
		/**
		 * Active connections by instance key.
		 * @type {Object.<string, Object>}
		 */
		connections: {},

		/**
		 * Currently muted state by instance key.
		 * @type {Object.<string, boolean>}
		 */
		muted: {},

		/**
		 * Check if WebRTC is supported in the current browser.
		 *
		 * @return {boolean}
		 */
		isSupported: function () {
			return (
				typeof window !== 'undefined' &&
				typeof window.RTCPeerConnection !== 'undefined' &&
				typeof navigator !== 'undefined' &&
				navigator.mediaDevices &&
				typeof navigator.mediaDevices.getUserMedia === 'function'
			);
		},

		/**
		 * Create a new RTCPeerConnection with default configuration.
		 *
		 * @return {RTCPeerConnection|null}
		 */
		createPeerConnection: function () {
			if (!this.isSupported()) {
				return null;
			}

			const config = (window.wpMcpAiWebRTCConfig && window.wpMcpAiWebRTCConfig.iceServers)
				? { iceServers: window.wpMcpAiWebRTCConfig.iceServers }
				: { iceServers: DEFAULT_ICE_SERVERS };

			try {
				return new RTCPeerConnection(config);
			} catch (e) {
				return null;
			}
		},

		/**
		 * Get the audio context for a connection key.
		 *
		 * @param {string} key  Instance key.
		 * @return {AudioContext|null}
		 */
		getAudioContext: function (key) {
			const conn = this.connections[key];
			if (conn && conn.audioContext) {
				return conn.audioContext;
			}
			try {
				const AudioCtx = window.AudioContext || window.webkitAudioContext;
				const ctx = new AudioCtx({ sampleRate: 24000 });
				if (conn) {
					conn.audioContext = ctx;
				}
				return ctx;
			} catch (e) {
				return null;
			}
		},

		/**
		 * Connect using the ephemeral token flow.
		 *
		 * 1. Fetch ephemeral token from NV oOS server
		 * 2. Create RTCPeerConnection
		 * 3. Add local microphone track
		 * 4. Create SDP offer
		 * 5. POST to OpenAI /v1/realtime/calls with ephemeral key
		 * 6. Set remote SDP answer
		 *
		 * @param {Object}   config           - Chat config from WordPress.
		 * @param {number}   assistantId      - The assistant ID.
		 * @param {Object}   options          - Voice options (model, voice, reasoning_effort).
		 * @param {Function} buildJsonHeaders - Headers builder function.
		 * @param {Object}   callbacks        - Event callbacks.
		 * @param {Function} callbacks.onStateChange    - Called with state name string.
		 * @param {Function} callbacks.onTranscript     - Called with user transcript text.
		 * @param {Function} callbacks.onResponseText   - Called with AI response text delta.
		 * @param {Function} callbacks.onError          - Called with Error object.
		 * @param {Function} callbacks.onFunctionCall   - Called with {name, call_id, arguments}.
		 * @param {Function} callbacks.onCommentary     - Called with commentary-phase text.
		 * @return {Object} Connection handle with close() method.
		 */
		connectWithToken: function (config, assistantId, options, buildJsonHeaders, callbacks) {
			const self = this;
			const key = 'rtc_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6);

			if (!this.isSupported()) {
				if (callbacks.onError) {
					callbacks.onError(new Error('WebRTC is not supported in this browser.'));
				}
				return null;
			}

			// Close any existing connection with the same key.
			this.disconnect(key);

			let pc          = null;
			let dc          = null;
			let micStream   = null;
			let audioEl     = null;
			let reconnectAttempts = 0;
			const active      = true;

			const conn = {
				key:    key,
				active: true,
				close:  function () {
					self.disconnect(key);
				},
				mute:   function () {
					self.setMuted(key, true);
				},
				unmute: function () {
					self.setMuted(key, false);
				},
				sendEvent: function (event) {
					self.sendEvent(key, event);
				},
			};

			/**
			 * Emit state change.
			 *
			 * @param {string} state
			 */
			function emitState(state) {
				if (callbacks.onStateChange) {
					callbacks.onStateChange(state);
				}
			}

			/**
			 * Fetch ephemeral token from the NV oOS server.
			 *
			 * @return {Promise<Object>}
			 */
			function fetchToken() {
				const tokenEndpoint = (window.wpMcpAiWebRTCConfig && window.wpMcpAiWebRTCConfig.tokenEndpoint)
					|| config.restUrl + 'mcp-ai/v1/realtime/token';

				const headers = typeof buildJsonHeaders === 'function'
					? buildJsonHeaders({ config: config })
					: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': config.nonce || '',
					};

				return fetch(tokenEndpoint, {
					method: 'POST',
					headers: headers,
					credentials: 'same-origin',
					body: JSON.stringify({
						assistant_id:    assistantId,
						model:           options.model || '',
						voice:           options.voice || '',
						reasoning_effort: options.reasoning_effort || '',
					}),
				}).then(function (response) {
					return response.json().then(function (body) {
						if (!response.ok) {
							throw new Error(body.message || 'Token creation failed (HTTP ' + response.status + ')');
						}
						return body;
					});
				});
			}

			/**
			 * Create the WebRTC peer connection and set up tracks.
			 *
			 * @param {Object} tokenData - Ephemeral token data from server.
			 */
			function establishConnection(tokenData) {
				const ephemeralKey = tokenData.client_secret && tokenData.client_secret.value;
				if (!ephemeralKey) {
					if (callbacks.onError) {
						callbacks.onError(new Error('Missing ephemeral token.'));
					}
					return;
				}

				pc = self.createPeerConnection();
				if (!pc) {
					if (callbacks.onError) {
						callbacks.onError(new Error('Failed to create RTCPeerConnection.'));
					}
					return;
				}

				// ── Remote audio track → play through <audio> element ──
				pc.ontrack = function (event) {
					if (!audioEl) {
						audioEl = document.createElement('audio');
						audioEl.autoplay = true;
					}
					audioEl.srcObject = event.streams[0];
				};

				// ── ICE connection state ──
				pc.oniceconnectionstatechange = function () {
					switch (pc.iceConnectionState) {
						case 'checking':
							emitState('connecting');
							break;
						case 'connected':
						case 'completed':
							reconnectAttempts = 0;
							emitState('active');
							break;
						case 'disconnected':
							attemptReconnect(tokenData);
							break;
						case 'failed':
							emitState('error');
							if (callbacks.onError) {
								callbacks.onError(new Error('ICE connection failed.'));
							}
							break;
						case 'closed':
							emitState('disconnected');
							break;
					}
				};

				// ── Connection state ──
				pc.onconnectionstatechange = function () {
					if (pc.connectionState === 'failed' || pc.connectionState === 'closed') {
						if (callbacks.onError) {
							callbacks.onError(new Error('WebRTC connection ' + pc.connectionState + '.'));
						}
					}
				};

				// ── Data channel for control events ──
				dc = pc.createDataChannel(DATA_CHANNEL_LABEL);
				dc.onopen = function () {
					emitState('ready');
				};
				dc.onmessage = function (event) {
					try {
						const msg = JSON.parse(event.data);
						handleServerEvent(msg);
					} catch (e) {
						// Non-JSON message; ignore.
					}
				};
				dc.onerror = function () {
					if (callbacks.onError) {
						callbacks.onError(new Error('Data channel error.'));
					}
				};

				// ── Add local microphone track ──
				navigator.mediaDevices.getUserMedia({
					audio: {
						sampleRate: 24000,
						channelCount: 1,
						echoCancellation: true,
						noiseSuppression: true,
					},
				}).then(function (stream) {
					if (!active) {
						stream.getTracks().forEach(function (t) { t.stop(); });
						return;
					}
					micStream = stream;
					stream.getTracks().forEach(function (track) {
						pc.addTrack(track, stream);
					});
				}).catch(function () {
					if (callbacks.onError) {
						callbacks.onError(new Error('Microphone access denied.'));
					}
				});

				// ── Create SDP offer and send to OpenAI ──
				pc.createOffer().then(function (offer) {
					return pc.setLocalDescription(offer).then(function () {
						return fetch('https://api.openai.com/v1/realtime/calls', {
							method: 'POST',
							body: offer.sdp,
							headers: {
								'Authorization': 'Bearer ' + ephemeralKey,
								'Content-Type': 'application/sdp',
							},
						});
					});
				}).then(function (sdpResponse) {
					if (!sdpResponse.ok) {
						throw new Error('OpenAI SDP exchange failed (HTTP ' + sdpResponse.status + ')');
					}
					return sdpResponse.text();
				}).then(function (answerSdp) {
					return pc.setRemoteDescription({
						type: 'answer',
						sdp: answerSdp,
					});
				}).catch(function (err) {
					if (callbacks.onError) {
						callbacks.onError(err);
					}
					emitState('error');
				});
			}

			/**
			 * Attempt reconnection with exponential backoff.
			 *
			 * @param {Object} tokenData - Original token data (may be expired).
			 */
			function attemptReconnect(_tokenData) {
				if (!active || reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
					if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
						emitState('disconnected');
						if (callbacks.onError) {
							callbacks.onError(new Error('Maximum reconnection attempts reached.'));
						}
					}
					return;
				}

				reconnectAttempts++;
				emitState('reconnecting');

				const delay = RECONNECT_BASE_DELAY_MS * Math.pow(2, reconnectAttempts - 1);
				setTimeout(function () {
					if (!active) {
						return;
					}
					cleanupConnection();
					// Re-fetch token (old one may have expired) and reconnect.
					fetchToken().then(establishConnection).catch(function (err) {
						if (callbacks.onError) {
							callbacks.onError(err);
						}
					});
				}, delay);
			}

			/**
			 * Handle incoming server events from the data channel.
			 *
			 * @param {Object} msg - Parsed JSON event from OpenAI.
			 */
			function handleServerEvent(msg) {
				switch (msg.type) {
					case 'session.created':
					case 'session.updated':
						emitState('active');
						break;

					case 'input_audio_buffer.speech_started':
						emitState('listening');
						break;

					case 'input_audio_buffer.speech_stopped':
						emitState('processing');
						break;

					case 'conversation.item.input_audio_transcription.completed':
						if (callbacks.onTranscript && msg.transcript) {
							callbacks.onTranscript(msg.transcript);
						}
						break;

					case 'response.output_audio_transcript.delta':
						if (callbacks.onResponseText && msg.delta) {
							callbacks.onResponseText(msg.delta);
						}
						break;

					case 'response.output_audio.done':
						emitState('active');
						break;

					case 'response.done':
						// Handle response phases (commentary vs final_answer).
						if (msg.response && Array.isArray(msg.response.output)) {
							msg.response.output.forEach(function (item) {
								if (item.phase === 'commentary' && callbacks.onCommentary && item.content) {
									item.content.forEach(function (part) {
										if (part.transcript && callbacks.onCommentary) {
											callbacks.onCommentary(part.transcript);
										}
									});
								}
							});
						}
						emitState('active');
						break;

					case 'response.function_call_arguments.done':
						if (callbacks.onFunctionCall && msg.name) {
							let args = {};
							try {
								args = JSON.parse(msg.arguments || '{}');
							} catch (e) {
								// Use empty args on parse failure.
							}
							callbacks.onFunctionCall({
								name:      msg.name,
								call_id:   msg.call_id,
								arguments: args,
							});
						}
						break;

					case 'error':
						if (callbacks.onError) {
							callbacks.onError(
								new Error((msg.error && msg.error.message) || 'Unknown server error.')
							);
						}
						emitState('error');
						break;

					case 'rate_limits.updated':
						// Rate limit info — could surface to UI.
						break;
				}
			}

			/**
			 * Clean up peer connection and media.
			 */
			function cleanupConnection() {
				if (dc) {
					try { dc.close(); } catch (e) { /* ignore */ }
					dc = null;
				}
				if (pc) {
					try { pc.close(); } catch (e) { /* ignore */ }
					pc = null;
				}
				if (micStream) {
					micStream.getTracks().forEach(function (t) { t.stop(); });
					micStream = null;
				}
				if (audioEl) {
					audioEl.srcObject = null;
					audioEl = null;
				}
			}

			// Store connection handle.
			this.connections[key] = conn;
			conn._cleanup = cleanupConnection;

			emitState('connecting');

			// Fetch token and establish connection.
			fetchToken().then(establishConnection).catch(function (err) {
				if (callbacks.onError) {
					callbacks.onError(err);
				}
				emitState('error');
			});

			return conn;
		},

		/**
		 * Connect using the unified interface (SDP relay).
		 *
		 * The browser creates an SDP offer, sends it to the NV oOS server,
		 * which relays it to OpenAI's /v1/realtime/calls with the server's
		 * API key. This keeps the API key entirely server-side.
		 *
		 * @param {Object}   config           - Chat config from WordPress.
		 * @param {number}   assistantId      - The assistant ID.
		 * @param {Object}   options          - Voice options.
		 * @param {Function} buildJsonHeaders - Headers builder.
		 * @param {Object}   callbacks        - Same as connectWithToken.
		 * @return {Object} Connection handle.
		 */
		connectWithRelay: function (config, assistantId, options, buildJsonHeaders, callbacks) {
			const self = this;
			const key = 'rtc_relay_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6);

			if (!this.isSupported()) {
				if (callbacks.onError) {
					callbacks.onError(new Error('WebRTC is not supported in this browser.'));
				}
				return null;
			}

			this.disconnect(key);

			let pc        = null;
			let dc        = null;
			let micStream = null;
			let audioEl   = null;
			const active    = true;

			const conn = {
				key:    key,
				active: true,
				close:  function () { self.disconnect(key); },
				mute:   function () { self.setMuted(key, true); },
				unmute: function () { self.setMuted(key, false); },
				sendEvent: function (event) { self.sendEvent(key, event); },
			};

			function emitState(state) {
				if (callbacks.onStateChange) {
					callbacks.onStateChange(state);
				}
			}

			/**
			 * Handle server events (reuses the same handler as ephemeral flow).
			 */
			function handleServerEvent(msg) {
				switch (msg.type) {
					case 'session.created':
					case 'session.updated':
						emitState('active');
						break;
					case 'input_audio_buffer.speech_started':
						emitState('listening');
						break;
					case 'input_audio_buffer.speech_stopped':
						emitState('processing');
						break;
					case 'conversation.item.input_audio_transcription.completed':
						if (callbacks.onTranscript && msg.transcript) {
							callbacks.onTranscript(msg.transcript);
						}
						break;
					case 'response.output_audio_transcript.delta':
						if (callbacks.onResponseText && msg.delta) {
							callbacks.onResponseText(msg.delta);
						}
						break;
					case 'response.output_audio.done':
						emitState('active');
						break;
					case 'response.done':
						if (msg.response && Array.isArray(msg.response.output)) {
							msg.response.output.forEach(function (item) {
								if (item.phase === 'commentary' && callbacks.onCommentary && item.content) {
									item.content.forEach(function (part) {
										if (part.transcript && callbacks.onCommentary) {
											callbacks.onCommentary(part.transcript);
										}
									});
								}
							});
						}
						emitState('active');
						break;
					case 'response.function_call_arguments.done':
						if (callbacks.onFunctionCall && msg.name) {
							let args = {};
							try {
								args = JSON.parse(msg.arguments || '{}');
							} catch (e) {
								// Use empty args.
							}
							callbacks.onFunctionCall({
								name:      msg.name,
								call_id:   msg.call_id,
								arguments: args,
							});
						}
						break;
					case 'error':
						if (callbacks.onError) {
							callbacks.onError(
								new Error((msg.error && msg.error.message) || 'Unknown server error.')
							);
						}
						emitState('error');
						break;
				}
			}

			function cleanupConnection() {
				if (dc) {
					try { dc.close(); } catch (e) { /* ignore */ }
					dc = null;
				}
				if (pc) {
					try { pc.close(); } catch (e) { /* ignore */ }
					pc = null;
				}
				if (micStream) {
					micStream.getTracks().forEach(function (t) { t.stop(); });
					micStream = null;
				}
				if (audioEl) {
					audioEl.srcObject = null;
					audioEl = null;
				}
			}

			this.connections[key] = conn;
			conn._cleanup = cleanupConnection;
			emitState('connecting');

			// ── Build peer connection ──
			pc = self.createPeerConnection();
			if (!pc) {
				if (callbacks.onError) {
					callbacks.onError(new Error('Failed to create RTCPeerConnection.'));
				}
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
				if (pc.iceConnectionState === 'connected' || pc.iceConnectionState === 'completed') {
					emitState('active');
				} else if (pc.iceConnectionState === 'failed') {
					emitState('error');
				}
			};

			dc = pc.createDataChannel(DATA_CHANNEL_LABEL);
			dc.onmessage = function (event) {
				try {
					handleServerEvent(JSON.parse(event.data));
				} catch (e) {
					// Ignore non-JSON.
				}
			};

			navigator.mediaDevices.getUserMedia({
				audio: {
					sampleRate: 24000,
					channelCount: 1,
					echoCancellation: true,
					noiseSuppression: true,
				},
			}).then(function (stream) {
				if (!active) {
					stream.getTracks().forEach(function (t) { t.stop(); });
					return;
				}
				micStream = stream;
				stream.getTracks().forEach(function (track) {
					pc.addTrack(track, stream);
				});
			}).catch(function () {
				if (callbacks.onError) {
					callbacks.onError(new Error('Microphone access denied.'));
				}
			});

			// ── Create offer, relay through NV oOS server ──
			pc.createOffer().then(function (offer) {
				return pc.setLocalDescription(offer).then(function () {
					const sessionEndpoint = (window.wpMcpAiWebRTCConfig && window.wpMcpAiWebRTCConfig.sessionEndpoint)
						|| config.restUrl + 'mcp-ai/v1/realtime/session';

					const headers = typeof buildJsonHeaders === 'function'
						? buildJsonHeaders({ config: config })
						: { 'Content-Type': 'application/sdp', 'X-WP-Nonce': config.nonce || '' };

					return fetch(sessionEndpoint + '?assistant_id=' + encodeURIComponent(assistantId), {
						method: 'POST',
						headers: headers,
						credentials: 'same-origin',
						body: offer.sdp,
					});
				});
			}).then(function (relayResponse) {
				if (!relayResponse.ok) {
					throw new Error('SDP relay failed (HTTP ' + relayResponse.status + ')');
				}
				return relayResponse.text();
			}).then(function (answerSdp) {
				return pc.setRemoteDescription({
					type: 'answer',
					sdp: answerSdp,
				});
			}).catch(function (err) {
				if (callbacks.onError) {
					callbacks.onError(err);
				}
				emitState('error');
			});

			return conn;
		},

		/**
		 * Send a JSON event over the data channel.
		 *
		 * @param {string} key   - Instance key.
		 * @param {Object} event - Event to send (will be JSON-stringified).
		 */
		sendEvent: function (key, event) {
			const conn = this.connections[key];
			if (!conn || !conn._dc) {
				return;
			}
			const dc = conn._dc;
			if (dc && dc.readyState === 'open') {
				try {
					dc.send(JSON.stringify(event));
				} catch (e) {
					// Ignore send errors (channel may have closed).
				}
			}
		},

		/**
		 * Mute or unmute the microphone for a connection.
		 *
		 * @param {string}  key   - Instance key.
		 * @param {boolean} muted - Whether to mute.
		 */
		setMuted: function (key, muted) {
			this.muted[key] = muted;
			const conn = this.connections[key];
			if (!conn || !conn._micStream) {
				return;
			}
			conn._micStream.getAudioTracks().forEach(function (track) {
				track.enabled = !muted;
			});
		},

		/**
		 * Disconnect and clean up a WebRTC session.
		 *
		 * @param {string} key - Instance key.
		 */
		disconnect: function (key) {
			const conn = this.connections[key];
			if (!conn) {
				return;
			}

			conn.active = false;

			if (conn._cleanup && typeof conn._cleanup === 'function') {
				conn._cleanup();
			}

			delete this.connections[key];
			delete this.muted[key];
		},

		/**
		 * Get the current state of a connection.
		 *
		 * @param {string} key - Instance key.
		 * @return {string} State: 'idle', 'connecting', 'active', 'error', 'disconnected'.
		 */
		getState: function (key) {
			const conn = this.connections[key];
			if (!conn) {
				return 'idle';
			}
			if (!conn._pc) {
				return 'idle';
			}
			switch (conn._pc.connectionState) {
				case 'new':
				case 'connecting':
					return 'connecting';
				case 'connected':
					return 'active';
				case 'disconnected':
					return 'disconnected';
				case 'failed':
				case 'closed':
					return 'error';
				default:
					return 'idle';
			}
		},
	};

	window.wpMcpAiWebRTC = wpMcpAiWebRTC;
})(window);
