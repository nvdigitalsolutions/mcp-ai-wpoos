import { test } from 'node:test';
import assert from 'node:assert/strict';
import { validateBlueprint, BLUEPRINT_SCHEMA_VERSION } from './validate.js';

function validBlueprint( overrides = {} ) {
	return {
		blueprint_version: BLUEPRINT_SCHEMA_VERSION,
		name: 'Clinic Operations Copilot',
		slug: 'clinic-ops',
		tagline: 'Bookings, stock and compliance for small clinics',
		industry: 'healthcare',
		bundle: {
			format: 'nvoos-assistant',
			format_version: 1,
			assistants: [
				{
					title: 'Clinic Operations Copilot',
					slug: 'clinic-ops-copilot',
					status: 'publish',
					content: 'You are an operations copilot for a small clinic.',
					meta: {
						_wp_mcp_ai_tools: [ 'get_environment_status' ],
					},
				},
			],
		},
		toolkits: [ 'crm' ],
		site: {
			region: 'us',
			size_hint: 'small',
			php: '8.1',
			domain: null,
			wp_manager: true,
		},
		gateway: {
			visibility: 'private',
			tool_allowlist: [ 'get_environment_status' ],
			rate_limit_profile: 'standard',
		},
		commerce: {
			stripe_price_id: 'price_1234567890',
			trial_days: 14,
			metered: false,
		},
		listing: {
			summary: 'An operations assistant for small clinics and private practices.',
			use_cases: [ 'Booking triage', 'Stock alerts' ],
			pricing_note: 'Billed monthly per clinic.',
			connect_snippets: true,
		},
		...overrides,
	};
}

test( 'accepts a valid blueprint and applies defaults', () => {
	const result = validateBlueprint( validBlueprint() );
	assert.equal( result.ok, true );
	assert.equal( result.blueprint.slug, 'clinic-ops' );
	assert.equal( result.blueprint.console.enable, true );
	assert.equal( result.blueprint.console.template, 'complete-velocity' );
	assert.equal( result.blueprint.storefront.enable, false );
	assert.deepEqual( result.blueprint.toolkits, [ 'crm' ] );
	assert.equal( result.blueprint.commerce.trial_days, 14 );
} );

test( 'rejects an unsupported blueprint_version', () => {
	const result = validateBlueprint( validBlueprint( { blueprint_version: 2 } ) );
	assert.equal( result.ok, false );
	assert.match( result.errors[ 0 ].message, /blueprint_version/ );
} );

test( 'rejects unknown top-level fields', () => {
	const result = validateBlueprint( validBlueprint( { surprise: true } ) );
	assert.equal( result.ok, false );
	assert.ok( result.errors.some( ( error ) => error.path === 'surprise' ) );
} );

test( 'rejects malformed slugs', () => {
	for ( const slug of [ 'Clinic-Ops', '-clinic', 'clinic-', 'a', 'a'.repeat( 41 ) ] ) {
		const result = validateBlueprint( validBlueprint( { slug } ) );
		assert.equal( result.ok, false, `slug ${ slug } should fail` );
	}
} );

test( 'rejects duplicate slugs', () => {
	const result = validateBlueprint( validBlueprint(), { existingSlugs: [ 'clinic-ops' ] } );
	assert.equal( result.ok, false );
	assert.ok( result.errors.some( ( error ) => error.message.includes( 'already used' ) ) );
} );

test( 'rejects a public gate without an allowlist', () => {
	const blueprint = validBlueprint();
	blueprint.gateway.visibility = 'public';
	blueprint.gateway.tool_allowlist = [];
	const result = validateBlueprint( blueprint );
	assert.equal( result.ok, false );
	assert.ok( result.errors.some( ( error ) => error.path === 'gateway.tool_allowlist' ) );
} );

test( 'rejects secret-shaped keys outside _meta', () => {
	const blueprint = validBlueprint();
	blueprint.bundle.assistants[ 0 ].meta.api_key = 'sk-should-never-be-here';
	const result = validateBlueprint( blueprint );
	assert.equal( result.ok, false );
	assert.ok( result.errors.some( ( error ) => /Secret-shaped field/.test( error.message ) ) );
} );

test( 'rejects credential-shaped values anywhere', () => {
	const blueprint = validBlueprint();
	blueprint.bundle.assistants[ 0 ].content = 'Use op_abcd1234.SECRETTOKEN to connect.';
	const result = validateBlueprint( blueprint );
	assert.equal( result.ok, false );
	assert.ok( result.errors.some( ( error ) => /live credential/.test( error.message ) ) );
} );

test( 'allows _meta without scanning it for secret-shaped keys', () => {
	const blueprint = validBlueprint();
	blueprint._meta = { author: 'seed', source: 'exchange-seed' };
	const result = validateBlueprint( blueprint );
	assert.equal( result.ok, true );
} );

test( 'rejects mcp_apps entries carrying inline credentials', () => {
	const blueprint = validBlueprint();
	blueprint.mcp_apps = [
		{ connection_ref: 'mcp_server:elementor-primary', token: 'abc' },
	];
	const result = validateBlueprint( blueprint );
	assert.equal( result.ok, false );
	assert.ok( result.errors.some( ( error ) => error.path === 'mcp_apps[0].token' ) );
} );

test( 'rejects malformed price ids', () => {
	const blueprint = validBlueprint();
	blueprint.commerce.stripe_price_id = 'price_$bad';
	const result = validateBlueprint( blueprint );
	assert.equal( result.ok, false );
	assert.ok( result.errors.some( ( error ) => error.path === 'commerce.stripe_price_id' ) );
} );

test( 'requires a template when the storefront is enabled', () => {
	const blueprint = validBlueprint();
	blueprint.storefront = { enable: true, template: null };
	const result = validateBlueprint( blueprint );
	assert.equal( result.ok, false );
	assert.ok( result.errors.some( ( error ) => error.path === 'storefront.template' ) );
} );

test( 'rejects an empty assistant bundle', () => {
	const blueprint = validBlueprint();
	blueprint.bundle.assistants = [];
	const result = validateBlueprint( blueprint );
	assert.equal( result.ok, false );
	assert.ok( result.errors.some( ( error ) => error.path === 'bundle.assistants' ) );
} );

test( 'rejects a bundle with the wrong format marker', () => {
	const blueprint = validBlueprint();
	blueprint.bundle.format = 'something-else';
	const result = validateBlueprint( blueprint );
	assert.equal( result.ok, false );
	assert.ok( result.errors.some( ( error ) => error.path === 'bundle.format' ) );
} );
