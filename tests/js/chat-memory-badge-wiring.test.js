/**
 * Regression test for the memory-badge auto-wire in chat.js.
 *
 * The 🧠 Memory badge decorator is shipped by chat-memory-drawer.js. This test
 * pins the call-site that wires the decorator into the assistant branch of
 * appendMessage(), so that future refactors of chat.js can't silently drop it.
 *
 * @package WP_MCP_AI
 */

const fs = require( 'fs' );
const path = require( 'path' );

const chatJsSource = fs.readFileSync(
	path.join( __dirname, '../../assets/js/chat.js' ),
	'utf8'
);

describe( 'chat.js — memory badge auto-wire', () => {
	test( 'appendMessage() invokes wpMcpAiChatMemoryDrawer.decorateMessageWithBadge', () => {
		expect( chatJsSource ).toMatch(
			/wpMcpAiChatMemoryDrawer\.decorateMessageWithBadge\(\s*entry\s*,\s*payload\.tool_calls\s*\)/
		);
	} );

	test( 'auto-wire is guarded against drawer-disabled environments', () => {
		// Must check both window.wpMcpAiChatMemoryDrawer and the function before invoking.
		expect( chatJsSource ).toMatch( /window\.wpMcpAiChatMemoryDrawer\b/ );
		expect( chatJsSource ).toMatch(
			/typeof window\.wpMcpAiChatMemoryDrawer\.decorateMessageWithBadge\s*===\s*['"]function['"]/
		);
	} );

	test( 'auto-wire only fires for messages that have tool_calls', () => {
		// The condition must check Array.isArray(payload.tool_calls) and length.
		const block = chatJsSource.match(
			/Array\.isArray\(\s*payload\.tool_calls\s*\)\s*&&\s*payload\.tool_calls\.length[\s\S]{0,400}decorateMessageWithBadge/
		);
		expect( block ).not.toBeNull();
	} );

	test( 'live agentic-loop path forwards message.tool_calls onto assistantDisplay', () => {
		// The decorator only sees tool_calls if they live on the appendMessage payload.
		expect( chatJsSource ).toMatch(
			/assistantDisplay\.tool_calls\s*=\s*message\.tool_calls/
		);
	} );

	test( 'restore path forwards display.tool_calls onto the assistant payload', () => {
		// Pre-existing wiring; pinned here to prevent regressions of the restore flow.
		expect( chatJsSource ).toMatch(
			/assistantPayload\.tool_calls\s*=\s*display\.tool_calls/
		);
	} );

	test( 'badge errors do not break message rendering', () => {
		// The auto-wire must be wrapped in try/catch.
		const guarded = chatJsSource.match(
			/try\s*{[\s\S]{0,200}decorateMessageWithBadge[\s\S]{0,200}catch\s*\(\s*\w+\s*\)/
		);
		expect( guarded ).not.toBeNull();
	} );
} );
