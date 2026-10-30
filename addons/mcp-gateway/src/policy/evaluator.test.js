/**
 * Policy evaluator unit tests (Phase 2).
 *
 * Asserts the rule grammar: parsing/validation, first-match-wins, tool/site/
 * key/time/geo conditions, midnight-crossing windows, and the deny default
 * once a ruleset exists.
 */

import { test } from 'node:test';
import assert from 'node:assert';
import {
	parseTimeToken,
	validateRule,
	parsePolicy,
	localTime,
	inWindow,
	evaluatePolicy,
} from './evaluator.js';
import { parseGeoMap, resolveCountry } from './geo.js';

test( 'parseTimeToken parses HH:MM and rejects malformed times', () => {
	assert.strictEqual( parseTimeToken( '09:00' ), 540 );
	assert.strictEqual( parseTimeToken( '00:00' ), 0 );
	assert.strictEqual( parseTimeToken( '23:59' ), 1439 );
	assert.strictEqual( parseTimeToken( '9:00' ), 540 );
	assert.strictEqual( parseTimeToken( '24:00' ), null );
	assert.strictEqual( parseTimeToken( '09:60' ), null );
	assert.strictEqual( parseTimeToken( 'foo' ), null );
} );

test( 'validateRule catches malformed rules', () => {
	assert.deepStrictEqual( validateRule( { id: 'r1', effect: 'deny' } ), [] );
	assert.ok( validateRule( { effect: 'deny' } ).length > 0, 'missing id' );
	assert.ok( validateRule( { id: 'r1', effect: 'allow' } ).length > 0, 'bad effect' );
	assert.ok( validateRule( { id: 'r1', effect: 'deny', site: 'site-a' } ).length > 0, 'site not array' );
	assert.ok(
		validateRule( {
			id: 'r1',
			effect: 'deny',
			time: { window: { tz: 'UTC', days: [ 1 ], start: '9am', end: '10:00' } },
		} ).length > 0,
		'bad window time'
	);
	assert.ok(
		validateRule( { id: 'r1', effect: 'deny', geo: { country: [ 'GBR' ] } } ).length > 0,
		'geo must be alpha-2'
	);
} );

test( 'parsePolicy compiles rules and collects errors', () => {
	const errors = [];
	const policy = parsePolicy(
		JSON.stringify( {
			rules: [
				{ id: 'no-deletes', effect: 'deny', tool: { match: 'delete_*' } },
				{ id: 'approve-writes', effect: 'require_approval', site: [ 'site-a' ] },
			],
		} ),
		errors
	);
	assert.deepStrictEqual( errors, [] );
	assert.strictEqual( policy.rules.length, 2 );

	const bad = [];
	assert.strictEqual( parsePolicy( '{not json', bad ), null );
	assert.ok( bad.length > 0 );
	assert.strictEqual( parsePolicy( '', [] ), null );
} );

test( 'evaluatePolicy: first match wins, tool globs match, no ruleset allows', () => {
	const policy = parsePolicy(
		JSON.stringify( {
			rules: [
				{ id: 'specific', effect: 'deny', tool: { match: 'delete_post' } },
				{ id: 'broad', effect: 'require_approval', tool: { match: 'delete_*' } },
			],
		} ),
		[]
	);
	const input = { tool: 'delete_post', site: 's', key: 'k', time: new Date(), country: null };
	assert.deepStrictEqual( evaluatePolicy( policy, input ), { effect: 'deny', ruleId: 'specific' } );
	assert.deepStrictEqual( evaluatePolicy( policy, { ...input, tool: 'delete_page' } ), {
		effect: 'require_approval',
		ruleId: 'broad',
	} );
	assert.deepStrictEqual( evaluatePolicy( policy, { ...input, tool: 'get_post' } ), {
		effect: 'allow',
		ruleId: null,
	} );
	assert.deepStrictEqual( evaluatePolicy( null, input ), { effect: 'allow', ruleId: null } );
} );

test( 'evaluatePolicy: site, key, and geo conditions', () => {
	const policy = parsePolicy(
		JSON.stringify( {
			rules: [
				{ id: 'uk-only-block', effect: 'deny', site: [ 'site-a' ], geo: { country: [ 'GB' ] } },
				{ id: 'key-block', effect: 'deny', key: [ 'key-x' ] },
			],
		} ),
		[]
	);
	const base = { tool: 't', time: new Date(), country: 'GB' };
	assert.deepStrictEqual( evaluatePolicy( policy, { ...base, site: 'site-a', key: 'k' } ), {
		effect: 'deny',
		ruleId: 'uk-only-block',
	} );
	assert.deepStrictEqual( evaluatePolicy( policy, { ...base, site: 'site-b', key: 'key-x' } ), {
		effect: 'deny',
		ruleId: 'key-block',
	} );
	assert.deepStrictEqual( evaluatePolicy( policy, { ...base, site: 'site-b', key: 'ok', country: 'US' } ), {
		effect: 'allow',
		ruleId: null,
	} );
} );

test( 'evaluatePolicy: time windows honour tz, days, and midnight crossing', () => {
	const policy = parsePolicy(
		JSON.stringify( {
			rules: [
				{
					id: 'night',
					effect: 'deny',
					time: { window: { tz: 'Europe/London', days: [ 6, 7 ], start: '22:00', end: '06:00' } },
				},
			],
		} ),
		[]
	);

	// 2026-10-31 is a Saturday (day 6); 23:00 UTC = 23:00 London (GMT).
	const saturdayNight = new Date( '2026-10-31T23:00:00Z' );
	assert.deepStrictEqual(
		evaluatePolicy( policy, { tool: 't', site: 's', key: 'k', time: saturdayNight, country: null } ),
		{ effect: 'deny', ruleId: 'night' }
	);
	// Same instant of week, but a weekday → allow.
	const mondayNight = new Date( '2026-10-26T23:00:00Z' );
	assert.deepStrictEqual(
		evaluatePolicy( policy, { tool: 't', site: 's', key: 'k', time: mondayNight, country: null } ),
		{ effect: 'allow', ruleId: null }
	);
	// Saturday midday → outside the window → allow.
	const saturdayNoon = new Date( '2026-10-31T12:00:00Z' );
	assert.deepStrictEqual(
		evaluatePolicy( policy, { tool: 't', site: 's', key: 'k', time: saturdayNoon, country: null } ),
		{ effect: 'allow', ruleId: null }
	);
} );

test( 'localTime resolves the zone correctly and inWindow wraps midnight', () => {
	const local = localTime( new Date( '2026-10-31T23:30:00Z' ), 'Europe/London' );
	assert.strictEqual( local.weekday, 6 ); // Saturday.
	assert.strictEqual( local.minuteOfDay, 23 * 60 + 30 );

	assert.strictEqual( inWindow( 1410, 1320, 360 ), true ); // 23:30 inside 22:00–06:00.
	assert.strictEqual( inWindow( 300, 1320, 360 ), true ); // 05:00 inside.
	assert.strictEqual( inWindow( 720, 1320, 360 ), false ); // 12:00 outside.
	assert.strictEqual( inWindow( 600, 540, 1020 ), true ); // Normal window.
} );

test( 'geo: CF-IPCountry trusted only behind the proxy, GEO_MAP fallback', () => {
	assert.deepStrictEqual( parseGeoMap( '', [] ), null );
	const bad = [];
	assert.strictEqual( parseGeoMap( '{bad', bad ), null );
	assert.ok( bad.length > 0 );
	assert.deepStrictEqual( parseGeoMap( JSON.stringify( { k: 'gb' } ), [] ).get( 'k' ), 'GB' );

	const reqTrusted = { get: () => 'gb' };
	assert.strictEqual( resolveCountry( reqTrusted, { trustProxy: true, geoMap: null } ), 'GB' );
	const reqUntrusted = { get: () => 'gb' };
	assert.strictEqual( resolveCountry( reqUntrusted, { trustProxy: false, geoMap: null } ), null );
	const reqMap = { get: () => '', gatewayKeyId: 'k' };
	assert.strictEqual(
		resolveCountry( reqMap, { trustProxy: false, geoMap: parseGeoMap( JSON.stringify( { k: 'FR' } ), [] ) } ),
		'FR'
	);
} );
