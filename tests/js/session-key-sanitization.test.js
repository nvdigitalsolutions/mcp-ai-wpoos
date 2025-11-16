/**
 * Tests for session key sanitization
 *
 * Ensures that session keys are properly sanitized to remove
 * whitespace (including tabs) and invalid characters, matching
 * the PHP-side normalization behavior.
 *
 * @package WP_MCP_AI
 */

describe( 'Session Key Sanitization', () => {
	let sanitizeSessionKey;

	beforeEach( () => {
		// Mock the window object and chat storage service
		global.window = global;
		
		// Load the chat-storage-service.js IIFE
		// Since it's an IIFE, we need to execute it to populate window.wpMcpAiChatStorage
		const fs = require( 'fs' );
		const path = require( 'path' );
		const storageServiceCode = fs.readFileSync(
			path.join( __dirname, '../../assets/js/chat-storage-service.js' ),
			'utf8'
		);
		
		// Execute the IIFE in the global context
		eval( storageServiceCode );
		
		// Get the sanitization function from the service
		sanitizeSessionKey = window.wpMcpAiChatStorage.sanitizeSessionKey;
	} );

	afterEach( () => {
		delete global.window.wpMcpAiChatStorage;
	} );

	describe( 'sanitizeSessionKey', () => {
		it( 'should remove tab characters from session keys', () => {
			const keyWithTab = 'session\tkey';
			const sanitized = sanitizeSessionKey( keyWithTab );
			
			expect( sanitized ).toBe( 'sessionkey' );
			expect( sanitized ).not.toContain( '\t' );
		} );

		it( 'should remove leading and trailing whitespace', () => {
			const keyWithSpaces = '  session-key  ';
			const sanitized = sanitizeSessionKey( keyWithSpaces );
			
			expect( sanitized ).toBe( 'session-key' );
		} );

		it( 'should remove newlines and carriage returns', () => {
			const keyWithNewlines = 'session\nkey\r';
			const sanitized = sanitizeSessionKey( keyWithNewlines );
			
			expect( sanitized ).toBe( 'sessionkey' );
		} );

		it( 'should remove all spaces including internal ones', () => {
			const keyWithSpaces = 'session key value';
			const sanitized = sanitizeSessionKey( keyWithSpaces );
			
			expect( sanitized ).toBe( 'sessionkeyvalue' );
		} );

		it( 'should preserve valid characters (alphanumeric, underscore, hyphen)', () => {
			const validKey = 'session-key_123';
			const sanitized = sanitizeSessionKey( validKey );
			
			expect( sanitized ).toBe( 'session-key_123' );
		} );

		it( 'should preserve UUID format session keys', () => {
			const uuid = 'd28ff0cd-0d82-4d6d-b733-cc318b71ac9a';
			const sanitized = sanitizeSessionKey( uuid );
			
			expect( sanitized ).toBe( uuid );
		} );

		it( 'should remove special characters', () => {
			const keyWithSpecialChars = 'session!@#$%key';
			const sanitized = sanitizeSessionKey( keyWithSpecialChars );
			
			expect( sanitized ).toBe( 'sessionkey' );
		} );

		it( 'should handle empty string', () => {
			const sanitized = sanitizeSessionKey( '' );
			
			expect( sanitized ).toBe( '' );
		} );

		it( 'should handle null', () => {
			const sanitized = sanitizeSessionKey( null );
			
			expect( sanitized ).toBe( '' );
		} );

		it( 'should handle undefined', () => {
			const sanitized = sanitizeSessionKey( undefined );
			
			expect( sanitized ).toBe( '' );
		} );

		it( 'should handle non-string values', () => {
			const sanitized = sanitizeSessionKey( 123 );
			
			expect( sanitized ).toBe( '' );
		} );

		it( 'should match PHP normalization behavior', () => {
			// Test cases that match PHP preg_replace('/[^a-zA-Z0-9_-]/', '', $value)
			const testCases = [
				{ input: 'test-session!@#$%123', expected: 'test-session123' },
				{ input: 'wp-mcp-ai-session_12345', expected: 'wp-mcp-ai-session_12345' },
				{ input: 'session\t\n\r key', expected: 'sessionkey' },
				{ input: '  trimmed  ', expected: 'trimmed' },
			];

			testCases.forEach( ( testCase ) => {
				const sanitized = sanitizeSessionKey( testCase.input );
				expect( sanitized ).toBe( testCase.expected );
			} );
		} );

		it( 'should handle session keys with mixed invalid characters', () => {
			const messyKey = '\tsession-key_123\n with\ttabs\rand spaces!@#';
			const sanitized = sanitizeSessionKey( messyKey );
			
			expect( sanitized ).toBe( 'session-key_123withtabsandspaces' );
			expect( sanitized ).not.toContain( '\t' );
			expect( sanitized ).not.toContain( '\n' );
			expect( sanitized ).not.toContain( '\r' );
			expect( sanitized ).not.toContain( ' ' );
		} );
	} );

	describe( 'Integration with storage', () => {
		beforeEach( () => {
			// Clear localStorage before each test
			localStorage.clear();
		} );

		it( 'should sanitize session keys when saving to storage', () => {
			const state = {
				config: {
					sessionKey: 'session\tkey\twith\ttabs',
					assistantId: 'test-assistant',
				},
				conversation: [],
				originalAssistantId: 'test-assistant',
			};

			// Force immediate save by passing the immediate option
			window.wpMcpAiChatStorage.saveConversationToStorage( state, { immediate: true } );

			const storageKey = window.wpMcpAiChatStorage.getStorageKey( 'test-assistant' );
			const stored = localStorage.getItem( storageKey );
			const data = JSON.parse( stored );

			expect( data.sessionKey ).toBe( 'sessionkeywithtabs' );
			expect( data.sessionKey ).not.toContain( '\t' );
		} );

		it( 'should sanitize session keys when loading from storage', () => {
			const storageKey = window.wpMcpAiChatStorage.getStorageKey( 'test-assistant' );
			
			// Manually insert data with tab in sessionKey
			const dirtyData = {
				conversation: [],
				sessionKey: 'session\tkey',
				timestamp: Date.now(),
				assistantId: 'test-assistant',
			};
			localStorage.setItem( storageKey, JSON.stringify( dirtyData ) );

			const state = {
				config: { assistantId: 'test-assistant' },
				originalAssistantId: 'test-assistant',
			};

			const loaded = window.wpMcpAiChatStorage.loadConversationFromStorage( state );

			expect( loaded.sessionKey ).toBe( 'sessionkey' );
			expect( loaded.sessionKey ).not.toContain( '\t' );
		} );
	} );
} );
