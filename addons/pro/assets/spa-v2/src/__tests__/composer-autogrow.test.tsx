/**
 * Pro SPA v2 — composer vertical-expansion tests (Proposal 080).
 *
 * Covers:
 *   - useAutoGrowTextarea: JS scrollHeight fallback grows the textarea;
 *     the native field-sizing path leaves inline heights untouched.
 *   - clampComposerHeight / maxComposerHeight bounds.
 *   - useComposerHeight: localStorage persistence + clamping.
 *   - ResizableComposerHandle: drag, keyboard, and double-click toggle.
 *   - AgentPanel integration: expand (focus mode) dialog lifecycle.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React, { useRef, type RefObject, type JSX } from 'react';

import { useAutoGrowTextarea } from '../hooks/useAutoGrowTextarea';
import {
	useComposerHeight,
	clampComposerHeight,
	maxComposerHeight,
	MIN_COMPOSER_HEIGHT,
} from '../hooks/useComposerHeight';
import { ResizableComposerHandle } from '../components/shared/ResizableComposerHandle';
import { AgentPanel, type AgentPanelProps } from '../features/chat/AgentPanel';

// ── Mocks ──────────────────────────────────────────────────────────────────────

vi.mock( '@wordpress/i18n', () => ( {
	__: ( text: string ) => text,
	sprintf: ( text: string ) => text,
} ) );

/** Stub CSS.supports so the auto-grow hook takes a deterministic path. */
function stubCssSupports( result: boolean ): void {
	const win = window as unknown as {
		CSS?: { supports?: ( p: string, v: string ) => boolean };
	};
	if ( ! win.CSS || typeof win.CSS.supports !== 'function' ) {
		Object.defineProperty( window, 'CSS', {
			configurable: true,
			value: { supports: () => result },
		} );
		return;
	}
	// Overwrite via defineProperty so repeated calls never double-spy.
	Object.defineProperty( win.CSS, 'supports', {
		configurable: true,
		writable: true,
		value: () => result,
	} );
}

/** Make requestAnimationFrame execute synchronously for deterministic tests.
 * Returns 0 so the hook's `raf = requestAnimationFrame( cb )` bookkeeping
 * stays consistent with the already-executed callback. */
function stubSynchronousRaf(): void {
	vi.stubGlobal(
		'requestAnimationFrame',
		( cb: FrameRequestCallback ) => {
			cb( 0 );
			return 0;
		}
	);
	vi.stubGlobal( 'cancelAnimationFrame', () => {} );
}

// ── Test helpers ───────────────────────────────────────────────────────────────

function setScrollHeight( el: HTMLElement, px: number ): void {
	Object.defineProperty( el, 'scrollHeight', {
		configurable: true,
		get: () => px,
	} );
}

function Harness( {
	value,
	enabled = true,
}: {
	value: string;
	enabled?: boolean;
} ): JSX.Element {
	const ref = useRef< HTMLTextAreaElement | null >( null );
	useAutoGrowTextarea( ref, value, enabled );
	return <textarea ref={ ref } value={ value } readOnly aria-label="test-input" />;
}

function HeightHarness(): JSX.Element {
	const { height, setHeight } = useComposerHeight();
	return (
		<button onClick={ () => setHeight( 300 ) }>
			{ height === null ? 'auto' : String( height ) }
		</button>
	);
}

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
		...overrides,
	};
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────

beforeEach( () => {
	localStorage.clear();
	stubCssSupports( false );
	stubSynchronousRaf();
} );

afterEach( () => {
	vi.restoreAllMocks();
	vi.unstubAllGlobals();
} );

// ── useAutoGrowTextarea ────────────────────────────────────────────────────────

describe( 'useAutoGrowTextarea', () => {
	it( 'grows the textarea via scrollHeight when field-sizing is unsupported', () => {
		const { container, rerender } = render( <Harness value="a" /> );
		const el = container.querySelector( 'textarea' )!;

		setScrollHeight( el, 84 );
		fireEvent.input( el );
		expect( el.style.height ).toBe( '84px' );

		// Programmatic value changes re-measure too (media insert, voice…).
		setScrollHeight( el, 126 );
		rerender( <Harness value={ 'a\nb\nc' } /> );
		expect( el.style.height ).toBe( '126px' );
	} );

	it( 'leaves the height to CSS when field-sizing is supported', () => {
		stubCssSupports( true );
		const { container } = render( <Harness value="a" /> );
		const el = container.querySelector( 'textarea' )!;

		setScrollHeight( el, 84 );
		fireEvent.input( el );
		expect( el.style.height ).toBe( '' );
	} );

	it( 'clears the inline height when growth is disabled', () => {
		const { container, rerender } = render( <Harness value="a" /> );
		const el = container.querySelector( 'textarea' )!;

		setScrollHeight( el, 84 );
		fireEvent.input( el );
		expect( el.style.height ).toBe( '84px' );

		// Manual composer sizing takes over — the stylesheet height must
		// win over the inline height the fallback path set.
		rerender( <Harness value="a" enabled={ false } /> );
		expect( el.style.height ).toBe( '' );
	} );
} );

// ── useComposerHeight ─────────────────────────────────────────────────────────

describe( 'useComposerHeight', () => {
	it( 'persists the manual height and restores it after remount', () => {
		const { unmount } = render( <HeightHarness /> );
		fireEvent.click( screen.getByRole( 'button' ) );
		expect( screen.getByRole( 'button' ).textContent ).toBe( '300' );
		expect(
			localStorage.getItem( 'nvoos-pro-spa.composerHeight' )
		).toBe( '300' );

		unmount();
		render( <HeightHarness /> );
		expect( screen.getByRole( 'button' ).textContent ).toBe( '300' );
	} );

	it( 'clamps values to the allowed range', () => {
		expect( clampComposerHeight( 10 ) ).toBe( MIN_COMPOSER_HEIGHT );
		expect( clampComposerHeight( 100000 ) ).toBe( maxComposerHeight() );
		expect( clampComposerHeight( 200 ) ).toBe( 200 );
	} );
} );

// ── ResizableComposerHandle ────────────────────────────────────────────────────

describe( 'ResizableComposerHandle', () => {
	function renderHandle(
		height: number | null,
		onChange: ( h: number | null ) => void = vi.fn<( h: number | null ) => void>()
	) {
		const target = document.createElement( 'div' );
		vi.spyOn( target, 'getBoundingClientRect' ).mockReturnValue( {
			height: 200,
			width: 600,
			top: 0,
			left: 0,
			right: 600,
			bottom: 200,
			x: 0,
			y: 0,
			toJSON: () => ( {} ),
		} as DOMRect );
		const targetRef: RefObject< HTMLDivElement | null > = {
			current: target,
		};
		const { rerender } = render(
			<ResizableComposerHandle
				height={ height }
				onChange={ onChange }
				targetRef={ targetRef }
			/>
		);
		return {
			handle: screen.getByTestId(
				'nvoos-pro-spa-composer-resizer'
			) as HTMLButtonElement,
			onChange,
			rerender,
			targetRef,
		};
	}

	it( 'resizes on drag and stops after pointer up', () => {
		const { handle, onChange } = renderHandle( 300 );
		fireEvent.pointerDown( handle, { button: 0, clientY: 400, pointerId: 1 } );
		fireEvent.pointerMove( handle, { clientY: 250 } ); // up 150 → 450
		expect( onChange ).toHaveBeenLastCalledWith( 450 );

		fireEvent.pointerUp( handle );
		fireEvent.pointerMove( handle, { clientY: 100 } );
		expect( onChange ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'measures the live composer when a drag starts from auto', () => {
		const { handle, onChange } = renderHandle( null );
		fireEvent.pointerDown( handle, { button: 0, clientY: 400, pointerId: 1 } );
		fireEvent.pointerMove( handle, { clientY: 300 } ); // up 100 → 200 + 100
		expect( onChange ).toHaveBeenLastCalledWith( 300 );
	} );

	it( 'supports Arrow / Home / End / Escape keys', () => {
		const { handle, onChange } = renderHandle( 300 );
		fireEvent.keyDown( handle, { key: 'ArrowUp' } );
		expect( onChange ).toHaveBeenLastCalledWith( 324 );
		fireEvent.keyDown( handle, { key: 'ArrowDown' } );
		expect( onChange ).toHaveBeenLastCalledWith( 276 );
		fireEvent.keyDown( handle, { key: 'Home' } );
		expect( onChange ).toHaveBeenLastCalledWith( MIN_COMPOSER_HEIGHT );
		fireEvent.keyDown( handle, { key: 'End' } );
		expect( onChange ).toHaveBeenLastCalledWith( maxComposerHeight() );
		fireEvent.keyDown( handle, { key: 'Escape' } );
		expect( onChange ).toHaveBeenLastCalledWith( null );
	} );

	it( 'double-click toggles between auto and the last manual height', () => {
		const { handle, onChange, rerender, targetRef } = renderHandle( 300 );
		fireEvent.doubleClick( handle );
		expect( onChange ).toHaveBeenLastCalledWith( null );

		rerender(
			<ResizableComposerHandle
				height={ null }
				onChange={ onChange }
				targetRef={ targetRef }
			/>
		);
		fireEvent.doubleClick( handle );
		expect( onChange ).toHaveBeenLastCalledWith( 300 );
	} );
} );

// ── AgentPanel integration ────────────────────────────────────────────────────

describe( 'AgentPanel — vertical expansion surfaces', () => {
	it( 'renders the resize divider and the expand button', () => {
		render( <AgentPanel { ...defaultPanelProps() } /> );
		expect(
			screen.getByTestId( 'nvoos-pro-spa-composer-resizer' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Expand message composer' } )
		).toBeInTheDocument();
	} );

	it( 'marks the panel as resized when a manual composer height is persisted', () => {
		localStorage.setItem( 'nvoos-pro-spa.composerHeight', '300' );
		render( <AgentPanel { ...defaultPanelProps() } /> );
		expect(
			screen.getByRole( 'region', { name: 'Chat agent panel' } )
		).toHaveClass( 'nvoos-pro-spa-agent-panel--composer-resized' );
	} );

	it( 'opens the expanded composer, focuses it, and closes on Escape', () => {
		render( <AgentPanel { ...defaultPanelProps() } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Expand message composer' } )
		);

		const dialog = screen.getByRole( 'dialog', {
			name: 'Expanded message composer',
		} );
		expect( dialog ).toBeInTheDocument();
		const focusEl = dialog.querySelector( 'textarea' )!;
		expect( document.activeElement ).toBe( focusEl );

		fireEvent.keyDown( focusEl, { key: 'Escape' } );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );

	it( 'submits from the expanded composer and closes it', () => {
		const handleSubmit = vi.fn();
		render(
			<AgentPanel
				{ ...defaultPanelProps( { input: 'hello', handleSubmit } ) }
			/>
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Expand message composer' } )
		);

		const focusEl = screen
			.getByRole( 'dialog' )
			.querySelector( 'textarea' )!;
		fireEvent.keyDown( focusEl, { key: 'Enter' } );
		expect( handleSubmit ).toHaveBeenCalled();
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );
} );
