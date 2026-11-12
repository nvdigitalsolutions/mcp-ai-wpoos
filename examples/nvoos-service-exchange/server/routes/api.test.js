import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createApiRouter, createRateLimiter } from './api.js';
import { createEstateStore } from '../estates/store.js';
import { createEstateService } from '../estates/service.js';
import { createProvisionRunner } from '../provision/runner.js';

const SEED = {
	blueprint_version: 1,
	name: 'Clinic Operations Copilot',
	slug: 'clinic-ops',
	tagline: 'Bookings, stock and compliance for small clinics',
	industry: 'healthcare',
	bundle: {
		format: 'nvoos-assistant',
		format_version: 1,
		assistants: [ { title: 'Clinic Ops Copilot', meta: {} } ],
	},
	site: { region: 'us', size_hint: 'small', php: '8.1', domain: null, wp_manager: true },
	gateway: { visibility: 'private', tool_allowlist: [ 'get_environment_status' ], rate_limit_profile: 'standard' },
	listing: { summary: 'Operations assistant for small clinics.', use_cases: [], connect_snippets: true },
};

function buildHarness( { mode = 'mock', adminToken = '' } = {} ) {
	const config = {
		mode,
		adminToken,
		encryptionKey: 'test-key',
		rateLimit: { windowMs: 60_000, max: 100 },
		cloudways: { defaultServerId: '1' },
		wp: { wpManagerEnabled: true, sshKeyPresent: false },
		gateway: { baseUrl: 'https://gateway.test' },
	};
	const clients = {
		cloudways: { ensureApp: async () => ( { id: 'mock-app' } ) },
		github: { pushEstateBranch: async () => ( { branch: 'estate/clinic-ops', commit: 'x' } ) },
		gateway: { verify: async () => ( { ok: true, steps: {}, namespacedTools: [ 'clinic-ops.tool' ] } ) },
	};
	const store = createEstateStore();
	const provisioner = createProvisionRunner( { config, store, clients } );
	const service = createEstateService( { config, store, provisioner, clients, seeds: [ SEED ] } );
	const router = createApiRouter( { config, service, seeds: [ SEED ], version: '0.1.0-test' } );
	return { config, store, service, router };
}

async function call( router, overrides = {} ) {
	return router.handle( {
		method: 'GET',
		path: '/api/v1/health',
		headers: {},
		body: null,
		ip: '127.0.0.1',
		...overrides,
	} );
}

function bearer( token = 'admin-token' ) {
	return { authorization: `Bearer ${ token }` };
}

test( 'health is public and reports mode/version', async () => {
	const { router } = buildHarness();
	const response = await call( router );
	assert.equal( response.status, 200 );
	assert.equal( response.body.ok, true );
	assert.equal( response.body.data.mode, 'mock' );
	assert.equal( response.body.data.version, '0.1.0-test' );
} );

test( 'live mode rejects missing or wrong tokens', async () => {
	const { router } = buildHarness( { mode: 'live', adminToken: 'admin-token' } );
	assert.equal( ( await call( router, { path: '/api/v1/estates' } ) ).status, 401 );
	assert.equal(
		( await call( router, { path: '/api/v1/estates', headers: bearer( 'wrong' ) } ) ).status,
		401
	);
	assert.equal(
		( await call( router, { path: '/api/v1/estates', headers: bearer( 'admin-token' ) } ) ).status,
		200
	);
} );

test( 'creates an estate from a blueprint slug and advances it', async () => {
	const { router } = buildHarness();
	const created = await call( router, {
		method: 'POST',
		path: '/api/v1/estates',
		body: { blueprint_slug: 'clinic-ops' },
	} );
	assert.equal( created.status, 201 );
	assert.equal( created.body.data.slug, 'clinic-ops' );
	assert.equal( created.body.data.state, 'requested' );

	const advanced = await call( router, {
		method: 'POST',
		path: '/api/v1/estates/clinic-ops/advance',
	} );
	assert.equal( advanced.status, 200 );
	assert.equal( advanced.body.data.state, 'cloudways_app' );

	const detail = await call( router, { path: '/api/v1/estates/clinic-ops' } );
	assert.equal( detail.status, 200 );
	assert.equal( detail.body.data.steps.filter( ( step ) => step.ok ).length, 1 );
} );

test( 'rejects invalid blueprints with 422', async () => {
	const { router } = buildHarness();
	const response = await call( router, {
		method: 'POST',
		path: '/api/v1/estates',
		body: { blueprint: { blueprint_version: 1, name: 'x' } },
	} );
	assert.equal( response.status, 422 );
	assert.equal( response.body.error.code, 'validation_failed' );
} );

test( 'duplicate slugs are rejected', async () => {
	const { router } = buildHarness();
	await call( router, { method: 'POST', path: '/api/v1/estates', body: { blueprint_slug: 'clinic-ops' } } );
	const duplicate = await call( router, {
		method: 'POST',
		path: '/api/v1/estates',
		body: { blueprint_slug: 'clinic-ops' },
	} );
	assert.equal( duplicate.status, 422 );
	assert.ok( duplicate.body.error.errors.some( ( error ) => /already used/.test( error.message ) ) );
} );

test( 'teardown requires explicit confirmation and slug echo', async () => {
	const { router } = buildHarness();
	await call( router, { method: 'POST', path: '/api/v1/estates', body: { blueprint_slug: 'clinic-ops' } } );

	const refused = await call( router, {
		method: 'POST',
		path: '/api/v1/estates/clinic-ops/teardown',
		body: { confirm: true, slug: 'wrong' },
	} );
	assert.equal( refused.status, 400 );

	const removed = await call( router, {
		method: 'POST',
		path: '/api/v1/estates/clinic-ops/teardown',
		body: { confirm: true, slug: 'clinic-ops' },
	} );
	assert.equal( removed.status, 200 );
	assert.equal( removed.body.data.removed, true );
	assert.equal( ( await call( router, { path: '/api/v1/estates/clinic-ops' } ) ).status, 404 );
} );

test( 'unknown routes return a 404 envelope', async () => {
	const { router } = buildHarness();
	const response = await call( router, { path: '/api/v1/nope' } );
	assert.equal( response.status, 404 );
	assert.equal( response.body.error.code, 'not_found' );
} );

test( 'rate limiter blocks after the budget is exhausted', async () => {
	const limiter = createRateLimiter( { windowMs: 60_000, max: 2 } );
	const { router } = buildHarness();
	const limited = createApiRouter( {
		config: { mode: 'mock', adminToken: '', rateLimit: { windowMs: 60_000, max: 2 } },
		service: { list: () => [], catalogue: () => [] },
		seeds: [],
		limiter,
	} );
	assert.equal( ( await limited.handle( { method: 'GET', path: '/api/v1/estates', ip: '1.2.3.4' } ) ).status, 200 );
	assert.equal( ( await limited.handle( { method: 'GET', path: '/api/v1/estates', ip: '1.2.3.4' } ) ).status, 200 );
	const blocked = await limited.handle( { method: 'GET', path: '/api/v1/estates', ip: '1.2.3.4' } );
	assert.equal( blocked.status, 429 );
	assert.equal( blocked.body.error.code, 'rate_limited' );
	// Other IPs are unaffected.
	assert.equal( ( await limited.handle( { method: 'GET', path: '/api/v1/estates', ip: '5.6.7.8' } ) ).status, 200 );
} );
