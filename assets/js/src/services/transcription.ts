/**
 * Chat Transcription Service — TypeScript edition.
 *
 * Handles audio recording and transcription for the NV oOS chat interface.
 * This includes:
 * - Audio recording with MediaRecorder API
 * - Recording state management
 * - Audio file upload
 * - Transcription API requests
 * - Result insertion into chat input
 *
 * Converted from `assets/js/chat-transcription-service.js` (IIFE → ESM).
 *
 * @package NV_MCP_AI
 * @since   1.1.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

// ── Constants ────────────────────────────────────────────────────────

export const TRANSCRIBE_TOOL_NAME = 'transcribe_openai_audio';
export const TRANSCRIBE_RECORDING_CLASS =
	'wp-mcp-ai-chat__transcribe--recording';
export const MAX_TRANSCRIBE_BYTES = 26214400; // 25MB

// ── Types ────────────────────────────────────────────────────────────

/** Subset of chat instance state used by the transcription service. */
interface ChatState {
	container: HTMLElement | null;
	textarea: HTMLTextAreaElement | null;
	transcribeButton: HTMLButtonElement | null;
	transcribeInput: HTMLInputElement | null;
	busy: boolean;
	uploading: number;
	transcribing: boolean;
	isRecording: boolean;
	canUploadAttachments: boolean;
	recordingStream: MediaStream | null;
	recordedChunks: Blob[];
	mediaRecorder: MediaRecorder | null;
	recordingShouldProcess: boolean;
	config: {
		uploadEndpoint?: string;
		toolsEndpoint?: string;
		assistantId?: string | number;
		[ key: string ]: unknown;
	};
}

/** Upload record returned by the server after an audio file is uploaded. */
interface UploadRecord {
	id?: string | number;
	fileId?: string;
	name?: string;
	url?: string;
	mime?: string;
	size?: number;
}

/** Transcription result extracted from the API response. */
interface TranscriptionResult {
	text?: string;
	language?: string;
	duration?: number;
	translated?: boolean;
	segments?: TranscriptionSegment[];
	[ key: string ]: unknown;
}

/** A single timestamped transcription segment. */
interface TranscriptionSegment {
	start: number;
	end: number;
	text?: string;
	[ key: string ]: unknown;
}

/**
 * Bag of helper functions passed in from the chat initialisation layer.
 *
 * These signatures reflect how helpers are *called* within this module.
 * The chat.js layer pre-binds extra arguments (the `helpers` object itself,
 * `getString`, `setStatus`, `formatDuration`, etc.) so the call sites
 * here pass fewer arguments than the full exported function signatures.
 */
interface TranscriptionHelpers {
	getString( key: string, fallback: string ): string;
	setStatus( container: HTMLElement | null, message: string ): void;
	formatString( template: string, ...args: string[] ): string;
	formatDuration( seconds: number ): string;
	updateTranscribeButtonState( state: ChatState ): void;
	stopRecordingStream( state: ChatState ): void;
	setTranscribeRecordingState(
		state: ChatState,
		recording: boolean,
	): void;
	startTranscribeRecording( state: ChatState ): void;
	stopTranscribeRecording( state: ChatState ): void;
	transcribeAudioFile( state: ChatState, file: File ): void;
	uploadAudioForTranscription(
		state: ChatState,
		file: File,
	): Promise< UploadRecord >;
	requestTranscription(
		state: ChatState,
		record: UploadRecord,
	): Promise< Record< string, unknown > >;
	insertTranscriptionResult(
		state: ChatState,
		result: TranscriptionResult,
		record: UploadRecord | File,
	): void;
	uploadFile(
		url: string,
		file: File,
		headers: Record< string, string >,
		options: Record< string, unknown >,
	): Promise< Response >;
	buildJsonHeaders( state: ChatState ): Record< string, string >;
	normaliseUploadResponse(
		data: unknown,
		file: File,
	): UploadRecord;
	createContentDispositionHeader(
		filename: string,
	): string | null;
	postJson(
		url: string,
		payload: Record< string, unknown >,
		headers: Record< string, string >,
		options: Record< string, unknown >,
	): Promise< Response >;
}

// ── Service ──────────────────────────────────────────────────────────

/**
 * Check if browser supports audio recording.
 *
 * @return True if MediaRecorder and getUserMedia are available.
 */
export function supportsAudioRecording(): boolean {
	return (
		typeof window !== 'undefined' &&
		typeof navigator !== 'undefined' &&
		!! navigator.mediaDevices &&
		typeof navigator.mediaDevices.getUserMedia === 'function' &&
		typeof window.MediaRecorder !== 'undefined'
	);
}

/**
 * Stop and clean up the recording stream.
 *
 * @param state - Chat state object.
 */
export function stopRecordingStream( state: ChatState ): void {
	if ( ! state || ! state.recordingStream ) {
		return;
	}

	const tracks: MediaStreamTrack[] = state.recordingStream.getTracks
		? state.recordingStream.getTracks()
		: [];
	tracks.forEach( function ( track: MediaStreamTrack ) {
		if ( track && track.stop ) {
			track.stop();
		}
	} );

	state.recordingStream = null;
}

/**
 * Update recording state and UI.
 *
 * @param state     - Chat state object.
 * @param recording - Whether recording is active.
 * @param getString - String translation function.
 * @param setStatus - Status message function.
 */
export function setTranscribeRecordingState(
	state: ChatState,
	recording: boolean,
	getString: TranscriptionHelpers[ 'getString' ],
	setStatus: TranscriptionHelpers[ 'setStatus' ],
): void {
	if ( ! state ) {
		return;
	}

	state.isRecording = !! recording;

	const button = state.transcribeButton;
	if ( button && button.classList ) {
		if ( state.isRecording ) {
			button.classList.add( TRANSCRIBE_RECORDING_CLASS );
		} else {
			button.classList.remove( TRANSCRIBE_RECORDING_CLASS );
		}
	}

	if ( button ) {
		const label = state.isRecording
			? getString( 'stopRecording', 'Stop recording' )
			: getString( 'transcribeAudio', 'Transcribe audio' );
		button.setAttribute( 'aria-label', label );
		button.setAttribute( 'title', label );
	}

	if ( state.container ) {
		if ( state.isRecording ) {
			setStatus(
				state.container,
				getString(
					'recording',
					'Recording\u2026 tap to stop.',
				),
			);
		} else if ( ! state.transcribing && ! state.busy ) {
			setStatus( state.container, '' );
		}
	}
}

/**
 * Update transcribe button enabled/disabled state.
 *
 * @param state - Chat state object.
 */
export function updateTranscribeButtonState( state: ChatState ): void {
	if ( ! state ) {
		return;
	}

	const button = state.transcribeButton;
	const input = state.transcribeInput;

	const canUse = !! state.canUploadAttachments;
	let disabled =
		! canUse ||
		state.busy ||
		state.uploading > 0 ||
		state.transcribing;

	if ( state.isRecording ) {
		disabled = false;
	}

	if ( button ) {
		button.disabled = disabled;

		if ( ! canUse ) {
			button.hidden = true;
		} else {
			button.hidden = false;
		}
	}

	if ( input ) {
		input.disabled =
			! canUse ||
			state.busy ||
			state.uploading > 0 ||
			state.transcribing ||
			state.isRecording;
	}
}

/**
 * Handle transcribe button click.
 *
 * @param state   - Chat state object.
 * @param helpers - Helper functions object (with pre-bound args).
 */
export function handleTranscribeButtonClick(
	state: ChatState,
	helpers: TranscriptionHelpers,
): void {
	if ( ! state || state.transcribing ) {
		return;
	}

	if ( state.isRecording ) {
		helpers.stopTranscribeRecording( state );
		return;
	}

	if ( ! state.transcribeInput ) {
		return;
	}

	if ( supportsAudioRecording() ) {
		const buttons = document.querySelectorAll(
			'[data-wp-mcp-ai-file-select-trigger]',
		);
		let shouldAllowRecording = true;

		if ( buttons && buttons.length ) {
			buttons.forEach( function ( btn ) {
				if ( ( btn as HTMLButtonElement ).disabled ) {
					shouldAllowRecording = false;
				}
			} );
		}

		if ( shouldAllowRecording ) {
			helpers.startTranscribeRecording( state );
			return;
		}
	}

	state.transcribeInput.click();
}

/**
 * Start audio recording.
 *
 * @param state   - Chat state object.
 * @param helpers - Helper functions object (with pre-bound args).
 */
export function startTranscribeRecording(
	state: ChatState,
	helpers: TranscriptionHelpers,
): void {
	if ( ! state || ! supportsAudioRecording() ) {
		return;
	}

	state.recordingShouldProcess = false;

	if ( state.transcribeButton ) {
		state.transcribeButton.disabled = true;
	}

	navigator.mediaDevices
		.getUserMedia( { audio: true } )
		.then( function ( stream: MediaStream ) {
			state.recordingStream = stream;
			state.recordedChunks = [];

			try {
				state.mediaRecorder = new MediaRecorder( stream );
			} catch ( error ) {
				helpers.stopRecordingStream( state );
				helpers.setStatus(
					state.container,
					helpers.getString(
						'recordingError',
						'Could not access your microphone. Please allow access or upload an audio file instead.',
					),
				);
				helpers.updateTranscribeButtonState( state );
				return;
			}

			if ( ! state.mediaRecorder ) {
				helpers.stopRecordingStream( state );
				helpers.updateTranscribeButtonState( state );
				return;
			}

			state.recordingShouldProcess = true;

			state.mediaRecorder.addEventListener(
				'dataavailable',
				function ( event: BlobEvent ) {
					if ( event && event.data && event.data.size ) {
						state.recordedChunks.push( event.data );
					}
				},
			);

			state.mediaRecorder.addEventListener( 'stop', function () {
				const chunks = state.recordedChunks || [];
				const mimeType =
					state.mediaRecorder && state.mediaRecorder.mimeType
						? state.mediaRecorder.mimeType
						: 'audio/webm';
				let baseMimeType =
					typeof mimeType === 'string'
						? mimeType.split( ';' )[ 0 ]
						: '';
				if (
					! baseMimeType &&
					typeof mimeType === 'string'
				) {
					baseMimeType = mimeType;
				}

				helpers.stopRecordingStream( state );
				helpers.setTranscribeRecordingState( state, false );

				if ( ! state.recordingShouldProcess ) {
					state.mediaRecorder = null;
					state.recordedChunks = [];
					return;
				}

				let blob: Blob | null = null;
				try {
					let blobType = baseMimeType || mimeType;
					if (
						blobType &&
						typeof blobType === 'string'
					) {
						blobType = blobType.split( ';' )[ 0 ];
					}
					blob = new Blob( chunks, {
						type: blobType || 'audio/webm',
					} );
				} catch ( error ) {
					// Fallback if Blob creation fails
				}

				state.mediaRecorder = null;
				state.recordedChunks = [];

				if ( ! blob || ! blob.size ) {
					helpers.updateTranscribeButtonState( state );
					return;
				}

				let extension = '';
				if (
					baseMimeType &&
					baseMimeType.indexOf( '/' ) !== -1
				) {
					extension =
						baseMimeType.split( '/' )[ 1 ] || '';
				}

				let safeExtension = extension
					? extension.replace( /[^a-z0-9]/gi, '' )
					: 'webm';
				if ( ! safeExtension ) {
					safeExtension = 'webm';
				}
				const fileName =
					'transcription-' +
					Date.now() +
					'.' +
					safeExtension;

				let file: File | ( Blob & { name?: string } );
				try {
					let fileType =
						blob && blob.type
							? blob.type
							: baseMimeType || 'audio/webm';
					if (
						fileType &&
						typeof fileType === 'string'
					) {
						fileType = fileType.split( ';' )[ 0 ];
					}
					file = new File( [ blob ], fileName, {
						type: fileType || 'audio/webm',
					} );
				} catch ( error ) {
					// Fallback for older browsers
					file = blob;
					(
						file as Blob & { name?: string }
					).name = fileName;
					const typedFile = file as Blob & {
						type?: string;
					};
					if (
						typedFile.type &&
						typeof typedFile.type === 'string'
					) {
						typedFile.type = typedFile.type
							.split( ';' )[ 0 ];
					}
					if (
						file &&
						! typedFile.type &&
						baseMimeType
					) {
						typedFile.type = baseMimeType;
					}
				}

				helpers.transcribeAudioFile( state, file as File );
			} );

			state.mediaRecorder.start();
			helpers.setTranscribeRecordingState( state, true );
			helpers.updateTranscribeButtonState( state );
		} )
		.catch( function () {
			helpers.stopRecordingStream( state );
			helpers.setStatus(
				state.container,
				helpers.getString(
					'recordingError',
					'Could not access your microphone. Please allow access or upload an audio file instead.',
				),
			);

			if (
				state.transcribeInput &&
				! state.transcribeInput.disabled
			) {
				state.transcribeInput.click();
			}

			helpers.updateTranscribeButtonState( state );
		} );
}

/**
 * Stop audio recording.
 *
 * @param state   - Chat state object.
 * @param helpers - Helper functions object (with pre-bound args).
 */
export function stopTranscribeRecording(
	state: ChatState,
	helpers: TranscriptionHelpers,
): void {
	if ( ! state || ! state.mediaRecorder ) {
		return;
	}

	state.recordingShouldProcess = true;

	try {
		if ( state.mediaRecorder.state !== 'inactive' ) {
			state.mediaRecorder.stop();
		}
	} catch ( error ) {
		helpers.stopRecordingStream( state );
		helpers.setTranscribeRecordingState( state, false );
		helpers.updateTranscribeButtonState( state );
	}
}

/**
 * Handle file selection for transcription.
 *
 * @param event               - File input change event.
 * @param state               - Chat state object.
 * @param transcribeAudioFile - Bound function to process audio file (pre-wired with helpers).
 */
export function handleTranscribeFileSelection(
	event: Event,
	state: ChatState,
	transcribeAudioFile: ( state: ChatState, file: File ) => void,
): void {
	if ( ! state || ! state.canUploadAttachments ) {
		return;
	}

	const target = event.target as HTMLInputElement | null;
	if ( ! event || ! target || ! target.files ) {
		return;
	}

	const files: File[] = Array.prototype.slice.call( target.files );
	target.value = '';

	if ( ! files.length ) {
		return;
	}

	const file = files[ 0 ];
	transcribeAudioFile( state, file );
}

/**
 * Process audio file for transcription.
 *
 * @param state   - Chat state object.
 * @param file    - Audio file to transcribe.
 * @param helpers - Helper functions object (with pre-bound args).
 */
export function transcribeAudioFile(
	state: ChatState,
	file: File,
	helpers: TranscriptionHelpers,
): void {
	if (
		! state ||
		! file ||
		! state.canUploadAttachments ||
		state.transcribing
	) {
		return;
	}

	if ( file.size && file.size > MAX_TRANSCRIBE_BYTES ) {
		helpers.setStatus(
			state.container,
			helpers.getString(
				'transcriptionFileTooLarge',
				'The selected audio file is too large. Please choose a file under 25MB.',
			),
		);
		helpers.updateTranscribeButtonState( state );
		return;
	}

	state.transcribing = true;
	helpers.updateTranscribeButtonState( state );

	helpers.setStatus(
		state.container,
		helpers.getString(
			'transcribing',
			'Transcribing audio\u2026',
		),
	);

	let uploadedRecord: UploadRecord | null = null;

	helpers
		.uploadAudioForTranscription( state, file )
		.then( function ( record: UploadRecord ) {
			uploadedRecord = record;
			if (
				! record ||
				typeof record.id === 'undefined'
			) {
				throw new Error( 'Upload failed' );
			}

			// NOTE: Do NOT add transcription audio to attachmentLibrary.
			// Transcription audio files are temporary recordings used only for transcription,
			// not attachments that should persist in the conversation. Adding them to the
			// library causes file reuse issues where old recordings are incorrectly used.

			return helpers.requestTranscription(
				state,
				record,
			);
		} )
		.then( function (
			response: Record< string, unknown >,
		) {
			const result =
				extractTranscriptionResult( response );
			helpers.insertTranscriptionResult(
				state,
				result || {},
				uploadedRecord || file,
			);

			let label = '';
			if (
				uploadedRecord &&
				uploadedRecord.name
			) {
				label = uploadedRecord.name;
			} else if ( file && file.name ) {
				label = file.name;
			}

			const messageLabel =
				label ||
				helpers.getString(
					'transcribeAudio',
					'Transcribe audio',
				);
			const message = helpers.formatString(
				helpers.getString(
					'transcriptionSuccess',
					'Inserted transcription from "%s".',
				),
				messageLabel,
			);
			helpers.setStatus(
				state.container,
				message,
			);
		} )
		.catch( function ( error: Error ) {
			helpers.setStatus(
				state.container,
				helpers.getString(
					'transcriptionError',
					'The transcription request failed. Please try again.',
				),
			);

			if (
				window.console &&
				console.error
			) {
				console.error(
					'Transcription failed',
					error,
				);
			}
		} )
		.finally( function () {
			state.transcribing = false;
			helpers.updateTranscribeButtonState( state );
		} );
}

/**
 * Upload audio file for transcription.
 *
 * @param state   - Chat state object.
 * @param file    - Audio file to upload.
 * @param helpers - Helper functions object.
 * @return Promise resolving to upload record.
 */
export function uploadAudioForTranscription(
	state: ChatState,
	file: File,
	helpers: TranscriptionHelpers,
): Promise< UploadRecord > {
	if (
		! state ||
		! file ||
		! state.config ||
		! state.config.uploadEndpoint
	) {
		if ( window.console && console.error ) {
			console.error(
				'Voice chat: Upload configuration missing',
				{
					hasState: !! state,
					hasFile: !! file,
					hasConfig: !! ( state && state.config ),
					uploadEndpoint:
						state && state.config
							? state.config.uploadEndpoint
							: 'undefined',
				},
			);
		}
		return Promise.reject(
			new Error( 'Upload unavailable' ),
		);
	}

	if ( window.console && console.log ) {
		console.log( 'Voice chat: Uploading audio file', {
			fileName: file.name,
			fileSize: file.size,
			fileType: file.type,
			endpoint: state.config.uploadEndpoint,
		} );
	}

	const headers = helpers.buildJsonHeaders( state );
	delete headers[ 'Content-Type' ]; // Let uploadFile set it

	const contentDisposition =
		helpers.createContentDispositionHeader(
			file.name || 'audio',
		);
	if ( contentDisposition ) {
		headers[ 'Content-Disposition' ] = contentDisposition;
	}

	let contentType = '';
	if (
		file &&
		file.type &&
		typeof file.type === 'string'
	) {
		contentType = file.type.split( ';' )[ 0 ];
	}

	headers[ 'Content-Type' ] = contentType || 'audio/webm';

	return helpers
		.uploadFile(
			state.config.uploadEndpoint,
			file,
			headers,
			{ state: state },
		)
		.then( function ( response: Response ) {
			if ( window.console && console.log ) {
				console.log(
					'Voice chat: Upload response received',
					{
						status: response.status,
						statusText: response.statusText,
						ok: response.ok,
					},
				);
			}

			return response
				.json()
				.catch( function (
					parseError: Error,
				) {
					if (
						window.console &&
						console.error
					) {
						console.error(
							'Voice chat: Failed to parse upload response JSON',
							parseError,
						);
					}
					return null;
				} )
				.then( function (
					data: unknown,
				) {
					if ( ! response.ok ) {
						if (
							window.console &&
							console.error
						) {
							console.error(
								'Voice chat: Upload failed',
								{
									status: response.status,
									statusText:
										response.statusText,
									data: data,
								},
							);
						}
						const error = new Error(
							'Upload failed',
						) as Error & {
							response?: Response;
							status?: number;
							data?: unknown;
						};
						error.response = response;
						error.status =
							response.status;
						error.data = data;
						throw error;
					}
					return data;
				} );
		} )
		.then( function ( data: unknown ) {
			const record =
				helpers.normaliseUploadResponse(
					data,
					file,
				);
			if ( window.console && console.log ) {
				console.log(
					'Voice chat: Media file created successfully',
					{
						id: record
							? record.id
							: 'none',
						fileId: record
							? record.fileId
							: 'none',
						name: record
							? record.name
							: 'none',
					},
				);
			}
			return record;
		} );
}

/**
 * Request transcription from server.
 *
 * @param state   - Chat state object.
 * @param record  - Upload record with attachment ID.
 * @param helpers - Helper functions object.
 * @return Promise resolving to transcription response.
 */
export function requestTranscription(
	state: ChatState,
	record: UploadRecord,
	helpers: TranscriptionHelpers,
): Promise< Record< string, unknown > > {
	if (
		! state ||
		! record ||
		typeof record.id === 'undefined'
	) {
		return Promise.reject(
			new Error( 'Missing attachment id' ),
		);
	}

	if (
		! state.config ||
		! state.config.toolsEndpoint
	) {
		if ( window.console && console.error ) {
			console.error(
				'Voice chat: Tools endpoint not configured',
				{
					hasConfig: !! state.config,
					toolsEndpoint: state.config
						? state.config.toolsEndpoint
						: 'undefined',
				},
			);
		}
		return Promise.reject(
			new Error(
				'Tools endpoint unavailable',
			),
		);
	}

	const payload = {
		assistant_id: state.config.assistantId,
		tool: TRANSCRIBE_TOOL_NAME,
		arguments: {
			attachment_id: record.id,
		},
	};

	if ( window.console && console.log ) {
		console.log(
			'Voice chat: Requesting transcription',
			{
				endpoint:
					state.config.toolsEndpoint,
				attachmentId: record.id,
				tool: TRANSCRIBE_TOOL_NAME,
			},
		);
	}

	return helpers
		.postJson(
			state.config.toolsEndpoint,
			payload as Record< string, unknown >,
			helpers.buildJsonHeaders( state ),
			{ state: state },
		)
		.then( function ( response: Response ) {
			if ( ! response.ok ) {
				if (
					window.console &&
					console.error
				) {
					console.error(
						'Voice chat: Transcription request failed',
						{
							status: response.status,
							statusText:
								response.statusText,
							url: response.url,
							endpoint:
								state.config
									.toolsEndpoint,
							attachmentId:
								record.id,
						},
					);
				}
			}

			return response
				.json()
				.catch( function (
					parseError: Error,
				) {
					if (
						window.console &&
						console.error
					) {
						console.error(
							'Voice chat: Failed to parse response JSON',
							parseError,
						);
					}
					return null;
				} )
				.then( function (
					data: unknown,
				) {
					if ( ! response.ok ) {
						const error = new Error(
							'Transcription request failed',
						) as Error & {
							response?: Response;
							status?: number;
							data?: unknown;
						};
						error.response =
							response;
						error.status =
							response.status;
						error.data = data;
						throw error;
					}
					return data as Record<
						string,
						unknown
					>;
				} );
		} );
}

/**
 * Extract transcription result from API response.
 *
 * @param body - API response body.
 * @return Transcription result or null.
 */
export function extractTranscriptionResult(
	body: Record< string, unknown > | null,
): TranscriptionResult | null {
	if ( ! body || typeof body !== 'object' ) {
		return null;
	}

	if (
		Object.prototype.hasOwnProperty.call(
			body,
			'result',
		)
	) {
		return body.result as TranscriptionResult;
	}

	return body as TranscriptionResult;
}

/**
 * Insert transcription result into textarea.
 *
 * @param state          - Chat state object.
 * @param result         - Transcription result.
 * @param _record        - Upload record or file (unused; kept for API compatibility).
 * @param formatDuration - Function to format duration (seconds → string).
 */
export function insertTranscriptionResult(
	state: ChatState,
	result: TranscriptionResult,
	_record: UploadRecord | File,
	formatDuration: TranscriptionHelpers[ 'formatDuration' ],
): void {
	if ( ! state || ! state.textarea ) {
		return;
	}

	const payload: TranscriptionResult = result || {};
	let text = '';

	if ( payload && typeof payload.text === 'string' ) {
		text = payload.text.trim();
	}

	const metaParts: string[] = [];
	// Note: We intentionally do NOT add record.name (filename) to metaParts
	// because it's shown in the status message and would be redundant in the textarea.
	// Users want just the transcription text, not the temporary audio filename.

	if ( payload.language ) {
		metaParts.push( 'Language: ' + payload.language );
	}

	if ( typeof payload.duration === 'number' ) {
		const duration = formatDuration(
			payload.duration,
		);
		if ( duration ) {
			metaParts.push(
				'Duration: ' + duration,
			);
		}
	}

	if ( payload.translated ) {
		metaParts.push( 'Translated to English' );
	}

	let segmentsText = '';
	if (
		Array.isArray( payload.segments ) &&
		payload.segments.length
	) {
		segmentsText = payload.segments
			.map( function (
				segment: TranscriptionSegment,
			) {
				if ( ! segment ) {
					return '';
				}

				const start = formatDuration(
					segment.start,
				);
				const end = formatDuration(
					segment.end,
				);
				const segmentText =
					segment.text || '';
				let prefix = '';

				if ( start && end ) {
					prefix =
						start +
						'\u2013' +
						end;
				} else if ( start ) {
					prefix = start;
				}

				if ( prefix ) {
					return (
						prefix +
						': ' +
						segmentText
					);
				}

				return segmentText;
			} )
			.filter( function (
				segmentText: string,
			) {
				return (
					segmentText &&
					segmentText.trim()
				);
			} )
			.join( '\n' );
	}

	const hasTextContent =
		Boolean( text ) || Boolean( segmentsText );
	if ( ! hasTextContent ) {
		return;
	}

	const sections: string[] = [];
	if ( metaParts.length ) {
		sections.push(
			metaParts.join( ' \u2022 ' ),
		);
	}

	if ( text ) {
		sections.push( text );
	}

	if ( segmentsText ) {
		sections.push( segmentsText );
	}

	const combined = sections
		.join( '\n\n' )
		.trim();
	if ( ! combined ) {
		return;
	}

	const existing = state.textarea.value || '';
	const trimmedExisting = existing.replace(
		/\s+$/,
		'',
	);
	const newValue = trimmedExisting
		? trimmedExisting + '\n\n' + combined
		: combined;

	state.textarea.value = newValue;
	state.textarea.focus();
}

// ── Backward-compatible global ───────────────────────────────────────

(
	window as unknown as Record< string, unknown >
).wpMcpAiChatTranscription = {
	supportsAudioRecording,
	stopRecordingStream,
	setTranscribeRecordingState,
	updateTranscribeButtonState,
	handleTranscribeButtonClick,
	startTranscribeRecording,
	stopTranscribeRecording,
	handleTranscribeFileSelection,
	transcribeAudioFile,
	uploadAudioForTranscription,
	requestTranscription,
	extractTranscriptionResult,
	insertTranscriptionResult,
	TRANSCRIBE_TOOL_NAME,
	TRANSCRIBE_RECORDING_CLASS,
	MAX_TRANSCRIBE_BYTES,
};
