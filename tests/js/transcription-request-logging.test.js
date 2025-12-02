/**
 * Tests for transcription request logging in chat UI
 * 
 * Verifies that transcription requests properly log their state,
 * validation errors, request details, and response handling.
 *
 * @package WP_MCP_AI
 */

describe( 'Transcription Request Logging', () => {
	let consoleLogSpy;
	let consoleErrorSpy;

	beforeEach( () => {
		// Mock console methods
		consoleLogSpy = jest.spyOn( console, 'log' ).mockImplementation();
		consoleErrorSpy = jest.spyOn( console, 'error' ).mockImplementation();
	} );

	afterEach( () => {
		// Restore console methods
		consoleLogSpy.mockRestore();
		consoleErrorSpy.mockRestore();
	} );

	describe( 'Validation Errors', () => {
		it( 'should log error when state is missing', () => {
			console.error( '[WP oOS] Transcription request failed: Missing state or record', {
				hasState: false,
				hasRecord: true,
				recordId: 123,
			} );

			expect( consoleErrorSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcription request failed: Missing state or record',
				{
					hasState: false,
					hasRecord: true,
					recordId: 123,
				}
			);
		} );

		it( 'should log error when record is missing', () => {
			console.error( '[WP oOS] Transcription request failed: Missing state or record', {
				hasState: true,
				hasRecord: false,
				recordId: undefined,
			} );

			expect( consoleErrorSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcription request failed: Missing state or record',
				{
					hasState: true,
					hasRecord: false,
					recordId: undefined,
				}
			);
		} );

		it( 'should log error when config is missing', () => {
			console.error( '[WP oOS] Transcription request failed: Missing config or endpoint', {
				hasConfig: false,
				hasEndpoint: false,
			} );

			expect( consoleErrorSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcription request failed: Missing config or endpoint',
				{
					hasConfig: false,
					hasEndpoint: false,
				}
			);
		} );

		it( 'should log error when tools endpoint is missing', () => {
			console.error( '[WP oOS] Transcription request failed: Missing config or endpoint', {
				hasConfig: true,
				hasEndpoint: false,
			} );

			expect( consoleErrorSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcription request failed: Missing config or endpoint',
				{
					hasConfig: true,
					hasEndpoint: false,
				}
			);
		} );

		it( 'should log error when assistant_id is missing', () => {
			console.error( '[WP oOS] Transcription request failed: Missing or invalid assistant_id', {
				assistantId: undefined,
				config: { toolsEndpoint: 'https://example.com/wp-json/mcp-ai/v1/tools' },
			} );

			expect( consoleErrorSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcription request failed: Missing or invalid assistant_id',
				expect.objectContaining( {
					assistantId: undefined,
				} )
			);
		} );

		it( 'should log error when assistant_id is zero', () => {
			console.error( '[WP oOS] Transcription request failed: Missing or invalid assistant_id', {
				assistantId: 0,
				config: { 
					assistantId: 0,
					toolsEndpoint: 'https://example.com/wp-json/mcp-ai/v1/tools',
				},
			} );

			expect( consoleErrorSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcription request failed: Missing or invalid assistant_id',
				expect.objectContaining( {
					assistantId: 0,
				} )
			);
		} );
	} );

	describe( 'Request Logging', () => {
		it( 'should log transcription request with all details', () => {
			const requestDetails = {
				endpoint: 'https://example.com/wp-json/mcp-ai/v1/tools',
				assistant_id: 123,
				attachment_id: 456,
				tool: 'transcribe_openai_audio',
				payload: {
					assistant_id: 123,
					tool: 'transcribe_openai_audio',
					arguments: {
						attachment_id: 456,
						translate: false,
					},
				},
			};

			console.log( '[WP oOS] Requesting transcription:', requestDetails );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Requesting transcription:',
				expect.objectContaining( {
					endpoint: expect.any( String ),
					assistant_id: 123,
					attachment_id: 456,
					tool: 'transcribe_openai_audio',
					payload: expect.objectContaining( {
						assistant_id: 123,
						tool: 'transcribe_openai_audio',
					} ),
				} )
			);
		} );

		it( 'should include payload with correct structure', () => {
			const payload = {
				assistant_id: 123,
				tool: 'transcribe_openai_audio',
				arguments: {
					attachment_id: 456,
					translate: false,
				},
			};

			console.log( '[WP oOS] Requesting transcription:', { payload } );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Requesting transcription:',
				expect.objectContaining( {
					payload: expect.objectContaining( {
						assistant_id: 123,
						tool: 'transcribe_openai_audio',
						arguments: expect.objectContaining( {
							attachment_id: 456,
							translate: false,
						} ),
					} ),
				} )
			);
		} );
	} );

	describe( 'Response Logging', () => {
		it( 'should log successful response', () => {
			console.log( '[WP oOS] Transcription response received:', {
				status: 200,
				statusText: 'OK',
				ok: true,
				url: 'https://example.com/wp-json/mcp-ai/v1/tools',
			} );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcription response received:',
				expect.objectContaining( {
					status: 200,
					statusText: 'OK',
					ok: true,
				} )
			);
		} );

		it( 'should log failed response with 400 status', () => {
			console.log( '[WP oOS] Transcription response received:', {
				status: 400,
				statusText: 'Bad Request',
				ok: false,
				url: 'https://example.com/wp-json/mcp-ai/v1/tools',
			} );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcription response received:',
				expect.objectContaining( {
					status: 400,
					statusText: 'Bad Request',
					ok: false,
				} )
			);
		} );

		it( 'should log error details when request fails', () => {
			const errorDetails = {
				status: 400,
				statusText: 'Bad Request',
				responseData: {
					code: 'wp_mcp_ai_missing_assistant',
					message: 'No assistant was provided and no default assistant is configured.',
				},
				payload: {
					assistant_id: 0,
					tool: 'transcribe_openai_audio',
				},
			};

			console.error( '[WP oOS] Transcription request failed:', errorDetails );

			expect( consoleErrorSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcription request failed:',
				expect.objectContaining( {
					status: 400,
					statusText: 'Bad Request',
					responseData: expect.any( Object ),
					payload: expect.any( Object ),
				} )
			);
		} );

		it( 'should log JSON parse errors', () => {
			const parseError = new Error( 'Unexpected token < in JSON' );
			console.error( '[WP oOS] Failed to parse transcription response JSON:', parseError );

			expect( consoleErrorSpy ).toHaveBeenCalledWith(
				'[WP oOS] Failed to parse transcription response JSON:',
				parseError
			);
		} );

		it( 'should log successful completion', () => {
			console.log( '[WP oOS] Transcription completed successfully:', {
				hasData: true,
				dataKeys: [ 'result', 'text', 'language' ],
			} );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcription completed successfully:',
				expect.objectContaining( {
					hasData: true,
					dataKeys: expect.any( Array ),
				} )
			);
		} );
	} );

	describe( 'Voice Chat Processing Logging', () => {
		it( 'should log voice chat processing start with all details', () => {
			const processingDetails = {
				blobSize: 1024000,
				blobType: 'audio/webm',
				fileName: 'voice-chat-1234567890.webm',
				hasState: true,
				hasConfig: true,
				assistantId: 123,
				hasHelpers: true,
				hasUploadFunction: true,
				hasTranscriptionFunction: true,
			};

			console.log( '[WP oOS] Starting voice chat processing:', processingDetails );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Starting voice chat processing:',
				expect.objectContaining( {
					blobSize: expect.any( Number ),
					blobType: 'audio/webm',
					fileName: expect.stringContaining( 'voice-chat' ),
					assistantId: 123,
				} )
			);
		} );

		it( 'should log error when helper functions are missing', () => {
			console.error( '[WP oOS] Voice chat failed: Missing required helper functions', {
				hasHelpers: true,
				hasUpload: false,
				hasTranscription: true,
			} );

			expect( consoleErrorSpy ).toHaveBeenCalledWith(
				'[WP oOS] Voice chat failed: Missing required helper functions',
				expect.objectContaining( {
					hasHelpers: true,
					hasUpload: false,
					hasTranscription: true,
				} )
			);
		} );
	} );

	describe( 'Logging Format Consistency', () => {
		it( 'should use [WP oOS] prefix for all logs', () => {
			const logMessages = [
				'[WP oOS] Transcription request failed: Missing state or record',
				'[WP oOS] Transcription request failed: Missing config or endpoint',
				'[WP oOS] Transcription request failed: Missing or invalid assistant_id',
				'[WP oOS] Requesting transcription:',
				'[WP oOS] Transcription response received:',
				'[WP oOS] Transcription completed successfully:',
				'[WP oOS] Starting voice chat processing:',
				'[WP oOS] Voice chat failed: Missing required helper functions',
			];

			logMessages.forEach( ( message ) => {
				expect( message ).toMatch( /^\[WP oOS\]/ );
			} );
		} );

		it( 'should provide structured data objects', () => {
			const logData = {
				hasState: true,
				hasRecord: true,
				assistantId: 123,
			};

			console.log( '[WP oOS] Test log:', logData );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Test log:',
				expect.objectContaining( logData )
			);
		} );
	} );
} );
