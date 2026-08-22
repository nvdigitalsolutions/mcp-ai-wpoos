/**
 * Pro SPA v2 — OkfClient API + OkfDrawer component tests.
 *
 * Covers:
 *   - OkfClient constructor, listBundles, listConcepts, getConcept, search, listSkills
 *   - OkfDrawer open/close, tab rendering, ESC-dismiss
 *   - Bundle → concept drill-down and provenance badges
 *   - Skills tab with loadable/unloadable states
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';

import {
	OkfClient,
	type OkfClientOptions,
} from '../api/okf';
import { OkfDrawer, type OkfDrawerProps } from '../components/shared/OkfDrawer';

// ── Mocks ──────────────────────────────────────────────────────────────────────

vi.mock( '@wordpress/i18n', () => ( {
	__: ( text: string ) => text,
	sprintf: ( text: string, ...args: unknown[] ) =>
		text.replace( /%(s|d)/g, () => String( args.shift() ?? '' ) ),
} ) );

vi.mock( '../components/shared/MarkdownContent', () => ( {
	MarkdownContent: ( { content }: { content: string } ) =>
		React.createElement( 'div', { 'data-testid': 'mock-markdown' }, content ),
} ) );

// ── Test helpers ───────────────────────────────────────────────────────────────

function defaultDrawerProps(
	overrides: Partial< OkfDrawerProps > = {}
): OkfDrawerProps {
	return {
		endpoint: 'https://ex.com/wp-json/mcp-ai-pro/v1/okf',
		nonce: 'test-nonce',
		assistantId: 1,
		isOpen: true,
		onClose: vi.fn(),
		onInsertPrompt: vi.fn(),
		toggleRef: { current: null },
		...overrides,
	};
}

const mockFetch = vi.fn();

function mockFetchOk( body: unknown, status = 200 ): void {
	mockFetch.mockResolvedValueOnce(
		new Response( JSON.stringify( body ), { status } )
	);
}

function sampleBundle( name = 'site-knowledge' ) {
	return {
		name,
		protected: false,
		concept_count: 2,
		stale_count: 1,
		deprecated_count: 0,
		conformant: true,
		issue_count: 0,
		types: [ 'Policy', 'HowTo' ],
		trust_tiers: [ 0, 2 ],
		modified: 1700000000,
	};
}

function sampleConcept( concept_id = 'policies/refunds' ) {
	return {
		concept_id,
		type: 'Policy',
		title: 'Refund policy.',
		description: 'How refunds work.',
		tags: [ 'refunds' ],
		status: 'stable',
		trust_tier: 'human-reviewed',
		stale: false,
	};
}

// ── Hooks ──────────────────────────────────────────────────────────────────────

beforeEach( () => {
	vi.stubGlobal( 'fetch', mockFetch );
	mockFetch.mockReset();
} );

afterEach( () => {
	vi.restoreAllMocks();
} );

// ═══════════════════════════════════════════════════════════════════════════════
// OkfClient
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'OkfClient', () => {
	const baseOpts: OkfClientOptions = {
		endpoint: 'https://ex.com/wp-json/mcp-ai-pro/v1/okf/',
		nonce: 'wp-nonce-abc',
	};

	describe( 'constructor', () => {
		it( 'stores base and nonce', () => {
			const client = new OkfClient( baseOpts );
			expect( ( client as unknown as Record< string, unknown > ).base ).toBe(
				'https://ex.com/wp-json/mcp-ai-pro/v1/okf'
			);
			expect( ( client as unknown as Record< string, unknown > ).nonce ).toBe(
				'wp-nonce-abc'
			);
		} );
	} );

	describe( 'listBundles', () => {
		it( 'requests /bundles and returns the bundles array', async () => {
			mockFetchOk( { bundles: [ sampleBundle() ] } );
			const client = new OkfClient( baseOpts );
			const bundles = await client.listBundles();

			expect( mockFetch ).toHaveBeenCalledTimes( 1 );
			const url = mockFetch.mock.calls[ 0 ][ 0 ] as string;
			expect( url ).toBe( 'https://ex.com/wp-json/mcp-ai-pro/v1/okf/bundles' );
			expect( bundles ).toHaveLength( 1 );
			expect( bundles[ 0 ].name ).toBe( 'site-knowledge' );
		} );

		it( 'sends the X-WP-Nonce header', async () => {
			mockFetchOk( { bundles: [] } );
			const client = new OkfClient( baseOpts );
			await client.listBundles();

			const init = mockFetch.mock.calls[ 0 ][ 1 ] as RequestInit;
			expect( ( init.headers as Record< string, string > )[ 'X-WP-Nonce' ] ).toBe(
				'wp-nonce-abc'
			);
		} );

		it( 'tolerates a missing bundles key', async () => {
			mockFetchOk( {} );
			const client = new OkfClient( baseOpts );
			await expect( client.listBundles() ).resolves.toEqual( [] );
		} );
	} );

	describe( 'listConcepts', () => {
		it( 'URL-encodes the bundle and appends query params', async () => {
			mockFetchOk( { concepts: [ sampleConcept() ], total: 1 } );
			const client = new OkfClient( baseOpts );
			await client.listConcepts( 'site-knowledge', {
				q: 'refund',
				status: 'stable',
				trust_tier: 'human-reviewed',
				include_stale: false,
				limit: 50,
			} );

			const url = new URL( mockFetch.mock.calls[ 0 ][ 0 ] as string );
			expect( url.pathname ).toBe(
				'/wp-json/mcp-ai-pro/v1/okf/bundles/site-knowledge/concepts'
			);
			expect( url.searchParams.get( 'q' ) ).toBe( 'refund' );
			expect( url.searchParams.get( 'status' ) ).toBe( 'stable' );
			expect( url.searchParams.get( 'trust_tier' ) ).toBe( 'human-reviewed' );
			expect( url.searchParams.get( 'include_stale' ) ).toBe( '0' );
			expect( url.searchParams.get( 'limit' ) ).toBe( '50' );
		} );
	} );

	describe( 'getConcept', () => {
		it( 'requests the encoded concept route', async () => {
			mockFetchOk( {
				bundle: 'site-knowledge',
				concept_id: 'policies/refunds',
				frontmatter: { title: 'Refund policy.' },
				body: '# Body',
				links: [],
				trust_tier: 'human-reviewed',
				stale: false,
			} );
			const client = new OkfClient( baseOpts );
			const concept = await client.getConcept( 'site-knowledge', 'policies/refunds' );

			const url = mockFetch.mock.calls[ 0 ][ 0 ] as string;
			expect( url ).toBe(
				'https://ex.com/wp-json/mcp-ai-pro/v1/okf/bundles/site-knowledge/concepts/policies%2Frefunds'
			);
			expect( concept.trust_tier ).toBe( 'human-reviewed' );
		} );
	} );

	describe( 'search', () => {
		it( 'requests /search with q', async () => {
			mockFetchOk( { results: [], total: 0 } );
			const client = new OkfClient( baseOpts );
			await client.search( 'refund' );

			const url = new URL( mockFetch.mock.calls[ 0 ][ 0 ] as string );
			expect( url.pathname ).toBe( '/wp-json/mcp-ai-pro/v1/okf/search' );
			expect( url.searchParams.get( 'q' ) ).toBe( 'refund' );
		} );
	} );

	describe( 'listSkills', () => {
		it( 'requests /skills with assistant_id', async () => {
			mockFetchOk( { assistant_id: 42, skills: [] } );
			const client = new OkfClient( baseOpts );
			await client.listSkills( 42 );

			const url = new URL( mockFetch.mock.calls[ 0 ][ 0 ] as string );
			expect( url.pathname ).toBe( '/wp-json/mcp-ai-pro/v1/okf/skills' );
			expect( url.searchParams.get( 'assistant_id' ) ).toBe( '42' );
		} );
	} );
} );

// ═══════════════════════════════════════════════════════════════════════════════
// OkfDrawer component
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'OkfDrawer', () => {
	it( 'renders nothing when isOpen is false', () => {
		const props = defaultDrawerProps( { isOpen: false } );
		const { container } = render( <OkfDrawer { ...props } /> );
		expect( container.innerHTML ).toBe( '' );
	} );

	it( 'renders the drawer when isOpen is true', async () => {
		mockFetchOk( { bundles: [] } );
		render( <OkfDrawer { ...defaultDrawerProps() } /> );
		expect(
			await screen.findByRole( 'dialog', { name: 'Skills & Knowledge' } )
		).toBeInTheDocument();
	} );

	it( 'renders two tabs', async () => {
		mockFetchOk( { bundles: [] } );
		render( <OkfDrawer { ...defaultDrawerProps() } /> );
		await screen.findByRole( 'dialog', { name: 'Skills & Knowledge' } );

		const tabs = screen.getAllByRole( 'tab' );
		expect( tabs ).toHaveLength( 2 );
		expect( tabs[ 0 ] ).toHaveTextContent( 'Knowledge' );
		expect( tabs[ 1 ] ).toHaveTextContent( 'Skills' );
	} );

	it( 'calls onClose when Escape is pressed', async () => {
		const onClose = vi.fn();
		mockFetchOk( { bundles: [] } );
		render( <OkfDrawer { ...defaultDrawerProps( { onClose } ) } /> );
		await screen.findByRole( 'dialog', { name: 'Skills & Knowledge' } );

		fireEvent.keyDown( document, { key: 'Escape' } );
		expect( onClose ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'calls onClose when the close button is clicked', async () => {
		const onClose = vi.fn();
		mockFetchOk( { bundles: [] } );
		render( <OkfDrawer { ...defaultDrawerProps( { onClose } ) } /> );
		await screen.findByRole( 'dialog', { name: 'Skills & Knowledge' } );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Close skills & knowledge drawer' } )
		);
		expect( onClose ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'renders bundles and drills into concepts', async () => {
		mockFetchOk( { bundles: [ sampleBundle() ] } );
		mockFetchOk( { bundle: 'site-knowledge', concepts: [ sampleConcept() ], total: 1 } );

		render( <OkfDrawer { ...defaultDrawerProps() } /> );

		const bundleCard = await screen.findByTestId( 'nvoos-pro-spa-okf-bundle' );
		fireEvent.click( bundleCard );

		const conceptCard = await screen.findByTestId( 'nvoos-pro-spa-okf-concept' );
		expect( conceptCard ).toHaveTextContent( 'Refund policy.' );
		expect( conceptCard ).toHaveTextContent( 'Human-reviewed' );
		expect( conceptCard ).toHaveTextContent( 'Stable' );
	} );

	it( 'opens concept detail with cross-links', async () => {
		mockFetchOk( { bundles: [ sampleBundle() ] } );
		mockFetchOk( { bundle: 'site-knowledge', concepts: [ sampleConcept() ], total: 1 } );
		mockFetchOk( {
			bundle: 'site-knowledge',
			concept_id: 'policies/refunds',
			frontmatter: { title: 'Refund policy.', description: 'How refunds work.' },
			body: 'Body with [link](policies/exchanges.md).',
			links: [ 'policies/exchanges' ],
			trust_tier: 'human-reviewed',
			stale: false,
		} );

		render( <OkfDrawer { ...defaultDrawerProps() } /> );

		fireEvent.click( await screen.findByTestId( 'nvoos-pro-spa-okf-bundle' ) );
		fireEvent.click( await screen.findByTestId( 'nvoos-pro-spa-okf-concept' ) );

		const detail = await screen.findByTestId( 'nvoos-pro-spa-okf-detail' );
		expect( detail ).toHaveTextContent( 'policies/exchanges' );
		expect( screen.getByTestId( 'mock-markdown' ) ).toBeInTheDocument();
	} );

	it( 'renders the skills tab with a loadable skill', async () => {
		const onInsertPrompt = vi.fn();
		mockFetchOk( { bundles: [] } );
		mockFetchOk( {
			assistant_id: 1,
			skills: [
				{
					name: 'site-knowledge:policies/refunds',
					bundle: 'site-knowledge',
					concept_id: 'policies/refunds',
					title: 'Refund policy.',
					description: 'How refunds work.',
					type: 'Policy',
					status: 'stable',
					trust_tier: 'human-reviewed',
					stale: false,
					loadable: true,
					error: '',
				},
			],
		} );

		render(
			<OkfDrawer { ...defaultDrawerProps( { onInsertPrompt } ) } />
		);
		await screen.findByRole( 'dialog', { name: 'Skills & Knowledge' } );

		fireEvent.click( screen.getByRole( 'tab', { name: 'Skills' } ) );

		const skill = await screen.findByTestId( 'nvoos-pro-spa-okf-skill' );
		expect( skill ).toHaveTextContent( 'Refund policy.' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Load' } ) );
		expect( onInsertPrompt ).toHaveBeenCalledTimes( 1 );
		expect( onInsertPrompt ).toHaveBeenCalledWith(
			expect.stringContaining( 'site-knowledge:policies/refunds' ),
			true
		);
	} );

	it( 'shows the error for an unloadable skill', async () => {
		mockFetchOk( { bundles: [] } );
		mockFetchOk( {
			assistant_id: 1,
			skills: [
				{
					name: 'site-knowledge:drafts/wip',
					bundle: 'site-knowledge',
					concept_id: 'drafts/wip',
					title: 'Drafts/wip',
					description: '',
					type: '',
					status: 'draft',
					trust_tier: 'unverified',
					stale: false,
					loadable: false,
					error: 'The OKF concept "site-knowledge:drafts/wip" is a draft and cannot be loaded as a skill.',
				},
			],
		} );

		render( <OkfDrawer { ...defaultDrawerProps() } /> );
		await screen.findByRole( 'dialog', { name: 'Skills & Knowledge' } );

		fireEvent.click( screen.getByRole( 'tab', { name: 'Skills' } ) );

		const skill = await screen.findByTestId( 'nvoos-pro-spa-okf-skill' );
		expect( skill ).toHaveTextContent( 'is a draft and cannot be loaded' );
		expect( screen.queryByRole( 'button', { name: 'Load' } ) ).not.toBeInTheDocument();
	} );

	it( 'filters concepts via search within a bundle', async () => {
		mockFetchOk( { bundles: [ sampleBundle() ] } );
		mockFetchOk( { bundle: 'site-knowledge', concepts: [ sampleConcept() ], total: 1 } );
		mockFetchOk( {
			bundle: 'site-knowledge',
			concepts: [ sampleConcept() ],
			total: 1,
		} );

		render( <OkfDrawer { ...defaultDrawerProps() } /> );

		fireEvent.click( await screen.findByTestId( 'nvoos-pro-spa-okf-bundle' ) );
		await screen.findByTestId( 'nvoos-pro-spa-okf-concept' );

		const searchInput = screen.getByRole( 'searchbox', {
			name: 'Search OKF knowledge',
		} );
		fireEvent.change( searchInput, { target: { value: 'refund' } } );

		// Debounced search resolves to a result card (bundle-scoped).
		await screen.findByTestId( 'nvoos-pro-spa-okf-search-result' );
	} );
} );
