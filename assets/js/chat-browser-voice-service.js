/**
 * Browser Voice Service for NV oOS Chat
 *
 * Provides a voice fallback using the browser's built-in Web Speech API:
 * - SpeechRecognition (Chrome/Edge) for speech-to-text
 * - SpeechSynthesis for text-to-speech
 *
 * This service works without any API keys and provides a zero-cost voice
 * layer for browsers that support the Web Speech API. It serves as the
 * Tier 3 fallback when neither Realtime S2S nor chained pipeline are available.
 *
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

(function (window) {
	'use strict';

	if (window.wpMcpAiBrowserVoice) {
		return;
	}

	// ── Constants ──────────────────────────────────────────────────────

	// Class constants exported for external use by chat UI modules.
	/* eslint-disable no-unused-vars */
	const BROWSER_VOICE_MODE_CLASS = 'wp-mcp-ai-chat--voice-browser';
	const BROWSER_LISTENING_CLASS = 'wp-mcp-ai-chat__voice-browser--listening';
	const BROWSER_SPEAKING_CLASS = 'wp-mcp-ai-chat__voice-browser--speaking';
	/* eslint-enable no-unused-vars */

	// ── Service ─────────────────────────────────────────────────────────

	const wpMcpAiBrowserVoice = {
		/**
		 * Check if browser SpeechRecognition is available.
		 *
		 * @return {boolean}
		 */
		isSTTSupported: function () {
			return (
				typeof window !== 'undefined' &&
				(typeof window.SpeechRecognition !== 'undefined' ||
				 typeof window.webkitSpeechRecognition !== 'undefined')
			);
		},

		/**
		 * Check if browser SpeechSynthesis is available.
		 *
		 * @return {boolean}
		 */
		isTTSSupported: function () {
			return (
				typeof window !== 'undefined' &&
				typeof window.speechSynthesis !== 'undefined'
			);
		},

		/**
		 * Get the SpeechRecognition constructor.
		 *
		 * @return {Function|null}
		 */
		getRecognition: function () {
			return window.SpeechRecognition || window.webkitSpeechRecognition || null;
		},

		/**
		 * Create a speech recognition instance with defaults.
		 *
		 * @param {Object} options - Recognition options.
		 * @param {boolean} options.continuous - Keep listening after results.
		 * @param {boolean} options.interimResults - Show interim results.
		 * @param {string} options.lang - Language code (default: browser locale).
		 * @return {SpeechRecognition|null}
		 */
		createRecognition: function (options) {
			const Recognition = this.getRecognition();
			if (!Recognition) {
				return null;
			}

			options = options || {};

			try {
				const recognition = new Recognition();
				recognition.continuous = options.continuous !== false;
				recognition.interimResults = options.interimResults !== false;
				recognition.lang = options.lang || navigator.language || 'en-US';
				recognition.maxAlternatives = 1;
				return recognition;
			} catch (e) {
				return null;
			}
		},

		/**
		 * Start listening for speech and return results via callbacks.
		 *
		 * @param {Object} callbacks - Event callbacks.
		 * @param {Function} callbacks.onResult - Called with {text, isFinal}.
		 * @param {Function} callbacks.onError - Called with error.
		 * @param {Function} callbacks.onEnd - Called when recognition ends.
		 * @param {Object} options - Recognition options (see createRecognition).
		 * @return {Object} Handle with stop() method, or null if unsupported.
		 */
		startListening: function (callbacks, options) {
			const recognition = this.createRecognition(options);
			if (!recognition) {
				if (callbacks.onError) {
					callbacks.onError(new Error('Speech recognition not supported in this browser.'));
				}
				return null;
			}

			callbacks = callbacks || {};

			recognition.onresult = function (event) {
				for (let i = event.resultIndex; i < event.results.length; i++) {
					const result = event.results[i];
					if (callbacks.onResult) {
						callbacks.onResult({
							text: result[0].transcript,
							isFinal: result.isFinal,
							confidence: result[0].confidence,
						});
					}
				}
			};

			recognition.onerror = function (event) {
				if (callbacks.onError) {
					callbacks.onError(new Error(event.error || 'Speech recognition error.'));
				}
			};

			recognition.onend = function () {
				if (callbacks.onEnd) {
					callbacks.onEnd();
				}
			};

			try {
				recognition.start();
			} catch (e) {
				if (callbacks.onError) {
					callbacks.onError(e);
				}
				return null;
			}

			return {
				recognition: recognition,
				stop: function () {
					try { recognition.stop(); } catch (e) {}
				},
				abort: function () {
					try { recognition.abort(); } catch (e) {}
				},
			};
		},

		/**
		 * Speak text using browser speech synthesis.
		 *
		 * @param {string} text - Text to speak.
		 * @param {Object} options - TTS options.
		 * @param {string} options.voice - Voice name to use.
		 * @param {number} options.rate - Speech rate (0.1-10, default 1).
		 * @param {number} options.pitch - Speech pitch (0-2, default 1).
		 * @param {number} options.volume - Volume (0-1, default 1).
		 * @param {Function} options.onStart - Called when speech starts.
		 * @param {Function} options.onEnd - Called when speech ends.
		 * @param {Function} options.onError - Called on error.
		 * @return {Object} Handle with stop()/pause()/resume() methods.
		 */
		speak: function (text, options) {
			if (!this.isTTSSupported()) {
				if (options && options.onError) {
					options.onError(new Error('Speech synthesis not supported.'));
				}
				return null;
			}

			if (!text || typeof text !== 'string' || !text.trim()) {
				return null;
			}

			options = options || {};

			// Cancel any ongoing speech.
			window.speechSynthesis.cancel();

			const utterance = new SpeechSynthesisUtterance(text);

			// Try to match requested voice.
			if (options.voice) {
				const voices = window.speechSynthesis.getVoices();
				const match = voices.find(function (v) {
					return v.name === options.voice || v.lang === options.voice;
				});
				if (match) {
					utterance.voice = match;
				}
			} else {
				// Prefer a default English voice.
				const voices = window.speechSynthesis.getVoices();
				const enVoice = voices.find(function (v) {
					return v.lang && v.lang.startsWith('en') && v.localService;
				});
				if (enVoice) {
					utterance.voice = enVoice;
				}
			}

			utterance.rate = typeof options.rate === 'number' ? Math.max(0.1, Math.min(10, options.rate)) : 1;
			utterance.pitch = typeof options.pitch === 'number' ? Math.max(0, Math.min(2, options.pitch)) : 1;
			utterance.volume = typeof options.volume === 'number' ? Math.max(0, Math.min(1, options.volume)) : 1;

			utterance.onstart = function () {
				if (options.onStart) { options.onStart(); }
			};

			utterance.onend = function () {
				if (options.onEnd) { options.onEnd(); }
			};

			utterance.onerror = function (event) {
				if (options.onError) {
					options.onError(new Error(event.error || 'Speech synthesis error.'));
				}
			};

			window.speechSynthesis.speak(utterance);

			return {
				utterance: utterance,
				stop: function () {
					window.speechSynthesis.cancel();
				},
				pause: function () {
					window.speechSynthesis.pause();
				},
				resume: function () {
					window.speechSynthesis.resume();
				},
				isSpeaking: function () {
					return window.speechSynthesis.speaking;
				},
			};
		},

		/**
		 * Cancel all ongoing speech.
		 */
		cancelSpeech: function () {
			if (this.isTTSSupported()) {
				window.speechSynthesis.cancel();
			}
		},

		/**
		 * Get available voices for speech synthesis.
		 *
		 * @return {Array<SpeechSynthesisVoice>}
		 */
		getVoices: function () {
			if (!this.isTTSSupported()) {
				return [];
			}
			return window.speechSynthesis.getVoices();
		},

		/**
		 * Get recommended English voices for TTS.
		 *
		 * @return {Array<SpeechSynthesisVoice>}
		 */
		getRecommendedVoices: function () {
			const voices = this.getVoices();

			// Prioritize local, high-quality English voices.
			return voices.filter(function (v) {
				return v.lang && v.lang.startsWith('en') && v.localService;
			});
		},
	};

	window.wpMcpAiBrowserVoice = wpMcpAiBrowserVoice;
})(window);
