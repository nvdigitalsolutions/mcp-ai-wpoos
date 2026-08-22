/**
 * Pro SPA v2 — Composer slash-command routing + result normalization tests.
 *
 * Covers:
 *   - AgentPanel routes "/"-prefixed composer submissions to
 *     onSubmitSlashCommand instead of the LLM handleSubmit.
 *   - Normal messages still route to handleSubmit.
 *   - The composer input is cleared before the command executes.
 *   - normalizeSlashResult converts string / array / object results safely.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';

import { AgentPanel, type AgentPanelProps } from '../features/chat/AgentPanel';
import { normalizeSlashResult } from '../features/chat/ChatPage';

// ── Mocks ──────────────────────────────────────────────────────────────────────

vi.mock( '@wordpress/i18n', () => ( {
	__: ( text: string ) => text,
} ) );

const mockFetch = vi.fn();

// ── Test helpers ───────────────────────────────────────────────────────────────

function defaultPanelProps(
	overrides: Partial< AgentPanelProps > = {}
): AgentPanelProps {
	return {
		messages: [],
		input: '',
		handleInputChange: vi.fn(),
		handleSubmit: vi.fn(),
		status: 'ready',
		error: undefined,
		stop: vi.fn(),
		reload: vi.fn(),
		isStreaming: false,
		sendMessage: vi.fn(),
		threadId: 0,
		threadTitle: '',
		onRegenerate: vi.fn(),
		onSubmitSlashCommand: vi.fn().mockResolvedValue( undefined ),
		slashCommandsEndpoint:
			'https://ex.com/wp-json/mcp-ai-pro/v1/slash-commands',
		nonce: 'test-nonce',
		...overrides,
	};
}

function getTextarea( container: HTMLElement ): HTMLTextAreaElement {
	return container.querySelector(
		'#nvoos-pro-spa-composer-input'
	) as HTMLTextAreaElement;
}

function getForm( container: HTMLElement ): HTMLFormElement {
	return container.querySelector(
		'.nvoos-pro-spa-agent-panel__composer-form'
	) as HTMLFormElement;
}

// ── Hooks ──────────────────────────────────────────────────────────────────────

beforeEach( () => {
	vi.stubGlobal( 'fetch', mockFetch );
	mockFetch.mockReset();
	// Autocomplete hook fetches the command list on mount.
	mockFetch.mockResolvedValue(
		new Response( JSON.stringify( { commands: [] } ), { status: 200 } )
	);
} );

afterEach( () => {
	vi.restoreAllMocks();
} );

// ═══════════════════════════════════════════════════════════════════════════════
// Composer slash-command routing
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'AgentPanel composer slash-command routing', () => {
	it( 'routes a slash command submitted via Enter to onSubmitSlashCommand', () => {
		const props = defaultPanelProps( { input: '/workflow' } );
		const { container } = render( <AgentPanel { ...props } /> );

		fireEvent.keyDown( getTextarea( container ), { key: 'Enter' } );

		expect( props.onSubmitSlashCommand ).toHaveBeenCalledTimes( 1 );
		expect( props.onSubmitSlashCommand ).toHaveBeenCalledWith( '/workflow' );
		expect( props.handleSubmit ).not.toHaveBeenCalled();
		// Composer input is cleared before execution.
		expect( props.handleInputChange ).toHaveBeenCalledWith( '' );
	} );

	it( 'routes a slash command submitted via the form to onSubmitSlashCommand', () => {
		const props = defaultPanelProps( { input: '/optimize-perf' } );
		const { container } = render( <AgentPanel { ...props } /> );

		fireEvent.submit( getForm( container ) );

		expect( props.onSubmitSlashCommand ).toHaveBeenCalledTimes( 1 );
		expect( props.onSubmitSlashCommand ).toHaveBeenCalledWith(
			'/optimize-perf'
		);
		expect( props.handleSubmit ).not.toHaveBeenCalled();
	} );

	it( 'keeps normal messages on the LLM path (handleSubmit)', () => {
		const props = defaultPanelProps( { input: 'Hello there' } );
		const { container } = render( <AgentPanel { ...props } /> );

		fireEvent.keyDown( getTextarea( container ), { key: 'Enter' } );

		expect( props.handleSubmit ).toHaveBeenCalledTimes( 1 );
		expect( props.onSubmitSlashCommand ).not.toHaveBeenCalled();
	} );

	it( 'trims the input before routing a slash command', () => {
		const props = defaultPanelProps( { input: '  /workflow daily-review  ' } );
		const { container } = render( <AgentPanel { ...props } /> );

		fireEvent.keyDown( getTextarea( container ), { key: 'Enter' } );

		expect( props.onSubmitSlashCommand ).toHaveBeenCalledWith(
			'/workflow daily-review'
		);
		expect( props.handleSubmit ).not.toHaveBeenCalled();
	} );

	it( 'does not intercept when no slash executor is provided', () => {
		const props = defaultPanelProps( {
			input: '/workflow',
			onSubmitSlashCommand: undefined,
		} );
		const { container } = render( <AgentPanel { ...props } /> );

		fireEvent.keyDown( getTextarea( container ), { key: 'Enter' } );

		expect( props.handleSubmit ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows the executing label while a slash command is busy', () => {
		const props = defaultPanelProps( { isBusy: true } );
		render( <AgentPanel { ...props } /> );

		expect( screen.getByText( 'Executing…' ) ).toBeInTheDocument();
	} );
} );

// ═══════════════════════════════════════════════════════════════════════════════
// normalizeSlashResult
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'normalizeSlashResult', () => {
	it( 'returns strings unchanged', () => {
		expect( normalizeSlashResult( 'all good' ) ).toBe( 'all good' );
	} );

	it( 'joins string arrays with newlines', () => {
		expect( normalizeSlashResult( [ 'a', 'b' ] ) ).toBe( 'a\nb' );
	} );

	it( 'stringifies object items inside arrays', () => {
		expect( normalizeSlashResult( [ 'a', { id: 1 } ] ) ).toBe(
			'a\n{\n  "id": 1\n}'
		);
	} );

	it( 'pretty-prints plain objects', () => {
		expect( normalizeSlashResult( { id: 1 } ) ).toBe( '{\n  "id": 1\n}' );
	} );

	it( 'returns an empty string for null / undefined', () => {
		expect( normalizeSlashResult( null ) ).toBe( '' );
		expect( normalizeSlashResult( undefined ) ).toBe( '' );
	} );

	it( 'stringifies scalars', () => {
		expect( normalizeSlashResult( 42 ) ).toBe( '42' );
		expect( normalizeSlashResult( false ) ).toBe( 'false' );
	} );
} );
