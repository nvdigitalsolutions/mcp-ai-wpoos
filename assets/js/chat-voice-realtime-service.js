/**
 * Realtime Voice Service for NV oOS Chat
 *
 * Provides WebSocket-based realtime speech-to-speech (S2S) voice chat using
 * OpenAI's Realtime API and Google Gemini's Multimodal Live API.
 *
 * This is a self-contained service that connects to the voice session endpoint,
 * manages the WebSocket lifecycle, handles audio I/O through the browser's
 * MediaStream APIs, and integrates with the chat UI for transcription display.
 *
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

(function (window) {
	'use strict';

	if (window.wpMcpAiRealtimeVoice) {
		return;
	}

	// ── Constants ──────────────────────────────────────────────────────

	// Class constants exported for external use by chat UI modules.
	/* eslint-disable no-unused-vars */
	const REALTIME_VOICE_MODE_CLASS = 'wp-mcp-ai-chat--voice-realtime';
	const REALTIME_CONNECTING_CLASS = 'wp-mcp-ai-chat__voice-realtime--connecting';
	const REALTIME_ACTIVE_CLASS = 'wp-mcp-ai-chat__voice-realtime--active';
	const REALTIME_SPEAKING_CLASS = 'wp-mcp-ai-chat__voice-realtime--speaking';
	const REALTIME_LISTENING_CLASS = 'wp-mcp-ai-chat__voice-realtime--listening';
	const REALTIME_ERROR_CLASS = 'wp-mcp-ai-chat__voice-realtime--error';
	/* eslint-enable no-unused-vars */

	const MAX_RECONNECT_ATTEMPTS = 3;
	const RECONNECT_DELAY_MS = 1000;
	const AUDIO_BUFFER_SIZE = 4096;

	// ── Service ─────────────────────────────────────────────────────────

	const wpMcpAiRealtimeVoice = {
		/**
		 * Active connections by instance key.
		 */
		connections: {},

		/**
		 * Audio contexts by instance key.
		 */
		audioContexts: {},

		/**
		 * Check if the browser supports WebSocket and Web Audio APIs.
		 *
		 * @return {boolean}
		 */
		isSupported: function () {
			return (
				typeof window !== 'undefined' &&
				typeof WebSocket !== 'undefined' &&
				(typeof window.AudioContext !== 'undefined' || typeof window.webkitAudioContext !== 'undefined') &&
				typeof navigator !== 'undefined' &&
				navigator.mediaDevices &&
				typeof navigator.mediaDevices.getUserMedia === 'function'
			);
		},

		/**
		 * Get the AudioContext, creating one if needed.
		 *
		 * @param {string} key - Instance key.
		 * @return {AudioContext|null}
		 */
		getAudioContext: function (key) {
			if (!this.audioContexts[key]) {
				try {
					const AudioCtx = window.AudioContext || window.webkitAudioContext;
					this.audioContexts[key] = new AudioCtx({ sampleRate: 24000 });
				} catch (e) {
					return null;
				}
			}
			return this.audioContexts[key];
		},

		/**
		 * Close an audio context.
		 *
		 * @param {string} key - Instance key.
		 */
		closeAudioContext: function (key) {
			if (this.audioContexts[key]) {
				try {
					this.audioContexts[key].close();
				} catch (e) {}
				delete this.audioContexts[key];
			}
		},

		/**
		 * Create a voice session by calling the WordPress REST endpoint.
		 *
		 * @param {Object} config - Chat config object.
		 * @param {number|string} assistantId - The assistant ID.
		 * @param {Function} buildJsonHeaders - Headers builder function.
		 * @return {Promise<Object>} Session config.
		 */
		createSession: function (config, assistantId, buildJsonHeaders) {
			if (!config || !config.voiceEndpoint) {
				return Promise.reject(new Error('Voice endpoint not configured.'));
			}

			const headers = typeof buildJsonHeaders === 'function'
				? buildJsonHeaders({ config: config })
				: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce || '',
				};

			return fetch(config.voiceEndpoint, {
				method: 'POST',
				headers: headers,
				credentials: 'same-origin',
				body: JSON.stringify({
					assistant_id: assistantId,
				}),
			})
				.then(function (response) {
					return response.json().then(function (body) {
						if (!response.ok) {
							throw new Error(body.message || 'Session creation failed (HTTP ' + response.status + ')');
						}
						if (!body || typeof body !== 'object') {
							throw new Error('Invalid session response.');
						}
						return body;
					});
				});
		},

		/**
		 * Connect to a realtime voice session.
		 *
		 * @param {string} key - Unique instance key.
		 * @param {Object} sessionConfig - Session configuration from server.
		 * @param {Object} callbacks - Event callbacks.
		 * @param {Function} callbacks.onStateChange - Called with state name.
		 * @param {Function} callbacks.onTranscript - Called with user transcript text.
		 * @param {Function} callbacks.onResponseText - Called with AI response text delta.
		 * @param {Function} callbacks.onResponseAudio - Called with audio PCM16 buffer.
		 * @param {Function} callbacks.onError - Called with error object.
		 * @param {Function} callbacks.onFunctionCall - Called with function call details.
		 * @return {Object} Connection handle.
		 */
		connect: function (key, sessionConfig, callbacks) {
			if (!this.isSupported()) {
				if (callbacks.onError) {
					callbacks.onError(new Error('WebSocket or Web Audio not supported in this browser.'));
				}
				return null;
			}

			// Close existing connection if present.
			this.disconnect(key);

			const self = this;
			let ws = null;
			let micStream = null;
			let micSource = null;
			let micProcessor = null;
			let reconnectAttempts = 0;

			const conn = {
				key: key,
				active: true,
				ws: null,
				close: function () {
					self.disconnect(key);
				},
			};

			/**
			 * Emit state change to callbacks.
			 *
			 * @param {string} state - State name.
			 */
			function emitState(state) {
				if (callbacks.onStateChange) {
					callbacks.onStateChange(state);
				}
			}

			/**
			 * Connect the WebSocket.
			 */
			function connectWebSocket() {
				let wsUrl = null;
				let isOpenAI = false;

				if (sessionConfig.type === 'openai_realtime') {
					const token = sessionConfig.client_secret && sessionConfig.client_secret.value
						? sessionConfig.client_secret.value
						: '';
					if (!token) {
						if (callbacks.onError) {
							callbacks.onError(new Error('Missing realtime session token.'));
						}
						return;
					}
					wsUrl = sessionConfig.endpoint + '?model=' + encodeURIComponent(sessionConfig.model || 'gpt-realtime');
					isOpenAI = true;
				} else if (sessionConfig.type === 'gemini_live') {
					wsUrl = sessionConfig.ws_url;
				} else {
					if (callbacks.onError) {
						callbacks.onError(new Error('Unknown session type: ' + sessionConfig.type));
					}
					return;
				}

				try {
					ws = new WebSocket(wsUrl);
				} catch (e) {
					if (callbacks.onError) {
						callbacks.onError(e);
					}
					return;
				}

				conn.ws = ws;
				emitState('connecting');

				ws.onopen = function () {
					reconnectAttempts = 0;

					if (isOpenAI) {
						// Send session update with configuration.
						ws.send(JSON.stringify({
							type: 'session.update',
							session: {
								modalities: ['text', 'audio'],
								input_audio_format: 'pcm16',
								output_audio_format: 'pcm16',
								turn_detection: {
									type: 'server_vad',
									threshold: 0.5,
									prefix_padding_ms: 300,
									silence_duration_ms: 700,
								},
								tools: sessionConfig.tools || [],
								instructions: sessionConfig.instructions || '',
								voice: sessionConfig.voice || 'marin',
								temperature: 0.8,
							},
						}));
					} else if (sessionConfig.type === 'gemini_live') {
						// Send setup message for Gemini Live.
						ws.send(JSON.stringify({
							setup: sessionConfig.setup || {},
						}));
					}

					// Start microphone capture.
					startMicrophone();
				};

				ws.onmessage = function (event) {
					try {
						const msg = JSON.parse(event.data);
						handleServerMessage(msg, isOpenAI);
					} catch (_e) {
						// Binary audio data (handled separately for Gemini).
					}
				};

				ws.onerror = function () {
					if (callbacks.onError) {
						callbacks.onError(new Error('WebSocket error.'));
					}
					emitState('error');
				};

				ws.onclose = function () {
					if (!conn.active) {
						return;
					}

					stopMicrophone();

					if (reconnectAttempts < MAX_RECONNECT_ATTEMPTS) {
						reconnectAttempts++;
						emitState('reconnecting');
						setTimeout(function () {
							if (conn.active) {
								connectWebSocket();
							}
						}, RECONNECT_DELAY_MS * reconnectAttempts);
					} else {
						emitState('disconnected');
						if (callbacks.onError) {
							callbacks.onError(new Error('Connection lost. Maximum reconnection attempts reached.'));
						}
					}
				};
			}

			/**
			 * Handle incoming server messages.
			 *
			 * @param {Object} msg - Parsed message.
			 * @param {boolean} isOpenAI - Whether this is OpenAI protocol.
			 */
			function handleServerMessage(msg, isOpenAI) {
				if (isOpenAI) {
					// OpenAI Realtime protocol.
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

						case 'response.audio_transcript.delta':
							if (callbacks.onResponseText && msg.delta) {
								callbacks.onResponseText(msg.delta);
							}
							break;

						case 'response.audio.delta':
							if (callbacks.onResponseAudio && msg.delta) {
								// Base64-encoded PCM16 audio.
								const binaryStr = atob(msg.delta);
								const bytes = new Uint8Array(binaryStr.length);
								for (let i = 0; i < binaryStr.length; i++) {
									bytes[i] = binaryStr.charCodeAt(i);
								}
								callbacks.onResponseAudio(bytes.buffer);
							}
							break;

						case 'response.audio.done':
							emitState('active');
							break;

						case 'response.function_call_arguments.done':
							if (callbacks.onFunctionCall && msg.name) {
								let args = {};
								try {
									args = JSON.parse(msg.arguments || '{}');
								} catch (e) {}
								callbacks.onFunctionCall({
									name: msg.name,
									call_id: msg.call_id,
									arguments: args,
								});
							}
							break;

						case 'error':
							if (callbacks.onError) {
								callbacks.onError(new Error(msg.error && msg.error.message || 'Unknown server error.'));
							}
							break;
					}
				} else {
					// Gemini Live protocol.
					if (msg.serverContent && msg.serverContent.modelTurn) {
						const parts = msg.serverContent.modelTurn.parts || [];
						parts.forEach(function (part) {
							if (part.text && callbacks.onResponseText) {
								callbacks.onResponseText(part.text);
							}
							if (part.inlineData && part.inlineData.mimeType && part.inlineData.mimeType.startsWith('audio/') && callbacks.onResponseAudio) {
								const binaryStr = atob(part.inlineData.data);
								const bytes = new Uint8Array(binaryStr.length);
								for (let i = 0; i < binaryStr.length; i++) {
									bytes[i] = binaryStr.charCodeAt(i);
								}
								callbacks.onResponseAudio(bytes.buffer);
							}
						});
					}
				}
			}

			/**
			 * Start microphone capture and stream PCM16 audio to server.
			 */
			function startMicrophone() {
				if (!conn.active) {
					return;
				}

				navigator.mediaDevices.getUserMedia({
					audio: {
						sampleRate: 24000,
						channelCount: 1,
						echoCancellation: true,
						noiseSuppression: true,
					},
				}).then(function (stream) {
					if (!conn.active) {
						stream.getTracks().forEach(function (t) { t.stop(); });
						return;
					}

					micStream = stream;
					const audioCtx = self.getAudioContext(key);

					if (!audioCtx) {
						return;
					}

					micSource = audioCtx.createMediaStreamSource(stream);

					// Use ScriptProcessorNode for PCM16 capture (AudioWorklet is preferred but less compatible).
					micProcessor = audioCtx.createScriptProcessor(AUDIO_BUFFER_SIZE, 1, 1);

					micProcessor.onaudioprocess = function (e) {
						if (!conn.active || !ws || ws.readyState !== WebSocket.OPEN) {
							return;
						}

						const inputData = e.inputBuffer.getChannelData(0);

						// Convert Float32 to Int16 PCM.
						const pcm16 = new Int16Array(inputData.length);
						for (let i = 0; i < inputData.length; i++) {
							const s = Math.max(-1, Math.min(1, inputData[i]));
							pcm16[i] = s < 0 ? s * 0x8000 : s * 0x7FFF;
						}

						// Convert to base64.
						const bytes = new Uint8Array(pcm16.buffer);
						let binary = '';
						for (let i = 0; i < bytes.byteLength; i++) {
							binary += String.fromCharCode(bytes[i]);
						}
						const base64 = btoa(binary);

						if (sessionConfig.type === 'openai_realtime') {
							ws.send(JSON.stringify({
								type: 'input_audio_buffer.append',
								audio: base64,
							}));
						} else if (sessionConfig.type === 'gemini_live') {
							ws.send(JSON.stringify({
								realtimeInput: {
									mediaChunks: [{
										mimeType: 'audio/pcm',
										data: base64,
									}],
								},
							}));
						}
					};

					micSource.connect(micProcessor);
					micProcessor.connect(audioCtx.destination);
				}).catch(function () {
					if (callbacks.onError) {
						callbacks.onError(new Error('Microphone access denied.'));
					}
				});
			}

			/**
			 * Stop microphone capture and clean up.
			 */
			function stopMicrophone() {
				if (micProcessor) {
					try { micProcessor.disconnect(); } catch (e) {}
					micProcessor = null;
				}
				if (micSource) {
					try { micSource.disconnect(); } catch (e) {}
					micSource = null;
				}
				if (micStream) {
					micStream.getTracks().forEach(function (t) { t.stop(); });
					micStream = null;
				}
			}

			// Start connecting.
			connectWebSocket();

			return conn;
		},

		/**
		 * Disconnect a realtime voice session.
		 *
		 * @param {string} key - Instance key.
		 */
		disconnect: function (key) {
			const conn = this.connections[key];
			if (conn) {
				conn.active = false;
				if (conn.ws && conn.ws.readyState === WebSocket.OPEN) {
					try { conn.ws.close(); } catch (e) {}
				}
				delete this.connections[key];
			}

			this.closeAudioContext(key);
		},

		/**
		 * Play a PCM16 audio buffer through the browser speakers.
		 *
		 * @param {string} key - Instance key.
		 * @param {ArrayBuffer} buffer - PCM16 audio data.
		 * @param {number} sampleRate - Audio sample rate (default 24000).
		 * @return {Promise<void>}
		 */
		playAudioBuffer: function (key, buffer, sampleRate) {
			const audioCtx = this.getAudioContext(key);
			if (!audioCtx || !buffer) {
				return Promise.resolve();
			}

			sampleRate = sampleRate || 24000;

			return new Promise(function (resolve, reject) {
				try {
					// PCM16 → Float32.
					const int16 = new Int16Array(buffer);
					const float32 = new Float32Array(int16.length);
					for (let i = 0; i < int16.length; i++) {
						float32[i] = int16[i] / 32768.0;
					}

					const audioBuffer = audioCtx.createBuffer(1, float32.length, sampleRate);
					audioBuffer.getChannelData(0).set(float32);

					const source = audioCtx.createBufferSource();
					source.buffer = audioBuffer;
					source.connect(audioCtx.destination);
					source.onended = resolve;
					source.start(0);
				} catch (e) {
					reject(e);
				}
			});
		},
	};

	window.wpMcpAiRealtimeVoice = wpMcpAiRealtimeVoice;
})(window);
