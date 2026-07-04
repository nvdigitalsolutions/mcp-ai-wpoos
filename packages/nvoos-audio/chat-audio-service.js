/**
 * Audio Service for NV oOS Chat
 * 
 * Handles speech synthesis (text-to-speech), audio transcription (speech-to-text),
 * and voice chat functionality with browser MediaRecorder API support.
 * This is a self-contained service that can be used independently.
 * 
 * @since 1.0.0
 */

(function(window) {
	'use strict';

	// Speech synthesis constants
	const SPEECH_TOOL_NAME = 'generate_openai_speech';
	const SPEECH_BUTTON_CLASS = 'wp-mcp-ai-speech-button';
	const SPEECH_ENABLED_CLASS = 'wp-mcp-ai-speech-enabled';
	const SPEECH_ERROR_CLASS = 'wp-mcp-ai-speech-button--error';
	const SPEECH_PLAY_ICON = '<svg class="wp-mcp-ai-speech-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M6 4l9 6-9 6V4z"></path></svg>';
	const SPEECH_STOP_ICON = '<svg class="wp-mcp-ai-speech-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><rect x="6" y="5" width="8" height="10" rx="1"></rect></svg>';
	const SPEECH_SPINNER_ICON = '<span class="wp-mcp-ai-speech-spinner" aria-hidden="true"></span>';

	// Audio transcription constants
	const _TRANSCRIBE_TOOL_NAME = 'transcribe_openai_audio';
	const TRANSCRIBE_RECORDING_CLASS = 'wp-mcp-ai-chat__transcribe--recording';
	const MAX_TRANSCRIBE_BYTES = 26214400; // 25MB

	// Audio translation constants (uses same tool as transcription)
	const TRANSLATE_RECORDING_CLASS = 'wp-mcp-ai-chat__translate--recording';

	// Voice chat constants
	const VOICE_CHAT_RECORDING_CLASS = 'wp-mcp-ai-chat__voice-chat--recording';
	const VOICE_CHAT_PROCESSING_CLASS = 'wp-mcp-ai-chat__voice-chat--processing';
	const VOICE_CHAT_LISTENING_CLASS = 'wp-mcp-ai-chat__voice-chat--listening';

	// VAD (Voice Activity Detection) default constants
	// These can be overridden by WordPress settings passed via config.
	//
	// Thresholds are calibrated for local/browser VAD which lacks linguistic context.
	// Server-side VAD (OpenAI Realtime server_vad / semantic_vad) can use shorter
	// timeouts because it understands speech patterns. Local energy-threshold VAD
	// needs a longer hang-out to avoid clipping mid-thought pauses.
	//
	// Industry references:
	//   - OpenAI server_vad default: 500ms silence_duration_ms
	//   - VAD best practices: 500-1500ms hang-out for local detectors
	//   - Push-to-talk chained mode: 1000-1500ms recommended
	const VAD_DEFAULT_SILENCE_THRESHOLD_MS = 1200;     // Silence before auto-stop (was 700ms)
	const VAD_DEFAULT_MIN_SPEECH_DURATION_MS = 400;    // Minimum speech before VAD activates (was 300ms)
	const VAD_DEFAULT_MIN_RECORDING_MS = 500;          // Minimum total recording before auto-stop can fire
	const VAD_DEFAULT_AUDIO_LEVEL_THRESHOLD = -50;     // dB threshold for speech
	const VAD_CHECK_INTERVAL_MS = 100;                 // Check audio levels every 100ms

	// Object URL registry for cleanup
	let objectUrlRegistry = [];

	/**
	 * Register an object URL for later cleanup.
	 * 
	 * @param {string} url - The object URL to register
	 */
	function registerObjectUrl(url) {
		if (!url) {
			return;
		}

		objectUrlRegistry.push(url);
	}

	/**
	 * Revoke all registered object URLs to free memory.
	 */
	function revokeObjectUrls() {
		if (!objectUrlRegistry.length) {
			return;
		}

		objectUrlRegistry.forEach(function (url) {
			try {
				URL.revokeObjectURL(url);
			} catch (error) {
				// Ignore revoke errors.
			}
		});

		objectUrlRegistry = [];
	}

	/**
	 * Check if browser supports audio recording.
	 * 
	 * @return {boolean} True if audio recording is supported
	 */
	function supportsAudioRecording() {
		return (
			typeof window !== 'undefined' &&
			window.navigator &&
			navigator.mediaDevices &&
			typeof navigator.mediaDevices.getUserMedia === 'function' &&
			typeof window.MediaRecorder !== 'undefined'
		);
	}

	// ========================================
	// Speech Synthesis (Text-to-Speech)
	// ========================================

	/**
	 * Normalize speech text by trimming whitespace.
	 * 
	 * @param {string} text - Text to normalize
	 * @return {string} Normalized text
	 */
	function normalizeSpeechText(text) {
		if (typeof text !== 'string') {
			return '';
		}

		return text.trim();
	}

	/**
	 * Update speech button icon based on state.
	 * 
	 * @param {HTMLElement} button - The speech button element
	 * @param {string} stateName - State name ('idle', 'loading', 'playing')
	 */
	function updateSpeechButtonIcon(button, stateName) {
		if (!button) {
			return;
		}

		if (button.classList) {
			button.classList.remove(SPEECH_ERROR_CLASS);
		}

		button.dataset.state = stateName;

		if (stateName === 'loading') {
			button.innerHTML = SPEECH_SPINNER_ICON;
			button.setAttribute('aria-label', 'Generating audio...');
			button.setAttribute('title', 'Generating audio...');
			button.setAttribute('aria-busy', 'true');
			return;
		}

		button.removeAttribute('aria-busy');

		if (stateName === 'playing') {
			button.innerHTML = SPEECH_STOP_ICON;
			button.setAttribute('aria-label', 'Stop audio playback');
			button.setAttribute('title', 'Stop audio playback');
			return;
		}

		button.innerHTML = SPEECH_PLAY_ICON;
		button.setAttribute('aria-label', 'Play response audio');
		button.setAttribute('title', 'Play response audio');
	}

	/**
	 * Clear cached speech audio for given text.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {string} text - Text to clear from cache
	 */
	function clearSpeechCacheEntry(state, text) {
		if (!state || !state.speechCache || !text) {
			return;
		}

		delete state.speechCache[text];
	}

	/**
	 * Set speech button to error state.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {HTMLElement} button - Speech button element
	 * @param {string} text - Text that failed to generate audio
	 */
	function setSpeechButtonErrorState(state, button, text) {
		if (!button) {
			return;
		}

		button.dataset.state = 'error';
		button.innerHTML = SPEECH_PLAY_ICON;
		button.setAttribute('aria-label', 'Unable to generate audio');
		button.setAttribute('title', 'Unable to generate audio');
		button.removeAttribute('aria-busy');
		button.disabled = false;

		if (button.classList) {
			button.classList.add(SPEECH_ERROR_CLASS);
		}

		if (button._wpMcpAiAudio) {
			try {
				button._wpMcpAiAudio.pause();
			} catch (error) {}
		}

		button._wpMcpAiAudio = null;

		if (state && state.activeSpeech && state.activeSpeech.button === button) {
			state.activeSpeech = null;
		}

		clearSpeechCacheEntry(state, text);
	}

	/**
	 * Stop speech audio playback.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {HTMLElement} button - Speech button element
	 */
	function stopSpeechPlayback(state, button) {
		if (!state || !button) {
			return;
		}

		let audio = button._wpMcpAiAudio;
		if (!audio && state.activeSpeech && state.activeSpeech.button === button) {
			audio = state.activeSpeech.audio;
		}

		if (audio) {
			try {
				audio.pause();
			} catch (error) {}

			try {
				audio.currentTime = 0;
			} catch (error) {}
		}

		if (state.activeSpeech && state.activeSpeech.button === button) {
			state.activeSpeech = null;
		}

		updateSpeechButtonIcon(button, 'idle');
	}

	/**
	 * Start speech audio playback.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {HTMLElement} button - Speech button element
	 * @param {HTMLAudioElement} audio - Audio element to play
	 * @param {string} text - Text being spoken
	 */
	function startSpeechPlayback(state, button, audio, text) {
		if (!audio) {
			return;
		}

		if (state.activeSpeech && state.activeSpeech.audio && state.activeSpeech.audio !== audio) {
			try {
				state.activeSpeech.audio.pause();
			} catch (error) {}

			if (state.activeSpeech.button) {
				updateSpeechButtonIcon(state.activeSpeech.button, 'idle');
			}
		}

		audio.currentTime = 0;

		const playPromise = audio.play();
		if (playPromise && typeof playPromise.then === 'function') {
			playPromise.catch(function () {
				const currentText = button.dataset ? button.dataset.speechText || text : text;
				setSpeechButtonErrorState(state, button, currentText);
			});
		}
	}

	/**
	 * Create audio element with event listeners.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {HTMLElement} button - Speech button element
	 * @param {string} url - Audio URL
	 * @param {string} text - Text being spoken
	 * @return {HTMLAudioElement} Audio element
	 */
	function createSpeechAudio(state, button, url, text) {
		const audio = new Audio(url);
		audio.preload = 'auto';

		audio.addEventListener('ended', function () {
			if (state.activeSpeech && state.activeSpeech.audio === audio) {
				state.activeSpeech = null;
			}
			updateSpeechButtonIcon(button, 'idle');
		});

		audio.addEventListener('pause', function () {
			if (button.dataset && button.dataset.state === 'error') {
				return;
			}

			if (!audio.duration || audio.currentTime < audio.duration) {
				if (state.activeSpeech && state.activeSpeech.audio === audio) {
					state.activeSpeech = null;
				}
				updateSpeechButtonIcon(button, 'idle');
			}
		});

		audio.addEventListener('play', function () {
			state.activeSpeech = { button: button, audio: audio, text: text };
			updateSpeechButtonIcon(button, 'playing');
		});

		audio.addEventListener('error', function () {
			setSpeechButtonErrorState(state, button, text);
		});

		return audio;
	}

	/**
	 * Ensure audio element exists and start playback.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {HTMLElement} button - Speech button element
	 * @param {string} url - Audio URL
	 * @param {string} text - Text being spoken
	 */
	function ensureSpeechAudio(state, button, url, text) {
		if (!url) {
			return;
		}

		let audio = button._wpMcpAiAudio;
		if (!audio || audio.src !== url) {
			audio = createSpeechAudio(state, button, url, text);
			button._wpMcpAiAudio = audio;
		}

		startSpeechPlayback(state, button, audio, text);
	}

	/**
	 * Request speech audio from server.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {string} text - Text to convert to speech
	 * @param {Function} buildJsonHeaders - Function to build request headers
	 * @return {Promise<Object>} Promise resolving to audio info
	 */
	function requestSpeechAudio(state, text, buildJsonHeaders) {
		if (!state || !state.config || !state.config.toolsEndpoint) {
			return Promise.reject(new Error('Speech tool unavailable.'));
		}

		const payload = {
			assistant_id: state.config.assistantId,
			tool: SPEECH_TOOL_NAME,
			arguments: {
				text: text,
			},
		};

		return fetch(state.config.toolsEndpoint, {
			method: 'POST',
			headers: buildJsonHeaders(state),
			credentials: 'same-origin',
			body: JSON.stringify(payload),
		})
			.then(function (response) {
				return response
					.json()
					.catch(function () {
						return null;
					})
					.then(function (body) {
						if (!response.ok) {
							throw response;
						}
						if (!body || typeof body !== 'object') {
							return Promise.reject(new Error('Invalid response.'));
						}
						return body;
					});
			})
			.then(function (body) {

				const result = Object.prototype.hasOwnProperty.call(body, 'result') ? body.result : body;
				if (!result || typeof result !== 'object' || !result.url) {
					return Promise.reject(new Error('Missing audio result.'));
				}

				return {
					url: result.url,
					attachmentId: result.attachment_id,
					format: result.format,
					mimeType: result.mime_type,
				};
			});
	}

	/**
	 * Handle speech button click event.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {HTMLElement} button - Speech button element
	 * @param {Function} buildJsonHeaders - Function to build request headers
	 */
	function handleSpeechButtonClick(state, button, buildJsonHeaders) {
		if (!state || !button) {
			return;
		}

		const text = normalizeSpeechText(button.dataset.speechText || '');
		if (!text) {
			return;
		}

		const currentState = button.dataset.state;
		if (currentState === 'loading') {
			return;
		}

		if (currentState === 'playing') {
			stopSpeechPlayback(state, button);
			return;
		}

		if (!state.speechCache) {
			state.speechCache = Object.create(null);
		}

		const cache = state.speechCache[text];
		if (cache && cache.url) {
			ensureSpeechAudio(state, button, cache.url, text);
			return;
		}

		updateSpeechButtonIcon(button, 'loading');
		button.disabled = true;

		requestSpeechAudio(state, text, buildJsonHeaders)
			.then(function (info) {
				if (!info || !info.url) {
					throw new Error('Invalid speech response');
				}

				state.speechCache[text] = { url: info.url };
				ensureSpeechAudio(state, button, info.url, text);
			})
			.catch(function () {
				setSpeechButtonErrorState(state, button, text);
			})
			.finally(function () {
				button.disabled = false;
				if (button.dataset.state === 'loading') {
					updateSpeechButtonIcon(button, 'idle');
				}
			});
	}

	/**
	 * Resolve speech text from bubble or explicit text.
	 * 
	 * @param {HTMLElement} bubble - Message bubble element
	 * @param {string} text - Explicit text to speak
	 * @return {string} Text to speak
	 */
	function resolveSpeechText(bubble, text) {
		const provided = normalizeSpeechText(text || '');
		if (provided) {
			return provided;
		}

		if (bubble && bubble.dataset && bubble.dataset.speechText) {
			const stored = normalizeSpeechText(bubble.dataset.speechText);
			if (stored) {
				return stored;
			}
		}

		if (!bubble) {
			return '';
		}

		let textContent = '';
		if (typeof bubble.textContent === 'string') {
			textContent = bubble.textContent;
		} else if (bubble.innerText) {
			textContent = bubble.innerText;
		}

		return normalizeSpeechText(textContent);
	}

	/**
	 * Attach speech button to a message bubble.
	 * 
	 * @param {HTMLElement} bubble - Message bubble element
	 * @param {Object} state - Chat state object
	 * @param {string} text - Optional explicit text to speak
	 * @param {Function} buildJsonHeaders - Function to build request headers
	 */
	function attachSpeechButton(bubble, state, text, buildJsonHeaders) {
		if (!bubble || !state || !state.config || !state.config.toolsEndpoint || !state.config.assistantId) {
			return;
		}

		const normalisedText = resolveSpeechText(bubble, text);
		if (!normalisedText) {
			return;
		}

		if (bubble.classList) {
			bubble.classList.add(SPEECH_ENABLED_CLASS);
		}

		if (bubble.dataset) {
			bubble.dataset.speechText = normalisedText;
		}

		if (!state.speechCache) {
			state.speechCache = Object.create(null);
		}

		const existing = bubble.querySelector('.' + SPEECH_BUTTON_CLASS);
		if (existing) {
			const previousText = normalizeSpeechText(existing.dataset.speechText || '');

			if (previousText && previousText !== normalisedText) {
				stopSpeechPlayback(state, existing);
				clearSpeechCacheEntry(state, previousText);
			}

			existing.dataset.speechText = normalisedText;
			existing.disabled = false;
			updateSpeechButtonIcon(existing, 'idle');
			return;
		}

		const button = document.createElement('button');
		button.type = 'button';
		button.className = SPEECH_BUTTON_CLASS;
		button.dataset.speechText = normalisedText;
		button.setAttribute('aria-label', 'Play response audio');
		button.setAttribute('title', 'Play response audio');

		updateSpeechButtonIcon(button, 'idle');

		button.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			handleSpeechButtonClick(state, button, buildJsonHeaders);
		});

		bubble.appendChild(button);
	}

	// ========================================
	// Audio Transcription (Speech-to-Text)
	// ========================================

	/**
	 * Stop recording stream and release media tracks.
	 * 
	 * @param {Object} state - Chat state object
	 */
	function stopRecordingStream(state) {
		if (!state || !state.recordingStream) {
			return;
		}

		const tracks = state.recordingStream.getTracks ? state.recordingStream.getTracks() : [];
		tracks.forEach(function (track) {
			try {
				track.stop();
			} catch (error) {}
		});

		state.recordingStream = null;
	}

	/**
	 * Set transcribe recording state and update UI.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {boolean} recording - Whether recording is active
	 * @param {Object} helpers - Helper functions (getString, setStatus)
	 */
	function setTranscribeRecordingState(state, recording, helpers) {
		if (!state) {
			return;
		}

		state.isRecording = !!recording;

		const button = state.transcribeButton;
		if (button && button.classList) {
			if (state.isRecording) {
				button.classList.add(TRANSCRIBE_RECORDING_CLASS);
			} else {
				button.classList.remove(TRANSCRIBE_RECORDING_CLASS);
			}
		}

		if (button && helpers && helpers.getString) {
			const label = state.isRecording
				? helpers.getString('stopRecording', 'Stop recording')
				: helpers.getString('transcribeAudio', 'Transcribe audio');
			button.setAttribute('aria-label', label);
			button.setAttribute('title', label);
		}

		if (state.container && helpers && helpers.setStatus && helpers.getString) {
			if (state.isRecording) {
				helpers.setStatus(state.container, helpers.getString('recording', 'Recording… tap to stop.'));
			} else if (!state.transcribing && !state.busy) {
				helpers.setStatus(state.container, '');
			}
		}
	}

	/**
	 * Update transcribe button state based on chat state.
	 * 
	 * @param {Object} state - Chat state object
	 */
	function updateTranscribeButtonState(state) {
		if (!state) {
			return;
		}

		const button = state.transcribeButton;
		const input = state.transcribeInput;

		const canUse = !!state.canUploadAttachments;
		let disabled = !canUse || state.busy || state.uploading > 0 || state.transcribing;

		if (state.isRecording) {
			disabled = false;
		}

		if (button) {
			button.disabled = disabled;

			if (!canUse) {
				button.hidden = true;
			} else {
				button.hidden = false;
			}
		}

		if (input) {
			input.disabled = !canUse || state.busy || state.uploading > 0 || state.transcribing || state.isRecording;
		}
	}

	/**
	 * Handle transcribe button click event.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {Object} helpers - Helper functions
	 */
	function handleTranscribeButtonClick(state, helpers) {
		if (!state || state.transcribing) {
			return;
		}

		if (state.isRecording) {
			stopTranscribeRecording(state, helpers);
			return;
		}

		if (!state.canUploadAttachments) {
			return;
		}

		if (supportsAudioRecording()) {
			let shouldRecord = true;

			if (state.transcribeInput && helpers && helpers.getString) {
				const message = helpers.getString(
					'transcribeChooseSource',
					'Press OK to record with your microphone, or Cancel to choose an audio file.'
				);

				if (typeof window !== 'undefined' && typeof window.confirm === 'function') {
					shouldRecord = window.confirm(message);
				}
			}

			if (shouldRecord) {
				startTranscribeRecording(state, helpers);
				return;
			}
		}

		if (state.transcribeInput && !state.transcribeInput.disabled) {
			state.transcribeInput.click();
		}
	}

	/**
	 * Start audio recording for transcription.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {Object} helpers - Helper functions
	 */
	function startTranscribeRecording(state, helpers) {
		if (!state || !supportsAudioRecording()) {
			return;
		}

		state.recordingShouldProcess = false;

		if (state.transcribeButton) {
			state.transcribeButton.disabled = true;
		}

		navigator.mediaDevices
			.getUserMedia({ audio: true })
			.then(function (stream) {
				state.recordingStream = stream;
				state.recordedChunks = [];

				try {
					state.mediaRecorder = new MediaRecorder(stream);
				} catch (error) {
					stopRecordingStream(state);
					if (helpers && helpers.setStatus && helpers.getString) {
						helpers.setStatus(
							state.container,
							helpers.getString(
								'recordingError',
								'Could not access your microphone. Please allow access or upload an audio file instead.'
							)
						);
					}
					updateTranscribeButtonState(state);
					return;
				}

				if (!state.mediaRecorder) {
					stopRecordingStream(state);
					updateTranscribeButtonState(state);
					return;
				}

				state.recordingShouldProcess = true;

				state.mediaRecorder.addEventListener('dataavailable', function (event) {
					if (event && event.data && event.data.size) {
						state.recordedChunks.push(event.data);
					}
				});

				state.mediaRecorder.addEventListener('stop', function () {
					const chunks = state.recordedChunks || [];
					const mimeType = state.mediaRecorder && state.mediaRecorder.mimeType ? state.mediaRecorder.mimeType : 'audio/webm';
					let baseMimeType = typeof mimeType === 'string' ? mimeType.split(';')[0] : '';
					if (!baseMimeType && typeof mimeType === 'string') {
						baseMimeType = mimeType;
					}

					stopRecordingStream(state);
					setTranscribeRecordingState(state, false, helpers);

					if (!state.recordingShouldProcess) {
						state.mediaRecorder = null;
						state.recordedChunks = [];
						return;
					}

					let blob = null;
					try {
						let blobType = baseMimeType || mimeType;
						if (blobType && typeof blobType === 'string') {
							blobType = blobType.split(';')[0];
						}
						blob = new Blob(chunks, { type: blobType || 'audio/webm' });
					} catch (error) {}

					state.mediaRecorder = null;
					state.recordedChunks = [];

					if (!blob || !blob.size) {
						updateTranscribeButtonState(state);
						return;
					}

					let extension = '';
					if (baseMimeType && baseMimeType.indexOf('/') !== -1) {
						extension = baseMimeType.split('/')[1] || '';
					}

					let safeExtension = extension ? extension.replace(/[^a-z0-9]/gi, '') : 'webm';
					if (!safeExtension) {
						safeExtension = 'webm';
					}
					const fileName = 'transcription-' + Date.now() + '.' + safeExtension;

					let file = null;
					try {
						let fileType = blob && blob.type ? blob.type : baseMimeType || 'audio/webm';
						if (fileType && typeof fileType === 'string') {
							fileType = fileType.split(';')[0];
						}
						file = new File([blob], fileName, { type: fileType || 'audio/webm' });
					} catch (error) {
						file = blob;
						file.name = fileName;
						if (file && file.type && typeof file.type === 'string') {
							file.type = file.type.split(';')[0];
						}
						if (file && !file.type && baseMimeType) {
							file.type = baseMimeType;
						}
					}

					transcribeAudioFile(state, file, helpers);
				});

				state.mediaRecorder.start();
				setTranscribeRecordingState(state, true, helpers);
				updateTranscribeButtonState(state);
			})
			.catch(function () {
				stopRecordingStream(state);
				if (helpers && helpers.setStatus && helpers.getString) {
					helpers.setStatus(
						state.container,
						helpers.getString(
							'recordingError',
							'Could not access your microphone. Please allow access or upload an audio file instead.'
						)
					);
				}

				if (state.transcribeInput && !state.transcribeInput.disabled) {
					state.transcribeInput.click();
				}

				updateTranscribeButtonState(state);
			});
	}

	/**
	 * Stop transcribe recording.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {Object} helpers - Helper functions
	 */
	function stopTranscribeRecording(state, helpers) {
		if (!state || !state.mediaRecorder) {
			return;
		}

		state.recordingShouldProcess = true;

		try {
			if (state.mediaRecorder.state !== 'inactive') {
				state.mediaRecorder.stop();
			}
		} catch (error) {
			stopRecordingStream(state);
			setTranscribeRecordingState(state, false, helpers);
			updateTranscribeButtonState(state);
		}
	}

	/**
	 * Handle transcribe file input selection.
	 * 
	 * @param {Event} event - File input change event
	 * @param {Object} state - Chat state object
	 * @param {Object} helpers - Helper functions
	 */
	function handleTranscribeFileSelection(event, state, helpers) {
		if (!state || !state.canUploadAttachments) {
			return;
		}

		if (!event || !event.target || !event.target.files) {
			return;
		}

		const files = Array.prototype.slice.call(event.target.files);
		event.target.value = '';

		if (!files.length) {
			return;
		}

		const file = files[0];
		transcribeAudioFile(state, file, helpers);
	}

	/**
	 * Transcribe an audio file.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {File} file - Audio file to transcribe
	 * @param {Object} helpers - Helper functions
	 */
	function transcribeAudioFile(state, file, helpers) {
		if (!state || !file || !state.canUploadAttachments || state.transcribing) {
			return;
		}

		if (file.size && file.size > MAX_TRANSCRIBE_BYTES) {
			if (helpers && helpers.setStatus && helpers.getString) {
				helpers.setStatus(
					state.container,
					helpers.getString(
						'transcriptionFileTooLarge',
						'The selected audio file is too large. Please choose a file under 25MB.'
					)
				);
			}
			updateTranscribeButtonState(state);
			return;
		}

		state.transcribing = true;
		updateTranscribeButtonState(state);

		if (helpers && helpers.setStatus && helpers.getString) {
			helpers.setStatus(state.container, helpers.getString('transcribing', 'Transcribing audio…'));
		}

		let uploadedRecord = null;

		if (!helpers || !helpers.uploadAudioForTranscription || !helpers.requestTranscription) {
			state.transcribing = false;
			updateTranscribeButtonState(state);
			return;
		}

		helpers.uploadAudioForTranscription(state, file)
			.then(function (record) {
				uploadedRecord = record;
				if (!record || typeof record.id === 'undefined') {
					throw new Error('Upload failed');
				}

				// Note: DO NOT add transcription audio to attachmentLibrary
				// These are temporary files for transcription only, not message attachments
				// Adding them to attachmentLibrary causes them to persist and be reused

				// Add small delay to ensure WordPress has fully processed the uploaded file
				return new Promise(function(resolve) {
					setTimeout(function() {
						resolve(record);
					}, 150);
				});
			})
			.then(function (record) {
				return helpers.requestTranscription(state, record);
			})
			.then(function (response) {
				const result = extractTranscriptionResult(response);
				if (helpers.insertTranscriptionResult) {
					helpers.insertTranscriptionResult(state, result, uploadedRecord || file);
				}

				let label = '';
				if (uploadedRecord && uploadedRecord.name) {
					label = uploadedRecord.name;
				} else if (file && file.name) {
					label = file.name;
				}

				if (helpers && helpers.setStatus && helpers.getString) {
					const messageLabel = label || helpers.getString('transcribeAudio', 'Transcribe audio');
					const formatString = function(template, value) {
						return template.replace('%s', value);
					};
					const message = formatString(
						helpers.getString('transcriptionSuccess', 'Inserted transcription from "%s".'),
						messageLabel
					);
					helpers.setStatus(state.container, message);
				}
			})
			.catch(function (error) {
				// Provide more specific error messages based on error type
				let errorMessage = helpers.getString('transcriptionError', 'The transcription request failed. Please try again.');
				
				if (error && error.status === 404) {
					errorMessage = helpers.getString(
						'transcriptionEndpointNotFound',
						'Transcription service is temporarily unavailable. Please try again later.'
					);
				} else if (error && error.message === 'Tools endpoint unavailable') {
					errorMessage = helpers.getString(
						'transcriptionNotConfigured',
						'Transcription is not properly configured. Please contact support.'
					);
				}

				if (helpers && helpers.setStatus) {
					helpers.setStatus(state.container, errorMessage);
				}

				if (window.console && console.error) {
					console.error('Transcription failed', {
						error: error,
						message: error ? error.message : 'Unknown error',
						status: error ? error.status : undefined
					});
				}
			})
			.finally(function () {
				state.transcribing = false;
				updateTranscribeButtonState(state);
			});
	}

	/**
	 * Extract transcription result from response body.
	 * 
	 * @param {Object} body - Response body
	 * @return {Object|null} Transcription result
	 */
	function extractTranscriptionResult(body) {
		if (!body || typeof body !== 'object') {
			return null;
		}

		if (Object.prototype.hasOwnProperty.call(body, 'result')) {
			return body.result;
		}

		return body;
	}

	// ========================================
	// Audio Translation Functions
	// ========================================

	/**
	 * Handle translate button click event.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {Object} helpers - Helper functions
	 */
	function handleTranslateButtonClick(state, helpers) {
		if (!state || state.translating) {
			return;
		}

		if (state.isTranslateRecording) {
			stopTranslateRecording(state, helpers);
			return;
		}

		if (!state.canUploadAttachments) {
			return;
		}

		if (supportsAudioRecording()) {
			let shouldRecord = true;

			if (state.translateInput && helpers && helpers.getString) {
				const message = helpers.getString(
					'translateChooseSource',
					'Press OK to record with your microphone, or Cancel to choose an audio file.'
				);

				if (typeof window !== 'undefined' && typeof window.confirm === 'function') {
					shouldRecord = window.confirm(message);
				}
			}

			if (shouldRecord) {
				startTranslateRecording(state, helpers);
				return;
			}
		}

		if (state.translateInput && !state.translateInput.disabled) {
			state.translateInput.click();
		}
	}

	/**
	 * Start audio recording for translation.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {Object} helpers - Helper functions
	 */
	function startTranslateRecording(state, helpers) {
		if (!state || !supportsAudioRecording()) {
			return;
		}

		state.translateRecordingShouldProcess = false;

		if (state.translateButton) {
			state.translateButton.disabled = true;
		}

		navigator.mediaDevices
			.getUserMedia({ audio: true })
			.then(function (stream) {
				state.translateRecordingStream = stream;
				state.translateRecordedChunks = [];

				try {
					state.translateMediaRecorder = new MediaRecorder(stream);
				} catch (error) {
					stopTranslateRecordingStream(state);
					if (helpers && helpers.setStatus && helpers.getString) {
						helpers.setStatus(
							state.container,
							helpers.getString(
								'recordingError',
								'Could not access your microphone. Please allow access or upload an audio file instead.'
							)
						);
					}
					updateTranslateButtonState(state);
					return;
				}

				if (!state.translateMediaRecorder) {
					stopTranslateRecordingStream(state);
					updateTranslateButtonState(state);
					return;
				}

				state.translateRecordingShouldProcess = true;

				state.translateMediaRecorder.addEventListener('dataavailable', function (event) {
					if (event && event.data && event.data.size) {
						state.translateRecordedChunks.push(event.data);
					}
				});

				state.translateMediaRecorder.addEventListener('stop', function () {
					const chunks = state.translateRecordedChunks || [];
					const mimeType = state.translateMediaRecorder && state.translateMediaRecorder.mimeType ? state.translateMediaRecorder.mimeType : 'audio/webm';
					let baseMimeType = typeof mimeType === 'string' ? mimeType.split(';')[0] : '';
					if (!baseMimeType && typeof mimeType === 'string') {
						baseMimeType = mimeType;
					}

					stopTranslateRecordingStream(state);
					setTranslateRecordingState(state, false, helpers);

					if (!state.translateRecordingShouldProcess) {
						state.translateRecordedChunks = [];
						updateTranslateButtonState(state);
						return;
					}

					if (!chunks || !chunks.length) {
						if (helpers && helpers.setStatus && helpers.getString) {
							helpers.setStatus(
								state.container,
								helpers.getString('voiceChatNoData', 'No audio was recorded.')
							);
						}
						updateTranslateButtonState(state);
						return;
					}

					const blob = new Blob(chunks, { type: baseMimeType || 'audio/webm' });
					state.translateRecordedChunks = [];

					processTranslateAudio(state, blob, helpers);
				});

				state.translateMediaRecorder.start();
				state.translateRecordingShouldProcess = true;
				setTranslateRecordingState(state, true, helpers);
			})
			.catch(function () {
				if (helpers && helpers.setStatus && helpers.getString) {
					helpers.setStatus(
						state.container,
						helpers.getString(
							'recordingError',
							'Could not access your microphone. Please allow access or upload an audio file instead.'
						)
					);
				}
				updateTranslateButtonState(state);
			});
	}

	/**
	 * Stop translate recording.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {Object} helpers - Helper functions
	 */
	function stopTranslateRecording(state, helpers) {
		if (!state) {
			return;
		}

		setTranslateRecordingState(state, false, helpers);

		if (state.translateMediaRecorder && state.translateMediaRecorder.state !== 'inactive') {
			state.translateMediaRecorder.stop();
		} else {
			stopTranslateRecordingStream(state);
			updateTranslateButtonState(state);
		}
	}

	/**
	 * Stop translate recording stream and release media tracks.
	 * 
	 * @param {Object} state - Chat state object
	 */
	function stopTranslateRecordingStream(state) {
		if (!state || !state.translateRecordingStream) {
			return;
		}

		const tracks = state.translateRecordingStream.getTracks ? state.translateRecordingStream.getTracks() : [];
		tracks.forEach(function (track) {
			try {
				track.stop();
			} catch (error) {}
		});

		state.translateRecordingStream = null;
	}

	/**
	 * Set translate recording state and update UI.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {boolean} recording - Whether recording is active
	 * @param {Object} helpers - Helper functions
	 */
	function setTranslateRecordingState(state, recording, helpers) {
		if (!state) {
			return;
		}

		state.isTranslateRecording = !!recording;

		const button = state.translateButton;
		if (button && button.classList) {
			if (state.isTranslateRecording) {
				button.classList.add(TRANSLATE_RECORDING_CLASS);
			} else {
				button.classList.remove(TRANSLATE_RECORDING_CLASS);
			}
		}

		if (button && helpers && helpers.getString) {
			const label = state.isTranslateRecording
				? helpers.getString('stopRecording', 'Stop recording')
				: helpers.getString('translateAudio', 'Translate audio');
			button.setAttribute('aria-label', label);
			button.setAttribute('title', label);
		}

		if (state.container && helpers && helpers.setStatus && helpers.getString) {
			if (state.isTranslateRecording) {
				helpers.setStatus(state.container, helpers.getString('recording', 'Recording… tap to stop.'));
			} else if (!state.translating && !state.busy) {
				helpers.setStatus(state.container, '');
			}
		}
	}

	/**
	 * Update translate button state based on chat state.
	 * 
	 * @param {Object} state - Chat state object
	 */
	function updateTranslateButtonState(state) {
		if (!state) {
			return;
		}

		const button = state.translateButton;
		const input = state.translateInput;

		const canUse = !!state.canUploadAttachments;
		let disabled = !canUse || state.busy || state.uploading > 0 || state.translating;

		if (state.isTranslateRecording) {
			disabled = false;
		}

		if (button) {
			button.disabled = disabled;

			if (!canUse) {
				button.hidden = true;
			} else {
				button.hidden = false;
			}
		}

		if (input) {
			input.disabled = !canUse || state.busy || state.uploading > 0 || state.translating || state.isTranslateRecording;
		}
	}

	/**
	 * Handle translate file input change.
	 * 
	 * @param {Event} event - File input change event
	 * @param {Object} state - Chat state object
	 * @param {Object} helpers - Helper functions
	 */
	function handleTranslateFileSelection(event, state, helpers) {
		if (!event || !event.target || !state || state.translating) {
			return;
		}

		const files = event.target.files;
		if (!files || !files.length) {
			return;
		}

		const file = files[0];
		event.target.value = '';

		if (file.size > MAX_TRANSCRIBE_BYTES) {
			if (helpers && helpers.setStatus && helpers.getString) {
				helpers.setStatus(
					state.container,
					helpers.getString(
						'translationFileTooLarge',
						'The selected audio file is too large. Please choose a file under 25MB.'
					)
				);
			}
			return;
		}

		processTranslateAudio(state, file, helpers);
	}

	/**
	 * Process audio for translation.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {Blob|File} blob - Audio blob or file to translate
	 * @param {Object} helpers - Helper functions
	 */
	function processTranslateAudio(state, blob, helpers) {
		if (!state || !blob || state.translating) {
			return;
		}

		if (blob.size > MAX_TRANSCRIBE_BYTES) {
			if (helpers && helpers.setStatus && helpers.getString) {
				helpers.setStatus(
					state.container,
					helpers.getString(
						'translationFileTooLarge',
						'The selected audio file is too large. Please choose a file under 25MB.'
					)
				);
			}
			updateTranslateButtonState(state);
			return;
		}

		state.translating = true;
		updateTranslateButtonState(state);

		if (helpers && helpers.setStatus && helpers.getString) {
			helpers.setStatus(state.container, helpers.getString('translating', 'Translating audio…'));
		}

		const file = blob instanceof File ? blob : new File([blob], 'audio-' + Date.now() + '.webm', {
			type: blob.type || 'audio/webm',
			lastModified: Date.now(),
		});

		let uploadedRecord = null;

		if (!helpers || !helpers.uploadAudioForTranscription || !helpers.requestTranscription) {
			state.translating = false;
			updateTranslateButtonState(state);
			return;
		}

		helpers.uploadAudioForTranscription(state, file)
			.then(function (record) {
				uploadedRecord = record;
				if (!record || typeof record.id === 'undefined') {
					throw new Error('Upload failed');
				}

				// Note: DO NOT add translation audio to attachmentLibrary
				// These are temporary files for translation only, not message attachments
				// Adding them to attachmentLibrary causes them to persist and be reused

				// Add small delay to ensure WordPress has fully processed the uploaded file
				return new Promise(function(resolve) {
					setTimeout(function() {
						resolve(record);
					}, 150);
				});
			})
			.then(function (record) {
				// Request translation (translate=true)
				return helpers.requestTranscription(state, record, true);
			})
			.then(function (response) {
				const result = extractTranscriptionResult(response);

				if (!result || !result.text || !result.text.trim()) {
					throw new Error('No text translated');
				}

				const translatedText = result.text.trim();
				insertTranslatedText(state, translatedText, helpers);

				if (helpers && helpers.setStatus && helpers.getString) {
					const fileName = uploadedRecord && uploadedRecord.filename ? uploadedRecord.filename : 'audio file';
					const successMessage = helpers.getString('translationSuccess', 'Inserted translation from "%s".');
					helpers.setStatus(
						state.container,
						successMessage.replace('%s', fileName)
					);
				}
			})
			.catch(function (error) {
				// Provide more specific error messages based on error type
				let errorMessage = helpers.getString('translationError', 'The translation request failed. Please try again.');
				
				if (error && error.status === 404) {
					errorMessage = helpers.getString(
						'translationEndpointNotFound',
						'Translation service is temporarily unavailable. Please try again later.'
					);
				} else if (error && (error.message === 'Tools endpoint unavailable' || error.message === 'Could not locate transcription tool')) {
					errorMessage = helpers.getString(
						'translationNotConfigured',
						'Translation is not properly configured. Please contact support.'
					);
				}

				if (helpers && helpers.setStatus) {
					helpers.setStatus(state.container, errorMessage);
				}

				if (window.console && console.error) {
					console.error('Translation failed', {
						error: error,
						message: error ? error.message : 'Unknown error',
						status: error ? error.status : undefined
					});
				}
			})
			.finally(function () {
				state.translating = false;
				updateTranslateButtonState(state);
			});
	}

	/**
	 * Insert translated text into the chat textarea.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {string} text - Translated text
	 * @param {Object} helpers - Helper functions
	 */
	function insertTranslatedText(state, text, _helpers) {
		if (!state || !state.textarea || !text) {
			return;
		}

		const trimmedText = text.trim();
		if (!trimmedText) {
			return;
		}

		const existing = state.textarea.value || '';
		const trimmedExisting = existing.replace(/\s+$/, '');
		const newValue = trimmedExisting ? trimmedExisting + '\n\n' + trimmedText : trimmedText;

		state.textarea.value = newValue;
		state.textarea.focus();

		try {
			const caret = newValue.length;
			state.textarea.setSelectionRange(caret, caret);
		} catch (error) {}
	}

	// ========================================
	// Voice Activity Detection (VAD) Functions
	// ========================================

	/**
	 * Initialize VAD monitoring for voice chat recording.
	 * Uses Web Audio API to analyze audio levels in real-time.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {MediaStream} stream - Audio stream from getUserMedia
	 * @param {Object} helpers - Helper functions
	 */
	function initVoiceActivityDetection(state, stream, helpers) {
		if (!state || !stream) {
			return;
		}

		// Check if VAD is enabled in WordPress settings
		const vadEnabled = state.config && typeof state.config.vadEnabled !== 'undefined' 
			? state.config.vadEnabled 
			: true;

		if (!vadEnabled) {
			if (window.console && console.log) {
				console.log('VAD: Voice Activity Detection disabled in settings');
			}
			return;
		}

		// Get configurable VAD settings from WordPress
		const silenceThreshold = state.config && state.config.vadSilenceThreshold 
			? state.config.vadSilenceThreshold 
			: VAD_DEFAULT_SILENCE_THRESHOLD_MS;
		
		const minSpeechDuration = state.config && state.config.vadMinSpeech 
			? state.config.vadMinSpeech 
			: VAD_DEFAULT_MIN_SPEECH_DURATION_MS;
		
		const minRecordingMs = state.config && state.config.vadMinRecordingMs
			? state.config.vadMinRecordingMs
			: VAD_DEFAULT_MIN_RECORDING_MS;
		
		const audioThreshold = state.config && typeof state.config.vadAudioThreshold !== 'undefined'
			? state.config.vadAudioThreshold 
			: VAD_DEFAULT_AUDIO_LEVEL_THRESHOLD;

		try {
			// Create Web Audio API context
			const AudioContext = window.AudioContext || window.webkitAudioContext;
			if (!AudioContext) {
				// VAD not supported, fall back to manual mode
				return;
			}

			state.vadAudioContext = new AudioContext();
			state.vadAnalyser = state.vadAudioContext.createAnalyser();
			state.vadAnalyser.fftSize = 2048;
			state.vadAnalyser.smoothingTimeConstant = 0.8;

			const source = state.vadAudioContext.createMediaStreamSource(stream);
			source.connect(state.vadAnalyser);

			// Initialize VAD state with configurable thresholds
			state.vadSilenceStart = null;
			state.vadSpeechStart = Date.now();
			state.vadLastSpeechTime = Date.now();
			state.vadEnabled = true;
			state.vadSilenceThreshold = silenceThreshold;
			state.vadMinSpeechDuration = minSpeechDuration;
			state.vadMinRecordingMs = minRecordingMs;
			state.vadAudioThreshold = audioThreshold;

			// Start monitoring audio levels
			state.vadMonitorInterval = setInterval(function() {
				checkVoiceActivity(state, helpers);
			}, VAD_CHECK_INTERVAL_MS);

			if (window.console && console.log) {
				console.log('VAD: Voice Activity Detection initialized', {
					silenceThreshold: silenceThreshold + 'ms',
					minSpeechDuration: minSpeechDuration + 'ms',
					audioThreshold: audioThreshold + 'dB'
				});
			}
		} catch (error) {
			if (window.console && console.warn) {
				console.warn('VAD: Could not initialize Voice Activity Detection', error);
			}
			// Continue without VAD
		}
	}

	/**
	 * Check voice activity and trigger auto-stop on silence.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {Object} helpers - Helper functions
	 */
	function checkVoiceActivity(state, helpers) {
		if (!state || !state.vadEnabled || !state.vadAnalyser || !state.isVoiceChatRecording) {
			return;
		}

		try {
			// Get audio level
			const bufferLength = state.vadAnalyser.frequencyBinCount;
			const dataArray = new Uint8Array(bufferLength);
			state.vadAnalyser.getByteFrequencyData(dataArray);

			// Calculate average audio level
			let sum = 0;
			for (let i = 0; i < bufferLength; i++) {
				sum += dataArray[i];
			}
			const average = sum / bufferLength;

			// Convert to dB scale (0-255 range to dB)
			const dB = 20 * Math.log10(average / 255);

			const now = Date.now();
			const speechDuration = now - state.vadSpeechStart;
			
			// Use configurable thresholds from state
			const audioThreshold = state.vadAudioThreshold || VAD_DEFAULT_AUDIO_LEVEL_THRESHOLD;
			const silenceThreshold = state.vadSilenceThreshold || VAD_DEFAULT_SILENCE_THRESHOLD_MS;
			const minSpeechDuration = state.vadMinSpeechDuration || VAD_DEFAULT_MIN_SPEECH_DURATION_MS;
			const minRecordingMs = state.vadMinRecordingMs || VAD_DEFAULT_MIN_RECORDING_MS;
			
			const isSpeech = dB > audioThreshold;

			// Minimum recording duration guard: prevent auto-stop from firing on
			// clips shorter than minRecordingMs. This avoids processing near-empty
			// recordings caused by transient noises or accidental mic activation.
			if (speechDuration < minRecordingMs) {
				return;
			}

			if (isSpeech) {
				// Speech detected
				state.vadLastSpeechTime = now;
				state.vadSilenceStart = null;

				// Update UI to show "listening" state
				if (state.voiceChatButton && state.voiceChatButton.classList) {
					state.voiceChatButton.classList.add(VOICE_CHAT_LISTENING_CLASS);
				}
			} else {
				// Silence detected
				if (state.vadSilenceStart === null) {
					state.vadSilenceStart = now;
				}

				const silenceDuration = now - state.vadSilenceStart;

				// Remove "listening" class during silence
				if (state.voiceChatButton && state.voiceChatButton.classList) {
					state.voiceChatButton.classList.remove(VOICE_CHAT_LISTENING_CLASS);
				}

				// Check if we should auto-stop
				if (speechDuration >= minSpeechDuration && 
					silenceDuration >= silenceThreshold) {
					
					if (window.console && console.log) {
						console.log('VAD: Auto-stopping after ' + silenceDuration + 'ms of silence');
					}

					// Auto-stop recording
					stopVoiceActivityDetection(state);
					stopVoiceChatRecording(state, helpers);
				}
			}
		} catch (error) {
			if (window.console && console.error) {
				console.error('VAD: Error checking voice activity', error);
			}
		}
	}

	/**
	 * Stop VAD monitoring and clean up resources.
	 * 
	 * @param {Object} state - Chat state object
	 */
	function stopVoiceActivityDetection(state) {
		if (!state) {
			return;
		}

		state.vadEnabled = false;

		if (state.vadMonitorInterval) {
			clearInterval(state.vadMonitorInterval);
			state.vadMonitorInterval = null;
		}

		if (state.vadAudioContext) {
			try {
				state.vadAudioContext.close();
			} catch (error) {
				// Ignore close errors
			}
			state.vadAudioContext = null;
		}

		state.vadAnalyser = null;
		state.vadSilenceStart = null;
		state.vadSpeechStart = null;
		state.vadLastSpeechTime = null;

		// Remove listening class
		if (state.voiceChatButton && state.voiceChatButton.classList) {
			state.voiceChatButton.classList.remove(VOICE_CHAT_LISTENING_CLASS);
		}
	}

	// ========================================
	// Voice Chat Functions
	// ========================================

	/**
	 * Handle voice chat button click event.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {Object} helpers - Helper functions
	 */
	function handleVoiceChatButtonClick(state, helpers) {
		if (!state || state.voiceChatProcessing) {
			return;
		}

		if (state.isVoiceChatRecording) {
			stopVoiceChatRecording(state, helpers);
			return;
		}

		if (!state.canUploadAttachments) {
			return;
		}

		if (supportsAudioRecording()) {
			startVoiceChatRecording(state, helpers);
		} else if (helpers && helpers.setStatus && helpers.getString) {
			helpers.setStatus(
				state.container,
				helpers.getString('voiceChatUnavailable', 'Voice chat is not available in your browser.')
			);
		}
	}

	/**
	 * Start voice chat recording.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {Object} helpers - Helper functions
	 */
	function startVoiceChatRecording(state, helpers) {
		if (!state || !supportsAudioRecording()) {
			return;
		}

		state.voiceChatShouldProcess = false;
		updateVoiceChatButtonState(state);

		navigator.mediaDevices
			.getUserMedia({ audio: true })
			.then(function (stream) {
				state.voiceChatStream = stream;
				state.voiceChatChunks = [];

				try {
					state.voiceChatRecorder = new MediaRecorder(stream);
				} catch (error) {
					stopVoiceChatStream(state);
					if (helpers && helpers.setStatus && helpers.getString) {
						helpers.setStatus(
							state.container,
							helpers.getString('voiceChatRecorderError', 'Could not start voice recording.')
						);
					}
					updateVoiceChatButtonState(state);
					return;
				}

				state.voiceChatRecorder.addEventListener('dataavailable', function (event) {
					if (event.data && event.data.size > 0) {
						state.voiceChatChunks.push(event.data);
					}
				});

				state.voiceChatRecorder.addEventListener('stop', function () {
					stopVoiceChatStream(state);

					if (!state.voiceChatShouldProcess) {
						state.voiceChatChunks = [];
						updateVoiceChatButtonState(state);
						return;
					}

					if (!state.voiceChatChunks || !state.voiceChatChunks.length) {
						if (helpers && helpers.setStatus && helpers.getString) {
							helpers.setStatus(state.container, helpers.getString('voiceChatNoData', 'No audio was recorded.'));
						}
						updateVoiceChatButtonState(state);
						return;
					}

					const blob = new Blob(state.voiceChatChunks, { type: 'audio/webm' });
					state.voiceChatChunks = [];

					processVoiceChatAudio(state, blob, helpers);
				});

				state.voiceChatRecorder.start();
				state.voiceChatShouldProcess = true;
				setVoiceChatRecordingState(state, true, helpers);
				updateVoiceChatButtonState(state);

				// Initialize Voice Activity Detection for hands-free auto-stop
				initVoiceActivityDetection(state, stream, helpers);
			})
			.catch(function (_error) {
				if (helpers && helpers.setStatus && helpers.getString) {
					helpers.setStatus(
						state.container,
						helpers.getString('voiceChatPermissionDenied', 'Microphone access was denied.')
					);
				}
				updateVoiceChatButtonState(state);
			});
	}

	/**
	 * Stop voice chat recording.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {Object} helpers - Helper functions
	 */
	function stopVoiceChatRecording(state, helpers) {
		if (!state) {
			return;
		}

		// Stop VAD monitoring
		stopVoiceActivityDetection(state);

		setVoiceChatRecordingState(state, false, helpers);

		if (state.voiceChatRecorder && state.voiceChatRecorder.state !== 'inactive') {
			state.voiceChatRecorder.stop();
		} else {
			stopVoiceChatStream(state);
			updateVoiceChatButtonState(state);
		}
	}

	/**
	 * Stop voice chat stream and release media tracks.
	 * 
	 * @param {Object} state - Chat state object
	 */
	function stopVoiceChatStream(state) {
		if (!state || !state.voiceChatStream) {
			return;
		}

		try {
			state.voiceChatStream.getTracks().forEach(function (track) {
				track.stop();
			});
		} catch (error) {}

		state.voiceChatStream = null;
	}

	/**
	 * Set voice chat recording state and update UI.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {boolean} recording - Whether recording is active
	 * @param {Object} helpers - Helper functions
	 */
	function setVoiceChatRecordingState(state, recording, helpers) {
		if (!state) {
			return;
		}

		state.isVoiceChatRecording = !!recording;

		const button = state.voiceChatButton;
		if (button && button.classList) {
			if (state.isVoiceChatRecording) {
				button.classList.add(VOICE_CHAT_RECORDING_CLASS);
			} else {
				button.classList.remove(VOICE_CHAT_RECORDING_CLASS);
			}
		}

		if (button && helpers && helpers.getString) {
			const label = state.isVoiceChatRecording
				? helpers.getString('stopVoiceChat', 'Stop voice chat')
				: helpers.getString('voiceChat', 'Voice chat');
			button.setAttribute('aria-label', label);
			button.setAttribute('title', label);
		}

		if (state.container && helpers && helpers.setStatus && helpers.getString) {
			if (state.isVoiceChatRecording) {
				helpers.setStatus(state.container, helpers.getString('voiceChatRecording', 'Recording… tap to stop and send.'));
			} else if (!state.voiceChatProcessing && !state.busy) {
				helpers.setStatus(state.container, '');
			}
		}
	}

	/**
	 * Update voice chat button state based on chat state.
	 * 
	 * @param {Object} state - Chat state object
	 */
	function updateVoiceChatButtonState(state) {
		if (!state) {
			return;
		}

		const button = state.voiceChatButton;

		const canUse = !!state.canUploadAttachments;
		let disabled = !canUse || state.busy || state.uploading > 0 || state.voiceChatProcessing;

		if (state.isVoiceChatRecording) {
			disabled = false;
		}

		if (button) {
			button.disabled = disabled;

			if (!canUse) {
				button.hidden = true;
			} else {
				button.hidden = false;
			}
		}
	}

	/**
	 * Process voice chat audio and send as message.
	 * 
	 * @param {Object} state - Chat state object
	 * @param {Blob} blob - Audio blob to process
	 * @param {Object} helpers - Helper functions
	 */
	function processVoiceChatAudio(state, blob, helpers) {
		if (!state || !blob || state.voiceChatProcessing) {
			return;
		}

		if (blob.size > MAX_TRANSCRIBE_BYTES) {
			if (helpers && helpers.setStatus && helpers.getString) {
				helpers.setStatus(
					state.container,
					helpers.getString(
						'voiceChatFileTooLarge',
						'The recorded audio is too large. Please try a shorter message.'
					)
				);
			}
			updateVoiceChatButtonState(state);
			return;
		}

		state.voiceChatProcessing = true;
		updateVoiceChatButtonState(state);

		const button = state.voiceChatButton;
		if (button && button.classList) {
			button.classList.add(VOICE_CHAT_PROCESSING_CLASS);
		}

		if (helpers && helpers.setStatus && helpers.getString) {
			helpers.setStatus(state.container, helpers.getString('voiceChatProcessing', 'Processing your voice message…'));
		}

		const file = new File([blob], 'voice-chat-' + Date.now() + '.webm', {
			type: 'audio/webm',
			lastModified: Date.now(),
		});

		if (!helpers || !helpers.uploadAudioForTranscription || !helpers.requestTranscription) {
			state.voiceChatProcessing = false;
			updateVoiceChatButtonState(state);
			if (button && button.classList) {
				button.classList.remove(VOICE_CHAT_PROCESSING_CLASS);
			}
			return;
		}

		helpers.uploadAudioForTranscription(state, file)
			.then(function (record) {
				if (!record || typeof record.id === 'undefined') {
					throw new Error('Upload failed');
				}

				// Note: DO NOT add voice chat audio to attachmentLibrary
				// These are temporary files for transcription only, not message attachments
				// Adding them to attachmentLibrary causes them to persist and be reused

				// Add small delay to ensure WordPress has fully processed the uploaded file
				return new Promise(function(resolve) {
					setTimeout(function() {
						resolve(record);
					}, 150);
				});
			})
			.then(function (record) {
				return helpers.requestTranscription(state, record);
			})
			.then(function (response) {
				const result = extractTranscriptionResult(response);
				
				if (!result || !result.text || !result.text.trim()) {
					throw new Error('No text transcribed');
				}

				// Enable voice chat mode to auto-play the response
				state.voiceChatModeActive = true;

				const transcribedText = result.text.trim();
				
				// Set textarea value temporarily for form submission
				if (state.textarea) {
					state.textarea.value = transcribedText;
				}

				if (helpers && helpers.setStatus && helpers.getString) {
					helpers.setStatus(
						state.container,
						helpers.getString('voiceChatSending', 'Sending your message…')
					);
				}

				// Trigger form submission which will clear the textarea
				const form = state.container ? state.container.querySelector('.wp-mcp-ai-chat__form') : null;
				if (form) {
					const submitEvent = new Event('submit', {
						bubbles: true,
						cancelable: true,
					});
					form.dispatchEvent(submitEvent);
				} else if (helpers && helpers.sendMessage) {
					// Fallback to sendMessage helper if form not found
					helpers.sendMessage(state);
					// Clear textarea after sending since sendMessage doesn't do it automatically
					if (state.textarea) {
						state.textarea.value = '';
					}
				}
			})
			.catch(function (error) {
				// Provide more specific error messages based on error type
				let errorMessage = helpers.getString('voiceChatError', 'Voice chat failed. Please try again or type your message.');
				
				if (error && error.status === 404) {
					errorMessage = helpers.getString(
						'voiceChatEndpointNotFound',
						'Voice chat service is temporarily unavailable. Please type your message instead.'
					);
				} else if (error && error.message === 'Tools endpoint unavailable') {
					errorMessage = helpers.getString(
						'voiceChatNotConfigured',
						'Voice chat is not properly configured. Please type your message instead.'
					);
				}

				if (helpers && helpers.setStatus) {
					helpers.setStatus(state.container, errorMessage);
				}

				if (window.console && console.error) {
					console.error('Voice chat failed', {
						error: error,
						message: error ? error.message : 'Unknown error',
						status: error ? error.status : undefined,
						endpoint: state.config ? state.config.toolsEndpoint : 'not configured'
					});
				}
			})
			.finally(function () {
				state.voiceChatProcessing = false;
				updateVoiceChatButtonState(state);

				if (button && button.classList) {
					button.classList.remove(VOICE_CHAT_PROCESSING_CLASS);
				}
			});
	}

	// Export public API
	window.wpMcpAiChatAudio = {
		// Object URL management
		registerObjectUrl: registerObjectUrl,
		revokeObjectUrls: revokeObjectUrls,
		
		// Capabilities
		supportsAudioRecording: supportsAudioRecording,
		
		// Speech synthesis (text-to-speech)
		attachSpeechButton: attachSpeechButton,
		updateSpeechButtonIcon: updateSpeechButtonIcon,
		stopSpeechPlayback: stopSpeechPlayback,
		SPEECH_BUTTON_CLASS: SPEECH_BUTTON_CLASS,
		SPEECH_ENABLED_CLASS: SPEECH_ENABLED_CLASS,
		
		// Audio transcription (speech-to-text)
		handleTranscribeButtonClick: handleTranscribeButtonClick,
		handleTranscribeFileSelection: handleTranscribeFileSelection,
		updateTranscribeButtonState: updateTranscribeButtonState,
		extractTranscriptionResult: extractTranscriptionResult,
		TRANSCRIBE_RECORDING_CLASS: TRANSCRIBE_RECORDING_CLASS,
		MAX_TRANSCRIBE_BYTES: MAX_TRANSCRIBE_BYTES,
		
		// Audio translation
		handleTranslateButtonClick: handleTranslateButtonClick,
		handleTranslateFileSelection: handleTranslateFileSelection,
		updateTranslateButtonState: updateTranslateButtonState,
		TRANSLATE_RECORDING_CLASS: TRANSLATE_RECORDING_CLASS,
		
		// Voice chat
		handleVoiceChatButtonClick: handleVoiceChatButtonClick,
		updateVoiceChatButtonState: updateVoiceChatButtonState,
		VOICE_CHAT_RECORDING_CLASS: VOICE_CHAT_RECORDING_CLASS,
		VOICE_CHAT_PROCESSING_CLASS: VOICE_CHAT_PROCESSING_CLASS
	};

})(window);
