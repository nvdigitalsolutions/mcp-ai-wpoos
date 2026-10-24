/**
 * DocsPage tests.
 *
 * Covers the page states (unavailable / error / ready), the
 * imperative-island lifecycle contract with the vendored Docs Hub bundle,
 * and the dual-source contract (Proposal 087 Phase 2):
 *   - the `NVOOS_DOCS_HUB` config is injected from the runtime before mount
 *     (apiUrl + nonce + MemoryRouter island flags),
 *   - `window.NVoosDocsHub.mountDocsHub` is called once per mount with the
 *     shell-owned container,
 *   - `unmount()` runs on cleanup (React root teardown) and the injected
 *     config is removed,
 *   - the source toggle (Docs Hub vs OKF Knowledge) remounts the island
 *     against the selected REST base, and single-source sites auto-select.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup, fireEvent } from '@testing-library/react';

import { DocsPage } from '../DocsPage';

// The vendored bundle is a self-contained 660KB IIFE — mock the module so
// jsdom never executes it, and expose the island API the bundle assigns to
// window.NVoosDocsHub at load time.
const { mountMock, unmountMock } = vi.hoisted( () => ( {
	mountMock: vi.fn( () => ( { unmount: unmountMock } ) ),
	unmountMock: vi.fn(),
} ) );
vi.mock( '../upstream/docs-hub', () => {
	( window as unknown as Record< string, unknown > ).NVoosDocsHub = {
		mountDocsHub: mountMock,
	};
	return {};
} );

const DOCS_BASE = 'https://example.com/wp-json/nvoos-docs/v1';
const OKF_BASE = 'https://example.com/wp-json/mcp-ai-pro/v1/okf';

function setRuntime( docsHub: string, okf = '' ): void {
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
			okf,
			contentGraph: '',
			docsHub,
		},
		user: { id: 1, login: 'admin', displayName: 'Admin', capabilities: [] },
		mentionTypes: [],
	};
}

function injectedHub(): { apiUrl: string; config: Record< string, unknown > } {
	return (
		window as unknown as {
			NVOOS_DOCS_HUB: { apiUrl: string; config: Record< string, unknown > };
		}
	 ).NVOOS_DOCS_HUB;
}

beforeEach( () => {
	mountMock.mockClear();
	unmountMock.mockClear();
	( window as unknown as Record< string, unknown > ).NVoosDocsHub = {
		mountDocsHub: mountMock,
	};
	delete ( window as unknown as Record< string, unknown > ).NVOOS_DOCS_HUB;
} );

afterEach( () => {
	cleanup();
	delete ( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA;
	delete ( window as unknown as Record< string, unknown > ).NVOOS_DOCS_HUB;
} );

describe( 'DocsPage', () => {
	it( 'renders the unavailable state when no source is configured', () => {
		setRuntime( '' );
		render( <DocsPage /> );

		expect(
			screen.getByText(
				'The connected WordPress site does not expose a documentation API.',
			)
		).toBeInTheDocument();
		expect( mountMock ).not.toHaveBeenCalled();
	} );

	it( 'injects the island config and mounts the vendored app once', () => {
		setRuntime( DOCS_BASE );
		const { container } = render( <DocsPage /> );

		const island = container.querySelector(
			'.nvoos-pro-spa-docs-hub__island'
		);
		expect( island ).not.toBeNull();

		expect( injectedHub() ).toEqual( {
			apiUrl: DOCS_BASE,
			nonce: 'test-nonce',
			config: { router: 'memory', defer_mount: true },
		} );

		expect( mountMock ).toHaveBeenCalledTimes( 1 );
		expect( mountMock ).toHaveBeenCalledWith( island );
		expect( unmountMock ).not.toHaveBeenCalled();
	} );

	it( 'forwards an explicit light/dark theme from the runtime config', () => {
		setRuntime( DOCS_BASE );
		( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = {
			...( window as unknown as Record< string, unknown > )
				.NVOOS_PRO_SPA as object,
			config: { assistantId: 1, theme: 'dark', mode: 'admin' },
		};
		render( <DocsPage /> );

		expect( injectedHub().config ).toEqual( {
			router: 'memory',
			defer_mount: true,
			theme: 'dark',
		} );
	} );

	it( 'unmounts the island and clears the injected config on cleanup', () => {
		setRuntime( DOCS_BASE );
		const { unmount } = render( <DocsPage /> );

		unmount();

		expect( unmountMock ).toHaveBeenCalledTimes( 1 );
		expect(
			( window as unknown as Record< string, unknown > ).NVOOS_DOCS_HUB
		).toBeUndefined();
	} );

	it( 'renders the error state when the island API is missing', () => {
		setRuntime( DOCS_BASE );
		delete ( window as unknown as Record< string, unknown > ).NVoosDocsHub;
		render( <DocsPage /> );

		expect(
			screen.getByText(
				'The documentation browser could not be initialised.',
			)
		).toBeInTheDocument();
		expect( mountMock ).not.toHaveBeenCalled();
	} );

	it( 'auto-selects OKF knowledge when the Docs Hub plugin is absent', () => {
		setRuntime( '', OKF_BASE );
		render( <DocsPage /> );

		expect( injectedHub().apiUrl ).toBe( `${ OKF_BASE }/docs` );
		expect( mountMock ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.queryByRole( 'group', { name: 'Documentation source' } )
		).not.toBeInTheDocument();
	} );

	it( 'shows the source toggle when both sources exist and remounts on switch', () => {
		setRuntime( DOCS_BASE, OKF_BASE );
		render( <DocsPage /> );

		expect( injectedHub().apiUrl ).toBe( DOCS_BASE );
		expect( mountMock ).toHaveBeenCalledTimes( 1 );

		const toggle = screen.getByRole( 'group', {
			name: 'Documentation source',
		} );
		expect( toggle ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'OKF Knowledge' } ) );

		expect( unmountMock ).toHaveBeenCalledTimes( 1 );
		expect( injectedHub().apiUrl ).toBe( `${ OKF_BASE }/docs` );
		expect( mountMock ).toHaveBeenCalledTimes( 2 );
	} );
} );
