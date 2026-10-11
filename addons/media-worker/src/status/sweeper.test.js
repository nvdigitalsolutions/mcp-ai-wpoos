/**
 * Tests for the dead man's switch sweeper (fake store + injectable clock).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { startSweeper } from './sweeper.js';

/** In-memory fake of the StatusStore surface used by the sweeper. */
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
	heartbeatIntervalMs: 300000,
	graceMultiplier: 2,
	confirmMisses: 2,
	confirmRecoveries: 1,
	syntheticEnabled: false,
	maintenanceUntil: '',
	sweeperIntervalMs: 60000,
};

test( 'sweeper flags a site with two consecutive misses as major_outage', async () => {
	const now0 = 1_760_000_000_000;
	const store = fakeStore( {
		'site-a': {
			slug: 'site-a',
			lastHeartbeatAt: now0 - 900000, // 3 missed intervals.
			reportedStatus: 'operational',
			components: {},
			status: 'operational',
			missCount: 0,
			since: now0,
		},
	} );
	const bus = fakeBus();
	const sweeper = startSweeper( { store, alertBus: bus, now: () => now0, siteConfigFn: () => CFG, intervalMs: 60000 } );

	await sweeper.sweepOnce();
	sweeper.stop();

	const record = store.records.get( 'site-a' );
	assert.equal( record.status, 'major_outage' );
	assert.ok( record.missCount >= 2 );
	assert.deepEqual( bus.events, [ { event: 'site.down', slug: 'site-a', status: 'major_outage' } ] );
} );

test( 'a fresh heartbeat resets the site to operational and fires recovery', async () => {
	const now0 = 1_760_000_000_000;
	const store = fakeStore( {
		'site-a': {
			slug: 'site-a',
			lastHeartbeatAt: now0 - 60000,
			reportedStatus: 'operational',
			components: {},
			status: 'major_outage',
			missCount: 3,
			since: now0 - 900000,
			downSince: now0 - 900000,
		},
	} );
	const bus = fakeBus();
	const sweeper = startSweeper( { store, alertBus: bus, now: () => now0, siteConfigFn: () => CFG, intervalMs: 60000 } );

	await sweeper.sweepOnce();
	sweeper.stop();

	assert.equal( store.records.get( 'site-a' ).status, 'operational' );
	assert.equal( store.records.get( 'site-a' ).downSince, 0 );
	assert.deepEqual( bus.events, [ { event: 'site.recovered', slug: 'site-a', status: 'operational' } ] );
} );

test( 'one miss inside grace -> at_risk without an alert', async () => {
	const now0 = 1_760_000_000_000;
	const store = fakeStore( {
		'site-a': {
			slug: 'site-a',
			lastHeartbeatAt: now0 - 350000,
			reportedStatus: 'operational',
			components: {},
			status: 'operational',
			missCount: 0,
			since: now0,
		},
	} );
	const bus = fakeBus();
	const sweeper = startSweeper( { store, alertBus: bus, now: () => now0, siteConfigFn: () => CFG, intervalMs: 60000 } );

	await sweeper.sweepOnce();
	sweeper.stop();

	assert.equal( store.records.get( 'site-a' ).status, 'at_risk' );
	assert.equal( bus.events.length, 0 );
} );

test( 'unchanged state persists nothing and fires no alerts', async () => {
	const now0 = 1_760_000_000_000;
	const store = fakeStore( {
		'site-a': {
			slug: 'site-a',
			lastHeartbeatAt: now0 - 60000,
			reportedStatus: 'operational',
			components: {},
			status: 'operational',
			missCount: 0,
			since: now0,
		},
	} );
	const bus = fakeBus();
	const sweeper = startSweeper( { store, alertBus: bus, now: () => now0, siteConfigFn: () => CFG, intervalMs: 60000 } );

	await sweeper.sweepOnce();
	sweeper.stop();

	assert.equal( store.transitions.length, 0 );
	assert.equal( bus.events.length, 0 );
} );

test( 'sweeper survives a store failure without throwing', async () => {
	const store = {
		async listSites() {
			throw new Error( 'boom' );
		},
		async getLatest() {
			return null;
		},
		async putLatest() {},
		async appendTransition() {},
	};
	const sweeper = startSweeper( { store, now: () => 0, siteConfigFn: () => CFG, intervalMs: 60000 } );
	await sweeper.sweepOnce(); // Must not throw.
	sweeper.stop();
	assert.ok( true );
} );
