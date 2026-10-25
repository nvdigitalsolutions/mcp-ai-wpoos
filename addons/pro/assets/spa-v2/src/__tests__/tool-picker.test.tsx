/**
 * Pro SPA v2 — ToolPicker tests (Proposal 088).
 *
 * Covers: parseToolSlugs, chip rendering + name resolution, unknown-slug
 * flagging, search filtering, click toggle, capability gating (incl. the
 * already-selected escape hatch), creatable raw-slug entry, comma-paste,
 * clear-all, add-all-matching, Backspace chip removal, and click-outside.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { useState, type JSX } from 'react';
import {
	ToolPicker,
	parseToolSlugs,
	toolSeverity,
	type ToolPickerProps,
} from '../features/assistants/ToolPicker';
import type { ToolDefinition } from '../api/tools';

// ── Mocks ──────────────────────────────────────────────────────────────────────

// Interpolating i18n mock so sprintf-produced labels ("Add "x"", "N selected")
// are deterministic and assertable.
vi.mock( '@wordpress/i18n', () => ( {
	__: ( text: string ) => text,
	sprintf: ( text: string, ...args: unknown[] ) =>
		text.replace( /%[sd]/g, () => String( args.shift() ?? '' ) ),
} ) );

const TOOLS: ToolDefinition[] = [
	{
		slug: 'web_search',
		name: 'Web Search',
		description: 'Search the web for current information.',
		category: 'Web',
		required_capability: '',
		flags: [ 'read-only', 'external-api' ],
		parameters: {},
	},
	{
		slug: 'list_posts',
		name: 'List Posts',
		description: 'List site posts.',
		category: 'WordPress',
		required_capability: '',
		flags: [ 'read-only' ],
		parameters: {},
	},
	{
		slug: 'get_site_health',
		name: 'Site Health',
		description: 'Retrieve the WordPress site health report.',
		category: 'WordPress',
		required_capability: 'edit_posts',
		flags: [ 'read-only', 'requires-capability' ],
		parameters: {},
	},
	{
		slug: 'wipe_database',
		name: 'Wipe Database',
		description: 'Needs admin capabilities.',
		category: 'WordPress',
		required_capability: 'manage_options',
		flags: [ 'write', 'state-changing', 'data-destruction' ],
		parameters: {},
	},
];

function renderPicker( props: Partial< ToolPickerProps > = {} ) {
	const onChange = vi.fn();
	const utils = render(
		<ToolPicker
			selected={ [] }
			onChange={ onChange }
			tools={ TOOLS }
			{ ...props }
		/>,
	);
	return { onChange, ...utils };
}

/** Controlled wrapper so toggle-off paths can assert on re-render. */
function StatefulPicker( props: Partial< ToolPickerProps > ) {
	const [ selected, setSelected ] = useState< string[] >(
		props.selected ?? [],
	);
	return (
		<ToolPicker
			tools={ TOOLS }
			{ ...props }
			selected={ selected }
			onChange={ setSelected }
		/>
	);
}

function open( input: HTMLElement ): void {
	fireEvent.focus( input );
}

function combobox(): HTMLInputElement {
	return screen.getByRole( 'combobox' ) as HTMLInputElement;
}

// ── parseToolSlugs ────────────────────────────────────────────────────────────

describe( 'parseToolSlugs', () => {
	it( 'splits on commas and trims whitespace', () => {
		expect( parseToolSlugs( 'a, b ,c' ) ).toEqual( [ 'a', 'b', 'c' ] );
	} );

	it( 'splits on newlines', () => {
		expect( parseToolSlugs( 'a\nb' ) ).toEqual( [ 'a', 'b' ] );
	} );

	it( 'drops empty segments', () => {
		expect( parseToolSlugs( ', , a,,, b,' ) ).toEqual( [ 'a', 'b' ] );
	} );

	it( 'returns an empty array for blank input', () => {
		expect( parseToolSlugs( '   ' ) ).toEqual( [] );
	} );

	it( 'preserves order and duplicates (dedupe happens at add time)', () => {
		expect( parseToolSlugs( 'b, a, b' ) ).toEqual( [ 'b', 'a', 'b' ] );
	} );
} );

// ── toolSeverity ──────────────────────────────────────────────────────────────

describe( 'toolSeverity', () => {
	it( 'maps data-destruction and its peers to danger', () => {
		expect( toolSeverity( [ 'write', 'data-destruction' ] ) ).toBe( 'danger' );
		expect( toolSeverity( [ 'irreversible' ] ) ).toBe( 'danger' );
		expect( toolSeverity( [ 'financial-impact' ] ) ).toBe( 'danger' );
		expect( toolSeverity( [ 'access-control-change' ] ) ).toBe( 'danger' );
	} );

	it( 'maps write/state-changing to warning', () => {
		expect( toolSeverity( [ 'write' ] ) ).toBe( 'warning' );
		expect( toolSeverity( [ 'state-changing' ] ) ).toBe( 'warning' );
	} );

	it( 'maps external-communication to caution', () => {
		expect( toolSeverity( [ 'external-communication' ] ) ).toBe( 'caution' );
	} );

	it( 'maps read-only to safe', () => {
		expect( toolSeverity( [ 'read-only' ] ) ).toBe( 'safe' );
		expect( toolSeverity( [ 'read-only', 'external-api' ] ) ).toBe( 'safe' );
	} );

	it( 'returns neutral for no or unrecognised flags', () => {
		expect( toolSeverity( [] ) ).toBe( 'neutral' );
		expect( toolSeverity( undefined ) ).toBe( 'neutral' );
		expect( toolSeverity( [ 'pro' ] ) ).toBe( 'neutral' );
	} );

	it( 'matches the legacy precedence: danger beats warning beats caution', () => {
		expect(
			toolSeverity( [
				'read-only',
				'external-communication',
				'write',
				'data-destruction',
			] ),
		).toBe( 'danger' );
		expect( toolSeverity( [ 'read-only', 'external-communication', 'write' ] ) ).toBe(
			'warning',
		);
		expect( toolSeverity( [ 'read-only', 'external-communication' ] ) ).toBe(
			'caution',
		);
	} );
} );

// ── Chips & summaries ─────────────────────────────────────────────────────────

describe( 'ToolPicker chips', () => {
	it( 'renders selected slugs as chips resolved to registry names', () => {
		renderPicker( { selected: [ 'web_search' ] } );
		expect(
			screen.getByRole( 'button', { name: 'Remove Web Search' } ),
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Remove web_search' } ),
		).not.toBeInTheDocument();
	} );

	it( 'flags slugs not present in the registry without dropping them', () => {
		const { container } = renderPicker( {
			selected: [ 'ghost_tool', 'web_search' ],
		} );
		expect(
			screen.getByRole( 'button', { name: 'Remove ghost_tool' } ),
		).toBeInTheDocument();
		expect(
			container.querySelector(
				'.nvoos-pro-spa-tool-picker__chip--unknown',
			),
		).toBeInTheDocument();
		expect(
			screen.getByText( '(1 not found in the tool registry)' ),
		).toBeInTheDocument();
	} );

	it( 'shows the selected count', () => {
		renderPicker( { selected: [ 'web_search', 'list_posts' ] } );
		expect( screen.getByText( '2 selected' ) ).toBeInTheDocument();
	} );

	it( 'removes a chip on click', () => {
		const { onChange } = renderPicker( { selected: [ 'web_search' ] } );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Remove Web Search' } ),
		);
		expect( onChange ).toHaveBeenCalledWith( [] );
	} );

	it( 'colour-codes chips by capability-flag severity', () => {
		const { container } = renderPicker( {
			selected: [ 'web_search', 'wipe_database' ],
		} );
		expect(
		container.querySelector(
			'.nvoos-pro-spa-tool-picker__chip--safe',
		),
		).toBeInTheDocument();
		expect(
		container.querySelector(
			'.nvoos-pro-spa-tool-picker__chip--danger',
		),
		).toBeInTheDocument();
	} );

	it( 'leaves unknown chips uncoloured (dashed style only)', () => {
		const { container } = renderPicker( { selected: [ 'ghost_tool' ] } );
		expect(
		container.querySelector( '.nvoos-pro-spa-tool-picker__chip--unknown' ),
		).toBeInTheDocument();
		expect(
		container.querySelector(
			'.nvoos-pro-spa-tool-picker__chip--safe, .nvoos-pro-spa-tool-picker__chip--danger, .nvoos-pro-spa-tool-picker__chip--warning, .nvoos-pro-spa-tool-picker__chip--caution, .nvoos-pro-spa-tool-picker__chip--neutral',
		),
		).not.toBeInTheDocument();
	} );
} );

// ── Search & toggle ───────────────────────────────────────────────────────────

describe( 'ToolPicker search & toggle', () => {
	it( 'filters options across name, description, and slug', () => {
		renderPicker();
		const input = combobox();
		open( input );
		expect( screen.getAllByRole( 'option' ) ).toHaveLength( TOOLS.length );

		fireEvent.change( input, { target: { value: 'health' } } );
		expect( screen.getAllByRole( 'option' ) ).toHaveLength( 1 );
		expect(
			screen.getByRole( 'option', { name: /Site Health/ } ),
		).toBeInTheDocument();

		fireEvent.change( input, { target: { value: 'web_' } } );
		expect(
			screen.getByRole( 'option', { name: /Web Search/ } ),
		).toBeInTheDocument();
		expect( screen.getAllByRole( 'option' ) ).toHaveLength( 1 );
	} );

	it( 'adds and removes a tool via click (controlled round-trip)', () => {
		render( <StatefulPicker selected={ [] } /> );
		const input = combobox();
		open( input );

		const option = screen.getByRole( 'option', { name: /Web Search/ } );
		fireEvent.click( option );
		expect( option ).toHaveAttribute( 'aria-selected', 'true' );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Remove Web Search' } ),
		);
		expect( screen.queryByText( '0 selected' ) ).toBeInTheDocument();
	} );

	it( 'colour-codes option rows by capability-flag severity', () => {
		renderPicker();
		open( combobox() );
		expect(
			screen.getByRole( 'option', { name: /Web Search/ } ),
		).toHaveClass( 'nvoos-pro-spa-tool-picker__option--safe' );
		expect(
			screen.getByRole( 'option', { name: /Wipe Database/ } ),
		).toHaveClass( 'nvoos-pro-spa-tool-picker__option--danger' );
	} );

	it( 'supports the ARIA combobox keyboard contract', () => {
		const { onChange } = renderPicker();
		const input = combobox();
		open( input );

		// First option in DOM order: the 'Web' group sorts before
		// 'WordPress', so ArrowDown + Enter selects Web Search.
		fireEvent.keyDown( input, { key: 'ArrowDown' } );
		expect( input ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( input.getAttribute( 'aria-activedescendant' ) ).toContain(
			'-option-0',
		);
		fireEvent.keyDown( input, { key: 'Enter' } );
		expect( onChange ).toHaveBeenCalledWith( [ 'web_search' ] );

		// Escape closes the listbox.
		fireEvent.keyDown( input, { key: 'Escape' } );
		expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
	} );

	it( 'closes on click outside', () => {
		renderPicker();
		const input = combobox();
		open( input );
		expect( input ).toHaveAttribute( 'aria-expanded', 'true' );
		fireEvent.mouseDown( document.body );
		expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
	} );
} );

// ── Capability gating ─────────────────────────────────────────────────────────

describe( 'ToolPicker capability gating', () => {
	it( 'disables options whose required capability the user lacks', () => {
		const { onChange } = renderPicker( { capabilities: [ 'edit_posts' ] } );
		open( combobox() );

		const gated = screen.getByRole( 'option', { name: /Wipe Database/ } );
		expect( gated ).toHaveAttribute( 'aria-disabled', 'true' );
		fireEvent.click( gated );
		expect( onChange ).not.toHaveBeenCalled();

		// A tool whose capability the user holds stays selectable.
		const granted = screen.getByRole( 'option', { name: /Site Health/ } );
		expect( granted ).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );

	it( 'keeps already-selected gated tools removable', () => {
		render(
			<StatefulPicker
				selected={ [ 'wipe_database' ] }
				capabilities={ [ 'edit_posts' ] }
			/>,
		);
		open( combobox() );

		const gated = screen.getByRole( 'option', { name: /Wipe Database/ } );
		expect( gated ).not.toHaveAttribute( 'aria-disabled', 'true' );
		fireEvent.click( gated );
		expect( screen.queryByText( '0 selected' ) ).toBeInTheDocument();
	} );

	it( 'fails open when no capabilities are reported', () => {
		renderPicker( { capabilities: [] } );
		open( combobox() );
		expect(
			screen.getByRole( 'option', { name: /Wipe Database/ } ),
		).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );
} );

// ── Creatable entry & bulk actions ────────────────────────────────────────────

describe( 'ToolPicker creatable entry & bulk actions', () => {
	it( 'commits a typed slug verbatim when nothing matches (Enter)', () => {
		const { onChange } = renderPicker();
		const input = combobox();
		open( input );
		fireEvent.change( input, { target: { value: 'brand_new_slug' } } );
		expect( screen.getByText( 'No matching tools.' ) ).toBeInTheDocument();
		fireEvent.keyDown( input, { key: 'Enter' } );
		expect( onChange ).toHaveBeenCalledWith( [ 'brand_new_slug' ] );
	} );

	it( 'accepts comma-pasted slugs via the legacy format', () => {
		const { onChange } = renderPicker();
		const input = combobox();
		open( input );
		fireEvent.change( input, { target: { value: 'new_a, new_b' } } );
		fireEvent.keyDown( input, { key: 'Enter' } );
		expect( onChange ).toHaveBeenCalledWith( [ 'new_a', 'new_b' ] );
	} );

	it( 'offers an Add "…" button when the query matches no option exactly', () => {
		const { onChange } = renderPicker();
		const input = combobox();
		open( input );
		fireEvent.change( input, { target: { value: 'solo_slug' } } );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Add “solo_slug”/ } ),
		);
		expect( onChange ).toHaveBeenCalledWith( [ 'solo_slug' ] );
	} );

	it( 'adds all matching non-disabled options', () => {
		const { onChange } = renderPicker( {
			capabilities: [ 'manage_options', 'edit_posts' ],
		} );
		open( combobox() );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Add all matching' } ),
		);
		expect( onChange ).toHaveBeenCalledWith( [
			'web_search',
			'list_posts',
			'get_site_health',
			'wipe_database',
		] );
	} );

	it( 'skips gated options when adding all matching', () => {
		const { onChange } = renderPicker( { capabilities: [ 'edit_posts' ] } );
		open( combobox() );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Add all matching' } ),
		);
		expect( onChange ).toHaveBeenCalledWith( [
			'web_search',
			'list_posts',
			'get_site_health',
		] );
	} );

	it( 'clears the selection', () => {
		const { onChange } = renderPicker( { selected: [ 'web_search' ] } );
		open( combobox() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Clear all' } ) );
		expect( onChange ).toHaveBeenCalledWith( [] );
	} );

	it( 'Backspace on an empty query removes the last chip', () => {
		const { onChange } = renderPicker( {
			selected: [ 'web_search', 'list_posts' ],
		} );
		const input = combobox();
		open( input );
		fireEvent.keyDown( input, { key: 'Backspace' } );
		expect( onChange ).toHaveBeenCalledWith( [ 'web_search' ] );
	} );
} );

// ── Loading & error states ────────────────────────────────────────────────────

describe( 'ToolPicker loading & error states', () => {
	it( 'shows a loading status while the registry is fetched', () => {
		renderPicker( { loading: true, tools: [] } );
		open( combobox() );
		expect( screen.getByText( 'Loading tools…' ) ).toBeInTheDocument();
	} );

	it( 'shows the error with a retry action', () => {
		const onRetry = vi.fn();
		renderPicker( { error: 'Registry unavailable', onRetry, tools: [] } );
		open( combobox() );
		expect(
			screen.getByRole( 'alert' ).textContent,
		).toContain( 'Registry unavailable' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Retry' } ) );
		expect( onRetry ).toHaveBeenCalledTimes( 1 );
	} );
} );
