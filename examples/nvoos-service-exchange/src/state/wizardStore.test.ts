import { create } from 'zustand';
import { STEPS, useWizardStore } from './wizardStore';
import type { Blueprint } from '../types';

function seed( overrides: Partial<Blueprint> = {} ): Blueprint {
	return {
		blueprint_version: 1,
		name: 'Clinic Operations Copilot',
		slug: 'clinic-ops',
		tagline: 'Bookings and stock for clinics',
		industry: 'healthcare',
		bundle: {
			format: 'nvoos-assistant',
			format_version: 1,
			assistants: [
				{
					title: 'Clinic Ops',
					content: 'Original prompt',
					meta: { _wp_mcp_ai_model: 'gpt-4o-mini', _wp_mcp_ai_temperature: 0.2 },
				},
			],
		},
		site: { region: 'eu', size_hint: 'small', domain: null, wp_manager: true },
		gateway: { visibility: 'private', tool_allowlist: [ 'get_environment_status' ] },
		commerce: { stripe_price_id: null, trial_days: 21 },
		listing: { summary: 'An operations assistant for small clinics.' },
		...overrides,
	};
}

function resetStore() {
	useWizardStore.getState().reset();
}

describe( 'wizard store', () => {
	beforeEach( resetStore );

	it( 'starts on the profile step', () => {
		const state = useWizardStore.getState();
		expect( state.step ).toBe( 'profile' );
		expect( STEPS.map( ( entry ) => entry.id ) ).toContain( state.step );
	} );

	it( 'moves forward and backward within bounds', () => {
		const store = useWizardStore.getState();
		store.next();
		expect( useWizardStore.getState().step ).toBe( 'blueprint' );
		useWizardStore.getState().back();
		useWizardStore.getState().back();
		expect( useWizardStore.getState().step ).toBe( 'profile' );
		for ( let index = 0; index < 20; index += 1 ) {
			useWizardStore.getState().next();
		}
		expect( useWizardStore.getState().step ).toBe( 'publish' );
	} );

	it( 'selecting a seed prefills the wizard fields', () => {
		useWizardStore.getState().selectSeed( seed() );
		const state = useWizardStore.getState();
		expect( state.assistant.systemPrompt ).toBe( 'Original prompt' );
		expect( state.assistant.model ).toBe( 'gpt-4o-mini' );
		expect( state.site.region ).toBe( 'eu' );
		expect( state.commerce.trialDays ).toBe( 21 );
		expect( state.gateway.allowlist ).toBe( 'get_environment_status' );
	} );

	it( 'composeBlueprint merges wizard edits into the seed', () => {
		const store = useWizardStore.getState();
		store.selectSeed( seed() );
		useWizardStore.getState().patchAssistant( { systemPrompt: 'New prompt', temperature: 9 } );
		useWizardStore.getState().patchSite( { region: 'us', domain: 'clinic.example.test' } );
		useWizardStore.getState().patchGateway( { visibility: 'public', allowlist: 'alpha, beta' } );
		useWizardStore.getState().patchCommerce( { priceId: 'price_1234567890' } );

		const blueprint = useWizardStore.getState().composeBlueprint();
		expect( blueprint ).not.toBeNull();
		expect( blueprint?.bundle.assistants[ 0 ].content ).toBe( 'New prompt' );
		expect( blueprint?.bundle.assistants[ 0 ].meta?._wp_mcp_ai_temperature ).toBe( 2 );
		expect( blueprint?.site.region ).toBe( 'us' );
		expect( blueprint?.site.domain ).toBe( 'clinic.example.test' );
		expect( blueprint?.gateway.visibility ).toBe( 'public' );
		expect( blueprint?.gateway.tool_allowlist ).toEqual( [ 'alpha', 'beta' ] );
		expect( blueprint?.commerce.stripe_price_id ).toBe( 'price_1234567890' );
	} );

	it( 'composeBlueprint returns null without a seed', () => {
		expect( useWizardStore.getState().composeBlueprint() ).toBeNull();
	} );

	it( 'reset restores defaults and clears the estate', () => {
		const store = useWizardStore.getState();
		store.selectSeed( seed() );
		store.setEstate( { slug: 'clinic-ops' } as never );
		store.next();
		useWizardStore.getState().reset();
		const state = useWizardStore.getState();
		expect( state.step ).toBe( 'profile' );
		expect( state.seed ).toBeNull();
		expect( state.estate ).toBeNull();
		expect( state.site.region ).toBe( 'us' );
	} );
} );
