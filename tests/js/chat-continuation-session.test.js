/**
 * Jest tests for the async-chat-continuation functions in chat.js.
 *
 * Tests cover:
 *  - resolveChatSessionId  — derives or mints a session ID
 *  - handleChatResumedFrame — appends a continuation message and deduplicates
 *  - handleChatStatusFrame  — surfaces a toast for non-success frames
 *
 * Because these helpers are inner functions of the chat.js IIFE, the tests
 * work with the source text directly (the same approach used by other test
 * files in tests/js/).
 *
 * @package WP_MCP_AI
 */

const fs   = require( 'fs' );
const path = require( 'path' );

const chatJsSource = fs.readFileSync(
	path.join( __dirname, '../../assets/js/chat.js' ),
	'utf8'
);

// ── resolveChatSessionId ───────────────────────────────────────────────────────

describe( 'chat.js — resolveChatSessionId', () => {
	test( 'function is defined in chat.js', () => {
		expect( chatJsSource ).toMatch( /function resolveChatSessionId\s*\(/ );
	} );

	test( 'returns sessionKey when it is a non-empty string on config', () => {
		expect( chatJsSource ).toMatch(
			/config\.sessionKey\s*&&\s*typeof config\.sessionKey\s*===\s*['"]string['"]/
		);
	} );

	test( 'uses localStorage key scoped to assistantId', () => {
		expect( chatJsSource ).toMatch( /wp_mcp_ai_chat_session_id_/ );
	} );

	test( 'mints a session ID starting with "cs" when nothing is stored', () => {
		expect( chatJsSource ).toMatch( /'cs'\s*\+\s*Date\.now\(\)\.toString\(36\)/ );
	} );

	test( 'validates stored session ID against a safe character pattern', () => {
		// Must reject session IDs outside [a-zA-Z0-9_-] or longer than 64 chars.
		expect( chatJsSource ).toMatch(
			/\/\^?\[a-zA-Z0-9_-\].*\{1,64\}.*\$\//
		);
	} );

	test( 'persists minted ID to localStorage', () => {
		expect( chatJsSource ).toMatch(
			/localStorage\.setItem\s*\(\s*lsKey\s*,\s*minted\s*\)/
		);
	} );
} );

// ── handleChatResumedFrame ─────────────────────────────────────────────────────

describe( 'chat.js — handleChatResumedFrame', () => {
	test( 'function is defined in chat.js', () => {
		expect( chatJsSource ).toMatch( /function handleChatResumedFrame\s*\(/ );
	} );

	test( 'returns early when frameData is missing', () => {
		// The function guards both state and frameData.
		expect( chatJsSource ).toMatch(
			/if\s*\(\s*!state\s*\|\|\s*!frameData\s*\)\s*\{[\s\S]{0,30}return/
		);
	} );

	test( 'returns early when message is empty', () => {
		// After extracting message, bail if falsy.
		const block = chatJsSource.match(
			/handleChatResumedFrame[\s\S]{0,400}if\s*\(\s*!message\s*\)\s*\{[\s\S]{0,30}return/
		);
		expect( block ).not.toBeNull();
	} );

	test( 'deduplicates via _continuationToolCallId', () => {
		expect( chatJsSource ).toMatch( /_continuationToolCallId\s*===\s*toolCallId/ );
	} );

	test( 'pushes an assistant message with _isContinuation flag', () => {
		expect( chatJsSource ).toMatch( /_isContinuation\s*:\s*true/ );
	} );

	test( 'pushes assistant message onto state.conversation', () => {
		expect( chatJsSource ).toMatch(
			/state\.conversation\.push\s*\(\s*assistantMsg\s*\)/
		);
	} );

	test( 'calls showJobToast with completed type', () => {
		// The toast is fired inside a showJobToast type-guard near the end of
		// handleChatResumedFrame (≈1500 chars from fn definition).
		const block = chatJsSource.match(
			/function handleChatResumedFrame[\s\S]{0,1600}showJobToast[\s\S]{0,50}'completed'/
		);
		expect( block ).not.toBeNull();
	} );

	test( 'preserves job_id on the assistant message', () => {
		expect( chatJsSource ).toMatch( /_jobId\s*:\s*frameData\.job_id\s*\|\|\s*null/ );
	} );
} );

// ── handleChatStatusFrame ──────────────────────────────────────────────────────

describe( 'chat.js — handleChatStatusFrame', () => {
	test( 'function is defined in chat.js', () => {
		expect( chatJsSource ).toMatch( /function handleChatStatusFrame\s*\(/ );
	} );

	test( 'returns early when state or frameData is missing', () => {
		const block = chatJsSource.match(
			/handleChatStatusFrame[\s\S]{0,200}if\s*\(\s*!state\s*\|\|\s*!frameData\s*\)/
		);
		expect( block ).not.toBeNull();
	} );

	test( 'maps chat:error eventName to a "failed" toast type', () => {
		expect( chatJsSource ).toMatch(
			/['"]chat:error['"]\s*===\s*eventName[\s\S]{0,30}['"]failed['"]/
		);
	} );

	test( 'reads message from frameData.message with frameData.error as fallback', () => {
		expect( chatJsSource ).toMatch( /frameData\.message\s*\|\|\s*frameData\.error/ );
	} );

	test( 'delegates to showJobToast when available', () => {
		const block = chatJsSource.match(
			/handleChatStatusFrame[\s\S]{0,400}typeof showJobToast\s*===\s*['"]function['"]/
		);
		expect( block ).not.toBeNull();
	} );
} );

// ── initChatSessionStream ──────────────────────────────────────────────────────

describe( 'chat.js — initChatSessionStream', () => {
	test( 'function is defined in chat.js', () => {
		expect( chatJsSource ).toMatch( /function initChatSessionStream\s*\(/ );
	} );

	test( 'bails early when sessionStreamEndpoint is not set on config', () => {
		expect( chatJsSource ).toMatch(
			/if\s*\(\s*!config\s*\|\|\s*!config\.sessionStreamEndpoint\s*\)/
		);
	} );

	test( 'interpolates session_id into the endpoint URL', () => {
		expect( chatJsSource ).toMatch(
			/sessionStreamEndpoint\.replace\s*\(\s*'\{session_id\}'\s*,\s*encodeURIComponent\s*\(\s*sessionId\s*\)\s*\)/
		);
	} );

	test( 'registers a chat:resumed EventSource listener', () => {
		expect( chatJsSource ).toMatch(
			/addEventListener\s*\(\s*['"]chat:resumed['"]/
		);
	} );

	test( 'registers a chat:tool_result EventSource listener', () => {
		expect( chatJsSource ).toMatch(
			/addEventListener\s*\(\s*['"]chat:tool_result['"]/
		);
	} );

	test( 'registers a chat:error EventSource listener', () => {
		expect( chatJsSource ).toMatch(
			/addEventListener\s*\(\s*['"]chat:error['"]/
		);
	} );

	test( 'reconnects on visibilitychange when page becomes visible', () => {
		expect( chatJsSource ).toMatch(
			/visibilitychange[\s\S]{0,200}visibilityState/
		);
	} );
} );

// ── Feature-gate wiring ────────────────────────────────────────────────────────

describe( 'chat.js — asyncChatContinuation feature gate', () => {
	test( 'initChatSessionStream is called only when asyncChatContinuation is true', () => {
		expect( chatJsSource ).toMatch(
			/config\.asyncChatContinuation\s*&&\s*(?:state\.config|config)\.sessionStreamEndpoint/
		);
	} );

	test( 'resolveChatSessionId is called before initChatSessionStream', () => {
		const block = chatJsSource.match(
			/resolveChatSessionId\s*\([\s\S]{0,50}initChatSessionStream\s*\(/
		);
		expect( block ).not.toBeNull();
	} );
} );
