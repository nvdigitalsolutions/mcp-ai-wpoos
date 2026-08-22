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
		sessionReplay: jest.fn().mockResolvedValue( { events: [] } ),
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
			memoryRoom: '',
			sessionKey: 'sess_default_7'
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

	test( 'memory items surface wing/room scope and mark unscoped memories', async () => {
		installMemoryStub( {
			recall: jest.fn().mockResolvedValue( {
				contexts: [
					{ context_id: 's-1', title: 'Scoped', content: 'In a wing', wing: 'wing-a', room: 'room-1' },
					{ context_id: 'u-1', title: 'Unscoped', content: 'No wing or room' }
				]
			} )
		} );
		const container = makeContainer();
		window.wpMcpAiChatMemoryDrawer.attach( container );
		container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
		await Promise.resolve();
		await Promise.resolve();

		const scoped = container.querySelector( '[data-context-id="s-1"]' );
		const unscoped = container.querySelector( '[data-context-id="u-1"]' );

		expect( scoped.querySelector( '[data-testid="wp-mcp-ai-memory-wing-chip"]' ).textContent ).toBe( 'wing-a' );
		expect( scoped.querySelector( '[data-testid="wp-mcp-ai-memory-room-chip"]' ).textContent ).toBe( 'room-1' );
		expect( scoped.querySelector( '[data-testid="wp-mcp-ai-memory-unscoped-chip"]' ) ).toBeNull();

		expect( unscoped.querySelector( '[data-testid="wp-mcp-ai-memory-unscoped-chip"]' ) ).not.toBeNull();
		expect( unscoped.querySelector( '[data-testid="wp-mcp-ai-memory-wing-chip"]' ) ).toBeNull();
		expect( unscoped.querySelector( '[data-testid="wp-mcp-ai-memory-room-chip"]' ) ).toBeNull();
	} );

	test( 'memories panel shows the agent ID the drawer recalls under', async () => {
		installMemoryStub();
		const container = makeContainer();
		window.wpMcpAiChatMemoryDrawer.attach( container );
		container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();

		const agentMeta = container.querySelector( '[data-testid="wp-mcp-ai-memory-agent-id"]' );
		expect( agentMeta ).not.toBeNull();
		expect( agentMeta.textContent ).toContain( '#7' );
	} );

	test( 'all-scopes toggle recalls without wing/room filters', async () => {
		const { stub } = installMemoryStub();
		const container = makeContainer();
		window.wpMcpAiChatMemoryDrawer.attach( container );
		container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
		await Promise.resolve();
		await Promise.resolve();

		const toggle = container.querySelector( '[data-testid="wp-mcp-ai-memory-all-scopes"]' );
		expect( toggle ).not.toBeNull();
		toggle.checked = true;
		toggle.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
		await Promise.resolve();
		await Promise.resolve();

		const lastCall = stub.recall.mock.calls[ stub.recall.mock.calls.length - 1 ];
		expect( lastCall[ 1 ].wing ).toBe( '' );
		expect( lastCall[ 1 ].room ).toBe( '' );
	} );

	test( 'merged alias-bucket records render a stored-under chip', async () => {
		installMemoryStub( {
			recall: jest.fn().mockResolvedValue( {
				contexts: [
					{ context_id: 'a-1', title: 'From alias bucket', content: 'x', stored_under: 'nvoos-pro-spa-memory-drawer' }
				]
			} )
		} );
		const container = makeContainer();
		window.wpMcpAiChatMemoryDrawer.attach( container );
		container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
		await Promise.resolve();
		await Promise.resolve();

		const chip = container.querySelector( '[data-testid="wp-mcp-ai-memory-stored-under"]' );
		expect( chip ).not.toBeNull();
		expect( chip.textContent ).toContain( 'nvoos-pro-spa-memory-drawer' );
	} );

	test( 'a memory_event stored frame refreshes an open drawer', async () => {
		const { stub } = installMemoryStub();
		const container = makeContainer();
		window.wpMcpAiChatMemoryDrawer.attach( container );
		container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
		await Promise.resolve();
		await Promise.resolve();
		expect( stub.recall ).toHaveBeenCalledTimes( 1 );

		window.wpMcpAiChatMemoryDrawer.handleSseMemoryEvent( { action: 'stored' } );
		await Promise.resolve();
		await Promise.resolve();

		expect( stub.recall.mock.calls.length ).toBeGreaterThanOrEqual( 2 );
	} );

	test( 'memories panel renders retrieval waterfall rows from rrf_breakdown metadata', async () => {
		const recallMock = jest.fn().mockResolvedValue( {
			contexts: [
				{
					context_id: 'm-1',
					title: 'First',
					content: 'Hello world',
					tags: [ 'a' ],
					tier: 'recall',
					rrf_breakdown: { bm25_rank: 0, vector_rank: 2, graph_rank: null }
				},
				{
					context_id: 'm-2',
					title: 'Second',
					content: 'Another note',
					tags: [],
					tier: 'core',
					rrf_breakdown: { bm25_rank: null, vector_rank: 0, graph_rank: 1 }
				}
			]
		} );
		installMemoryStub( { recall: recallMock } );
		const container = makeContainer();
		window.wpMcpAiChatMemoryDrawer.attach( container );
		container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();

		await Promise.resolve();
		await Promise.resolve();

		const waterfall = container.querySelector( '[data-testid="wp-mcp-ai-memory-waterfall"]' );
		expect( waterfall ).not.toBeNull();
		expect( waterfall.hidden ).toBe( false );

		const rows = waterfall.querySelectorAll( '[data-testid="wp-mcp-ai-memory-waterfall-row"]' );
		expect( rows.length ).toBe( 3 );
		expect( rows[ 0 ].textContent ).toContain( 'BM25' );
		expect( rows[ 1 ].textContent ).toContain( 'Vector' );
		expect( rows[ 2 ].textContent ).toContain( 'Graph' );
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

	describe( 'session replay tab', () => {
		test( 'switching to Session Replay lazy-loads via memoryService.sessionReplay()', async () => {
			const sessionReplayMock = jest.fn().mockResolvedValue( {
				events: [
					{ id: 1, event: 'chat:resumed', timestamp: '2026-05-18T19:00:00Z', data: { message: 'done' } },
					{ id: 2, event: 'memory_event', timestamp: '2026-05-18T19:00:01Z', data: { action: 'stored' } }
				]
			} );
			installMemoryStub( { sessionReplay: sessionReplayMock } );
			const container = makeContainer();
			window.wpMcpAiChatMemoryDrawer.attach( container );
			container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
			const drawer = container.querySelector( '.wp-mcp-ai-memory-drawer' );

			const replayTab = drawer.querySelector( '[data-testid="wp-mcp-ai-memory-replay-tab"]' );
			replayTab.click();

			expect( sessionReplayMock ).toHaveBeenCalledTimes( 1 );
			expect( sessionReplayMock ).toHaveBeenCalledWith( 'sess_default_7', { limit: 100 } );

			await Promise.resolve();
			await Promise.resolve();

			const panel = drawer.querySelector( '[data-testid="wp-mcp-ai-memory-replay-panel"]' );
			expect( panel.hidden ).toBe( false );
			const items = panel.querySelectorAll( '[data-testid="wp-mcp-ai-memory-replay-item"]' );
			expect( items.length ).toBe( 2 );
		} );

		test( 'empty Session Replay response shows the empty state', async () => {
			installMemoryStub( { sessionReplay: jest.fn().mockResolvedValue( { events: [] } ) } );
			const container = makeContainer();
			window.wpMcpAiChatMemoryDrawer.attach( container );
			container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
			const drawer = container.querySelector( '.wp-mcp-ai-memory-drawer' );
			drawer.querySelector( '[data-testid="wp-mcp-ai-memory-replay-tab"]' ).click();

			await Promise.resolve();
			await Promise.resolve();

			const panel = drawer.querySelector( '[data-testid="wp-mcp-ai-memory-replay-panel"]' );
			const empty = panel.querySelector( '.wp-mcp-ai-memory-drawer__empty' );
			expect( empty.hidden ).toBe( false );
		} );
	} );

	describe( 'export button', () => {
		/**
		 * Stub URL.createObjectURL / revokeObjectURL + intercept anchor clicks
		 * so tests can assert the download path without actually triggering one.
		 */
		function installDownloadCaptors() {
			const created = [];
			const revoked = [];
			const clicked = [];
			window.URL.createObjectURL = jest.fn( ( blob ) => {
				const url = 'blob:fake-' + created.length;
				// Pull the blob's body out via the constructor — jsdom's Blob
				// doesn't implement .text(), but we can read the original
				// parts the SUT passed in via blob[Symbol.for('jest.bodyParts')]
				// when we wrap it. Simpler: re-read via a FileReader fallback,
				// or stash on a global.
				created.push( { blob, url } );
				return url;
			} );
			window.URL.revokeObjectURL = jest.fn( ( url ) => {
				revoked.push( url );
			} );
			const origBlob = window.Blob;
			window.Blob = function( parts, opts ) {
				const b = new origBlob( parts, opts );
				// Surface the JSON body for assertions.
				b.__parts = parts;
				return b;
			};
			window.Blob.prototype = origBlob.prototype;
			const origClick = window.HTMLAnchorElement.prototype.click;
			window.HTMLAnchorElement.prototype.click = function() {
				clicked.push( {
					href: this.getAttribute( 'href' ),
					download: this.getAttribute( 'download' )
				} );
			};
			return {
				created,
				revoked,
				clicked,
				restore: () => {
					window.HTMLAnchorElement.prototype.click = origClick;
					window.Blob = origBlob;
				}
			};
		}

		test( 'export button is rendered with a stable testid', () => {
			installMemoryStub();
			const container = makeContainer();
			window.wpMcpAiChatMemoryDrawer.attach( container );
			container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();
			const btn = container.querySelector( '[data-testid="wp-mcp-ai-memory-export"]' );
			expect( btn ).not.toBeNull();
			expect( btn.tagName ).toBe( 'BUTTON' );
		} );

		test( 'click triggers recall() with the active scope and a high limit, then downloads JSON', async () => {
			const recallMock = jest.fn().mockResolvedValue( {
				contexts: [
					{ context_id: 'm-1', title: 'A', content: 'a', tags: [], tier: 'core' },
					{ context_id: 'm-2', title: 'B', content: 'b', tags: [], tier: 'recall' }
				]
			} );
			installMemoryStub( { recall: recallMock } );
			const captors = installDownloadCaptors();
			try {
				const container = makeContainer();
				window.wpMcpAiChatMemoryDrawer.attach( container );
				container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();

				// First call from open()/loadMemories — clear it.
				recallMock.mockClear();

				const btn = container.querySelector( '[data-testid="wp-mcp-ai-memory-export"]' );
				btn.click();

				// recall() called once with the export filter shape.
				expect( recallMock ).toHaveBeenCalledTimes( 1 );
				const [ query, filters ] = recallMock.mock.calls[ 0 ];
				expect( query ).toBe( '' );
				expect( filters.agentId ).toBe( 7 );
				expect( filters.wing ).toBe( 'wing-a' );
				expect( filters.limit ).toBe( 200 );

				// Wait for the promise chain to flush.
				await Promise.resolve();
				await Promise.resolve();
				await Promise.resolve();

				// Anchor click + revoke fired.
				expect( captors.created.length ).toBe( 1 );
				expect( captors.clicked.length ).toBe( 1 );
				expect( captors.clicked[ 0 ].download ).toMatch( /^mcp-ai-memory-7-/ );
				expect( captors.clicked[ 0 ].download ).toMatch( /\.json$/ );

				// The blob payload contains the records and an exported_at timestamp.
				const blob = captors.created[ 0 ].blob;
				expect( blob.type ).toBe( 'application/json' );
				const text = ( blob.__parts || [] ).join( '' );
				const parsed = JSON.parse( text );
				expect( parsed.count ).toBe( 2 );
				expect( parsed.agent_id ).toBe( 7 );
				expect( parsed.scope.wing ).toBe( 'wing-a' );
				expect( typeof parsed.exported_at ).toBe( 'string' );
				expect( parsed.memories.length ).toBe( 2 );

				// Button is re-enabled after success.
				expect( btn.disabled ).toBe( false );

				// objectURL revoked (setTimeout 0).
				await new Promise( ( r ) => setTimeout( r, 1 ) );
				expect( captors.revoked.length ).toBe( 1 );
			} finally {
				captors.restore();
			}
		} );

		test( 'export error surfaces a toast and re-enables the button', async () => {
			const recallMock = jest.fn()
				.mockResolvedValueOnce( { contexts: [] } )         // initial loadMemories
				.mockRejectedValueOnce( { message: 'boom' } );      // export click
			installMemoryStub( { recall: recallMock } );
			const captors = installDownloadCaptors();
			try {
				const container = makeContainer();
				window.wpMcpAiChatMemoryDrawer.attach( container );
				container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();

				const btn = container.querySelector( '[data-testid="wp-mcp-ai-memory-export"]' );
				btn.click();
				expect( btn.disabled ).toBe( true );

				await Promise.resolve();
				await Promise.resolve();
				await Promise.resolve();

				expect( btn.disabled ).toBe( false );
				expect( captors.clicked.length ).toBe( 0 );
				const region = document.getElementById( 'wp-mcp-ai-memory-toasts' );
				expect( region.textContent ).toContain( 'boom' );
			} finally {
				captors.restore();
			}
		} );

		test( 'concurrent clicks while in flight only fire one recall()', async () => {
			let resolveExport;
			const recallMock = jest.fn()
				.mockResolvedValueOnce( { contexts: [] } )
				.mockImplementationOnce( () => new Promise( ( r ) => {
					resolveExport = () => r( { contexts: [] } );
				} ) );
			installMemoryStub( { recall: recallMock } );
			const captors = installDownloadCaptors();
			try {
				const container = makeContainer();
				window.wpMcpAiChatMemoryDrawer.attach( container );
				container.querySelector( '.wp-mcp-ai-memory-toggle' ).click();

				const btn = container.querySelector( '[data-testid="wp-mcp-ai-memory-export"]' );
				btn.click();
				btn.click();
				btn.click();

				// Initial loadMemories (1) + a single export call (2) — additional clicks ignored.
				expect( recallMock ).toHaveBeenCalledTimes( 2 );

				resolveExport();
				await Promise.resolve();
				await Promise.resolve();
				await Promise.resolve();

				expect( btn.disabled ).toBe( false );
			} finally {
				captors.restore();
			}
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

	describe( 'memory-event toast (G8)', () => {
		function getToasts() {
			const region = document.getElementById( 'wp-mcp-ai-memory-toasts' );
			return region ? region.querySelectorAll( '[data-testid="wp-mcp-ai-memory-toast"]' ) : [];
		}

		test( 'fires a single toast when a retrieve tool was called', () => {
			const bubble = document.createElement( 'div' );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( bubble, [
				{ tool: 'recall_memory' }
			] );
			const toasts = getToasts();
			expect( toasts.length ).toBe( 1 );
			expect( toasts[ 0 ].textContent ).toMatch( /Used long-term memory/ );
			expect( bubble.getAttribute( 'data-wp-mcp-ai-memory-toast' ) ).toBe( '1' );
		} );

		test( 'fires the "saved" copy when only a store tool was called', () => {
			const bubble = document.createElement( 'div' );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( bubble, [
				{ function: { name: 'store_agent_context' } }
			] );
			const toasts = getToasts();
			expect( toasts.length ).toBe( 1 );
			expect( toasts[ 0 ].textContent ).toMatch( /Saved a memory/ );
		} );

		test( 'fires the combined copy when both retrieve and store tools were called', () => {
			const bubble = document.createElement( 'div' );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( bubble, [
				{ tool: 'recall_memory' },
				{ tool: 'store_agent_context' }
			] );
			const toasts = getToasts();
			expect( toasts.length ).toBe( 1 );
			expect( toasts[ 0 ].textContent ).toMatch( /Used and saved long-term memory/ );
		} );

		test( 'no toast when no memory tool was called', () => {
			const bubble = document.createElement( 'div' );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( bubble, [
				{ tool: 'create_post' }
			] );
			expect( getToasts().length ).toBe( 0 );
		} );

		test( 'is idempotent across re-decorations of the same bubble', () => {
			const bubble = document.createElement( 'div' );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( bubble, [ { tool: 'recall_memory' } ] );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( bubble, [ { tool: 'recall_memory' } ] );
			expect( getToasts().length ).toBe( 1 );
		} );
	} );

	describe( 'SSE memory_event handler (G8 Phase 2)', () => {
		function getToasts() {
			const region = document.getElementById( 'wp-mcp-ai-memory-toasts' );
			return region ? region.querySelectorAll( '[data-testid="wp-mcp-ai-memory-toast"]' ) : [];
		}

		test( 'fires the "used" toast for action=retrieved', () => {
			window.wpMcpAiChatMemoryDrawer.handleSseMemoryEvent( { action: 'retrieved', tool_name: 'recall_memory' } );
			const toasts = getToasts();
			expect( toasts.length ).toBe( 1 );
			expect( toasts[ 0 ].textContent ).toMatch( /Used long-term memory/ );
		} );

		test( 'fires the "saved" toast for action=stored', () => {
			window.wpMcpAiChatMemoryDrawer.handleSseMemoryEvent( { action: 'stored', tool_name: 'store_agent_context' } );
			const toasts = getToasts();
			expect( toasts.length ).toBe( 1 );
			expect( toasts[ 0 ].textContent ).toMatch( /Saved a memory/ );
		} );

		test( 'is a no-op on missing or unknown action', () => {
			window.wpMcpAiChatMemoryDrawer.handleSseMemoryEvent( null );
			window.wpMcpAiChatMemoryDrawer.handleSseMemoryEvent( {} );
			window.wpMcpAiChatMemoryDrawer.handleSseMemoryEvent( { action: 'banana' } );
			expect( getToasts().length ).toBe( 0 );
		} );

		test( 'suppresses the end-of-stream decorator toast for the same turn', () => {
			// Server frame fires first.
			window.wpMcpAiChatMemoryDrawer.handleSseMemoryEvent( { action: 'retrieved' } );
			expect( getToasts().length ).toBe( 1 );
			// End-of-stream decoration arrives — should NOT add a second toast,
			// but should still draw the badge.
			const bubble = document.createElement( 'div' );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( bubble, [ { tool: 'recall_memory' } ] );
			expect( getToasts().length ).toBe( 1 );
			expect( bubble.querySelector( '.wp-mcp-ai-memory-badge' ) ).not.toBeNull();
			expect( bubble.getAttribute( 'data-wp-mcp-ai-memory-toast' ) ).toBe( '1' );
		} );

		test( 'two SSE frames suppress two end-of-stream decorator toasts', () => {
			window.wpMcpAiChatMemoryDrawer.handleSseMemoryEvent( { action: 'retrieved' } );
			window.wpMcpAiChatMemoryDrawer.handleSseMemoryEvent( { action: 'stored' } );
			expect( getToasts().length ).toBe( 2 );
			const b1 = document.createElement( 'div' );
			const b2 = document.createElement( 'div' );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( b1, [ { tool: 'recall_memory' } ] );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( b2, [ { tool: 'store_agent_context' } ] );
			// Still 2 — both bubbles' toasts were suppressed.
			expect( getToasts().length ).toBe( 2 );
		} );

		test( 'decorator falls back to its own toast when no SSE frame fired (non-streaming)', () => {
			const bubble = document.createElement( 'div' );
			window.wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( bubble, [ { tool: 'recall_memory' } ] );
			expect( getToasts().length ).toBe( 1 );
		} );
	} );

	describe( 'auto-summary on conversation close (G6)', () => {
		/**
		 * Install a wpMcpAiChatStorage stub returning a small transcript.
		 */
		function installTranscript( turns ) {
			window.wpMcpAiChatStorage = {
				loadConversationFromStorage: jest.fn( () => ( {
					conversation: turns,
					assistantId: 7,
					sessionKey: 's'
				} ) )
			};
		}

		test( 'storeBeacon is exposed on the memory service', () => {
			// Load the actual service file (not the stub) into a sandbox to verify shape.
			const code = fs.readFileSync(
				path.join( __dirname, '../../assets/js/chat-memory-service.js' ),
				'utf8'
			);
			window.wpMcpAiChat = {
				memoryEndpoints: {
					recall: '/r', wakeUp: '/w', store: '/s', preferences: '/p',
					audit: '/a', itemBase: '/i/'
				},
				nonce: 'NONCE'
			};
			delete window.wpMcpAiChatMemory;
			// eslint-disable-next-line no-eval
			eval( code );
			expect( typeof window.wpMcpAiChatMemory.storeBeacon ).toBe( 'function' );
			window.fetch = jest.fn().mockResolvedValue( {
				ok: true,
				json: () => Promise.resolve( { success: true } )
			} );
			window.wpMcpAiChatMemory.storeBeacon( {
				agentId: 7,
				content: 'transcript',
				tags: [ 'transcript-summary' ]
			} );
			expect( window.fetch ).toHaveBeenCalledTimes( 1 );
			const [ url, options ] = window.fetch.mock.calls[ 0 ];
			expect( url ).toBe( '/s' );
			expect( options.method ).toBe( 'POST' );
			expect( options.keepalive ).toBe( true );
			expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'NONCE' );
			expect( JSON.parse( options.body ).content ).toBe( 'transcript' );
		} );

		test( 'pagehide is a no-op when autosummarize toggle is off', async () => {
			const storeBeacon = jest.fn().mockResolvedValue( {} );
			installMemoryStub( {
				getPreferences: jest.fn().mockResolvedValue( {
					enabled: true,
					autosummarize: false
				} ),
				storeBeacon
			} );
			installTranscript( [
				{ role: 'user', content: 'hi' },
				{ role: 'assistant', content: 'hello' }
			] );
			window.sessionStorage.clear();
			const container = makeContainer();
			window.wpMcpAiChatMemoryDrawer.attach( container );
			// Wait for the prefs prefetch.
			await Promise.resolve();
			await Promise.resolve();
			window.dispatchEvent( new Event( 'pagehide' ) );
			expect( storeBeacon ).not.toHaveBeenCalled();
		} );

		test( 'pagehide fires storeBeacon once when toggle is on', async () => {
			const storeBeacon = jest.fn().mockResolvedValue( {} );
			installMemoryStub( {
				getPreferences: jest.fn().mockResolvedValue( {
					enabled: true,
					autosummarize: true
				} ),
				storeBeacon
			} );
			installTranscript( [
				{ role: 'user', content: 'hi' },
				{ role: 'assistant', content: 'hello there' },
				{ role: 'user', content: 'thanks' }
			] );
			window.sessionStorage.clear();
			const container = makeContainer();
			window.wpMcpAiChatMemoryDrawer.attach( container );
			await Promise.resolve();
			await Promise.resolve();

			window.dispatchEvent( new Event( 'pagehide' ) );
			expect( storeBeacon ).toHaveBeenCalledTimes( 1 );
			const payload = storeBeacon.mock.calls[ 0 ][ 0 ];
			expect( payload.agentId ).toBe( 7 );
			expect( payload.contextType ).toBe( 'transcript_summary' );
			expect( payload.tags ).toEqual( [ 'transcript-summary', 'autosummary' ] );
			expect( payload.content ).toContain( 'User: hi' );
			expect( payload.content ).toContain( 'Assistant: hello there' );
			expect( payload.title ).toMatch( /Conversation summary/ );
			// G6 Phase 2 — auto-capture opts into LLM summarisation.
			expect( payload.summarize ).toBe( true );

			// Second pagehide is suppressed by the sessionStorage one-shot flag.
			window.dispatchEvent( new Event( 'pagehide' ) );
			expect( storeBeacon ).toHaveBeenCalledTimes( 1 );
		} );

		test( 'pagehide is a no-op when transcript has fewer than 2 turns', async () => {
			const storeBeacon = jest.fn().mockResolvedValue( {} );
			installMemoryStub( {
				getPreferences: jest.fn().mockResolvedValue( {
					enabled: true,
					autosummarize: true
				} ),
				storeBeacon
			} );
			installTranscript( [ { role: 'user', content: 'hi' } ] );
			window.sessionStorage.clear();
			const container = makeContainer();
			window.wpMcpAiChatMemoryDrawer.attach( container );
			await Promise.resolve();
			await Promise.resolve();
			window.dispatchEvent( new Event( 'pagehide' ) );
			expect( storeBeacon ).not.toHaveBeenCalled();
		} );

		test( 'readTranscript truncates to 4 KB from the front', () => {
			const big = 'x'.repeat( 5000 );
			window.wpMcpAiChatStorage = {
				loadConversationFromStorage: () => ( {
					conversation: [
						{ role: 'user', content: big },
						{ role: 'assistant', content: 'tail' }
					]
				} )
			};
			const result = window.wpMcpAiChatMemoryDrawer.readTranscript( 7 );
			expect( result ).not.toBeNull();
			expect( result.text.startsWith( '…' ) ).toBe( true );
			expect( result.text.length ).toBeLessThanOrEqual( 4096 + 4 );
			expect( result.text.endsWith( 'Assistant: tail' ) ).toBe( true );
		} );
	} );
} );
