/**
 * Tests for the chat storage service ↔ storage worker wiring (proposal 032).
 *
 * Loads chat-storage-service.js directly (same pattern as
 * session-key-sanitization.test.js) and asserts that
 * saveConversationToStorage() routes large writes through
 * window.wpMcpAiStorageUtil.stringifyJSON() while small writes,
 * unload flushes (immediate), and the kill-switch threshold stay on
 * the main thread.
 *
 * @package WP_MCP_AI
 */

const fs = require( 'fs' );
const path = require( 'path' );

const storageServiceCode = fs.readFileSync(
	path.join( __dirname, '../../assets/js/chat-storage-service.js' ),
	'utf8'
);

const flushPromises = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

describe( 'Storage worker wiring', () => {
	let saveConversationToStorage;
	let stringifyJSON;

	beforeEach( () => {
		global.window = global;
		// Disable the debounce path so performSave() runs directly.
		global.wpMcpAiChatDebugMode = true;
		global.wpMcpAiChat = { storageWorkerThreshold: 100 };

		stringifyJSON = jest.fn( () => Promise.resolve( '{"offloaded":true}' ) );
		global.wpMcpAiStorageUtil = { stringifyJSON };

		eval( storageServiceCode );
		saveConversationToStorage = window.wpMcpAiChatStorage.saveConversationToStorage;

		localStorage.clear();
	} );

	afterEach( () => {
		delete global.window.wpMcpAiChatStorage;
		delete global.wpMcpAiStorageUtil;
		delete global.wpMcpAiChat;
		delete global.wpMcpAiChatDebugMode;
		delete global.window;
		localStorage.clear();
	} );

	function makeState( message ) {
		return {
			config: { assistantId: '42', sessionKey: 'abc' },
			conversation: [ { role: 'user', content: message || 'hello' } ],
		};
	}

	it( 'writes small conversations synchronously without the worker', () => {
		const result = saveConversationToStorage( makeState() );

		expect( stringifyJSON ).not.toHaveBeenCalled();
		expect( result.success ).toBe( true );
		expect( result.offloaded ).toBeUndefined();

		const stored = JSON.parse( localStorage.getItem( 'wp_mcp_ai_chat_42' ) );
		expect( stored.conversation ).toHaveLength( 1 );
	} );

	it( 'offloads large conversation writes to the worker', async () => {
		const result = saveConversationToStorage( makeState( 'x'.repeat( 500 ) ) );

		expect( result ).toEqual( { success: true, offloaded: true } );
		expect( stringifyJSON ).toHaveBeenCalledTimes( 1 );

		const [ data, threshold ] = stringifyJSON.mock.calls[ 0 ];
		expect( threshold ).toBe( 100 );
		expect( data.assistantId ).toBe( '42' );

		await flushPromises();
		expect( localStorage.getItem( 'wp_mcp_ai_chat_42' ) ).toBe( '{"offloaded":true}' );
	} );

	it( 'keeps unload flushes (immediate) on the main thread', async () => {
		const result = saveConversationToStorage( makeState( 'x'.repeat( 500 ) ), { immediate: true } );

		expect( stringifyJSON ).not.toHaveBeenCalled();
		expect( result.success ).toBe( true );
		expect( result.offloaded ).toBeUndefined();

		const stored = JSON.parse( localStorage.getItem( 'wp_mcp_ai_chat_42' ) );
		expect( stored.conversation[ 0 ].content ).toHaveLength( 500 );
	} );

	it( 'disables offload when the threshold is zero (kill switch)', () => {
		global.wpMcpAiChat.storageWorkerThreshold = 0;

		const result = saveConversationToStorage( makeState( 'x'.repeat( 500 ) ) );

		expect( stringifyJSON ).not.toHaveBeenCalled();
		expect( result.success ).toBe( true );
		expect( localStorage.getItem( 'wp_mcp_ai_chat_42' ) ).toBeTruthy();
	} );

	it( 'falls back to the main thread when the util is absent', () => {
		delete global.wpMcpAiStorageUtil;

		const result = saveConversationToStorage( makeState( 'x'.repeat( 500 ) ) );

		expect( result.success ).toBe( true );
		expect( result.offloaded ).toBeUndefined();
		expect( localStorage.getItem( 'wp_mcp_ai_chat_42' ) ).toBeTruthy();
	} );

	it( 'falls back to the main thread when the worker rejects', async () => {
		global.wpMcpAiStorageUtil = { stringifyJSON: jest.fn( () => Promise.reject( new Error( 'boom' ) ) ) };

		const result = saveConversationToStorage( makeState( 'x'.repeat( 500 ) ) );

		expect( result ).toEqual( { success: true, offloaded: true } );

		await flushPromises();
		const stored = JSON.parse( localStorage.getItem( 'wp_mcp_ai_chat_42' ) );
		expect( stored.assistantId ).toBe( '42' );
	} );

	it( 'preserves the quota-exceeded retry on the offloaded path', async () => {
		// Seed an expired entry so cleanupOldStorageEntries() removes one and
		// the quota branch retries the write.
		localStorage.setItem(
			'wp_mcp_ai_chat_old',
			JSON.stringify( { timestamp: 1, assistantId: 'old' } )
		);

		const originalSetItem = localStorage.setItem.bind( localStorage );
		let calls = 0;
		localStorage.setItem = jest.fn( ( ...args ) => {
			calls++;
			if ( calls === 1 ) {
				const error = new Error( 'QuotaExceededError' );
				error.name = 'QuotaExceededError';
				throw error;
			}
			originalSetItem( ...args );
		} );

		const result = saveConversationToStorage( makeState( 'x'.repeat( 500 ) ) );

		expect( result ).toEqual( { success: true, offloaded: true } );

		await flushPromises();
		expect( localStorage.setItem ).toHaveBeenCalledTimes( 2 );
		localStorage.setItem = originalSetItem;
	} );
} );
