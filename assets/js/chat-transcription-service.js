/**
 * Chat Transcription Service
 *
 * Handles audio recording and transcription for the WP oOS chat interface.
 * This includes:
 * - Audio recording with MediaRecorder API
 * - Recording state management
 * - Audio file upload
 * - Transcription API requests
 * - Result insertion into chat input
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

(function() {
	'use strict';

	/**
	 * Create the transcription service and expose it globally.
	 */
	const wpMcpAiChatTranscription = {
		// Constants
		TRANSCRIBE_TOOL_NAME: 'transcribe_openai_audio',
		TRANSCRIBE_RECORDING_CLASS: 'wp-mcp-ai-chat__transcribe--recording',
		MAX_TRANSCRIBE_BYTES: 26214400, // 25MB

		/**
		 * Check if browser supports audio recording.
		 *
		 * @return {boolean} True if MediaRecorder and getUserMedia are available.
		 */
		supportsAudioRecording: function() {
			return (
				typeof window !== 'undefined' &&
				window.navigator &&
				navigator.mediaDevices &&
				typeof navigator.mediaDevices.getUserMedia === 'function' &&
				typeof window.MediaRecorder !== 'undefined'
			);
		},

		/**
		 * Stop and clean up the recording stream.
		 *
		 * @param {Object} state - Chat state object.
		 */
		stopRecordingStream: function(state) {
			if (!state || !state.recordingStream) {
				return;
			}

			const tracks = state.recordingStream.getTracks ? state.recordingStream.getTracks() : [];
			tracks.forEach(function(track) {
				if (track && track.stop) {
					track.stop();
				}
			});

			state.recordingStream = null;
		},

		/**
		 * Update recording state and UI.
		 *
		 * @param {Object}   state     - Chat state object.
		 * @param {boolean}  recording - Whether recording is active.
		 * @param {Function} getString - String translation function.
		 * @param {Function} setStatus - Status message function.
		 */
		setTranscribeRecordingState: function(state, recording, getString, setStatus) {
			if (!state) {
				return;
			}

			state.isRecording = !!recording;

			const button = state.transcribeButton;
			if (button && button.classList) {
				if (state.isRecording) {
					button.classList.add(this.TRANSCRIBE_RECORDING_CLASS);
				} else {
					button.classList.remove(this.TRANSCRIBE_RECORDING_CLASS);
				}
			}

			if (button) {
				const label = state.isRecording
					? getString('stopRecording', 'Stop recording')
					: getString('transcribeAudio', 'Transcribe audio');
				button.setAttribute('aria-label', label);
				button.setAttribute('title', label);
			}

			if (state.container) {
				if (state.isRecording) {
					setStatus(state.container, getString('recording', 'Recording… tap to stop.'));
				} else if (!state.transcribing && !state.busy) {
					setStatus(state.container, '');
				}
			}
		},

		/**
		 * Update transcribe button enabled/disabled state.
		 *
		 * @param {Object} state - Chat state object.
		 */
		updateTranscribeButtonState: function(state) {
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
		},

		/**
		 * Handle transcribe button click.
		 *
		 * @param {Object}   state     - Chat state object.
		 * @param {Object}   helpers   - Helper functions object.
		 * @param {Function} helpers.startTranscribeRecording - Function to start recording.
		 * @param {Function} helpers.stopTranscribeRecording  - Function to stop recording.
		 */
		handleTranscribeButtonClick: function(state, helpers) {
			if (!state || state.transcribing) {
				return;
			}

			if (state.isRecording) {
				helpers.stopTranscribeRecording(state);
				return;
			}

			if (!state.transcribeInput) {
				return;
			}

			if (this.supportsAudioRecording()) {
				const buttons = document.querySelectorAll('[data-wp-mcp-ai-file-select-trigger]');
				let shouldAllowRecording = true;

				if (buttons && buttons.length) {
					buttons.forEach(function(btn) {
						if (btn && btn.disabled) {
							shouldAllowRecording = false;
						}
					});
				}

				if (shouldAllowRecording) {
					helpers.startTranscribeRecording(state);
					return;
				}
			}

			state.transcribeInput.click();
		},

		/**
		 * Start audio recording.
		 *
		 * @param {Object}   state     - Chat state object.
		 * @param {Object}   helpers   - Helper functions object.
		 * @param {Function} helpers.getString                    - String translation function.
		 * @param {Function} helpers.setStatus                    - Status message function.
		 * @param {Function} helpers.transcribeAudioFile          - Function to process audio file.
		 * @param {Function} helpers.stopRecordingStream          - Function to stop stream.
		 * @param {Function} helpers.setTranscribeRecordingState  - Function to update recording state.
		 * @param {Function} helpers.updateTranscribeButtonState  - Function to update button state.
		 */
		startTranscribeRecording: function(state, helpers) {
			const self = this;
			if (!state || !this.supportsAudioRecording()) {
				return;
			}

			state.recordingShouldProcess = false;

			if (state.transcribeButton) {
				state.transcribeButton.disabled = true;
			}

			navigator.mediaDevices
				.getUserMedia({ audio: true })
				.then(function(stream) {
					state.recordingStream = stream;
					state.recordedChunks = [];

					try {
						state.mediaRecorder = new MediaRecorder(stream);
					} catch (error) {
						helpers.stopRecordingStream(state);
						helpers.setStatus(
							state.container,
							helpers.getString(
								'recordingError',
								'Could not access your microphone. Please allow access or upload an audio file instead.'
							)
						);
						helpers.updateTranscribeButtonState(state);
						return;
					}

					if (!state.mediaRecorder) {
						helpers.stopRecordingStream(state);
						helpers.updateTranscribeButtonState(state);
						return;
					}

					state.recordingShouldProcess = true;

					state.mediaRecorder.addEventListener('dataavailable', function(event) {
						if (event && event.data && event.data.size) {
							state.recordedChunks.push(event.data);
						}
					});

					state.mediaRecorder.addEventListener('stop', function() {
						const chunks = state.recordedChunks || [];
						const mimeType = state.mediaRecorder && state.mediaRecorder.mimeType ? state.mediaRecorder.mimeType : 'audio/webm';
						let baseMimeType = typeof mimeType === 'string' ? mimeType.split(';')[0] : '';
						if (!baseMimeType && typeof mimeType === 'string') {
							baseMimeType = mimeType;
						}

						helpers.stopRecordingStream(state);
						helpers.setTranscribeRecordingState(state, false);

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
						} catch (error) {
							// Fallback if Blob creation fails
						}

						state.mediaRecorder = null;
						state.recordedChunks = [];

						if (!blob || !blob.size) {
							helpers.updateTranscribeButtonState(state);
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
							// Fallback for older browsers
							file = blob;
							file.name = fileName;
							if (file && file.type && typeof file.type === 'string') {
								file.type = file.type.split(';')[0];
							}
							if (file && !file.type && baseMimeType) {
								file.type = baseMimeType;
							}
						}

						helpers.transcribeAudioFile(state, file);
					});

					state.mediaRecorder.start();
					helpers.setTranscribeRecordingState(state, true);
					helpers.updateTranscribeButtonState(state);
				})
				.catch(function() {
					helpers.stopRecordingStream(state);
					helpers.setStatus(
						state.container,
						helpers.getString(
							'recordingError',
							'Could not access your microphone. Please allow access or upload an audio file instead.'
						)
					);

					if (state.transcribeInput && !state.transcribeInput.disabled) {
						state.transcribeInput.click();
					}

					helpers.updateTranscribeButtonState(state);
				});
		},

		/**
		 * Stop audio recording.
		 *
		 * @param {Object}   state   - Chat state object.
		 * @param {Object}   helpers - Helper functions object.
		 * @param {Function} helpers.stopRecordingStream          - Function to stop stream.
		 * @param {Function} helpers.setTranscribeRecordingState  - Function to update recording state.
		 * @param {Function} helpers.updateTranscribeButtonState  - Function to update button state.
		 */
		stopTranscribeRecording: function(state, helpers) {
			if (!state || !state.mediaRecorder) {
				return;
			}

			state.recordingShouldProcess = true;

			try {
				if (state.mediaRecorder.state !== 'inactive') {
					state.mediaRecorder.stop();
				}
			} catch (error) {
				helpers.stopRecordingStream(state);
				helpers.setTranscribeRecordingState(state, false);
				helpers.updateTranscribeButtonState(state);
			}
		},

		/**
		 * Handle file selection for transcription.
		 *
		 * @param {Event}    event   - File input change event.
		 * @param {Object}   state   - Chat state object.
		 * @param {Function} transcribeAudioFile - Function to process audio file.
		 */
		handleTranscribeFileSelection: function(event, state, transcribeAudioFile) {
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
			transcribeAudioFile(state, file);
		},

		/**
		 * Process audio file for transcription.
		 *
		 * @param {Object}   state    - Chat state object.
		 * @param {File}     file     - Audio file to transcribe.
		 * @param {Object}   helpers  - Helper functions object.
		 * @param {Function} helpers.getString                    - String translation function.
		 * @param {Function} helpers.setStatus                    - Status message function.
		 * @param {Function} helpers.formatString                 - String formatting function.
		 * @param {Function} helpers.updateTranscribeButtonState  - Function to update button state.
		 * @param {Function} helpers.uploadAudioForTranscription  - Function to upload audio.
		 * @param {Function} helpers.requestTranscription         - Function to request transcription.
		 * @param {Function} helpers.insertTranscriptionResult    - Function to insert result.
		 */
		transcribeAudioFile: function(state, file, helpers) {
			const self = this;
			if (!state || !file || !state.canUploadAttachments || state.transcribing) {
				return;
			}

			if (file.size && file.size > this.MAX_TRANSCRIBE_BYTES) {
				helpers.setStatus(
					state.container,
					helpers.getString(
						'transcriptionFileTooLarge',
						'The selected audio file is too large. Please choose a file under 25MB.'
					)
				);
				helpers.updateTranscribeButtonState(state);
				return;
			}

			state.transcribing = true;
			helpers.updateTranscribeButtonState(state);

			helpers.setStatus(state.container, helpers.getString('transcribing', 'Transcribing audio…'));

			let uploadedRecord = null;

			helpers.uploadAudioForTranscription(state, file)
				.then(function(record) {
					uploadedRecord = record;
					if (!record || typeof record.id === 'undefined') {
						throw new Error('Upload failed');
					}

					if (state.attachmentLibrary && record.fileId) {
						state.attachmentLibrary[record.fileId] = record;
					}

					return helpers.requestTranscription(state, record);
				})
				.then(function(response) {
					const result = self.extractTranscriptionResult(response);
					helpers.insertTranscriptionResult(state, result, uploadedRecord || file);

					let label = '';
					if (uploadedRecord && uploadedRecord.name) {
						label = uploadedRecord.name;
					} else if (file && file.name) {
						label = file.name;
					}

					const messageLabel = label || helpers.getString('transcribeAudio', 'Transcribe audio');
					const message = helpers.formatString(
						helpers.getString('transcriptionSuccess', 'Inserted transcription from "%s".'),
						messageLabel
					);
					helpers.setStatus(state.container, message);
				})
				.catch(function(error) {
					helpers.setStatus(
						state.container,
						helpers.getString('transcriptionError', 'The transcription request failed. Please try again.')
					);

					if (window.console && console.error) {
						console.error('Transcription failed', error);
					}
				})
				.finally(function() {
					state.transcribing = false;
					helpers.updateTranscribeButtonState(state);
				});
		},

		/**
		 * Upload audio file for transcription.
		 *
		 * @param {Object}   state   - Chat state object.
		 * @param {File}     file    - Audio file to upload.
		 * @param {Object}   helpers - Helper functions object.
		 * @param {Function} helpers.uploadFile            - Function to upload file.
		 * @param {Function} helpers.buildJsonHeaders      - Function to build headers.
		 * @param {Function} helpers.normaliseUploadResponse - Function to normalize response.
		 * @param {Function} helpers.createContentDispositionHeader - Function to create Content-Disposition.
		 * @return {Promise} Promise resolving to upload record.
		 */
		uploadAudioForTranscription: function(state, file, helpers) {
			if (!state || !file || !state.config || !state.config.uploadEndpoint) {
				if (window.console && console.error) {
					console.error('Voice chat: Upload configuration missing', {
						hasState: !!state,
						hasFile: !!file,
						hasConfig: !!(state && state.config),
						uploadEndpoint: state && state.config ? state.config.uploadEndpoint : 'undefined'
					});
				}
				return Promise.reject(new Error('Upload unavailable'));
			}

			if (window.console && console.log) {
				console.log('Voice chat: Uploading audio file', {
					fileName: file.name,
					fileSize: file.size,
					fileType: file.type,
					endpoint: state.config.uploadEndpoint
				});
			}

			const headers = helpers.buildJsonHeaders(state);
			delete headers['Content-Type']; // Let uploadFile set it

			const contentDisposition = helpers.createContentDispositionHeader(file.name || 'audio');
			if (contentDisposition) {
				headers['Content-Disposition'] = contentDisposition;
			}

			let contentType = '';
			if (file && file.type && typeof file.type === 'string') {
				contentType = file.type.split(';')[0];
			}

			headers['Content-Type'] = contentType || 'audio/webm';

			return helpers.uploadFile(
				state.config.uploadEndpoint,
				file,
				headers,
				{ state: state }
			)
				.then(function(response) {
					if (window.console && console.log) {
						console.log('Voice chat: Upload response received', {
							status: response.status,
							statusText: response.statusText,
							ok: response.ok
						});
					}

					return response
						.json()
						.catch(function(parseError) {
							if (window.console && console.error) {
								console.error('Voice chat: Failed to parse upload response JSON', parseError);
							}
							return null;
						})
						.then(function(data) {
							if (!response.ok) {
								if (window.console && console.error) {
									console.error('Voice chat: Upload failed', {
										status: response.status,
										statusText: response.statusText,
										data: data
									});
								}
								const error = new Error('Upload failed');
								error.response = response;
								error.status = response.status;
								error.data = data;
								throw error;
							}
							return data;
						});
				})
				.then(function(data) {
					const record = helpers.normaliseUploadResponse(data, file);
					if (window.console && console.log) {
						console.log('Voice chat: Media file created successfully', {
							id: record ? record.id : 'none',
							fileId: record ? record.fileId : 'none',
							name: record ? record.name : 'none'
						});
					}
					return record;
				});
		},

		/**
		 * Request transcription from server.
		 *
		 * @param {Object}   state   - Chat state object.
		 * @param {Object}   record  - Upload record with attachment ID.
		 * @param {Object}   helpers - Helper functions object.
		 * @param {Function} helpers.postJson          - Function to make POST request.
		 * @param {Function} helpers.buildJsonHeaders  - Function to build headers.
		 * @return {Promise} Promise resolving to transcription response.
		 */
		requestTranscription: function(state, record, helpers) {
			if (!state || !record || typeof record.id === 'undefined') {
				return Promise.reject(new Error('Missing attachment id'));
			}

			if (!state.config || !state.config.toolsEndpoint) {
				if (window.console && console.error) {
					console.error('Voice chat: Tools endpoint not configured', {
						hasConfig: !!state.config,
						toolsEndpoint: state.config ? state.config.toolsEndpoint : 'undefined'
					});
				}
				return Promise.reject(new Error('Tools endpoint unavailable'));
			}

			const payload = {
				assistant_id: state.config.assistantId,
				tool: this.TRANSCRIBE_TOOL_NAME,
				arguments: {
					attachment_id: record.id,
				},
			};

			if (window.console && console.log) {
				console.log('Voice chat: Requesting transcription', {
					endpoint: state.config.toolsEndpoint,
					attachmentId: record.id,
					tool: this.TRANSCRIBE_TOOL_NAME
				});
			}

			return helpers.postJson(
				state.config.toolsEndpoint,
				payload,
				helpers.buildJsonHeaders(state),
				{ state: state }
			).then(function(response) {
				if (!response.ok) {
					if (window.console && console.error) {
						console.error('Voice chat: Transcription request failed', {
							status: response.status,
							statusText: response.statusText,
							url: response.url,
							endpoint: state.config.toolsEndpoint,
							attachmentId: record.id
						});
					}
				}

				return response
					.json()
					.catch(function(parseError) {
						if (window.console && console.error) {
							console.error('Voice chat: Failed to parse response JSON', parseError);
						}
						return null;
					})
					.then(function(data) {
						if (!response.ok) {
							const error = new Error('Transcription request failed');
							error.response = response;
							error.status = response.status;
							error.data = data;
							throw error;
						}
						return data;
					});
			});
		},

		/**
		 * Extract transcription result from API response.
		 *
		 * @param {Object} body - API response body.
		 * @return {Object|null} Transcription result.
		 */
		extractTranscriptionResult: function(body) {
			if (!body || typeof body !== 'object') {
				return null;
			}

			if (Object.prototype.hasOwnProperty.call(body, 'result')) {
				return body.result;
			}

			return body;
		},

		/**
		 * Insert transcription result into textarea.
		 *
		 * @param {Object}   state   - Chat state object.
		 * @param {Object}   result  - Transcription result.
		 * @param {Object}   record  - Upload record.
		 * @param {Function} formatDuration - Function to format duration.
		 */
		insertTranscriptionResult: function(state, result, record, formatDuration) {
			if (!state || !state.textarea) {
				return;
			}

			const payload = result || {};
			let text = '';

			if (payload && typeof payload.text === 'string') {
				text = payload.text.trim();
			}

			const metaParts = [];
			if (record && record.name) {
				metaParts.push(record.name);
			}

			if (payload.language) {
				metaParts.push('Language: ' + payload.language);
			}

			if (typeof payload.duration === 'number') {
				const duration = formatDuration(payload.duration);
				if (duration) {
					metaParts.push('Duration: ' + duration);
				}
			}

			if (payload.translated) {
				metaParts.push('Translated to English');
			}

			let segmentsText = '';
			if (Array.isArray(payload.segments) && payload.segments.length) {
				segmentsText = payload.segments
					.map(function(segment) {
						if (!segment) {
							return '';
						}

						const start = formatDuration(segment.start);
						const end = formatDuration(segment.end);
						const segmentText = segment.text || '';
						let prefix = '';

						if (start && end) {
							prefix = start + '–' + end;
						} else if (start) {
							prefix = start;
						}

						if (prefix) {
							return prefix + ': ' + segmentText;
						}

						return segmentText;
					})
					.filter(function(segmentText) {
						return segmentText && segmentText.trim();
					})
					.join('\n');
			}

			const hasTextContent = Boolean(text) || Boolean(segmentsText);
			if (!hasTextContent) {
				return;
			}

			const sections = [];
			if (metaParts.length) {
				sections.push(metaParts.join(' • '));
			}

			if (text) {
				sections.push(text);
			}

			if (segmentsText) {
				sections.push(segmentsText);
			}

			const combined = sections.join('\n\n').trim();
			if (!combined) {
				return;
			}

			const existing = state.textarea.value || '';
			const trimmedExisting = existing.replace(/\s+$/, '');
			const newValue = trimmedExisting ? trimmedExisting + '\n\n' + combined : combined;

			state.textarea.value = newValue;
			state.textarea.focus();
		}
	};

	// Expose service globally for chat.js and other modules
	window.wpMcpAiChatTranscription = wpMcpAiChatTranscription;
})();
