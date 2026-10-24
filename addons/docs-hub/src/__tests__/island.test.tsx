/**
 * Island-mode regression tests.
 *
 * Covers the host-embed contract (Proposal 087):
 *   - `config.router: 'memory'` renders the app under MemoryRouter — the
 *     island never writes `window.location.hash`, which the host app owns;
 *   - `window.NVoosDocsHub.mountDocsHub( container )` mounts a root into a
 *     host-provided container and the returned `unmount()` tears it down;
 *   - `config.defer_mount` keeps the entry point's auto-scan from racing the
 *     host's explicit mount.
 */
import { describe, it, expect, vi, beforeAll } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import React from 'react';
import App from '../App';
// Importing the entry exposes `window.NVoosDocsHub` (the island API). Its
// auto-mount scan is a no-op here — no [data-config] containers exist yet.
import '../index';

const MANIFEST = {
	version: '0.5.3',
	built_at: Date.now(),
	cache_version: 'island-1',
	total_pages: 2,
	broken_links: [],
	slug_map: {},
	tree: [
		{
			plugin_name: 'NV oOS',
			source: 'base',
			pages: [
				{ slug: 'readme', title: 'Readme', order: 1 },
				{ slug: 'guide', title: 'Guide', order: 2 },
			],
		},
	],
};

const PAGES: Record< string, unknown > = {
	readme: {
		slug: 'readme',
		title: 'Readme',
		content: '# Hello\n\nWelcome. [Jump to guide](#/guide)\n',
		toc: [],
		prev: null,
		next: { slug: 'guide', title: 'Guide' },
		source: 'base',
		plugin_name: 'NV oOS',
		tags: [],
		description: '',
		last_modified: 0,
		relative_path: 'docs/readme.md',
	},
	guide: {
		slug: 'guide',
		title: 'Guide',
		content: '# Guide\n\nSecond page.\n',
		toc: [],
		prev: { slug: 'readme', title: 'Readme' },
		next: null,
		source: 'base',
		plugin_name: 'NV oOS',
		tags: [],
		description: '',
		last_modified: 0,
		relative_path: 'docs/guide.md',
	},
};

function stubIslandConfig( extra: Record< string, unknown > = {} ): void {
	vi.stubGlobal(
		'NVOOS_DOCS_HUB',
		{
			apiUrl: 'http://example.test/wp-json/nvoos-docs/v1',
			nonce: '',
			config: { home: 'readme', ...extra },
		}
	);
}

beforeAll( () => {
	window.matchMedia = window.matchMedia || ( ( query: string ) => ( {
		matches: false,
		media: query,
		onchange: null,
		addListener: () => {},
		removeListener: () => {},
		addEventListener: () => {},
		removeEventListener: () => {},
		dispatchEvent: () => false,
	} ) );

	Element.prototype.scrollTo = Element.prototype.scrollTo || ( () => {} );
	Element.prototype.scrollIntoView = Element.prototype.scrollIntoView || ( () => {} );

	vi.stubGlobal(
		'fetch',
		vi.fn( async ( input: RequestInfo | URL ) => {
			const url = String( input );
			if ( url.includes( '/manifest' ) ) {
				return new Response( JSON.stringify( MANIFEST ), {
					status: 200,
					headers: { 'Content-Type': 'application/json' },
				} );
			}
			const match = /\/pages\/(.+)$/.exec( url );
			const slug = match ? decodeURIComponent( match[ 1 ] ) : '';
			const page = PAGES[ slug ];
			if ( ! page ) {
				return new Response( JSON.stringify( { code: 'not_found' } ), {
					status: 404,
					headers: { 'Content-Type': 'application/json' },
				} );
			}
			return new Response( JSON.stringify( page ), {
				status: 200,
				headers: { 'Content-Type': 'application/json' },
			} );
		} )
	);
} );

describe( 'docs-hub island mode', () => {
	it( 'renders under MemoryRouter and never writes window.location.hash', async () => {
		window.location.hash = '';
		sessionStorage.clear();
		stubIslandConfig( { router: 'memory' } );

		render( <App /> );

		await waitFor( () => {
			expect( screen.getByText( 'Welcome.' ) ).toBeInTheDocument();
		} );
		expect( window.location.hash ).toBe( '' );

		// Literal `#/slug` content links (the OKF source emits these) are
		// intercepted into the island's memory router.
		fireEvent.click( screen.getByRole( 'link', { name: 'Jump to guide' } ) );

		await waitFor( () => {
			expect( screen.getByText( 'Second page.' ) ).toBeInTheDocument();
		} );
		expect( window.location.hash ).toBe( '' );

		// Sidebar navigation stays inside the island's memory router too.
		fireEvent.click( screen.getByRole( 'link', { name: 'Guide' } ) );

		await waitFor( () => {
			expect( screen.getByText( 'Second page.' ) ).toBeInTheDocument();
		} );
		expect( window.location.hash ).toBe( '' );
	} );

	it( 'renders sidebar links as router paths in memory mode (no #/ prefix)', async () => {
		window.location.hash = '';
		sessionStorage.clear();
		stubIslandConfig( { router: 'memory' } );

		render( <App /> );

		const link = await screen.findByRole( 'link', { name: 'Guide' } );
		expect( link.getAttribute( 'href' ) ).toBe( '/guide' );
	} );

	it( 'mountDocsHub() mounts into a host container and unmount() tears down', async () => {
		sessionStorage.clear();
		stubIslandConfig( { router: 'memory', defer_mount: true } );

		const island = (
			window as unknown as {
				NVoosDocsHub: {
					mountDocsHub(
						container: HTMLElement
					): { unmount(): void };
				};
			}
		 ).NVoosDocsHub;

		const container = document.createElement( 'div' );
		document.body.appendChild( container );

		const handle = island.mountDocsHub( container );
		await waitFor( () => {
			expect(
				container.querySelector( '.nvoos-docs-hub-root' )
			).not.toBeNull();
		} );
		await waitFor( () => {
			expect(
				container.textContent ?? ''
			).toContain( 'Welcome.' );
		} );

		handle.unmount();
		expect( container.querySelector( '.nvoos-docs-hub-root' ) ).toBeNull();
		container.remove();
	} );
} );
