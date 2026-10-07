/**
 * Tests for external-target seeding + the synthetic probe loop
 * (fake store + injectable clock/check, mirroring sweeper.test.js).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { startSyntheticLoop, seedExternalTargets } from './synthetic.js';
import { startSweeper } from './sweeper.js';

/** In-memory fake of the StatusStore surface. */
function fakeStore( initial = {} ) {
	const records = new Map( Object.entries( initial ) );
	const transitions = [];
	return {
		records,
		transitions,
		async listSites() {
			return Array.from( records.keys() );
		},
		async getLatest( slug ) {
			return records.get( slug ) || null;
		},
		async putLatest( slug, record ) {
			records.set( slug, { ...record } );
		},
		async appendTransition( slug, status, at ) {
			transitions.push( { slug, status, at } );
		},
	};
}

/** Alert bus recorder. */
function fakeBus() {
	const events = [];
	return {
		events,
		async dispatch( event, slug, state ) {
			events.push( { event, slug, status: state.status } );
		},
	};
}

const CFG = {
	enabled: true,
	heartbeatIntervalMs: 300000,
	graceMultiplier: 2,
	confirmMisses: 2,
	confirmRecoveries: 1,
	syntheticEnabled: true,
	syntheticIntervalMs: 60000,
	maintenanceUntil: '',
	sweeperIntervalMs: 60000,
};

const NOW = 1_760_000_000_000;

test( 'seedExternalTargets creates synthetic-only records and skips existing ones', async () => {
	const store = fakeStore( {
		'heartbeat-site': { slug: 'heartbeat-site', status: 'operational', lastHeartbeatAt: NOW - 1000 },
	} );

	const seeded = await seedExternalTargets( store, [
		{ slug: 'gateway', url: 'https://mcp.nvoos.pro/health' },
		{ slug: 'heartbeat-site', url: 'https://other.example/health' },
	] );

	assert.equal( seeded, 1 );
	const gateway = store.records.get( 'gateway' );
	assert.ok( gateway.syntheticOnly );
	assert.equal( gateway.syntheticUrl, 'https://mcp.nvoos.pro/health' );
	assert.equal( gateway.siteUrl, 'https://mcp.nvoos.pro/health' );
	assert.equal( gateway.lastHeartbeatAt, 0 );
	assert.equal( gateway.status, 'unknown' );
	// The existing heartbeat site is untouched.
	assert.equal( store.records.get( 'heartbeat-site' ).status, 'operational' );
	assert.equal( store.records.get( 'heartbeat-site' ).syntheticOnly, undefined );
} );

test( 'runOnce probes enabled sites, persists the result, and counts outcomes', async () => {
	const store = fakeStore( {
		gateway: {
			slug: 'gateway',
			siteUrl: 'https://mcp.nvoos.pro/health',
			syntheticUrl: 'https://mcp.nvoos.pro/health',
			lastHeartbeatAt: 0,
			syntheticOnly: true,
			status: 'unknown',
			components: {},
		},
	} );
	const counters = { synthetic: {} };
	const checkFn = async ( record, cfg, now ) => ( {
		ok: true,
		checkedAt: now,
		statusCode: 200,
		latencyMs: 42,
		tlsDaysLeft: 120,
		error: '',
	} );

	const loop = startSyntheticLoop( {
		store,
		counters,
		now: () => NOW,
		siteConfigFn: () => CFG,
		checkFn,
		intervalMs: 60000,
	} );
	await loop.runOnce();
	loop.stop();

	const record = store.records.get( 'gateway' );
	assert.equal( record.synthetic.ok, true );
	assert.equal( record.synthetic.checkedAt, NOW );
	assert.equal( record.synthetic.latencyMs, 42 );
	// The loop writes probe results only — status stays for the sweeper.
	assert.equal( record.status, 'unknown' );
	assert.deepEqual( counters.synthetic.gateway, { ok: 1 } );
} );

test( 'runOnce skips sites whose config disables synthetic checks', async () => {
	const store = fakeStore( { 'site-a': { slug: 'site-a', status: 'operational' } } );
	const counters = { synthetic: {} };
	let calls = 0;
	const loop = startSyntheticLoop( {
		store,
		counters,
		now: () => NOW,
		siteConfigFn: () => ( { ...CFG, syntheticEnabled: false } ),
		checkFn: async () => {
			calls += 1;
			return { ok: true, checkedAt: NOW };
		},
		intervalMs: 60000,
	} );
	await loop.runOnce();
	loop.stop();

	assert.equal( calls, 0 );
	assert.equal( store.records.get( 'site-a' ).synthetic, undefined );
} );

test( 'runOnce is inert when the status module is disabled', async () => {
	const store = fakeStore( { 'site-a': { slug: 'site-a' } } );
	let calls = 0;
	const loop = startSyntheticLoop( {
		store,
		siteConfigFn: () => ( { ...CFG, enabled: false } ),
		checkFn: async () => {
			calls += 1;
			return { ok: true, checkedAt: NOW };
		},
		intervalMs: 60000,
	} );
	await loop.runOnce();
	loop.stop();
	assert.equal( calls, 0 );
} );

test( 'runOnce survives a store failure without throwing', async () => {
	const store = {
		async listSites() {
			throw new Error( 'boom' );
		},
		async getLatest() {
			return null;
		},
		async putLatest() {},
	};
	const loop = startSyntheticLoop( {
		store,
		siteConfigFn: () => CFG,
		intervalMs: 60000,
	} );
	await loop.runOnce(); // Must not throw.
	loop.stop();
	assert.ok( true );
} );

test( 'synthetic-only target flows operational -> major_outage -> recovered through the sweeper', async () => {
	const store = fakeStore();
	const bus = fakeBus();
	const counters = { synthetic: {} };

	await seedExternalTargets( store, [ { slug: 'gateway', url: 'https://mcp.nvoos.pro/health' } ] );

	let ok = true;
	const checkFn = async ( record, cfg, now ) => ( {
		ok,
		checkedAt: now,
		statusCode: ok ? 200 : 0,
		latencyMs: ok ? 42 : null,
		tlsDaysLeft: 120,
		error: ok ? '' : 'timeout',
	} );

	const loop = startSyntheticLoop( { store, counters, now: () => NOW, siteConfigFn: () => CFG, checkFn, intervalMs: 60000 } );
	const sweeper = startSweeper( { store, alertBus: bus, now: () => NOW, siteConfigFn: () => CFG, intervalMs: 60000 } );

	// First probe passes -> sweeper promotes unknown to operational (no alert).
	await loop.runOnce();
	await sweeper.sweepOnce();
	assert.equal( store.records.get( 'gateway' ).status, 'operational' );
	assert.equal( bus.events.length, 0 );

	// Probe fails -> sweeper flips to major_outage + site.down alert.
	ok = false;
	await loop.runOnce();
	await sweeper.sweepOnce();
	let record = store.records.get( 'gateway' );
	assert.equal( record.status, 'major_outage' );
	assert.ok( record.downSince > 0 );
	assert.deepEqual( bus.events, [ { event: 'site.down', slug: 'gateway', status: 'major_outage' } ] );

	// Probe recovers -> sweeper flips back and clears downSince.
	ok = true;
	await loop.runOnce();
	await sweeper.sweepOnce();
	record = store.records.get( 'gateway' );
	assert.equal( record.status, 'operational' );
	assert.equal( record.downSince, 0 );
	assert.deepEqual( bus.events[ 1 ], { event: 'site.recovered', slug: 'gateway', status: 'operational' } );
	assert.deepEqual( counters.synthetic.gateway, { ok: 2, fail: 1 } );

	loop.stop();
	sweeper.stop();
} );
