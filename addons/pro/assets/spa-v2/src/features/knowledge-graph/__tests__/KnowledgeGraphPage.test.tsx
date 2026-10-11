/**
 * KnowledgeGraphPage tests.
 *
 * Covers the three page states (unavailable / error / ready) and the
 * imperative-island lifecycle contract with the vendored explorer:
 *   - the `nvoosContentGraphAdmin` config is written from the runtime +
 *     the visual-config REST payload before init,
 *   - the factory is called once per mount,
 *   - `destroy()` runs on unmount (listeners/timers teardown).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';

import { KnowledgeGraphPage } from '../KnowledgeGraphPage';

// The vendored explorer + its heavy deps are mocked: jsdom cannot run
// Cytoscape's canvas pipeline, and the page contract under test is the
// config hand-off + lifecycle, not the vendored internals.
// vi.mock factories are hoisted — the shared mock lives in vi.hoisted().
const { initMock } = vi.hoisted( () => ( {
	initMock: vi.fn( () => ( { destroy: vi.fn() } ) ),
} ) );
vi.mock( 'jquery', () => ( { default: {} } ) );
vi.mock( 'cytoscape', () => ( { default: { use: vi.fn() } } ) );
vi.mock( 'cytoscape-fcose', () => ( { default: {} } ) );
// Paths are relative to THIS file (vitest), but must match the page's own
// import specifiers: __tests__/../upstream/...
vi.mock( '../upstream/content-graph-admin', () => ( {
	initNvoosGraphExplorer: initMock,
} ) );
vi.mock( '../upstream/content-graph-theme', () => ( {} ) );
vi.mock( '../upstream/content-graph-icons', () => ( {} ) );

const CONTENT_GRAPH_BASE = 'https://example.com/wp-json/nvoos-content-graph/v1';

function setRuntime( endpoints: Record< string, string > ): void {
	( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = {
		apiUrl: 'https://example.com/wp-json/mcp-ai/v1',
		proApi: 'https://example.com/wp-json/mcp-ai-pro/v1',
		nonce: 'test-nonce',
		config: { assistantId: 1, theme: 'auto', mode: 'admin' },
		endpoints: {
			chat: 'https://example.com/wp-json/mcp-ai/v1/chat',
			chatClient: 'https://example.com/wp-json/mcp-ai/v1/chat-client',
			transcripts: '',
			threads: '',
			tools: 'https://example.com/wp-json/mcp-ai/v1/tools',
			assistants: 'https://example.com/wp-json/mcp-ai/v1/assistants',
			settings: 'https://example.com/wp-json/mcp-ai/v1/settings',
			memory: '',
			workflows: '',
			analytics: '',
			approvals: '',
			shortcuts: '',
			slashCommands: '',
			okf: '',
			...endpoints,
		},
		user: { id: 1, login: 'admin', displayName: 'Admin', capabilities: [] },
		mentionTypes: [],
	};
}

function setFetch( payload: unknown, ok = true, status = 200 ): void {
	global.fetch = vi.fn( () =>
		Promise.resolve( {
			ok,
			status,
			json: () => Promise.resolve( payload ),
		} as Response )
	) as unknown as typeof fetch;
}

afterEach( () => {
	vi.restoreAllMocks();
	initMock.mockClear();
	cleanup();
	delete ( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA;
	delete ( window as unknown as Record< string, unknown > ).nvoosContentGraphAdmin;
} );

describe( 'KnowledgeGraphPage', () => {
	it( 'renders the unavailable state when the runtime has no contentGraph endpoint', () => {
		setRuntime( { contentGraph: '' } );
		render( <KnowledgeGraphPage /> );

		expect(
			screen.getByText(
				'The connected WordPress site does not expose the NV oOS Content Graph API.',
			)
		).toBeInTheDocument();
		expect( initMock ).not.toHaveBeenCalled();
	} );

	it( 'renders the error state when the visual-config fetch fails', async () => {
		setRuntime( { contentGraph: CONTENT_GRAPH_BASE } );
		setFetch( {}, false, 404 );
		render( <KnowledgeGraphPage /> );

		await waitFor( () => {
			expect(
				screen.getByText(
					'Could not load the knowledge graph configuration.',
				)
			).toBeInTheDocument();
		} );
		expect( initMock ).not.toHaveBeenCalled();
	} );

	it( 'writes the localized config and initialises the explorer', async () => {
		setRuntime( { contentGraph: CONTENT_GRAPH_BASE } );
		setFetch( {
			visual: { version: '1', theme: 'dark', color_by: 'type' },
			presets: { default: { label: 'Default' } },
			height: '560px',
			max_nodes: 900,
		} );
		render( <KnowledgeGraphPage /> );

		await waitFor( () => {
			expect( initMock ).toHaveBeenCalledTimes( 1 );
		} );

		const config = ( window as unknown as {
			nvoosContentGraphAdmin?: Record< string, unknown >;
		} ).nvoosContentGraphAdmin as Record< string, unknown >;
		expect( config ).toBeDefined();
		expect( config.rest_url ).toBe( CONTENT_GRAPH_BASE );
		expect( config.nonce ).toBe( 'test-nonce' );
		expect( config.height ).toBe( '560px' );
		expect( config.max_nodes ).toBe( 900 );
		expect( config.visual ).toEqual( {
			version: '1',
			theme: 'dark',
			color_by: 'type',
		} );

		// The explorer container is present in the markup.
		expect(
			document.getElementById( 'nvoos-content-graph-explorer' )
		).not.toBeNull();
	} );

	it( 'destroys the explorer and clears the global config on unmount', async () => {
		setRuntime( { contentGraph: CONTENT_GRAPH_BASE } );
		setFetch( { visual: { theme: 'dark' }, presets: {}, height: '600px' } );
		const destroy = vi.fn();
		initMock.mockReturnValue( { destroy } );

		const { unmount } = render( <KnowledgeGraphPage /> );
		await waitFor( () => {
			expect( initMock ).toHaveBeenCalledTimes( 1 );
		} );

		unmount();

		expect( destroy ).toHaveBeenCalledTimes( 1 );
		expect(
			( window as unknown as {
				nvoosContentGraphAdmin?: unknown;
			} ).nvoosContentGraphAdmin
		).toBeUndefined();
	} );
} );
