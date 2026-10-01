/**
 * media-studio — Fashion Batch Panel unit tests (Pro-backed UI).
 *
 * Covers job listing, source-ID validation, the review-confirm create flow,
 * and the variant review actions. The REST client is mocked — no network.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { FashionBatchPanel } from '../components/FashionBatchPanel';

const listJobsMock = vi.fn();
const getJobMock = vi.fn();
const createJobMock = vi.fn();
const reviewJobMock = vi.fn();

vi.mock( '../hooks/useAiApi', () => ( {
	aiApi: {
		capabilities: () => Promise.resolve( {} ),
		presets: () => Promise.resolve( [] ),
		models: () => Promise.resolve( [] ),
		generate: () => Promise.resolve( {} ),
		listJobs: ( ...args: unknown[] ) => listJobsMock( ...args ),
		getJob: ( ...args: unknown[] ) => getJobMock( ...args ),
		createJob: ( ...args: unknown[] ) => createJobMock( ...args ),
		reviewJob: ( ...args: unknown[] ) => reviewJobMock( ...args ),
		importAttachment: () => Promise.resolve( {} ),
		exportImage: () => Promise.resolve( {} ),
	},
} ) );

function makeJob( overrides: Record< string, unknown > = {} ) {
	return {
		id: 1,
		status: 'review',
		transform: 'background',
		total: 1,
		done: 1,
		estimate_usd: 0.5,
		per_image_usd: 0.5,
		product_id: 0,
		collection_id: 0,
		created: '2026-10-01T00:00:00+00:00',
		variants: [
			{
				key: 0,
				source_id: 7,
				status: 'generated',
				attachment_id: 99,
				url: 'http://example.org/out.png',
				error: '',
				export_error: '',
				reroll_of: 0,
			},
		],
		...overrides,
	};
}

const baseProps = {
	transform: 'background',
	description: '',
	color: '#c0392b',
	backgroundStyle: 'studio',
	aspectRatio: 'auto',
	identityId: 0,
};

beforeEach( () => {
	vi.clearAllMocks();
	listJobsMock.mockResolvedValue( [] );
	getJobMock.mockResolvedValue( makeJob() );
	createJobMock.mockResolvedValue( makeJob() );
	reviewJobMock.mockResolvedValue( { key: 0, status: 'approved' } );
} );

describe( 'FashionBatchPanel', () => {
	it( 'loads the job list on mount', async () => {
		listJobsMock.mockResolvedValue( [ makeJob( { id: 1 } ), makeJob( { id: 2, status: 'completed' } ) ] );
		render( <FashionBatchPanel { ...baseProps } /> );

		await waitFor( () => expect( screen.getAllByText( 'review' ).length ).toBeGreaterThan( 0 ) );
		expect( screen.getByText( 'completed' ) ).toBeInTheDocument();
	} );

	it( 'rejects batch creation without source IDs', async () => {
		render( <FashionBatchPanel { ...baseProps } /> );
		fireEvent.click( await screen.findByRole( 'button', { name: /Create batch/i } ) );

		await waitFor( () =>
			expect( screen.getByText( /Enter at least one source attachment ID/i ) ).toBeInTheDocument()
		);
		expect( createJobMock ).not.toHaveBeenCalled();
	} );

	it( 'shows the review-confirm dialog and re-posts with confirmed', async () => {
		const reviewError = new Error( 'review required' ) as Error & {
			status: number;
			code: string;
			review: { reason: string; blocked: boolean; estimate_usd: number | null } | null;
		};
		reviewError.status = 409;
		reviewError.code = 'nvoos_ms_review_required';
		reviewError.review = { reason: 'per_job_ceiling', blocked: false, estimate_usd: 15.5 };
		createJobMock.mockRejectedValueOnce( reviewError );

		render( <FashionBatchPanel { ...baseProps } /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Create batch/i } ) ).toBeInTheDocument() );

		fireEvent.change( screen.getByPlaceholderText( /Source attachment IDs/i ), {
			target: { value: '7, 8' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /Create batch/i } ) );

		await waitFor( () => expect( screen.getByRole( 'button', { name: /Confirm and run/i } ) ).toBeInTheDocument() );
		expect( screen.getByText( /15\.50/ ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: /Confirm and run/i } ) );
		await waitFor( () => expect( createJobMock ).toHaveBeenLastCalledWith( expect.objectContaining( { confirmed: true } ) ) );
		expect( createJobMock ).toHaveBeenLastCalledWith(
			expect.objectContaining( { attachment_ids: [ 7, 8 ], transform: 'background' } )
		);
	} );

	it( 'fetches the full job on expand and renders variant actions', async () => {
		listJobsMock.mockResolvedValue( [ makeJob( { variants: [] } ) ] );
		getJobMock.mockResolvedValue( makeJob() );

		render( <FashionBatchPanel { ...baseProps } /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /review/ } ) ).toBeInTheDocument() );

		fireEvent.click( screen.getByRole( 'button', { name: /review/ } ) );
		await waitFor( () => expect( getJobMock ).toHaveBeenCalledWith( 1 ) );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Approve/i } ) ).toBeInTheDocument() );

		fireEvent.click( screen.getByRole( 'button', { name: /Approve/i } ) );
		await waitFor( () =>
			expect( reviewJobMock ).toHaveBeenCalledWith( 1, { variant_key: 0, action: 'approve' } )
		);
	} );
} );
