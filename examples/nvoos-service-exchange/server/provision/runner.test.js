import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createEstateStore } from '../estates/store.js';
import { createProvisionRunner } from './runner.js';

function blueprint( overrides = {} ) {
	return {
		slug: 'clinic-ops',
		name: 'Clinic Ops',
		site: { wp_manager: true },
		gateway: { visibility: 'private', tool_allowlist: [ 'get_environment_status' ] },
		commerce: { stripe_price_id: null, trial_days: 14 },
		listing: {},
		...overrides,
	};
}

function build( { mode = 'mock' } = {} ) {
	const config = {
		mode,
		encryptionKey: 'test-key',
		cloudways: { defaultServerId: '1' },
		wp: { wpManagerEnabled: true, sshKeyPresent: false },
	};
	const clients = {
		cloudways: { ensureApp: async () => ( { id: 'app-1' } ) },
		github: { pushEstateBranch: async () => ( { branch: 'estate/clinic-ops', commit: 'c1' } ) },
		gateway: {
			verify: async () => ( {
				ok: true,
				steps: {},
				namespacedTools: [ 'clinic-ops.get_environment_status' ],
			} ),
		},
	};
	const store = createEstateStore();
	const runner = createProvisionRunner( { config, store, clients } );
	return { config, clients, store, runner };
}

test( 'advances one step at a time to live', async () => {
	const { store, runner } = build();
	await store.create( { slug: 'clinic-ops', blueprint: blueprint() } );

	const first = await runner.advance( 'clinic-ops' );
	assert.equal( first.state, 'cloudways_app' );
	assert.equal( first.advanced, true );

	const second = await runner.advance( 'clinic-ops' );
	assert.equal( second.state, 'wp_installed' );

	const finished = await runner.advanceToCompletion( 'clinic-ops' );
	assert.equal( finished.state, 'live' );
	assert.equal( store.get( 'clinic-ops' ).evidence.live, true );
} );

test( 'a completed estate is a no-op', async () => {
	const { store, runner } = build();
	await store.create( { slug: 'clinic-ops', blueprint: blueprint() } );
	await runner.advanceToCompletion( 'clinic-ops' );

	const again = await runner.advance( 'clinic-ops' );
	assert.equal( again.complete, true );
	assert.equal( again.advanced, false );
} );

test( 'concurrent advances serialize through the estate lock', async () => {
	const { store, runner } = build();
	await store.create( { slug: 'clinic-ops', blueprint: blueprint() } );

	const [ a, b ] = await Promise.all( [
		runner.advance( 'clinic-ops' ),
		runner.advance( 'clinic-ops' ),
	] );
	assert.equal( a.advanced, true );
	assert.equal( b.advanced, true );
	assert.equal( store.get( 'clinic-ops' ).evidence.cloudwaysApp, true );
	assert.equal( store.get( 'clinic-ops' ).evidence.wpInstalled, true );
} );

test( 'failures record a safe lastError and leave evidence unchanged', async () => {
	const { store, runner } = build( { mode: 'live' } );
	await store.create( { slug: 'clinic-ops', blueprint: blueprint() } );

	// cloudways_app succeeds with the stub, then wp_installed fails because
	// no sys_user is known for the estate.
	await runner.advance( 'clinic-ops' );
	await assert.rejects(
		() => runner.advance( 'clinic-ops' ),
		( error ) => error.code === 'provisioning_failed'
	);
	const estate = store.get( 'clinic-ops' );
	assert.equal( estate.evidence.wpInstalled, undefined );
	assert.equal( estate.lastError.code, 'provisioning_failed' );
	assert.ok( ! JSON.stringify( estate.lastError ).includes( 'test-key' ) );
} );

test( 'unknown estates raise not_found', async () => {
	const { runner } = build();
	await assert.rejects(
		() => runner.advance( 'missing' ),
		( error ) => error.code === 'not_found'
	);
} );
