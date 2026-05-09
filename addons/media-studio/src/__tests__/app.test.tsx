/**
 * media-studio — unit tests.
 *
 * Tests App mode dispatch logic.
 * react-konva, react-image-crop, wavesurfer.js, and react-player are kept
 * out of the test runner by mocking the three mode-specific components.
 */

import { describe, it, expect, vi } from 'vitest';
import { render } from '@testing-library/react';

// ---------------------------------------------------------------------------
// Mock the three media surfaces so their heavy imports never load in jsdom.
// ---------------------------------------------------------------------------
vi.mock( '../components/ImageEditor', () => ( { ImageEditor: () => null } ) );
vi.mock( '../components/MediaPlayer', () => ( { MediaPlayer: () => null } ) );
vi.mock( '../components/AudioWaveform', () => ( { AudioWaveform: () => null } ) );

import { App, type MediaMode } from '../App';

// ---------------------------------------------------------------------------
// App — mode dispatch
// ---------------------------------------------------------------------------
describe( 'App', () => {
	it( 'defaults to image-editor mode when no mode is supplied', () => {
		const { container } = render( <App config={ {} } /> );
		expect( container.querySelector( '[data-mode="image-editor"]' ) ).not.toBeNull();
	} );

	it( 'sets data-mode="image-editor" when mode="image-editor" is given', () => {
		const { container } = render( <App config={ { mode: 'image-editor' } } /> );
		expect( container.querySelector( '[data-mode="image-editor"]' ) ).not.toBeNull();
	} );

	it( 'sets data-mode="media-player" when mode="media-player" is given', () => {
		const { container } = render( <App config={ { mode: 'media-player' } } /> );
		expect( container.querySelector( '[data-mode="media-player"]' ) ).not.toBeNull();
	} );

	it( 'sets data-mode="audio-waveform" when mode="audio-waveform" is given', () => {
		const { container } = render( <App config={ { mode: 'audio-waveform' } } /> );
		expect( container.querySelector( '[data-mode="audio-waveform"]' ) ).not.toBeNull();
	} );

	it( 'falls back to image-editor for an unknown mode string', () => {
		const { container } = render( <App config={ { mode: 'unknown-mode' as MediaMode } } /> );
		expect( container.querySelector( '[data-mode="image-editor"]' ) ).not.toBeNull();
	} );

	it( 'applies the data-theme attribute when supplied', () => {
		const { container } = render( <App config={ { theme: 'dark' } } /> );
		expect( container.querySelector( '[data-theme="dark"]' ) ).not.toBeNull();
	} );

	it( 'defaults data-theme to "auto" when not supplied', () => {
		const { container } = render( <App config={ {} } /> );
		expect( container.querySelector( '[data-theme="auto"]' ) ).not.toBeNull();
	} );

	it( 'applies an inline height style when the height prop is provided', () => {
		const { container } = render( <App config={ { height: '600px' } } /> );
		const appEl = container.querySelector( '.nvoos-media-studio-app' ) as HTMLElement | null;
		expect( appEl?.style.height ).toBe( '600px' );
	} );
} );
