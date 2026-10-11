/**
 * Tests for the per-site state machine + uptime math.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	severityOf,
	worstOf,
	graceMs,
	computeState,
	computeUptime,
	isDowntime,
} from './state.js';

/** Standard test config (mimics siteConfig() defaults). */
function cfg( overrides = {} ) {
	return {
		heartbeatIntervalMs: 300000,
		graceMultiplier: 2,
		confirmMisses: 2,
		confirmRecoveries: 1,
		syntheticEnabled: false,
		maintenanceUntil: '',
		...overrides,
	};
}

const NOW = 1_760_000_000_000;

test( 'severityOf orders the shared taxonomy worst-first', () => {
	assert.equal( severityOf( 'operational' ), 1 );
	assert.equal( severityOf( 'at_risk' ), 2 );
	assert.equal( severityOf( 'under_maintenance' ), 3 );
	assert.equal( severityOf( 'degraded_performance' ), 4 );
	assert.equal( severityOf( 'partial_outage' ), 5 );
	assert.equal( severityOf( 'major_outage' ), 6 );
	assert.equal( severityOf( 'garbage' ), 0 );
} );

test( 'worstOf picks the most severe status', () => {
	assert.equal( worstOf( [] ), 'operational' );
	assert.equal( worstOf( [ 'operational', 'degraded_performance', 'operational' ] ), 'degraded_performance' );
	assert.equal( worstOf( [ 'major_outage', 'under_maintenance' ] ), 'major_outage' );
} );

test( 'graceMs is the heartbeat interval times the multiplier', () => {
	assert.equal( graceMs( cfg() ), 600000 );
} );

test( 'first heartbeat flips unknown -> operational', () => {
	const record = { lastHeartbeatAt: NOW - 60000, reportedStatus: 'operational', components: {} };
	const state = computeState( record, NOW, cfg() );
	assert.equal( state.status, 'operational' );
	assert.equal( state.missCount, 0 );
} );

test( 'heartbeat-carried maintenance wins over reported status', () => {
	const record = {
		lastHeartbeatAt: NOW - 60000,
		reportedStatus: 'operational',
		components: {},
		maintenanceUntil: NOW + 3600000,
	};
	const state = computeState( record, NOW, cfg() );
	assert.equal( state.status, 'under_maintenance' );
} );

test( 'expired maintenance falls back to the reported status', () => {
	const record = {
		lastHeartbeatAt: NOW - 60000,
		reportedStatus: 'operational',
		components: {},
		maintenanceUntil: NOW - 1000,
	};
	const state = computeState( record, NOW, cfg() );
	assert.equal( state.status, 'operational' );
} );

test( 'reported major_outage is respected on a fresh heartbeat', () => {
	const record = {
		lastHeartbeatAt: NOW - 60000,
		reportedStatus: 'major_outage',
		components: {},
	};
	const state = computeState( record, NOW, cfg() );
	assert.equal( state.status, 'major_outage' );
} );

test( 'split-brain: fresh heartbeat + synthetic failure -> partial_outage', () => {
	const record = {
		lastHeartbeatAt: NOW - 60000,
		reportedStatus: 'operational',
		components: {},
		synthetic: { ok: false, checkedAt: NOW - 30000, latencyMs: 120 },
	};
	const state = computeState( record, NOW, cfg( { syntheticEnabled: true } ) );
	assert.equal( state.status, 'partial_outage' );
} );

test( 'one miss inside grace -> at_risk, count preserved', () => {
	const record = { lastHeartbeatAt: NOW - 350000, reportedStatus: 'operational', components: {}, missCount: 0 };
	const state = computeState( record, NOW, cfg() );
	assert.equal( state.status, 'at_risk' );
	assert.equal( state.missCount, 1 );
} );

test( 'two consecutive misses -> major_outage', () => {
	const record = {
		lastHeartbeatAt: NOW - 600000,
		reportedStatus: 'operational',
		components: {},
		missCount: 2,
	};
	const state = computeState( record, NOW, cfg() );
	assert.equal( state.status, 'major_outage' );
} );

test( 'a fresh heartbeat recovers from an outage', () => {
	const record = {
		lastHeartbeatAt: NOW - 60000,
		reportedStatus: 'operational',
		components: {},
		missCount: 5,
		downSince: NOW - 600000,
	};
	const state = computeState( record, NOW, cfg() );
	assert.equal( state.status, 'operational' );
	assert.equal( state.missCount, 0 );
} );

test( 'no heartbeat yet -> unknown (major_outage when synthetic confirms)', () => {
	const empty = { lastHeartbeatAt: 0, components: {} };
	assert.equal( computeState( empty, NOW, cfg() ).status, 'unknown' );
	assert.equal(
		computeState( { lastHeartbeatAt: 0, components: {}, synthetic: { ok: false } }, NOW, cfg( { syntheticEnabled: true } ) ).status,
		'major_outage'
	);
} );

test( 'synthetic-only target: probe pass -> operational, fail -> major_outage, no probe -> unknown', () => {
	const base = { lastHeartbeatAt: 0, components: {}, syntheticOnly: true };
	assert.equal( computeState( base, NOW, cfg() ).status, 'unknown' );
	assert.equal(
		computeState( { ...base, synthetic: { ok: true, checkedAt: NOW - 1000 } }, NOW, cfg() ).status,
		'operational'
	);
	assert.equal(
		computeState( { ...base, synthetic: { ok: false, checkedAt: NOW - 1000 } }, NOW, cfg() ).status,
		'major_outage'
	);
} );

test( 'computeUptime counts only outage minutes', () => {
	const dayMs = 24 * 60 * 60 * 1000;
	const history = [
		{ status: 'operational', at: NOW - dayMs },
		{ status: 'major_outage', at: NOW - 12 * 60 * 60 * 1000 },
		{ status: 'operational', at: NOW - 11 * 60 * 60 * 1000 },
	];
	const { overall, byDay } = computeUptime( history, NOW, 2 );
	assert.ok( null !== overall );
	assert.ok( overall > 95 && overall < 100, `expected ~95-100%, got ${ overall }` );
	assert.equal( Object.keys( byDay ).length >= 1, true );
} );

test( 'isDowntime follows the Statuspage rule (outages only)', () => {
	assert.equal( isDowntime( 'major_outage' ), true );
	assert.equal( isDowntime( 'partial_outage' ), true );
	assert.equal( isDowntime( 'degraded_performance' ), false );
	assert.equal( isDowntime( 'under_maintenance' ), false );
	assert.equal( isDowntime( 'operational' ), false );
} );
