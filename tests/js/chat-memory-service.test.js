/**
 * Tests for chat-memory-service.js
 *
 * @package WP_MCP_AI
 */

const fs = require( 'fs' );
const path = require( 'path' );

// Load the service file
const serviceCode = fs.readFileSync(
	path.join( __dirname, '../../assets/js/chat-memory-service.js' ),
	'utf8'
);

describe( 'chat-memory-service', () => {
	let memory;
	const ENDPOINTS = {
		preferences: 'https://example.test/wp-json/mcp-ai/v1/chat-memory/preferences',
		wakeUp: 'https://example.test/wp-json/mcp-ai/v1/chat-memory/wake-up',
		recall: 'https://example.test/wp-json/mcp-ai/v1/chat-memory/recall',
		store: 'https://example.test/wp-json/mcp-ai/v1/chat-memory/store',
		audit: 'https://example.test/wp-json/mcp-ai/v1/chat-memory/audit',
		sessionBase: 'https://example.test/wp-json/mcp-ai/v1/chat-memory/sessions/',
		itemBase: 'https://example.test/wp-json/mcp-ai/v1/chat-memory/',
	};

	beforeEach( () => {
		// Reset window.wpMcpAiChat
		delete window.wpMcpAiChat;
		delete window.wpMcpAiChatMemory;
		// Re-eval the service so it re-installs against the fresh window
		// eslint-disable-next-line no-eval
		eval( serviceCode );
		memory = window.wpMcpAiChatMemory;
	} );

	test( 'isAvailable() is false when localized config is missing', () => {
		expect( memory.isAvailable() ).toBe( false );
	} );

	test( 'isAvailable() is true once endpoints are localized', () => {
		window.wpMcpAiChat = { nonce: 'abc', memoryEndpoints: ENDPOINTS };
		expect( memory.isAvailable() ).toBe( true );
	} );

	test( 'wakeUp() rejects with disabled error when service is not available', async () => {
		await expect( memory.wakeUp( { agentId: 1 } ) ).rejects.toMatchObject( {
			code: 'chat_memory_disabled',
		} );
	} );

	test( 'recall() builds GET query string and sends nonce header', async () => {
		window.wpMcpAiChat = { nonce: 'abc', memoryEndpoints: ENDPOINTS };
		const fetchMock = jest.fn().mockResolvedValue( {
			ok: true,
			json: () => Promise.resolve( { contexts: [] } ),
		} );
		window.fetch = fetchMock;

		await memory.recall( 'hello world', { agentId: 42, wing: 'projectA', limit: 5 } );

		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
		const [ url, options ] = fetchMock.mock.calls[ 0 ];
		expect( url ).toMatch( /^https:\/\/example\.test\/wp-json\/mcp-ai\/v1\/chat-memory\/recall\?/ );
		expect( url ).toContain( 'agent_id=42' );
		expect( url ).toContain( 'wing=projectA' );
		expect( url ).toContain( 'limit=5' );
		expect( url ).toContain( 'query=hello+world' );
		expect( options.method ).toBe( 'GET' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'abc' );
	} );

	test( 'store() POSTs JSON body with verbatim default true', async () => {
		window.wpMcpAiChat = { nonce: 'abc', memoryEndpoints: ENDPOINTS };
		const fetchMock = jest.fn().mockResolvedValue( {
			ok: true,
			json: () => Promise.resolve( { success: true } ),
		} );
		window.fetch = fetchMock;

		await memory.store( {
			agentId: 7,
			content: 'remember this',
			tags: [ 'todo' ],
		} );

		const [ url, options ] = fetchMock.mock.calls[ 0 ];
		expect( url ).toBe( ENDPOINTS.store );
		expect( options.method ).toBe( 'POST' );
		expect( options.headers[ 'Content-Type' ] ).toBe( 'application/json' );

		const body = JSON.parse( options.body );
		expect( body.agent_id ).toBe( 7 );
		expect( body.content ).toBe( 'remember this' );
		expect( body.verbatim ).toBe( true );
		expect( body.summarize ).toBe( false );
		expect( body.tags ).toEqual( [ 'todo' ] );
	} );

	test( 'store() forwards summarize:true when caller opts in (G6 Phase 2)', async () => {
		window.wpMcpAiChat = { nonce: 'abc', memoryEndpoints: ENDPOINTS };
		const fetchMock = jest.fn().mockResolvedValue( {
			ok: true,
			json: () => Promise.resolve( { success: true } ),
		} );
		window.fetch = fetchMock;

		await memory.store( {
			agentId: 7,
			content: 'a long transcript that should be summarised',
			summarize: true,
		} );

		const [ , options ] = fetchMock.mock.calls[ 0 ];
		const body = JSON.parse( options.body );
		expect( body.summarize ).toBe( true );
	} );

	test( 'remove() DELETEs the right URL', async () => {
		window.wpMcpAiChat = { nonce: 'abc', memoryEndpoints: ENDPOINTS };
		const fetchMock = jest.fn().mockResolvedValue( {
			ok: true,
			json: () => Promise.resolve( {} ),
		} );
		window.fetch = fetchMock;

		await memory.remove( 'ctx_abc123', { agentId: 7 } );

		const [ url, options ] = fetchMock.mock.calls[ 0 ];
		expect( url ).toContain( '/chat-memory/ctx_abc123' );
		expect( url ).toContain( 'agent_id=7' );
		expect( options.method ).toBe( 'DELETE' );
	} );

	test( 'isMemoryRetrievalResult() detects standard shapes', () => {
		expect( memory.isMemoryRetrievalResult( { contexts: [ {} ] } ) ).toBe( true );
		expect( memory.isMemoryRetrievalResult( { results: [] } ) ).toBe( true );
		expect( memory.isMemoryRetrievalResult( { memories: [ {} ] } ) ).toBe( true );
		expect( memory.isMemoryRetrievalResult( {} ) ).toBe( false );
		expect( memory.isMemoryRetrievalResult( null ) ).toBe( false );
	} );

	test( 'request() rejects on non-OK response with structured error', async () => {
		window.wpMcpAiChat = { nonce: 'abc', memoryEndpoints: ENDPOINTS };
		window.fetch = jest.fn().mockResolvedValue( {
			ok: false,
			status: 403,
			json: () => Promise.resolve( { code: 'rest_forbidden', message: 'no' } ),
		} );

		await expect( memory.recall( 'x', { agentId: 1 } ) ).rejects.toMatchObject( {
			status: 403,
			message: 'no',
		} );
	} );

	test( 'audit() rejects with disabled error when service is not available', async () => {
		await expect( memory.audit( { agentId: 1 } ) ).rejects.toMatchObject( {
			code: 'chat_memory_disabled',
		} );
	} );

	test( 'audit() rejects with disabled error when audit endpoint is missing', async () => {
		// Older sites might have a stale localized config without the new endpoint.
		const stale = Object.assign( {}, ENDPOINTS );
		delete stale.audit;
		window.wpMcpAiChat = { nonce: 'abc', memoryEndpoints: stale };
		await expect( memory.audit( { agentId: 1 } ) ).rejects.toMatchObject( {
			code: 'chat_memory_disabled',
		} );
	} );

	test( 'audit() builds GET query string with agent_id, limit, action_type and sends nonce', async () => {
		window.wpMcpAiChat = { nonce: 'abc', memoryEndpoints: ENDPOINTS };
		const fetchMock = jest.fn().mockResolvedValue( {
			ok: true,
			json: () => Promise.resolve( { entries: [] } ),
		} );
		window.fetch = fetchMock;

		await memory.audit( { agentId: 42, limit: 25, actionType: 'create' } );

		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
		const [ url, options ] = fetchMock.mock.calls[ 0 ];
		expect( url ).toMatch( /^https:\/\/example\.test\/wp-json\/mcp-ai\/v1\/chat-memory\/audit\?/ );
		expect( url ).toContain( 'agent_id=42' );
		expect( url ).toContain( 'limit=25' );
		expect( url ).toContain( 'action_type=create' );
		expect( options.method ).toBe( 'GET' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'abc' );
	} );

	test( 'audit() omits action_type when not provided', async () => {
		window.wpMcpAiChat = { nonce: 'abc', memoryEndpoints: ENDPOINTS };
		const fetchMock = jest.fn().mockResolvedValue( {
			ok: true,
			json: () => Promise.resolve( { entries: [] } ),
		} );
		window.fetch = fetchMock;

		await memory.audit( { agentId: 42 } );

		const [ url ] = fetchMock.mock.calls[ 0 ];
		expect( url ).not.toContain( 'action_type' );
	} );

	test( 'sessionReplay() builds GET query string with limit and sends nonce', async () => {
		window.wpMcpAiChat = { nonce: 'abc', memoryEndpoints: ENDPOINTS };
		const fetchMock = jest.fn().mockResolvedValue( {
			ok: true,
			json: () => Promise.resolve( { events: [] } ),
		} );
		window.fetch = fetchMock;

		await memory.sessionReplay( 'sess_123', { limit: 50 } );

		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
		const [ url, options ] = fetchMock.mock.calls[ 0 ];
		expect( url ).toBe( 'https://example.test/wp-json/mcp-ai/v1/chat-memory/sessions/sess_123?limit=50' );
		expect( options.method ).toBe( 'GET' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'abc' );
	} );

	test( 'sessionReplay() rejects with disabled error when sessionBase is missing', async () => {
		const stale = Object.assign( {}, ENDPOINTS );
		delete stale.sessionBase;
		window.wpMcpAiChat = { nonce: 'abc', memoryEndpoints: stale };
		await expect( memory.sessionReplay( 'sess_123', { limit: 10 } ) ).rejects.toMatchObject( {
			code: 'chat_memory_disabled',
		} );
	} );
} );
