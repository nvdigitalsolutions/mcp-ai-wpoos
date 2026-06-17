/**
 * document-editor — unit tests.
 *
 * Tests App mode dispatch and the ComingSoon placeholder component.
 * Tiptap and GrapesJS are kept out of the test environment by mocking
 * the EditorCanvas and SiteCreatorCanvas modules.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

// ---------------------------------------------------------------------------
// Mock heavy editor surfaces so Tiptap / GrapesJS never load in jsdom.
// ---------------------------------------------------------------------------
vi.mock( '../components/EditorCanvas', () => ( {
	EditorCanvas: ( { toolkit, documentId }: { toolkit?: string; documentId?: number } ) => {
		// Return a simple placeholder so we can assert the component was chosen.
		const { createElement } = require( 'react' );
		return createElement(
			'div',
			{ 'data-testid': 'editor-canvas', 'data-toolkit': toolkit, 'data-doc-id': documentId },
			'EditorCanvas'
		);
	},
} ) );
vi.mock( '../components/SiteCreatorCanvas', () => ( {
	SiteCreatorCanvas: () => {
		const { createElement } = require( 'react' );
		return createElement( 'div', { 'data-testid': 'site-creator-canvas' }, 'SiteCreatorCanvas' );
	},
} ) );

import { App, type EditorMode } from '../App';
import { ComingSoon } from '../components/ComingSoon';

// ---------------------------------------------------------------------------
// App — mode dispatch
// ---------------------------------------------------------------------------
describe( 'App', () => {
	it( 'defaults to editor mode when no mode is supplied', () => {
		const { container } = render( <App config={ {} } /> );
		expect( container.querySelector( '[data-mode="editor"]' ) ).not.toBeNull();
	} );

	it( 'renders EditorCanvas when mode="editor"', () => {
		render( <App config={ { mode: 'editor' } } /> );
		expect( screen.getByTestId( 'editor-canvas' ) ).toBeInTheDocument();
	} );

	it( 'renders SiteCreatorCanvas when mode="site-creator"', () => {
		render( <App config={ { mode: 'site-creator' } } /> );
		expect( screen.getByTestId( 'site-creator-canvas' ) ).toBeInTheDocument();
	} );

	it( 'sets data-mode="site-creator" correctly', () => {
		const { container } = render( <App config={ { mode: 'site-creator' } } /> );
		expect( container.querySelector( '[data-mode="site-creator"]' ) ).not.toBeNull();
	} );

	it( 'applies the toolkit prop through to EditorCanvas', () => {
		render( <App config={ { mode: 'editor', toolkit: 'my-toolkit' } } /> );
		expect( screen.getByTestId( 'editor-canvas' ) ).toHaveAttribute( 'data-toolkit', 'my-toolkit' );
	} );

	it( 'applies the document_id prop through to EditorCanvas', () => {
		render( <App config={ { mode: 'editor', document_id: 42 } } /> );
		expect( screen.getByTestId( 'editor-canvas' ) ).toHaveAttribute( 'data-doc-id', '42' );
	} );

	it( 'defaults data-theme to "auto"', () => {
		const { container } = render( <App config={ {} } /> );
		expect( container.querySelector( '[data-theme="auto"]' ) ).not.toBeNull();
	} );
} );

// ---------------------------------------------------------------------------
// ComingSoon — placeholder stub
// ---------------------------------------------------------------------------
describe( 'ComingSoon', () => {
	it( 'renders the label', () => {
		render( <ComingSoon mode="site-creator" label="Site Creator" note="Shipping in v0.3." /> );
		expect( screen.getByText( 'Site Creator' ) ).toBeInTheDocument();
	} );

	it( 'includes "coming soon" wording', () => {
		render( <ComingSoon mode="site-creator" label="Site Creator" note="Shipping in v0.3." /> );
		expect( screen.getByText( /coming soon/i ) ).toBeInTheDocument();
	} );

	it( 'renders the note', () => {
		render( <ComingSoon mode="site-creator" label="Site Creator" note="Details TBD." /> );
		expect( screen.getByText( 'Details TBD.' ) ).toBeInTheDocument();
	} );
} );
