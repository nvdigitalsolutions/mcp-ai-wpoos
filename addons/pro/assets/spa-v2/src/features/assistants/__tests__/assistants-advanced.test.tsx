/**
 * AssistantsPage — Advanced section (090-B assistant edit parity).
 *
 * Covers the shared contract (seed from GET, write back on save, block save
 * on unapplied/invalid JSON) and the bespoke editors for the four structured
 * fields: prompt shortcuts, prebuilt overrides, tool role rules and preferred
 * datasets.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import {
	render,
	screen,
	cleanup,
	waitFor,
	fireEvent,
	within,
} from '@testing-library/react';

import { AssistantsPage } from '../AssistantsPage';

vi.mock( '@wordpress/i18n', () => ( {
	__: ( text: string ) => text,
	sprintf: ( text: string, ...args: unknown[] ) =>
		text.replace( /%(s|d)/g, () => String( args.shift() ?? '' ) ),
} ) );

const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/assistants';
const SKILLS_ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/skills';
const MCP_APPS_ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/mcp-apps';
const REMOTE_SITES_ENDPOINT =
	'https://example.com/wp-json/mcp-ai-pro/v1/remote-sites';

const EMPTY_ADVANCED = {
	fallback_chain: [],
	adaptive_tool_cap: '',
	memory_files: [],
	vector_store_id: '',
	corpus_name: '',
	prompt_caching: false,
	disable_tool_shortcuts: false,
	tool_shortcuts: [],
	tool_prebuilt_shortcuts: {},
	tool_role_rules: [],
	external_action_id: '',
	external_action_type: '',
	required_capability: '',
	preferred_datasets: [],
	skills: [],
	skills_progressive: false,
};

const SKILLS_PAYLOAD = {
	success: true,
	skills: [
		{
			name: 'alpha-skill',
			description: 'Summarises long threads.',
			license: 'MIT',
			compatibility: 'wp-6.5',
		},
		{
			name: 'beta-skill',
			description: 'Translates content.',
			license: '',
			compatibility: '',
		},
	],
	total: 2,
};

const MCP_APPS_PAYLOAD = {
	success: true,
	assistant_id: 7,
	apps: [
		{
			label: 'Elementor MCP',
			server_url: 'https://mcp.example.com',
			connection_ref: '',
			auth_type: 'bearer',
			header_name: 'Authorization',
			enabled: true,
			timeout: 30,
			verify_ssl: true,
			// The read route masks stored secrets; they must never round-trip.
			token: '••••••••abcd',
		},
	],
	app_count: 1,
};

const REMOTE_SITES_PAYLOAD = {
	items: [
		{
			id: 'conn-1',
			source: 'pro',
			name: 'Elementor Cloud',
			connection_type: 'elementor',
			url: 'https://elementor.example.com',
			enabled: true,
			has_credentials: true,
		},
	],
	total: 1,
};

const SEEDED_ADVANCED = {
	...EMPTY_ADVANCED,
	fallback_chain: [ 'openai', 'anthropic' ],
	adaptive_tool_cap: 'on',
	memory_files: [ 12 ],
	vector_store_id: 'vs_123',
	corpus_name: 'corpus-a',
	prompt_caching: true,
	external_action_id: 'action-9',
	external_action_type: 'workflow',
	required_capability: 'manage_options',
	tool_shortcuts: [
		{ label: 'Summarise', payload: 'x', tool: 'web_search' },
	],
};

function makeRecord( advanced: Record< string, unknown > ) {
	return {
		id: 7,
		title: 'Research assistant',
		slug: 'research-assistant',
		status: 'publish',
		description: '',
		provider: 'openai',
		model: 'gpt-4o',
		temperature: 0.5,
		system_prompt: 'You are a research assistant.',
		tools: [],
		primary_role_slugs: [],
		advanced,
	};
}

type Call = { url: string; method: string; body: unknown };

let calls: Call[] = [];

function setRuntime( overrides: Record< string, string > = {} ): void {
	( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = {
		apiUrl: 'https://example.com/wp-json/mcp-ai/v1',
		proApi: 'https://example.com/wp-json/mcp-ai-pro/v1',
		nonce: 'test-nonce',
		config: { assistantId: 1, theme: 'auto', mode: 'admin' },
		endpoints: {
			chat: '',
			chatClient: '',
			transcripts: '',
			threads: '',
			tools: '',
			assistants: ENDPOINT,
			settings: '',
			professions: '',
			skills: '',
			mcpApps: '',
			remoteSites: '',
			...overrides,
		},
		user: {
			id: 1,
			login: 'admin',
			displayName: 'Admin',
			capabilities: [ 'manage_options' ],
		},
		mentionTypes: [],
	};
}

/**
 * Route by URL + method. Checked in order, so a single-record route is listed
 * before the collection route.
 */
function setFetchRoutes(
	routes: Array< { match: string; method?: string; payload: unknown } >
): void {
	calls = [];

	global.fetch = vi.fn(
		( input: RequestInfo | URL, init?: RequestInit ) => {
			const url = String( input );
			const method = ( init?.method ?? 'GET' ).toUpperCase();
			const body =
				typeof init?.body === 'string'
					? JSON.parse( init.body )
					: undefined;

			calls.push( { url, method, body } );

			const route = routes.find(
				( item ) =>
					url.includes( item.match ) &&
					( item.method ?? 'GET' ) === method
			);

			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( route ? route.payload : {} ),
			} as Response );
		}
	) as unknown as typeof fetch;
}

function routerFor(
	record: Record< string, unknown >,
	extraRoutes: Array< {
		match: string;
		method?: string;
		payload: unknown;
	} > = []
): void {
	setFetchRoutes( [
		...extraRoutes,
		{ match: '/assistants/7', payload: { success: true, data: record } },
		{
			match: '/assistants',
			payload: {
				assistants: [ { id: 7, title: record.title } ],
				total: 1,
			},
		},
		{
			match: '/assistants/7',
			method: 'PUT',
			payload: { success: true, data: record },
		},
	] );
}

afterEach( () => {
	vi.restoreAllMocks();
	cleanup();
	delete ( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA;
} );

async function openEdit(): Promise< void > {
	render( <AssistantsPage /> );

	await waitFor( () => {
		expect(
			screen.getByRole( 'button', { name: 'Edit assistant' } )
		).toBeTruthy();
	} );

	fireEvent.click( screen.getByRole( 'button', { name: 'Edit assistant' } ) );

	// The full record (system prompt + advanced) replaces the list seed.
	await waitFor( () => {
		expect(
			( screen.getByLabelText( 'System Prompt' ) as HTMLTextAreaElement )
				.value
		).toBe( 'You are a research assistant.' );
	} );
}

function savedAdvanced(): Record< string, unknown > {
	const put = calls.find( ( call ) => call.method === 'PUT' );
	expect( put ).toBeTruthy();
	return ( put?.body as { advanced: Record< string, unknown > } ).advanced;
}

describe( 'AssistantsPage advanced section', () => {
	it( 'seeds controls from the single-assistant GET', async () => {
		setRuntime();
		routerFor( makeRecord( SEEDED_ADVANCED ) );

		await openEdit();

		expect(
			( screen.getByLabelText( 'Fallback chain' ) as HTMLInputElement )
				.value
		).toBe( 'openai, anthropic' );
		expect(
			( screen.getByLabelText( 'Vector store ID' ) as HTMLInputElement )
				.value
		).toBe( 'vs_123' );
		expect(
			( screen.getByLabelText( 'Corpus name' ) as HTMLInputElement ).value
		).toBe( 'corpus-a' );
		expect(
			( screen.getByLabelText( 'Memory file IDs' ) as HTMLInputElement )
				.value
		).toBe( '12' );
		expect(
			(
				screen.getByLabelText(
					'Enable prompt caching'
				) as HTMLInputElement
			 ).checked
		).toBe( true );
		expect(
			( screen.getByLabelText( 'Adaptive tool cap' ) as HTMLSelectElement )
				.value
		).toBe( 'on' );
		// Structured editor renders the seeded shortcut card.
		expect(
			screen.getByRole( 'group', { name: 'Summarise' } )
		).toBeTruthy();
	} );

	it( 'writes the advanced object back on save', async () => {
		setRuntime();
		routerFor( makeRecord( SEEDED_ADVANCED ) );

		await openEdit();

		fireEvent.change( screen.getByLabelText( 'Fallback chain' ), {
			target: { value: 'openai, anthropic, ollama' },
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Update' } ) );

		await waitFor( () => {
			const advanced = savedAdvanced();
			expect( advanced.fallback_chain ).toEqual( [
				'openai',
				'anthropic',
				'ollama',
			] );
			expect( advanced.vector_store_id ).toBe( 'vs_123' );
			expect( advanced.prompt_caching ).toBe( true );
			expect( advanced.required_capability ).toBe( 'manage_options' );
		} );
	} );

	it( 'adds a prompt shortcut and saves it in order', async () => {
		setRuntime();
		routerFor( makeRecord( EMPTY_ADVANCED ) );

		await openEdit();

		fireEvent.click( screen.getByRole( 'button', { name: 'Add shortcut' } ) );

		const first = screen.getByRole( 'group', { name: 'Shortcut 1' } );
		fireEvent.change( within( first ).getByLabelText( /^Label/ ), {
			target: { value: 'Summarise' },
		} );
		fireEvent.change( within( first ).getByLabelText( /^Payload/ ), {
			target: { value: 'Summarise the thread' },
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Add shortcut' } ) );
		const second = screen.getByRole( 'group', { name: 'Shortcut 2' } );
		fireEvent.change( within( second ).getByLabelText( /^Label/ ), {
			target: { value: 'Translate' },
		} );
		fireEvent.change( within( second ).getByLabelText( /^Payload/ ), {
			target: { value: 'Translate to French' },
		} );

		// Move the second shortcut above the first, then save.
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Move shortcut 2 up' } )
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Update' } ) );

		await waitFor( () => {
			expect( savedAdvanced().tool_shortcuts ).toEqual( [
				{ label: 'Translate', payload: 'Translate to French' },
				{ label: 'Summarise', payload: 'Summarise the thread' },
			] );
		} );
	} );

	it( 'removes a shortcut with a uniquely named action', async () => {
		setRuntime();
		routerFor( makeRecord( SEEDED_ADVANCED ) );

		await openEdit();

		fireEvent.click(
			screen.getByRole( 'button', { name: /Remove shortcut/ } )
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Update' } ) );

		await waitFor( () => {
			expect( savedAdvanced().tool_shortcuts ).toEqual( [] );
		} );
	} );

	it( 'adds a tool role rule with an access flag', async () => {
		setRuntime();
		routerFor( makeRecord( EMPTY_ADVANCED ) );

		await openEdit();

		fireEvent.click( screen.getByRole( 'button', { name: 'Add rule' } ) );
		const ruleCard = screen.getByRole( 'group', { name: 'Rule 1' } );
		fireEvent.change( within( ruleCard ).getByLabelText( /^Tool/ ), {
			target: { value: 'web_search' },
		} );
		fireEvent.click(
			within( ruleCard ).getByLabelText( 'allow_guests' )
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Update' } ) );

		await waitFor( () => {
			expect( savedAdvanced().tool_role_rules ).toEqual( [
				{ tool: 'web_search', flags: [ 'allow_guests' ] },
			] );
		} );
	} );

	it( 'adds a preferred dataset with category and priority', async () => {
		setRuntime();
		routerFor( makeRecord( EMPTY_ADVANCED ) );

		await openEdit();

		fireEvent.click( screen.getByRole( 'button', { name: 'Add dataset' } ) );
		const datasetCard = screen.getByRole( 'group', { name: 'Dataset 1' } );
		fireEvent.change( within( datasetCard ).getByLabelText( /^Dataset/ ), {
			target: { value: 'hf/research-corpus' },
		} );
		fireEvent.change( within( datasetCard ).getByLabelText( 'Priority' ), {
			target: { value: 'high' },
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Update' } ) );

		await waitFor( () => {
			expect( savedAdvanced().preferred_datasets ).toEqual( [
				{ dataset: 'hf/research-corpus', priority: 'high' },
			] );
		} );
	} );

	it( 'blocks save while a JSON draft is unapplied', async () => {
		setRuntime();
		routerFor( makeRecord( SEEDED_ADVANCED ) );

		await openEdit();

		fireEvent.change( screen.getByLabelText( 'Role rules JSON' ), {
			target: { value: '[{"tool":"web_search"}]' },
		} );

		await waitFor( () => {
			expect(
				(
					screen.getByRole( 'button', {
						name: 'Update',
					} ) as HTMLButtonElement
				 ).disabled
			).toBe( true );
		} );

		expect( screen.getByText( 'Unapplied changes' ) ).toBeTruthy();
		expect( calls.some( ( call ) => call.method === 'PUT' ) ).toBe( false );
	} );

	it( 'reports invalid applied JSON inline and keeps save blocked', async () => {
		setRuntime();
		routerFor( makeRecord( SEEDED_ADVANCED ) );

		await openEdit();

		const jsonField = screen.getByLabelText( 'Role rules JSON' );
		fireEvent.change( jsonField, {
			target: { value: '{ not json' },
		} );

		const section = jsonField.closest( 'details' );
		expect( section ).toBeTruthy();

		fireEvent.click(
			within( section as HTMLElement ).getByRole( 'button', {
				name: 'Apply JSON',
			} )
		);

		await waitFor( () => {
			expect(
				screen.getByText(
					'This is not valid JSON. Check the brackets and quotes.'
				)
			).toBeTruthy();
		} );

		expect(
			(
				screen.getByRole( 'button', {
					name: 'Update',
				} ) as HTMLButtonElement
			 ).disabled
		).toBe( true );
} );

it( 'hides the skills picker when the runtime exposes no catalogue route', async () => {
	setRuntime();
	routerFor( makeRecord( EMPTY_ADVANCED ) );

	await openEdit();

	expect( screen.queryByRole( 'group', { name: 'Agent Skills' } ) ).toBeNull();
} );

it( 'assigns an installed skill through the checked list', async () => {
	setRuntime( { skills: SKILLS_ENDPOINT } );
	routerFor( makeRecord( EMPTY_ADVANCED ), [
		{ match: '/skills', payload: SKILLS_PAYLOAD },
	] );

	await openEdit();

	const checkbox = await screen.findByRole( 'checkbox', {
		name: /alpha-skill/,
	} );
	fireEvent.click( checkbox );

	fireEvent.click( screen.getByRole( 'button', { name: 'Update' } ) );

	await waitFor( () => {
		expect( savedAdvanced().skills ).toEqual( [ 'alpha-skill' ] );
		expect( savedAdvanced().skills_progressive ).toBe( false );
	} );
} );

it( 'turns on progressive skill disclosure', async () => {
	setRuntime( { skills: SKILLS_ENDPOINT } );
	routerFor( makeRecord( EMPTY_ADVANCED ), [
		{ match: '/skills', payload: SKILLS_PAYLOAD },
	] );

	await openEdit();

	const toggle = await screen.findByRole( 'checkbox', {
		name: /progressive disclosure/,
	} );
	fireEvent.click( toggle );

	fireEvent.click( screen.getByRole( 'button', { name: 'Update' } ) );

	await waitFor( () => {
		expect( savedAdvanced().skills_progressive ).toBe( true );
	} );
} );

it( 'keeps an assigned skill that is not installed and flags it', async () => {
	setRuntime( { skills: SKILLS_ENDPOINT } );
	routerFor(
		makeRecord( { ...EMPTY_ADVANCED, skills: [ 'retired-skill' ] } ),
		[ { match: '/skills', payload: SKILLS_PAYLOAD } ]
	);

	await openEdit();

	await waitFor( () => {
		expect(
			screen.getByText( /not installed here: retired-skill/ )
		).toBeTruthy();
	} );

	fireEvent.click( screen.getByRole( 'button', { name: 'Update' } ) );

	await waitFor( () => {
		expect( savedAdvanced().skills ).toEqual( [ 'retired-skill' ] );
	} );
} );

it( 'hides the MCP apps panel when the endpoint is not exposed', async () => {
	setRuntime();
	routerFor( makeRecord( EMPTY_ADVANCED ) );

	await openEdit();

	expect( screen.queryByRole( 'group', { name: 'MCP apps' } ) ).toBeNull();
} );

it( 'lists the MCP apps assigned to the assistant', async () => {
	setRuntime( {
		mcpApps: MCP_APPS_ENDPOINT,
		remoteSites: REMOTE_SITES_ENDPOINT,
	} );
	routerFor( makeRecord( EMPTY_ADVANCED ), [
		{ match: '/mcp-apps/7', payload: MCP_APPS_PAYLOAD },
		{ match: '/remote-sites', payload: REMOTE_SITES_PAYLOAD },
	] );

	await openEdit();

	const panel = await screen.findByRole( 'group', { name: 'MCP apps' } );
	await waitFor( () => {
		expect(
			within( panel ).getByRole( 'group', { name: 'Elementor MCP' } )
		).toBeTruthy();
	} );

	// The masked secret is never surfaced as an editable value.
	expect(
		within( panel ).queryByDisplayValue( '••••••••abcd' )
	).toBeNull();
} );

it( 'adds a connection-backed app and saves without secrets', async () => {
	setRuntime( {
		mcpApps: MCP_APPS_ENDPOINT,
		remoteSites: REMOTE_SITES_ENDPOINT,
	} );
	routerFor( makeRecord( EMPTY_ADVANCED ), [
		{ match: '/mcp-apps/7', payload: MCP_APPS_PAYLOAD },
		{ match: '/remote-sites', payload: REMOTE_SITES_PAYLOAD },
		{
			match: '/mcp-apps/7',
			method: 'PUT',
			payload: {
				success: true,
				assistant_id: 7,
				apps: [],
				app_count: 2,
			},
		},
	] );

	await openEdit();

	const panel = await screen.findByRole( 'group', { name: 'MCP apps' } );
	const select = await within( panel ).findByLabelText(
		'Add from a connection'
	);

	await waitFor( () => {
		expect(
			within( select ).getByRole( 'option', {
				name: /Elementor Cloud/,
			} )
		).toBeTruthy();
	} );

	fireEvent.change( select, { target: { value: 'conn-1' } } );
	fireEvent.click(
		within( panel ).getByRole( 'button', { name: 'Add app' } )
	);
	fireEvent.click(
		within( panel ).getByRole( 'button', { name: 'Save MCP apps' } )
	);

	await waitFor( () => {
		const put = calls.find(
			( call ) =>
				call.method === 'PUT' &&
				call.url.includes( '/mcp-apps/7' )
		);
		expect( put ).toBeTruthy();

		const apps = (
			put?.body as { apps: Array< Record< string, unknown > > }
		 ).apps;

		expect( apps ).toHaveLength( 2 );
		expect( apps[ 0 ] ).not.toHaveProperty( 'token' );
		expect( apps[ 0 ] ).not.toHaveProperty( 'oauth_data' );
		expect( apps[ 1 ] ).toMatchObject( {
			connection_ref: 'conn-1',
			server_url: '',
			label: 'Elementor Cloud',
			enabled: true,
		} );
		expect( apps[ 1 ] ).not.toHaveProperty( 'token' );
		expect( apps[ 1 ] ).not.toHaveProperty( 'oauth_data' );
	} );
} );
} );
