/**
 * Tests for chat-memory-drawer.js (Phase 3 — drawer + badge + toast).
 *
 * @package WP_MCP_AI
 */

const fs = require( 'fs' );
const path = require( 'path' );

const drawerCode = fs.readFileSync(
	path.join( __dirname, '../../assets/js/chat-memory-drawer.js' ),
	'utf8'
);

/**
 * Install a stub chat-memory bridge with controllable promise resolutions.
 */
function installMemoryStub( overrides ) {
	const calls = { recall: [], update: [], remove: [], store: [] };
	const stub = {
		isAvailable: () => true,
		wakeUp: jest.fn().mockResolvedValue( null ),
		recall: jest.fn( ( query, filters ) => {
			calls.recall.push( { query, filters } );
			return Promise.resolve( {
				contexts: [
					{ context_id: 'm-1', title: 'First', content: 'Hello world', tags: [ 'a' ], tier: 'recall' },
					{ context_id: 'm-2', title: 'Second', content: 'Another note', tags: [], tier: 'core' }
				]
			} );
		} ),
		store: jest.fn().mockResolvedValue( { success: true } ),
		update: jest.fn( ( id, patch ) => {
			calls.update.push( { id, patch } );
			return Promise.resolve( { success: true } );
		} ),
		remove: jest.fn( id => {
			calls.remove.push( id );
			return Promise.resolve( { success: true } );
		} ),
		isMemoryRetrievalResult: () => false,
		getPreferences: jest.fn(),
		setPreferences: jest.fn()
	};
	if ( overrides ) {
		Object.assign( stub, overrides );
	}
	window.wpMcpAiChatMemory = stub;
	return { stub, calls };
}

/**
 * Build a minimal chat container that the drawer expects.
 */
function makeContainer() {
	document.body.innerHTML = `
		<div class="wp-mcp-ai-chat__container" data-wp-mcp-ai-chat data-wp-mcp-ai-initialized="true">
			<div class="wp-mcp-ai-chat__transcript-controls"></div>
			<div class="wp-mcp-ai-chat__messages"></div>
		</div>
	`;
	const container = document.querySelector( '[data-wp-mcp-ai-chat]' );
	container.__wpMcpAiChatState = {
		config: {
			embeddedAssistantId: 7,
			memoryWing: 'wing-a',
			memoryRoom: ''
		}
	};
	return container;
}

describe( 'chat-memory-drawer', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
		delete window.wpMcpAiChatMemory;
		delete window.wpMcpAiChatMemoryDrawer;
		window.confirm = jest.fn( () => true );
		// eslint-disable-next-line no-eval
		eval( drawerCode );
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	test( 'public API surface is installed on window', () => {
		expect( typeof window.wpMcpAiChatMemoryDrawer ).toBe( 'object' );
		expect( typeof window.wpMcpAiChatMemoryDrawer.attach ).toBe( 'function' );
		expect( typeof window.wpMcpAiChatMemoryDrawer.attachAll ).toBe( 'function' );
		expect( typeof window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge ).toBe( 'function' );
		expect( typeof window.wpMcpAiChatMemoryDrawer.announceToast ).toBe( 'function' );
		expect( typeof window.wpMcpAiChatMemoryDrawer.ensureToastRegion ).toBe( 'function' );
		expect( typeof window.wpMcpAiChatMemoryDrawer.isAvailable ).toBe( 'function' );
	} );

	test( 'isAvailable() reflects the bridge stub', () => {
		expect( window.wpMcpAiChatMemoryDrawer.isAvailable() ).toBe( false );
		installMemoryStub();
		expect( window.wpMcpAiChatMemoryDrawer.isAvailable() ).toBe( true );
	} );

	test( 'attach() is a no-op when the bridge is not available', () => {
		const container = makeContainer();
		window.wpMcpAiChatMemoryDrawer.attach( container );
		expect( container.querySelector( '.wp-mcp-ai-memory-drawer' ) ).toBeNull();
		expect( container.querySelector( '.wp-mcp-ai-memory-toggle' ) ).toBeNull();
	} );

	test( 'attach() injects the toggle and creates a hidden drawer', () => {
		installMemoryStub();
		const container = makeContainer();
		window.wpMcpAiChatMemoryDrawer.attach( container );
		const toggle = container.querySelector( '.wp-mcp-ai-memory-toggle' );
		const drawer = container.querySelector( '.wp-mcp-ai-memory-drawer' );
		expect( toggle ).not.toBeNull();
		expect( drawer ).not.toBeNull();
		expect( drawer.hidden ).toBe( true );
		expect( drawer.getAttribute( 'role' ) ).toBe( 'dialog' );
		expect( drawer.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	} );

	test( 'attach() is idempotent', () => {
		installMemoryStub();
		const container = makeContainer();
		window.wpMcpAiChatMemoryDrawer.attach( container );
		window.wpMcpAiChatMemoryDrawer.attach( container );
		expect( container.querySelectorAll( '.wp-mcp-ai-memory-drawer' ).length ).toBe( 1 );
		expect( container.querySelectorAll( '.wp-mcp-ai-memory-toggle' ).length ).toBe( 1 );
	} );

	test( 'opening the drawer triggers a recall() call with the active scope', async () => {
		const { stub } = installMemoryStub();
		const container = makeContainer();
		window.wpMcpAiChatMemoryDrawer.attach( container );

		const toggle = container.querySelector( '.wp-mcp-ai-memory-toggle' );
		toggle.click();

		// Drawer is open + flagged
		const drawer = container.querySelector( '.wp-mcp-ai-memory-drawer' );
		expect( drawer.hidden ).toBe( false );
		expect( drawer.classList.contains( 'is-open' ) ).toBe( true );
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'true' );

		// recall() called with agent + wing
		expect( stub.recall ).toHaveBeenCalledTimes( 1 );
		const [ , filters ] = stub.recall.mock.calls[ 0 ];
		expect( filters.agentId ).toBe( 7 );
		expect( filters.wing ).toBe( 'wing-a' );

		// Wait for the promise chain to flush, then assert items were rendered.
		await Promise.resolve();
		await Promise.resolve();
		const items = drawer.querySelectorAll( '.wp-mcp-ai-memory-item' );
		expect( items.length ).toBe( 2 );
		expect( items[ 0 ].getAttribute( 'data-context-id' ) ).toBe( 'm-1' );
	} );

	test( 'pressing Escape closes the drawer and restores focus to the toggle', () => {
		installMemoryStub();
		const container = makeContainer();
		window.wpMcpAiChatMemoryDrawer.attach( container );
		const toggle = container.querySelector( '.wp-mcp-ai-memory-toggle' );
		const drawer = container.querySelector( '.wp-mcp-ai-memory-drawer' );

		toggle.focus();
		toggle.click();
		expect( drawer.hidden ).toBe( false );

		const evt = new window.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } );
		drawer.dispatchEvent( evt );

		expect( drawer.hidden ).toBe( true );
		expect( drawer.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
		expect( document.activeElement ).toBe( toggle );
	} );

	test( 'delete button calls remove() and removes the row', async () => {
		const { stub } = installMemoryStub();
		const container = makeContainer();
		window.wpMcpAiChatMemoryDrawer.attach( container );
		container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
		await Promise.resolve();
		await Promise.resolve();

		const firstItem = container.querySelector( '.wp-mcp-ai-memory-item' );
		const deleteBtn = firstItem.querySelector( '.wp-mcp-ai-memory-item__delete' );
		deleteBtn.click();

		await Promise.resolve();
		await Promise.resolve();

		expect( stub.remove ).toHaveBeenCalledWith( 'm-1', expect.any( Object ) );
		expect( container.querySelector( '[data-context-id="m-1"]' ) ).toBeNull();
	} );

	test( 'edit button reveals an inline form and update() commits the change', async () => {
		const { stub } = installMemoryStub();
		const container = makeContainer();
		window.wpMcpAiChatMemoryDrawer.attach( container );
		container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
		await Promise.resolve();
		await Promise.resolve();

		const item = container.querySelector( '[data-context-id="m-1"]' );
		item.querySelector( '.wp-mcp-ai-memory-item__edit' ).click();

		const form = container.querySelector( '.wp-mcp-ai-memory-item__edit-form' );
		expect( form ).not.toBeNull();

		form.querySelector( '.wp-mcp-ai-memory-item__edit-title' ).value = 'New title';
		form.querySelector( '.wp-mcp-ai-memory-item__edit-content' ).value = 'New body';
		form.dispatchEvent( new window.Event( 'submit', { bubbles: true, cancelable: true } ) );

		await Promise.resolve();
		await Promise.resolve();

		expect( stub.update ).toHaveBeenCalledWith( 'm-1', expect.objectContaining( {
			title: 'New title',
			content: 'New body'
		} ) );
	} );

	test( 'scope tab persists wing/room into state.config and re-runs recall()', async () => {
		const { stub } = installMemoryStub();
		const container = makeContainer();
		window.wpMcpAiChatMemoryDrawer.attach( container );
		container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
		await Promise.resolve();

		const tabs = container.querySelectorAll( '.wp-mcp-ai-memory-drawer__tab' );
		// Second tab is "Scope".
		tabs[ 1 ].click();

		const wingInput = container.querySelector( '.wp-mcp-ai-memory-drawer__wing' );
		const roomInput = container.querySelector( '.wp-mcp-ai-memory-drawer__room' );
		wingInput.value = 'wing-b';
		roomInput.value = 'room-1';

		const form = container.querySelector( '.wp-mcp-ai-memory-drawer__scope-form' );
		form.dispatchEvent( new window.Event( 'submit', { bubbles: true, cancelable: true } ) );

		expect( container.__wpMcpAiChatState.config.memoryWing ).toBe( 'wing-b' );
		expect( container.__wpMcpAiChatState.config.memoryRoom ).toBe( 'room-1' );

		// recall has been called twice now (initial + scope-applied refresh).
		expect( stub.recall.mock.calls.length ).toBeGreaterThanOrEqual( 2 );
		const lastCall = stub.recall.mock.calls[ stub.recall.mock.calls.length - 1 ];
		expect( lastCall[ 1 ].wing ).toBe( 'wing-b' );
		expect( lastCall[ 1 ].room ).toBe( 'room-1' );
	} );

	test( 'announceToast() appends a toast to the live region', () => {
		window.wpMcpAiChatMemoryDrawer.announceToast( 'Memory stored', 'success' );
		const region = document.getElementById( 'wp-mcp-ai-memory-toasts' );
		expect( region ).not.toBeNull();
		expect( region.getAttribute( 'aria-live' ) ).toBe( 'polite' );
		const toast = region.querySelector( '.wp-mcp-ai-memory-toast' );
		expect( toast ).not.toBeNull();
		expect( toast.textContent ).toBe( 'Memory stored' );
		expect( toast.classList.contains( 'wp-mcp-ai-memory-toast--success' ) ).toBe( true );
	} );

	describe( 'audit tab', () => {
		test( 'switching to Audit lazy-loads via memoryService.audit() and renders entries', async () => {
			const auditMock = jest.fn().mockResolvedValue( {
				entries: [
					{ timestamp: '2026-05-01T12:00:00Z', action: 'create', context_id: 'ctx_1' },
					{ timestamp: '2026-05-01T13:00:00Z', action: 'update', context_id: 'ctx_2' }
				]
			} );
			installMemoryStub( { audit: auditMock } );
			const container = makeContainer();
			window.wpMcpAiChatMemoryDrawer.attach( container );

			container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
			const drawer = container.querySelector( '.wp-mcp-ai-memory-drawer' );

			// Activating the Audit tab triggers the lazy load.
			const auditTab = drawer.querySelector( '[data-testid="wp-mcp-ai-memory-audit-tab"]' );
			expect( auditTab ).not.toBeNull();
			auditTab.click();

			expect( auditMock ).toHaveBeenCalledTimes( 1 );
			const [ args ] = auditMock.mock.calls[ 0 ];
			expect( args.agentId ).toBe( 7 );
			expect( args.limit ).toBe( 50 );

			// Audit panel becomes visible.
			const panel = drawer.querySelector( '[data-testid="wp-mcp-ai-memory-audit-panel"]' );
			expect( panel.hidden ).toBe( false );

			// Wait for the promise chain.
			await Promise.resolve();
			await Promise.resolve();
			const items = drawer.querySelectorAll( '.wp-mcp-ai-memory-drawer__audit-item' );
			expect( items.length ).toBe( 2 );
			expect( items[ 0 ].getAttribute( 'data-action' ) ).toBe( 'create' );
		} );

		test( 'audit tab is lazy — no audit() call until the tab is activated', () => {
			const auditMock = jest.fn().mockResolvedValue( { entries: [] } );
			installMemoryStub( { audit: auditMock } );
			const container = makeContainer();
			window.wpMcpAiChatMemoryDrawer.attach( container );
			container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
			expect( auditMock ).not.toHaveBeenCalled();
		} );

		test( 'changing the action-type filter re-fires audit() with the action_type', async () => {
			const auditMock = jest.fn().mockResolvedValue( { entries: [] } );
			installMemoryStub( { audit: auditMock } );
			const container = makeContainer();
			window.wpMcpAiChatMemoryDrawer.attach( container );
			container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
			const drawer = container.querySelector( '.wp-mcp-ai-memory-drawer' );
			drawer.querySelector( '[data-testid="wp-mcp-ai-memory-audit-tab"]' ).click();
			await Promise.resolve();

			const filter = drawer.querySelector( '[data-testid="wp-mcp-ai-memory-audit-filter"]' );
			filter.value = 'delete';
			filter.dispatchEvent( new Event( 'change' ) );

			expect( auditMock ).toHaveBeenCalledTimes( 2 );
			const [ args ] = auditMock.mock.calls[ 1 ];
			expect( args.actionType ).toBe( 'delete' );
		} );

		test( 'empty audit response shows the empty state', async () => {
			installMemoryStub( { audit: jest.fn().mockResolvedValue( { entries: [] } ) } );
			const container = makeContainer();
			window.wpMcpAiChatMemoryDrawer.attach( container );
			container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
			const drawer = container.querySelector( '.wp-mcp-ai-memory-drawer' );
			drawer.querySelector( '[data-testid="wp-mcp-ai-memory-audit-tab"]' ).click();

			await Promise.resolve();
			await Promise.resolve();

			const panel = drawer.querySelector( '[data-testid="wp-mcp-ai-memory-audit-panel"]' );
			const empty = panel.querySelector( '.wp-mcp-ai-memory-drawer__empty' );
			expect( empty.hidden ).toBe( false );
		} );

		test( 'audit() rejection surfaces the error state', async () => {
			installMemoryStub( {
				audit: jest.fn().mockRejectedValue( { message: 'forbidden' } )
			} );
			const container = makeContainer();
			window.wpMcpAiChatMemoryDrawer.attach( container );
			container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
			const drawer = container.querySelector( '.wp-mcp-ai-memory-drawer' );
			drawer.querySelector( '[data-testid="wp-mcp-ai-memory-audit-tab"]' ).click();

			await Promise.resolve();
			await Promise.resolve();
			await Promise.resolve();

			const panel = drawer.querySelector( '[data-testid="wp-mcp-ai-memory-audit-panel"]' );
			const error = panel.querySelector( '.wp-mcp-ai-memory-drawer__error' );
			expect( error.hidden ).toBe( false );
			expect( error.textContent ).toContain( 'forbidden' );
		} );
	} );

	describe( 'decorateMessageWithBadge', () => {
		test( 'attaches the 🧠 badge when a tool call retrieved memory', () => {
			const bubble = document.createElement( 'div' );
			document.body.appendChild( bubble );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( bubble, [
				{ tool: 'recall_memory' }
			] );
			expect( bubble.querySelector( '.wp-mcp-ai-memory-badge' ) ).not.toBeNull();
		} );

		test( 'no-op when no memory tools were called', () => {
			const bubble = document.createElement( 'div' );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( bubble, [
				{ tool: 'create_post' }
			] );
			expect( bubble.querySelector( '.wp-mcp-ai-memory-badge' ) ).toBeNull();
		} );

		test( 'is idempotent', () => {
			const bubble = document.createElement( 'div' );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( bubble, [ { tool: 'recall_memory' } ] );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( bubble, [ { tool: 'recall_memory' } ] );
			expect( bubble.querySelectorAll( '.wp-mcp-ai-memory-badge' ).length ).toBe( 1 );
		} );

		test( 'recognises OpenAI-style { function: { name } } tool calls', () => {
			const bubble = document.createElement( 'div' );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( bubble, [
				{ function: { name: 'wake_up_context' } }
			] );
			expect( bubble.querySelector( '.wp-mcp-ai-memory-badge' ) ).not.toBeNull();
		} );
	} );
} );
