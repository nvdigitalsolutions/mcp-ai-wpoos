/**
 * SPA navigation regression tests.
 *
 * Renders the real App (HashRouter + ReactMarkdown + rehype-highlight +
 * rehype-autolink-headings) against a mocked manifest and REST API, then
 * navigates via sidebar links and in-content anchors — the flows that were
 * reported broken when a page error unmounted the app.
 */
import { describe, it, expect, vi, beforeAll } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import React from 'react';
import App from '../App';

const MANIFEST = {
	version: '0.4.2',
	built_at: Date.now(),
	cache_version: 'repro-1',
	total_pages: 3,
	broken_links: [],
	slug_map: {},
	tree: [
		{
			plugin_name: 'NV oOS',
			source: 'base',
			pages: [
				{ slug: 'readme', title: 'Readme', order: 1 },
				{ slug: 'features/code', title: 'Code Page', order: 2 },
				{ slug: 'features/anchors', title: 'Anchors', order: 3 },
			],
		},
	],
};

const PAGES: Record<string, unknown> = {
	readme: {
		slug: 'readme',
		title: 'Readme',
		content: '# Hello\n\nWelcome.\n',
		toc: [],
		prev: null,
		next: { slug: 'features/code', title: 'Code Page' },
		source: 'base',
		plugin_name: 'NV oOS',
		tags: [],
		description: '',
		last_modified: 0,
		relative_path: 'docs/readme.md',
	},
	'features/code': {
		slug: 'features/code',
		title: 'Code Page',
		content: [
			'# Code Page',
			'',
			'## Sample heading',
			'',
			'```js',
			'const x = 1;',
			'console.log(x);',
			'```',
			'',
			'| A | B |',
			'|---|----|',
			'| 1 | 2 |',
			'',
			'> :::note',
			'> A note here',
			'> :::',
		].join( '\n' ),
		toc: [
			{ level: 2, text: 'Sample heading', anchor: 'sample-heading' },
		],
		prev: { slug: 'readme', title: 'Readme' },
		next: { slug: 'features/anchors', title: 'Anchors' },
		source: 'base',
		plugin_name: 'NV oOS',
		tags: [],
		description: '',
		last_modified: 0,
		relative_path: 'docs/features/code.md',
	},
	'features/anchors': {
		slug: 'features/anchors',
		title: 'Anchors',
		content: '# Anchors\n\n[Link back](readme.md)\n',
		toc: [],
		prev: { slug: 'features/code', title: 'Code Page' },
		next: null,
		source: 'base',
		plugin_name: 'NV oOS',
		tags: [],
		description: '',
		last_modified: 0,
		relative_path: 'docs/features/anchors.md',
	},
};

beforeAll( () => {
	// jsdom has no matchMedia — used by App's theme detection.
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

	// jsdom does not implement scrollTo / scrollIntoView.
	Element.prototype.scrollTo = Element.prototype.scrollTo || ( () => {} );
	Element.prototype.scrollIntoView = Element.prototype.scrollIntoView || ( () => {} );

	vi.stubGlobal(
		'NVOOS_DOCS_HUB',
		{
			apiUrl: 'http://example.test/wp-json/nvoos-docs/v1',
			nonce: '',
			config: { home: 'readme' },
		}
	);

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

describe( 'docs-hub SPA navigation (insertBefore repro)', () => {
	it( 'renders the first page, then navigates to a page with code blocks without crashing', async () => {
		window.location.hash = '';
		sessionStorage.clear();

		const errors: unknown[] = [];
		const onError = ( e: ErrorEvent ) => errors.push( e.error ?? e.message );
		window.addEventListener( 'error', onError );

		const { container } = render( <App /> );

		// Wait for manifest + first page.
		await waitFor( () => {
			expect( container.textContent ).toContain( 'Hello' );
		} );

		// Sidebar links exist.
		const codeLink = screen.getByRole( 'link', { name: 'Code Page' } );
		expect( codeLink ).toBeTruthy();

		// Click the sidebar link — this is the "left panel links not working"
		// flow, and it renders content with fenced code + tables.
		fireEvent.click( codeLink );

		await waitFor( () => {
			expect( container.textContent ).toContain( 'console.log' );
		} );

		// Anchor link within content (RightTOC / autolink headings).
		const heading = container.querySelector( 'h2' );
		expect( heading ).toBeTruthy();
		fireEvent.click( heading?.querySelector( 'a' ) as Element );

		window.removeEventListener( 'error', onError );
		expect( errors ).toEqual( [] );
	}, 30000 );

	it( 'navigates to a third page via sidebar link', async () => {
		window.location.hash = '';
		sessionStorage.clear();

		const { container } = render( <App /> );
		await waitFor( () => {
			expect( container.textContent ).toContain( 'Hello' );
		} );

		fireEvent.click( screen.getByRole( 'link', { name: 'Anchors' } ) );

		await waitFor( () => {
			expect( container.textContent ).toContain( 'Link back' );
		} );
	}, 30000 );
} );
