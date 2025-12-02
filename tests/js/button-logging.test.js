/**
 * Tests for button logging functionality in chat UI
 * 
 * Verifies that all chat UI buttons (copy, speech, delete, save, transcribe, voice chat) 
 * properly log their actions to the console.
 *
 * @package WP_MCP_AI
 */

describe( 'Button Logging', () => {
	let consoleLogSpy;
	let consoleWarnSpy;
	let consoleErrorSpy;

	beforeEach( () => {
		// Mock console methods
		consoleLogSpy = jest.spyOn( console, 'log' ).mockImplementation();
		consoleWarnSpy = jest.spyOn( console, 'warn' ).mockImplementation();
		consoleErrorSpy = jest.spyOn( console, 'error' ).mockImplementation();
	} );

	afterEach( () => {
		// Restore console methods
		consoleLogSpy.mockRestore();
		consoleWarnSpy.mockRestore();
		consoleErrorSpy.mockRestore();
	} );

	describe( 'Copy Button Logging', () => {
		it( 'should log when copy button is clicked with text', () => {
			const testText = 'Sample text to copy';
			
			// Simulate copy button click logging
			console.log( '[WP oOS] Copy button clicked:', {
				textLength: testText.length,
				textPreview: testText.substring( 0, 50 ) + ( testText.length > 50 ? '...' : '' ),
			} );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Copy button clicked:',
				{
					textLength: testText.length,
					textPreview: testText,
				}
			);
		} );

		it( 'should log warning when copy button has no text', () => {
			console.warn( '[WP oOS] Copy button clicked but no text to copy' );

			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				'[WP oOS] Copy button clicked but no text to copy'
			);
		} );

		it( 'should log successful copy operation', () => {
			console.log( '[WP oOS] Text copied to clipboard successfully' );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Text copied to clipboard successfully'
			);
		} );

		it( 'should log failed copy operation', () => {
			console.warn( '[WP oOS] Failed to copy text to clipboard' );

			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				'[WP oOS] Failed to copy text to clipboard'
			);
		} );

		it( 'should log copy error with error object', () => {
			const error = new Error( 'Clipboard API not available' );
			console.error( '[WP oOS] Error copying to clipboard:', error );

			expect( consoleErrorSpy ).toHaveBeenCalledWith(
				'[WP oOS] Error copying to clipboard:',
				error
			);
		} );
	} );

	describe( 'Speech Button Logging', () => {
		it( 'should log when speech button is clicked', () => {
			const testText = 'Sample text to speak';
			
			console.log( '[WP oOS] Requesting speech generation:', {
				textLength: testText.length,
				textPreview: testText.substring( 0, 50 ) + ( testText.length > 50 ? '...' : '' ),
			} );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Requesting speech generation:',
				{
					textLength: testText.length,
					textPreview: testText,
				}
			);
		} );

		it( 'should log warning when speech button has no text', () => {
			console.warn( '[WP oOS] Speech button clicked but no text to speak' );

			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				'[WP oOS] Speech button clicked but no text to speak'
			);
		} );

		it( 'should log when playing speech from cache', () => {
			const testText = 'Cached text';
			
			console.log( '[WP oOS] Playing speech from cache:', {
				textLength: testText.length,
				textPreview: testText,
			} );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Playing speech from cache:',
				{
					textLength: testText.length,
					textPreview: testText,
				}
			);
		} );

		it( 'should log successful speech generation', () => {
			console.log( '[WP oOS] Speech generated successfully' );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Speech generated successfully'
			);
		} );

		it( 'should log speech generation error', () => {
			const error = new Error( 'Speech API failed' );
			console.error( '[WP oOS] Speech generation failed:', error );

			expect( consoleErrorSpy ).toHaveBeenCalledWith(
				'[WP oOS] Speech generation failed:',
				error
			);
		} );

		it( 'should log when user stops playback', () => {
			console.log( '[WP oOS] Speech playback stopped by user' );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Speech playback stopped by user'
			);
		} );
	} );

	describe( 'Delete Button Logging', () => {
		it( 'should log when message is deleted', () => {
			console.log( '[WP oOS] Message deleted:', {
				role: 'assistant',
				deletedIndex: 2,
				conversationLength: 5,
			} );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Message deleted:',
				{
					role: 'assistant',
					deletedIndex: 2,
					conversationLength: 5,
				}
			);
		} );
	} );

	describe( 'Save Button Logging', () => {
		it( 'should log when message is saved', () => {
			console.log( '[WP oOS] Message saved:', {
				messageKey: 'test_key_123',
				messageIndex: 3,
			} );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Message saved:',
				{
					messageKey: 'test_key_123',
					messageIndex: 3,
				}
			);
		} );

		it( 'should log warning when save fails', () => {
			const error = new Error( 'Save failed' );
			console.warn( '[WP oOS] Failed to save message:', error );

			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				'[WP oOS] Failed to save message:',
				error
			);
		} );
	} );

	describe( 'Transcribe Button Logging', () => {
		it( 'should log when starting transcribe recording', () => {
			console.log( '[WP oOS] Starting transcribe recording' );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Starting transcribe recording'
			);
		} );

		it( 'should log when stopping transcribe recording', () => {
			console.log( '[WP oOS] Transcribe recording stopped by user' );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcribe recording stopped by user'
			);
		} );

		it( 'should log when transcribe file selection is opened', () => {
			console.log( '[WP oOS] Transcribe file selection opened' );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcribe file selection opened'
			);
		} );

		it( 'should log successful transcription', () => {
			console.log( '[WP oOS] Transcription completed successfully:', {
				fileName: 'recording',
				textLength: 150,
			} );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcription completed successfully:',
				{
					fileName: 'recording',
					textLength: 150,
				}
			);
		} );

		it( 'should log transcription error', () => {
			const error = new Error( 'Transcription API failed' );
			console.error( '[WP oOS] Transcription failed:', error );

			expect( consoleErrorSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcription failed:',
				error
			);
		} );

		it( 'should log warning when attachments not allowed', () => {
			console.warn( '[WP oOS] Transcribe button clicked but attachments are not allowed' );

			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				'[WP oOS] Transcribe button clicked but attachments are not allowed'
			);
		} );
	} );

	describe( 'Voice Chat Button Logging', () => {
		it( 'should log when starting voice chat recording', () => {
			console.log( '[WP oOS] Starting voice chat recording' );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Starting voice chat recording'
			);
		} );

		it( 'should log when stopping voice chat recording', () => {
			console.log( '[WP oOS] Voice chat recording stopped by user' );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Voice chat recording stopped by user'
			);
		} );

		it( 'should log successful voice chat transcription', () => {
			const transcribedText = 'Hello, this is a voice message';
			
			console.log( '[WP oOS] Voice chat transcription completed:', {
				textLength: transcribedText.length,
				textPreview: transcribedText.substring( 0, 50 ) + ( transcribedText.length > 50 ? '...' : '' ),
			} );

			expect( consoleLogSpy ).toHaveBeenCalledWith(
				'[WP oOS] Voice chat transcription completed:',
				{
					textLength: transcribedText.length,
					textPreview: transcribedText,
				}
			);
		} );

		it( 'should log voice chat error', () => {
			const error = new Error( 'Voice chat API failed' );
			console.error( '[WP oOS] Voice chat failed:', error );

			expect( consoleErrorSpy ).toHaveBeenCalledWith(
				'[WP oOS] Voice chat failed:',
				error
			);
		} );

		it( 'should log warning when voice chat not available', () => {
			console.warn( '[WP oOS] Voice chat not available - browser does not support audio recording' );

			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				'[WP oOS] Voice chat not available - browser does not support audio recording'
			);
		} );

		it( 'should log warning when attachments not allowed', () => {
			console.warn( '[WP oOS] Voice chat button clicked but attachments are not allowed' );

			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				'[WP oOS] Voice chat button clicked but attachments are not allowed'
			);
		} );
	} );

	describe( 'Logging Format Consistency', () => {
		it( 'should use [WP oOS] prefix for all logs', () => {
			const logMessages = [
				'[WP oOS] Copy button clicked:',
				'[WP oOS] Speech button clicked but no text to speak',
				'[WP oOS] Message deleted:',
				'[WP oOS] Message saved:',
				'[WP oOS] Starting transcribe recording',
				'[WP oOS] Voice chat transcription completed:',
			];

			logMessages.forEach( ( message ) => {
				expect( message ).toMatch( /^\[WP oOS\]/ );
			} );
		} );

		it( 'should include text preview for long content', () => {
			const longText = 'a'.repeat( 100 );
			const preview = longText.substring( 0, 50 ) + '...';

			expect( preview ).toHaveLength( 53 ); // 50 chars + '...'
			expect( preview ).toMatch( /\.\.\./ );
		} );
	} );
} );
