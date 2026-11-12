import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import App from './App';
import { createApiClient } from './api/client';
import { useWizardStore } from './state/wizardStore';

function response( body: unknown, status = 200 ): Response {
	return {
		ok: status >= 200 && status < 300,
		status,
		json: async () => body,
	} as Response;
}

const SEED = {
	slug: 'clinic-ops',
	name: 'Clinic Operations Copilot',
	tagline: 'Bookings for clinics',
	industry: 'healthcare',
	blueprint_version: 1,
	bundle: { format: 'nvoos-assistant', format_version: 1, assistants: [ { title: 'Clinic Ops' } ] },
	site: { region: 'us' },
	gateway: { visibility: 'private', tool_allowlist: [ 'get_environment_status' ] },
	listing: { summary: 'An operations assistant for small clinics.' },
};

function buildClient() {
	const fetchImpl = vi.fn( async ( url: string ) => {
		if ( url.endsWith( '/health' ) ) {
			return response( { ok: true, data: { service: 'exchange', version: 'test', mode: 'mock' } } );
		}
		if ( url.endsWith( '/blueprints' ) ) {
			return response( { ok: true, data: [ SEED ] } );
		}
		return response( { ok: false, error: { code: 'not_found', message: 'not found' } }, 404 );
	} );
	return createApiClient( { fetchImpl: fetchImpl as never, getToken: () => null } );
}

describe( 'wizard app', () => {
	beforeEach( () => {
		useWizardStore.getState().reset();
	} );

	it( 'renders the profile step and advances to blueprints', async () => {
		render( <App client={ buildClient() } /> );

		expect(
			screen.getByRole( 'heading', { name: /who is deploying/i } )
		).toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'button', { name: /continue/i } ) );

		expect(
			await screen.findByRole( 'heading', { name: /choose a blueprint/i } )
		).toBeInTheDocument();
		expect( await screen.findByText( 'Clinic Operations Copilot' ) ).toBeInTheDocument();
	} );

	it( 'shows the mock mode badge from the health probe', async () => {
		render( <App client={ buildClient() } /> );
		expect( await screen.findByText( /mock mode/i ) ).toBeInTheDocument();
	} );
} );
