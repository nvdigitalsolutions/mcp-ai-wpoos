/**
 * Security Center posture-utils contract tests — parsePosture, groupSignals,
 * gradeTone, formatTimestamp, summarizeAction.
 */

import { describe, it, expect } from 'vitest';

import {
	parsePosture,
	groupSignals,
	gradeTone,
	formatTimestamp,
	summarizeAction,
} from '../features/security-center/posture-utils';

const SIGNAL = {
	id: 'https_active',
	label: 'Site is served over HTTPS',
	weight: 10,
	passed: true,
	detail: 'HTTPS is active.',
	subtab: 'network',
	anchor: 'require_https',
};

describe( 'parsePosture', () => {
	it( 'parses the server report shape', () => {
		const report = parsePosture( {
			score: 72,
			grade: 'B',
			signals: [ SIGNAL ],
			quick_wins: [],
			computed_at: '2026-10-23T08:00:00+00:00',
		} );
		expect( report ).not.toBeNull();
		expect( report?.score ).toBe( 72 );
		expect( report?.grade ).toBe( 'B' );
		expect( report?.signals ).toHaveLength( 1 );
		expect( report?.signals[ 0 ] ).toMatchObject( {
			id: 'https_active',
			passed: true,
			subtab: 'network',
		} );
		expect( report?.computedAt ).toBe( '2026-10-23T08:00:00+00:00' );
	} );

	it( 'drops malformed signals instead of crashing', () => {
		const report = parsePosture( {
			score: 50,
			signals: [ { nope: true }, 'junk', SIGNAL ],
		} );
		expect( report?.signals ).toHaveLength( 1 );
		expect( report?.signals[ 0 ].id ).toBe( 'https_active' );
	} );

	it( 'returns null for unknown payloads', () => {
		expect( parsePosture( null ) ).toBeNull();
		expect( parsePosture( {} ) ).toBeNull();
		expect( parsePosture( { score: 'high' } ) ).toBeNull();
	} );
} );

describe( 'gradeTone', () => {
	it( 'maps letter grades to badge tones', () => {
		expect( gradeTone( 'A' ) ).toBe( 'ok' );
		expect( gradeTone( 'B' ) ).toBe( 'ok' );
		expect( gradeTone( 'C' ) ).toBe( 'warning' );
		expect( gradeTone( 'D' ) ).toBe( 'error' );
		expect( gradeTone( 'F' ) ).toBe( 'error' );
		expect( gradeTone( '' ) ).toBe( 'error' );
	} );
} );

describe( 'groupSignals', () => {
	it( 'groups by subtab preserving first-seen order', () => {
		const groups = groupSignals( [
			SIGNAL,
			{ ...SIGNAL, id: 'hsts_enabled', subtab: 'network' },
			{ ...SIGNAL, id: 'audit_log_on', subtab: 'audit' },
			{ ...SIGNAL, id: 'injection_guard', subtab: 'ai_safety' },
		] );
		expect( groups.map( ( g ) => g.label ) ).toEqual( [ 'Network', 'Audit & Compliance', 'AI Safety' ] );
		expect( groups[ 0 ].signals.map( ( s ) => s.id ) ).toEqual( [ 'https_active', 'hsts_enabled' ] );
	} );

	it( 'folds unknown subtabs under Other', () => {
		const groups = groupSignals( [ { ...SIGNAL, subtab: 'mystery' } ] );
		expect( groups[ 0 ].label ).toBe( 'Other' );
	} );
} );

describe( 'formatTimestamp', () => {
	it( 'localizes a valid ISO timestamp and refuses garbage', () => {
		expect( formatTimestamp( '2026-10-23T08:00:00+00:00' ) ).not.toBe( '' );
		expect( formatTimestamp( '' ) ).toBe( '' );
		expect( formatTimestamp( 'not-a-date' ) ).toBe( '' );
	} );
} );

describe( 'summarizeAction', () => {
	it( 'summarizes the self-test result shape', () => {
		const summary = summarizeAction( {
			success: true,
			results: {
				auth_fail: { passed: true, message: 'event fired' },
				ip_block: { passed: true, message: 'event fired' },
				injection: { passed: true, message: 'event fired' },
				audit_log_check: { passed: false, message: 'audit log is disabled' },
			},
		} );
		expect( summary ).toMatchObject( { title: 'Self-test', tone: 'error' } );
		expect( summary?.detail ).toContain( '3 of 4' );
		expect( summary?.checks ).toHaveLength( 4 );
		expect( summary?.checks?.[ 0 ] ).toMatchObject( { id: 'auth_fail', passed: true } );
	} );

	it( 'summarizes a snapshot save', () => {
		const summary = summarizeAction( {
			success: true,
			snapshot_id: 'uuid-1',
			label: 'Baseline',
			created_at: '2026-10-23T08:00:00+00:00',
		} );
		expect( summary ).toMatchObject( { title: 'Snapshot saved', tone: 'ok' } );
		expect( summary?.detail ).toContain( 'Baseline' );
	} );

	it( 'summarizes a snapshot restore', () => {
		const summary = summarizeAction( {
			success: true,
			snapshot_id: 'uuid-2',
			label: 'Pre-restore backup',
			keys_restored: 12,
		} );
		expect( summary ).toMatchObject( { title: 'Settings restored', tone: 'ok' } );
		expect( summary?.detail ).toContain( '12 security settings' );
	} );

	it( 'falls back to a generic summary for unknown shapes', () => {
		expect( summarizeAction( null ) ).toBeNull();
		expect( summarizeAction( { success: false } ) ).toMatchObject( {
			title: 'Security action',
			tone: 'error',
		} );
	} );
} );
