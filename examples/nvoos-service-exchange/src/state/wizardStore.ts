import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { Blueprint, EstateSummary } from '../types';

export const STEPS = [
	{ id: 'profile', label: 'Profile' },
	{ id: 'blueprint', label: 'Blueprint' },
	{ id: 'assistant', label: 'Assistant' },
	{ id: 'site', label: 'Site' },
	{ id: 'console', label: 'Console' },
	{ id: 'gateway', label: 'Gateway' },
	{ id: 'commerce', label: 'Commerce' },
	{ id: 'publish', label: 'Publish' },
] as const;

export type StepId = ( typeof STEPS )[ number ][ 'id' ];

export interface WizardData {
	profile: { name: string; brand: string; email: string };
	seed: Blueprint | null;
	assistant: { systemPrompt: string; model: string; temperature: number };
	site: { region: string; sizeHint: 'small' | 'medium' | 'large'; domain: string; wpManager: boolean };
	console: { enabled: boolean };
	gateway: { visibility: 'public' | 'private'; allowlist: string };
	commerce: { priceId: string; trialDays: number };
}

export interface WizardState extends WizardData {
	step: StepId;
	estate: EstateSummary | null;
	patchProfile: ( patch: Partial<WizardData[ 'profile' ]> ) => void;
	patchAssistant: ( patch: Partial<WizardData[ 'assistant' ]> ) => void;
	patchSite: ( patch: Partial<WizardData[ 'site' ]> ) => void;
	patchConsole: ( patch: Partial<WizardData[ 'console' ]> ) => void;
	patchGateway: ( patch: Partial<WizardData[ 'gateway' ]> ) => void;
	patchCommerce: ( patch: Partial<WizardData[ 'commerce' ]> ) => void;
	selectSeed: ( seed: Blueprint | null ) => void;
	setEstate: ( estate: EstateSummary | null ) => void;
	goTo: ( step: StepId ) => void;
	next: () => void;
	back: () => void;
	reset: () => void;
	composeBlueprint: () => Blueprint | null;
}

const INITIAL_DATA: WizardData = {
	profile: { name: '', brand: '', email: '' },
	seed: null,
	assistant: { systemPrompt: '', model: 'gpt-4o-mini', temperature: 0.3 },
	site: { region: 'us', sizeHint: 'small', domain: '', wpManager: true },
	console: { enabled: true },
	gateway: { visibility: 'private', allowlist: 'get_environment_status' },
	commerce: { priceId: '', trialDays: 14 },
};

function clampTemperature( value: number ): number {
	if ( Number.isNaN( value ) ) {
		return 0.3;
	}
	return Math.min( 2, Math.max( 0, value ) );
}

export const useWizardStore = create<WizardState>()(
	persist(
		( set, get ) => ( {
			...INITIAL_DATA,
			step: 'profile',
			estate: null,

			patchProfile: ( patch ) =>
				set( ( state ) => ( { profile: { ...state.profile, ...patch } } ) ),
			patchAssistant: ( patch ) =>
				set( ( state ) => ( { assistant: { ...state.assistant, ...patch } } ) ),
			patchSite: ( patch ) => set( ( state ) => ( { site: { ...state.site, ...patch } } ) ),
			patchConsole: ( patch ) =>
				set( ( state ) => ( { console: { ...state.console, ...patch } } ) ),
			patchGateway: ( patch ) =>
				set( ( state ) => ( { gateway: { ...state.gateway, ...patch } } ) ),
			patchCommerce: ( patch ) =>
				set( ( state ) => ( { commerce: { ...state.commerce, ...patch } } ) ),

			selectSeed: ( seed ) => {
				if ( ! seed ) {
					set( { seed: null } );
					return;
				}
				const meta = ( seed.bundle.assistants[ 0 ]?.meta ?? {} ) as Record<string, unknown>;
				set( {
					seed,
					assistant: {
						systemPrompt: seed.bundle.assistants[ 0 ]?.content ?? '',
						model: typeof meta._wp_mcp_ai_model === 'string' ? meta._wp_mcp_ai_model : 'gpt-4o-mini',
						temperature: clampTemperature( Number( meta._wp_mcp_ai_temperature ?? 0.3 ) ),
					},
					site: {
						region: seed.site.region,
						sizeHint: seed.site.size_hint ?? 'small',
						domain: seed.site.domain ?? '',
						wpManager: seed.site.wp_manager ?? true,
					},
					console: { enabled: seed.console?.enable ?? true },
					gateway: {
						visibility: seed.gateway.visibility,
						allowlist: ( seed.gateway.tool_allowlist ?? [] ).join( ', ' ),
					},
					commerce: {
						priceId: seed.commerce?.stripe_price_id ?? '',
						trialDays: seed.commerce?.trial_days ?? 14,
					},
				} );
			},

			setEstate: ( estate ) => set( { estate } ),

			goTo: ( step ) => set( { step } ),

			next: () => {
				const index = STEPS.findIndex( ( entry ) => entry.id === get().step );
				const target = STEPS[ Math.min( index + 1, STEPS.length - 1 ) ];
				set( { step: target.id } );
			},

			back: () => {
				const index = STEPS.findIndex( ( entry ) => entry.id === get().step );
				const target = STEPS[ Math.max( index - 1, 0 ) ];
				set( { step: target.id } );
			},

			reset: () => set( { ...INITIAL_DATA, step: 'profile', estate: null } ),

			composeBlueprint: () => {
				const { seed, assistant, site, console: consoleState, gateway, commerce } = get();
				if ( ! seed ) {
					return null;
				}
				const blueprint: Blueprint = structuredClone( seed );
				const first = blueprint.bundle.assistants[ 0 ];
				if ( first ) {
					first.content = assistant.systemPrompt || first.content;
					first.meta = {
						...( first.meta ?? {} ),
						_wp_mcp_ai_model: assistant.model,
						_wp_mcp_ai_temperature: clampTemperature( assistant.temperature ),
					};
				}
				blueprint.site = {
					...blueprint.site,
					region: site.region,
					size_hint: site.sizeHint,
					domain: site.domain.trim() || null,
					wp_manager: site.wpManager,
				};
				blueprint.console = {
					enable: consoleState.enabled,
					template: consoleState.enabled ? 'complete-velocity' : null,
				};
				blueprint.gateway = {
					...blueprint.gateway,
					visibility: gateway.visibility,
					tool_allowlist: gateway.allowlist
						.split( ',' )
						.map( ( entry ) => entry.trim() )
						.filter( Boolean ),
				};
				blueprint.commerce = {
					...blueprint.commerce,
					stripe_price_id: commerce.priceId.trim() || null,
					trial_days: commerce.trialDays,
				};
				return blueprint;
			},
		} ),
		{
			name: 'nvoos-exchange-wizard',
			partialize: ( state ) => ( {
				step: state.step,
				profile: state.profile,
				seed: state.seed,
				assistant: state.assistant,
				site: state.site,
				console: state.console,
				gateway: state.gateway,
				commerce: state.commerce,
			} ),
		}
	)
);
