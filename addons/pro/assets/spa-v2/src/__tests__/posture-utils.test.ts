// SPDX-License-Identifier: LicenseRef-Proprietary
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
	type PostureSignal,
} from '../features/security-center/posture-utils';

/** A pre-1.5 server payload — note the absence of `applicable`/`status`. */
const RAW_SIGNAL = {
	id: 'https_active',
	label: 'Site is served over HTTPS',
	weight: 10,
	passed: true,
	detail: 'HTTPS is active.',
	subtab: 'network',
	anchor: 'require_https',
};

/** The same signal after parseSignal(). */
const SIGNAL: PostureSignal = {
	...RAW_SIGNAL,
	applicable: true,
	status: 'pass',
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
			signals: [ { nope: true }, 'junk', RAW_SIGNAL ],
		} );
		expect( report?.signals ).toHaveLength( 1 );
		expect( report?.signals[ 0 ].id ).toBe( 'https_active' );
	} );

	it( 'returns null for unknown payloads', () => {
		expect( parsePosture( null ) ).toBeNull();
		expect( parsePosture( {} ) ).toBeNull();
		expect( parsePosture( { score: 'high' } ) ).toBeNull();
	} );

	it( 'treats signals from backends without `applicable` as applicable', () => {
		const report = parsePosture( { score: 40, signals: [ RAW_SIGNAL ] } );
		expect( report?.signals[ 0 ] ).toMatchObject( {
			applicable: true,
			status: 'pass',
			passed: true,
		} );
	} );

	it( 'parses a not-applicable signal without counting it as a failure', () => {
		const report = parsePosture( {
			score: 100,
			signals: [
				SIGNAL,
				{
					...RAW_SIGNAL,
					id: 'root_key_set',
					applicable: false,
					passed: false,
					status: 'not_applicable',
					detail: 'Not applicable on this runtime.',
					subtab: 'access',
				},
			],
		} );
		expect( report?.signals[ 1 ] ).toMatchObject( {
			id: 'root_key_set',
			applicable: false,
			passed: false,
			status: 'not_applicable',
		} );
	} );

	it( 'derives a not-applicable status when the backend omits it', () => {
		const report = parsePosture( {
			score: 90,
			signals: [ { ...RAW_SIGNAL, applicable: false, passed: false, status: undefined } ],
		} );
		expect( report?.signals[ 0 ].status ).toBe( 'not_applicable' );
	} );

	it( 'never reports an inapplicable signal as passing', () => {
		// A backend that sends applicable:false with passed:true is
		// contradictory; the inapplicable flag wins so the table cannot show a
		// green PASS on a control the runtime does not have.
		const report = parsePosture( {
			score: 90,
			signals: [ { ...RAW_SIGNAL, applicable: false, passed: true } ],
		} );
		expect( report?.signals[ 0 ].passed ).toBe( false );
		expect( report?.signals[ 0 ].status ).toBe( 'not_applicable' );
	} );

	it( 'keeps the catalogue reference when present', () => {
		const report = parsePosture( {
			score: 90,
			signals: [ { ...RAW_SIGNAL, reference: 'RFC 6797 (HSTS)' } ],
		} );
		expect( report?.signals[ 0 ].reference ).toBe( 'RFC 6797 (HSTS)' );
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

	it( 'handles the Mozilla Observatory +/- bands', () => {
		expect( gradeTone( 'A+' ) ).toBe( 'ok' );
		expect( gradeTone( 'A-' ) ).toBe( 'ok' );
		expect( gradeTone( 'B-' ) ).toBe( 'ok' );
		expect( gradeTone( 'C+' ) ).toBe( 'warning' );
		expect( gradeTone( 'C-' ) ).toBe( 'warning' );
		expect( gradeTone( 'D-' ) ).toBe( 'error' );
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

	it( 'keeps not-applicable signals visible in their subtab', () => {
		// They stay in the table — an operator needs to see that the control
		// was considered — they are just excluded from the score.
		const parsed = parsePosture( {
			score: 100,
			signals: [ { ...RAW_SIGNAL, applicable: false, passed: false, status: 'not_applicable' } ],
		} );
		const groups = groupSignals( parsed?.signals ?? [] );
		expect( groups ).toHaveLength( 1 );
		expect( groups[ 0 ].signals[ 0 ].status ).toBe( 'not_applicable' );
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
