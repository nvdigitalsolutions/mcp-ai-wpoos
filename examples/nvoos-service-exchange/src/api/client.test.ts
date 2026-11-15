import { createApiClient, ApiClientError } from './client';

function jsonResponse( status: number, body: unknown ) {
	return {
		ok: status >= 200 && status < 300,
		status,
		json: async () => body,
	} as Response;
}

describe( 'api client', () => {
	it( 'unwraps the canonical envelope', async () => {
		const fetchImpl = vi.fn( async () =>
			jsonResponse( 200, { ok: true, data: [ { slug: 'clinic-ops' } ] } )
		);
		const client = createApiClient( { fetchImpl, getToken: () => null } );
		const estates = await client.estates();
		expect( estates ).toEqual( [ { slug: 'clinic-ops' } ] );
	} );

	it( 'sends a bearer token when available', async () => {
		const fetchImpl = vi.fn( async ( _url: string, init?: RequestInit ) => {
			expect( ( init?.headers as Record<string, string> ).Authorization ).toBe( 'Bearer admin-token' );
			return jsonResponse( 200, { ok: true, data: [] } );
		} );
		const client = createApiClient( { fetchImpl: fetchImpl as never, getToken: () => 'admin-token' } );
		await client.estates();
		expect( fetchImpl ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'maps error envelopes to ApiClientError', async () => {
		const fetchImpl = vi.fn( async () =>
			jsonResponse( 422, {
				ok: false,
				error: { code: 'validation_failed', message: 'Blueprint failed validation', errors: [] },
			} )
		);
		const client = createApiClient( { fetchImpl: fetchImpl as never, getToken: () => null } );
		await expect( client.createEstate( { blueprint_slug: 'x' } ) ).rejects.toMatchObject( {
			code: 'validation_failed',
			status: 422,
		} );
		const error = await client.estates().catch( ( caught ) => caught );
		expect( error ).toBeInstanceOf( ApiClientError );
	} );
} );
