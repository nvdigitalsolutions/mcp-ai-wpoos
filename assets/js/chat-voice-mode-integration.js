/**
 * Voice Mode Integration for NV oOS Chat
 *
 * Glues together the voice services (realtime, chained, browser) with the
 * existing chat UI. Provides voice mode toggle, status display, live
 * transcription overlay, and graceful fallback between tiers.
 *
 * This module runs alongside chat.js and enhances the existing voice chat
 * functionality without modifying the core chat-bundle.js file.
 *
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

(function (window) {
	'use strict';

	if (window.wpMcpAiVoiceMode) {
		return;
	}

	const realtimeVoice = window.wpMcpAiRealtimeVoice || null;
	const browserVoice = window.wpMcpAiBrowserVoice || null;

	/**
	 * Voice mode states.
	 */
	const MODE_TEXT = 'off';
	const MODE_REALTIME = 'realtime';
	const MODE_CHAINED = 'chained';
	const MODE_BROWSER = 'browser';

	/**
	 * Voice Mode Manager.
	 */
	const wpMcpAiVoiceMode = {
		/** Current voice mode. */
		currentMode: MODE_CHAINED,

		/** Active realtime connection, if any. */
		realtimeConn: null,

		/** Active browser voice recognizer, if any. */
		browserRecognizer: null,

		/** Status bar element references. */
		elements: {},

		/**
		 * Initialize voice mode for a chat instance.
		 *
		 * @param {string} instanceKey - Unique instance key.
		 * @param {Object} state - Chat state object from chat.js.
		 * @param {HTMLElement} container - Chat container element.
		 * @param {Object} options - Configuration options.
		 */
		init: function (instanceKey, state, container, options) {
			if (!container) {
				return;
			}

			options = options || {};

			this.instanceKey = instanceKey;
			this.state = state;
			this.container = container;
			this.config = (state && state.config) || {};

			// Set initial mode from config.
			const configMode = this.config.voiceMode || MODE_CHAINED;
			this.setMode(configMode, true);

			// Build UI elements.
			this.buildVoiceUI(container, options);

			// Listen for mode changes from the toggle.
			this.bindEvents();
		},

		/**
		 * Build voice UI elements within the chat container.
		 *
		 * @param {HTMLElement} container - Chat container.
		 * @param {Object} options - Configuration options.
		 */
		buildVoiceUI: function (container, _options) {
			// Voice status bar.
			const statusBar = document.createElement('div');
			statusBar.className = 'wp-mcp-ai-chat__voice-status';
			statusBar.innerHTML =
				'<span class="wp-mcp-ai-chat__voice-status-dot" aria-hidden="true"></span>' +
				'<span class="wp-mcp-ai-chat__voice-status-text">Voice chat ready</span>' +
				'<button type="button" class="wp-mcp-ai-chat__voice-end-call" aria-label="End voice session">End Call</button>';

			// Live transcription overlay.
			const transcription = document.createElement('div');
			transcription.className = 'wp-mcp-ai-chat__voice-transcription';
			transcription.setAttribute('aria-live', 'polite');

			// Waveform canvas.
			const waveform = document.createElement('div');
			waveform.className = 'wp-mcp-ai-chat__voice-waveform';
			const canvas = document.createElement('canvas');
			canvas.width = 400;
			canvas.height = 48;
			waveform.appendChild(canvas);

			// Push-to-talk button (for browser mode).
			const pttButton = document.createElement('button');
			pttButton.type = 'button';
			pttButton.className = 'wp-mcp-ai-chat__voice-ptt';
			pttButton.textContent = 'Hold to Talk';
			pttButton.setAttribute('aria-label', 'Hold to talk');

			// Voice mode toggle button.
			const modeToggle = document.createElement('button');
			modeToggle.type = 'button';
			modeToggle.className = 'wp-mcp-ai-chat__voice-mode-toggle';
			modeToggle.innerHTML = this.getModeIcon(MODE_CHAINED) + ' <span>' + this.getModeLabel(MODE_CHAINED) + '</span>';
			modeToggle.setAttribute('aria-label', 'Voice mode: ' + this.getModeLabel(this.currentMode));

			// Insert UI elements.
			// Status bar goes after controls.
			const controls = container.querySelector('.wp-mcp-ai-chat__controls');
			if (controls) {
				controls.parentNode.insertBefore(statusBar, controls);
				controls.parentNode.insertBefore(transcription, controls);
				controls.parentNode.insertBefore(waveform, controls);

				// Add mode toggle to control buttons.
				const controlButtons = controls.querySelector('.wp-mcp-ai-chat__control-buttons');
				if (controlButtons) {
					controlButtons.appendChild(modeToggle);
				}

				// Add PTT button below controls.
				controls.parentNode.insertBefore(pttButton, controls.nextSibling);
			}

			// Store references.
			this.elements.statusBar = statusBar;
			this.elements.statusDot = statusBar.querySelector('.wp-mcp-ai-chat__voice-status-dot');
			this.elements.statusText = statusBar.querySelector('.wp-mcp-ai-chat__voice-status-text');
			this.elements.endCallBtn = statusBar.querySelector('.wp-mcp-ai-chat__voice-end-call');
			this.elements.transcription = transcription;
			this.elements.waveform = waveform;
			this.elements.canvas = canvas;
			this.elements.pttButton = pttButton;
			this.elements.modeToggle = modeToggle;

			// Start waveform animation.
			this.startWaveform();
		},

		/**
		 * Bind event listeners for voice UI.
		 */
		bindEvents: function () {
			const self = this;

			// Mode toggle click.
			if (this.elements.modeToggle) {
				this.elements.modeToggle.addEventListener('click', function (e) {
					e.preventDefault();
					self.cycleMode();
				});
			}

			// End call button.
			if (this.elements.endCallBtn) {
				this.elements.endCallBtn.addEventListener('click', function (e) {
					e.preventDefault();
					self.endVoiceSession();
				});
			}

			// Push-to-talk.
			if (this.elements.pttButton) {
				this.elements.pttButton.addEventListener('mousedown', function (e) {
					e.preventDefault();
					self.startPTT();
				});
				this.elements.pttButton.addEventListener('mouseup', function (e) {
					e.preventDefault();
					self.stopPTT();
				});
				this.elements.pttButton.addEventListener('mouseleave', function () {
					self.stopPTT();
				});
				// Touch events for mobile.
				this.elements.pttButton.addEventListener('touchstart', function (e) {
					e.preventDefault();
					self.startPTT();
				});
				this.elements.pttButton.addEventListener('touchend', function (e) {
					e.preventDefault();
					self.stopPTT();
				});
			}
		},

		/**
		 * Cycle through available voice modes.
		 */
		cycleMode: function () {
			const modes = [MODE_CHAINED, MODE_BROWSER, MODE_REALTIME, MODE_TEXT];
			const currentIdx = modes.indexOf(this.currentMode);
			const nextIdx = (currentIdx + 1) % modes.length;
			this.setMode(modes[nextIdx]);
		},

		/**
		 * Set the active voice mode.
		 *
		 * @param {string} mode - The mode to activate.
		 * @param {boolean} silent - If true, don't announce or update UI.
		 */
		setMode: function (mode, silent) {
			// End previous mode.
			this.endVoiceSession();

			const previousMode = this.currentMode;
			this.currentMode = mode;

			// Update container class.
			if (this.container && this.container.classList) {
				this.container.classList.remove(
					'wp-mcp-ai-chat--voice-realtime',
					'wp-mcp-ai-chat--voice-browser'
				);

				if (mode === MODE_REALTIME) {
					this.container.classList.add('wp-mcp-ai-chat--voice-realtime');
				} else if (mode === MODE_BROWSER) {
					this.container.classList.add('wp-mcp-ai-chat--voice-browser');
				}
			}

			// Update toggle button.
			if (this.elements.modeToggle) {
				this.elements.modeToggle.innerHTML =
					this.getModeIcon(mode) + ' <span>' + this.getModeLabel(mode) + '</span>';
				this.elements.modeToggle.setAttribute('aria-label', 'Voice mode: ' + this.getModeLabel(mode));
			}

			// Update status text.
			if (this.elements.statusText) {
				const labels = {
					realtime: 'Realtime voice active — speak naturally',
					chained: 'Voice chat active — tap mic to speak',
					browser: 'Browser voice active — hold to talk',
					off: 'Text mode — voice disabled',
				};
				this.elements.statusText.textContent = labels[mode] || labels.chained;
			}

			// Update status dot.
			if (this.elements.statusDot) {
				this.elements.statusDot.className = 'wp-mcp-ai-chat__voice-status-dot';
			}

			// Start realtime connection if mode is realtime.
			if (mode === MODE_REALTIME && realtimeVoice) {
				this.startRealtimeSession();
			}

			if (!silent) {
				this.announceModeChange(mode, previousMode);
			}
		},

		/**
		 * Start a realtime voice session.
		 */
		startRealtimeSession: function () {
			if (!realtimeVoice || !realtimeVoice.isSupported()) {
				this.setMode(MODE_CHAINED, true);
				this.setStatusMessage('Realtime voice not supported in this browser. Switched to chained mode.');
				return;
			}

			const self = this;
			const config = this.config;
			const assistantId = config.assistantId;

			if (!assistantId || !config.voiceEndpoint) {
				this.setMode(MODE_CHAINED, true);
				return;
			}

			this.setStatusMessage('Connecting to voice server…');

			// Build headers function using config.
			const buildJsonHeaders = function (_state) {
				return {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce || '',
				};
			};

			realtimeVoice.createSession(config, assistantId, buildJsonHeaders)
				.then(function (sessionConfig) {
					self.setStatusMessage('Connected. Start speaking!');

					self.realtimeConn = realtimeVoice.connect(
						self.instanceKey,
						sessionConfig,
						{
							onStateChange: function (state) {
								self.handleRealtimeState(state);
							},
							onTranscript: function (text) {
								self.showTranscription('You: ' + text);
							},
							onResponseText: function (text) {
								self.showTranscription('AI: ' + text);
							},
							onResponseAudio: function (buffer) {
								realtimeVoice.playAudioBuffer(self.instanceKey, buffer, 24000);
							},
							onError: function (error) {
								self.setStatusMessage('Voice error: ' + (error.message || 'Unknown error'));
								self.setMode(MODE_CHAINED, true);
							},
							onFunctionCall: function (call) {
								// Function calls are handled by the existing tool system.
								self.setStatusMessage('Running tool: ' + call.name + '…');
							},
						}
					);

					// Store connection reference on state.
					if (self.state) {
						self.state.voiceRealtimeConn = self.realtimeConn;
					}
				})
				.catch(function (_error) {
					self.setStatusMessage('Failed to start realtime voice. Using chained mode.');
					self.setMode(MODE_CHAINED, true);
				});
		},

		/**
		 * Handle realtime voice state changes.
		 *
		 * @param {string} state - State name.
		 */
		handleRealtimeState: function (state) {
			if (this.container && this.container.classList) {
				// Remove all state classes.
				this.container.classList.remove(
					'wp-mcp-ai-chat__voice-realtime--connecting',
					'wp-mcp-ai-chat__voice-realtime--active',
					'wp-mcp-ai-chat__voice-realtime--listening',
					'wp-mcp-ai-chat__voice-realtime--speaking',
					'wp-mcp-ai-chat__voice-realtime--error'
				);
			}

			const stateMessages = {
				connecting: 'Connecting…',
				active: 'Connected. Start speaking!',
				listening: 'Listening…',
				processing: 'Processing…',
				speaking: 'Speaking…',
				reconnecting: 'Reconnecting…',
				disconnected: 'Disconnected.',
				error: 'Connection error.',
			};

			if (this.container && this.container.classList) {
				const cls = 'wp-mcp-ai-chat__voice-realtime--' + state;
				this.container.classList.add(cls);
			}

			if (this.elements.statusText) {
				this.elements.statusText.textContent = stateMessages[state] || state;
			}
		},

		/**
		 * Start push-to-talk (browser mode).
		 */
		startPTT: function () {
			if (this.currentMode !== MODE_BROWSER || !browserVoice || !browserVoice.isSTTSupported()) {
				return;
			}

			if (this.elements.pttButton) {
				this.elements.pttButton.classList.add('wp-mcp-ai-chat__voice-ptt--active');
				this.elements.pttButton.textContent = 'Listening…';
			}

			const self = this;
			this.showTranscription('');

			this.browserRecognizer = browserVoice.startListening({
				onResult: function (result) {
					self.showTranscription(result.text);
					if (result.isFinal) {
						self.sendBrowserTranscript(result.text);
					}
				},
				onError: function (error) {
					self.setStatusMessage('Recognition error: ' + error.message);
				},
				onEnd: function () {
					if (self.elements.pttButton) {
						self.elements.pttButton.classList.remove('wp-mcp-ai-chat__voice-ptt--active');
						self.elements.pttButton.textContent = 'Hold to Talk';
					}
				},
			}, {
				continuous: false,
				interimResults: true,
			});
		},

		/**
		 * Stop push-to-talk.
		 */
		stopPTT: function () {
			if (this.browserRecognizer) {
				this.browserRecognizer.stop();
				this.browserRecognizer = null;
			}
		},

		/**
		 * Send transcript from browser voice to chat.
		 *
		 * @param {string} text - Transcribed text.
		 */
		sendBrowserTranscript: function (text) {
			if (!text || !this.state) {
				return;
			}

			// Set textarea value and trigger send.
			if (this.state.textarea) {
				this.state.textarea.value = text.trim();
			}

			// Use existing send mechanism from chat.js.
			if (this.state.sendMessage && typeof this.state.sendMessage === 'function') {
				this.state.sendMessage();
			} else if (this.container) {
				// Fallback: find and click the send button.
				const sendBtn = this.container.querySelector('.wp-mcp-ai-chat__send');
				if (sendBtn) {
					sendBtn.click();
				}
			}
		},

		/**
		 * Show live transcription.
		 *
		 * @param {string} text - Transcription text.
		 */
		showTranscription: function (text) {
			if (this.elements.transcription) {
				this.elements.transcription.textContent = text || '';
			}
		},

		/**
		 * Set status message.
		 *
		 * @param {string} message - Status message.
		 */
		setStatusMessage: function (message) {
			if (this.elements.statusText) {
				this.elements.statusText.textContent = message;
			}
		},

		/**
		 * End current voice session.
		 */
		endVoiceSession: function () {
			// Disconnect realtime.
			if (this.realtimeConn) {
				this.realtimeConn.close();
				this.realtimeConn = null;
			}

			if (realtimeVoice) {
				realtimeVoice.disconnect(this.instanceKey);
			}

			// Stop browser recognition.
			if (this.browserRecognizer) {
				this.browserRecognizer.abort();
				this.browserRecognizer = null;
			}

			// Cancel browser speech.
			if (browserVoice) {
				browserVoice.cancelSpeech();
			}

			// Clear transcription.
			this.showTranscription('');

			// Remove state classes.
			if (this.container && this.container.classList) {
				this.container.classList.remove(
					'wp-mcp-ai-chat__voice-realtime--connecting',
					'wp-mcp-ai-chat__voice-realtime--active',
					'wp-mcp-ai-chat__voice-realtime--listening',
					'wp-mcp-ai-chat__voice-realtime--speaking',
					'wp-mcp-ai-chat__voice-realtime--error'
				);
			}

			// Reset PTT button.
			if (this.elements.pttButton) {
				this.elements.pttButton.classList.remove('wp-mcp-ai-chat__voice-ptt--active');
				this.elements.pttButton.textContent = 'Hold to Talk';
			}
		},

		/**
		 * Announce mode change for screen readers.
		 *
		 * @param {string} newMode - New mode.
		 * @param {string} oldMode - Previous mode.
		 */
		announceModeChange: function (newMode, _oldMode) {
			const message = 'Switched to ' + this.getModeLabel(newMode) + ' mode.';
			if (this.elements.statusText) {
				// Brief aria-live announcement.
				const announcer = document.createElement('span');
				announcer.setAttribute('aria-live', 'assertive');
				announcer.className = 'screen-reader-text';
				announcer.textContent = message;
				document.body.appendChild(announcer);
				setTimeout(function () {
					announcer.remove();
				}, 3000);
			}
		},

		/**
		 * Get SVG icon for a voice mode.
		 *
		 * @param {string} mode - Voice mode.
		 * @return {string} SVG HTML.
		 */
		getModeIcon: function (mode) {
			const icons = {
				realtime: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 1a3 3 0 00-3 3v4a3 3 0 006 0V4a3 3 0 00-3-3z"/><path d="M5 8a5 5 0 0010 0h1a6 6 0 01-4.5 5.8V17h3v1h-9v-1h3v-3.2A6 6 0 014 8h1z" fill="currentColor"/></svg>',
				chained: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 2a3 3 0 00-3 3v5a3 3 0 006 0V5a3 3 0 00-3-3z"/><path d="M6 11a4 4 0 108 0h1a5 5 0 01-4 4.9V18h2v1H7v-1h2v-2.1A5 5 0 015 11h1z" fill="currentColor"/></svg>',
				browser: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3a3 3 0 00-3 3v4a3 3 0 006 0V6a3 3 0 00-3-3z"/><path d="M5 9a5 5 0 0010 0h1a6 6 0 01-11 0h0z" fill="currentColor"/><circle cx="16" cy="16" r="3" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>',
				off: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 3l14 14M10 3a3 3 0 00-3 3v4a3 3 0 006 0V6a3 3 0 00-3-3zM5 9a5 5 0 009 2.3" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>',
			};
			return icons[mode] || icons.off;
		},

		/**
		 * Get human-readable label for a voice mode.
		 *
		 * @param {string} mode - Voice mode.
		 * @return {string} Label.
		 */
		getModeLabel: function (mode) {
			const labels = {
				realtime: 'Realtime',
				chained: 'Voice',
				browser: 'Browser',
				off: 'Text',
			};
			return labels[mode] || 'Voice';
		},

		/**
		 * Start waveform visualization animation.
		 */
		startWaveform: function () {
			if (!this.elements.canvas) {
				return;
			}

			const canvas = this.elements.canvas;
			const ctx = canvas.getContext('2d');
			const self = this;

			let animationId = null;

			function draw() {
				if (!canvas || !self.container) {
					cancelAnimationFrame(animationId);
					return;
				}

				// Check if waveform should be visible.
				const isVisible = (
					self.container.classList.contains('wp-mcp-ai-chat--voice-realtime') ||
					self.container.classList.contains('wp-mcp-ai-chat--voice-browser')
				);

				if (!isVisible) {
					ctx.clearRect(0, 0, canvas.width, canvas.height);
					animationId = requestAnimationFrame(draw);
					return;
				}

				ctx.clearRect(0, 0, canvas.width, canvas.height);

				const w = canvas.width;
				const h = canvas.height;
				const barCount = 40;
				const barWidth = (w / barCount) - 2;
				const now = Date.now() / 200;

				// Check if currently in listening/speaking state.
				const isActive =
					self.container.classList.contains('wp-mcp-ai-chat__voice-realtime--listening') ||
					self.container.classList.contains('wp-mcp-ai-chat__voice-realtime--speaking');

				for (let i = 0; i < barCount; i++) {
					const phase = (i / barCount) * Math.PI * 2;
					let height;

					if (isActive) {
						height = Math.abs(Math.sin(now + phase * 3)) * (h * 0.8) + 4;
					} else {
						height = Math.abs(Math.sin(now * 0.5 + phase)) * (h * 0.3) + 2;
					}

					const x = i * (barWidth + 2);
					const y = (h - height) / 2;

					ctx.fillStyle = isActive
						? 'rgba(34, 113, 177, 0.6)'
						: 'rgba(34, 113, 177, 0.2)';
					ctx.fillRect(x, y, barWidth, height);
				}

				animationId = requestAnimationFrame(draw);
			}

			draw();
			this._waveformAnimationId = animationId;
		},

		/**
		 * Destroy voice mode integration and clean up.
		 */
		destroy: function () {
			this.endVoiceSession();

			if (this._waveformAnimationId) {
				cancelAnimationFrame(this._waveformAnimationId);
			}

			// Remove UI elements.
			Object.keys(this.elements).forEach(function (key) {
				const el = this.elements[key];
				if (el && el.parentNode) {
					el.parentNode.removeChild(el);
				}
			}.bind(this));

			this.elements = {};
			this.state = null;
			this.container = null;
		},
	};

	window.wpMcpAiVoiceMode = wpMcpAiVoiceMode;
})(window);
