/**
 * media-studio — Fashion Studio unit tests.
 *
 * Covers the capabilities-driven transform panel, the review-confirm flow,
 * the one-time acknowledgment flow, and the compliance chips on results.
 * The REST client is mocked — no network.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { FashionStudio } from '../components/FashionStudio';

const capabilitiesMock = vi.fn();
const presetsMock = vi.fn();
const generateMock = vi.fn();
const importMock = vi.fn();

vi.mock( '../hooks/useAiApi', () => ( {
	aiApi: {
		capabilities: ( ...args: unknown[] ) => capabilitiesMock( ...args ),
		presets: ( ...args: unknown[] ) => presetsMock( ...args ),
		models: () => Promise.resolve( [] ),
		generate: ( ...args: unknown[] ) => generateMock( ...args ),
		importAttachment: ( ...args: unknown[] ) => importMock( ...args ),
		exportImage: () => Promise.resolve( {} ),
	},
} ) );

function baseCapabilities() {
	return {
		version: '0.2.0',
		providers: { gemini: { label: 'Gemini', configured: true } },
		transforms: {
			'on-model': { available: true, backend: 'edit_gemini_image', fidelity: 'prompt-bound', requires_consent: false },
			'model-swap': { available: true, backend: 'edit_gemini_image', fidelity: 'native', requires_consent: false },
			'face-swap': { available: true, backend: 'edit_gemini_image', fidelity: 'native', requires_consent: true },
			background: { available: true, backend: 'edit_gemini_image', fidelity: 'native', requires_consent: false },
			recolor: { available: true, backend: 'edit_gemini_image', fidelity: 'native', requires_consent: false },
			packshot: { available: true, backend: 'edit_gemini_image', fidelity: 'native', requires_consent: false },
			'detail-repair': { available: true, backend: 'edit_gemini_image', fidelity: 'native', requires_consent: false },
			'try-on': { available: true, backend: 'edit_gemini_image', fidelity: 'prompt-bound', requires_consent: true },
		},
		settings: {
			ai_disclosure: 'metadata',
			watermark_face: true,
			require_ack: true,
			per_image_ceiling: 0.25,
			per_job_ceiling: 10,
			hard_cap: 100,
		},
		profiles: {
			amazon: { label: 'Amazon', min_side: 1600, square: true, format: 'image/jpeg', white_bg: true },
			woocommerce: { label: 'WooCommerce', min_side: 800, square: false, format: 'image/webp', white_bg: false },
		},
		sidecar: false,
		wc_active: false,
		pro_active: false,
		batch: false,
	};
}

function reviewError( reason: string, estimate: number | null = null ) {
	const error = new Error( 'review required' ) as Error & {
		status: number;
		code: string;
		review: { reason: string; blocked: boolean; estimate_usd: number | null } | null;
	};
	error.status = 409;
	error.code = 'nvoos_ms_review_required';
	error.review = { reason, blocked: false, estimate_usd: estimate };
	return error;
}

beforeEach( () => {
	vi.clearAllMocks();
	capabilitiesMock.mockResolvedValue( baseCapabilities() );
	presetsMock.mockResolvedValue( [
		{ slug: 'pdp-white', label: 'PDP — pure white', transform: 'packshot', background: 'studio' },
	] );
} );

describe( 'FashionStudio', () => {
	it( 'renders the transform panel from capabilities', async () => {
		render( <FashionStudio /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Recolor/i } ) ).toBeInTheDocument() );
		expect( screen.getByRole( 'button', { name: /Packshot/i } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: /Face swap/i } ) ).toBeInTheDocument();
	} );

	it( 'disables generate until a Media Library source is chosen', async () => {
		render( <FashionStudio /> );
		const generateButton = await screen.findByRole( 'button', { name: /Generate/i } );
		expect( generateButton ).toBeDisabled();
		expect( generateMock ).not.toHaveBeenCalled();
	} );

	it( 'shows the review-confirm dialog when the server demands review', async () => {
		importMock.mockResolvedValue( {
			attachment_id: 42,
			url: 'http://example.org/source.png',
			title: 'Source',
			ai_generated: false,
			mime_type: 'image/png',
			transform: '',
		} );
		generateMock.mockRejectedValueOnce( reviewError( 'unknown_pricing' ) );

		render( <FashionStudio /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Open Media Library/i } ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByPlaceholderText( 'Attachment ID' ), { target: { value: '42' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Load' } ) );
		await waitFor( () => expect( screen.getByText( /Source image loaded/i ) ).toBeInTheDocument() );

		fireEvent.click( screen.getByRole( 'button', { name: /Generate/i } ) );
		await waitFor( () =>
			expect( screen.getByText( /Provider pricing is unknown/i ) ).toBeInTheDocument()
		);
		expect( screen.getByRole( 'button', { name: /Confirm and run/i } ) ).toBeInTheDocument();
	} );

	it( 'runs with confirmed=true after the review dialog is confirmed', async () => {
		importMock.mockResolvedValue( {
			attachment_id: 7,
			url: 'http://example.org/source.png',
			title: 'Source',
			ai_generated: false,
			mime_type: 'image/png',
			transform: '',
		} );
		generateMock
			.mockRejectedValueOnce( reviewError( 'unknown_pricing' ) )
			.mockResolvedValueOnce( {
				attachment_id: 99,
				url: 'http://example.org/out.png',
				transform: 'background',
				provider: 'gemini',
				model: 'nano-banana',
				disclosure: 'metadata',
				watermarked: false,
				estimate_usd: null,
				per_image_usd: null,
				white_background: null,
			} );

		render( <FashionStudio /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Open Media Library/i } ) ).toBeInTheDocument() );
		fireEvent.click( screen.getByRole( 'button', { name: /Background/i } ) );
		fireEvent.change( screen.getByPlaceholderText( 'Attachment ID' ), { target: { value: '7' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Load' } ) );
		await waitFor( () => expect( screen.getByText( /Source image loaded/i ) ).toBeInTheDocument() );

		fireEvent.click( screen.getByRole( 'button', { name: /Generate/i } ) );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Confirm and run/i } ) ).toBeInTheDocument() );
		fireEvent.click( screen.getByRole( 'button', { name: /Confirm and run/i } ) );

		await waitFor( () =>
			expect( screen.getByText( /Provider/i ) ).toBeInTheDocument()
		);
		expect( generateMock ).toHaveBeenLastCalledWith(
			expect.objectContaining( { confirmed: true, transform: 'background' } )
		);
	} );

	it( 'shows the one-time acknowledgment dialog for face transforms', async () => {
		importMock.mockResolvedValue( {
			attachment_id: 7,
			url: 'http://example.org/source.png',
			title: 'Source',
			ai_generated: false,
			mime_type: 'image/png',
			transform: '',
		} );
		const ackError = new Error( 'ack required' ) as Error & { status: number; code: string };
		ackError.status = 409;
		ackError.code = 'nvoos_ms_ack_required';
		generateMock.mockRejectedValueOnce( ackError );

		render( <FashionStudio /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Face swap/i } ) ).toBeInTheDocument() );
		fireEvent.click( screen.getByRole( 'button', { name: /Face swap/i } ) );
		fireEvent.change( screen.getByPlaceholderText( 'Attachment ID' ), { target: { value: '7' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Load' } ) );
		await waitFor( () => expect( screen.getByText( /Source image loaded/i ) ).toBeInTheDocument() );

		fireEvent.click( screen.getByRole( 'button', { name: /Generate/i } ) );
		await waitFor( () =>
			expect( screen.getByRole( 'button', { name: /Acknowledge and run/i } ) ).toBeInTheDocument()
		);
	} );

	it( 'renders compliance chips on successful results', async () => {
		importMock.mockResolvedValue( {
			attachment_id: 7,
			url: 'http://example.org/source.png',
			title: 'Source',
			ai_generated: false,
			mime_type: 'image/png',
			transform: '',
		} );
		generateMock.mockResolvedValue( {
			attachment_id: 99,
			url: 'http://example.org/out.png',
			transform: 'packshot',
			provider: 'gemini',
			model: 'nano-banana',
			disclosure: 'metadata',
			watermarked: false,
			estimate_usd: 0.05,
			per_image_usd: 0.05,
			white_background: { is_white: true, max_delta: 0, tolerance: 8 },
		} );

		render( <FashionStudio /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Open Media Library/i } ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByPlaceholderText( 'Attachment ID' ), { target: { value: '7' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Load' } ) );
		await waitFor( () => expect( screen.getByText( /Source image loaded/i ) ).toBeInTheDocument() );

		fireEvent.click( screen.getByRole( 'button', { name: /Packshot/i } ) );
		fireEvent.click( screen.getByRole( 'button', { name: /Generate/i } ) );

		await waitFor( () => expect( screen.getByText( /White background ✓/i ) ).toBeInTheDocument() );
		expect( screen.getByText( /Provider/i ) ).toBeInTheDocument();
	} );
} );
