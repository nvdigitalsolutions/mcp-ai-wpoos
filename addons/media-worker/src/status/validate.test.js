/**
 * Tests for heartbeat payload v1 validation.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { validateHeartbeat, looksLikeCredential, VALID_STATUSES, MAX_PAYLOAD_BYTES } from './validate.js';

test( 'rejects non-object payloads', () => {
	assert.equal( validateHeartbeat( null ).ok, false );
	assert.equal( validateHeartbeat( 'string' ).ok, false );
	assert.equal( validateHeartbeat( [ 1, 2 ] ).ok, false );
} );

test( 'requires v=1', () => {
	assert.equal( validateHeartbeat( {} ).ok, false );
	assert.equal( validateHeartbeat( { v: 2 } ).ok, false );
	assert.ok( validateHeartbeat( { v: 1 } ).ok );
} );

test( 'sanitizes and allowlists the payload shape', () => {
	const result = validateHeartbeat( {
		v: 1,
		site_url: 'https://example.com/path',
		sent_at: 1760000000,
		checks: {
			overall: 'degraded_performance',
			components: {
				ai_providers: { status: 'operational', message: '<script>alert(1)</script>ok' },
				'Bad Slug!': { status: 'garbage', message: 'x'.repeat( 1000 ) },
			},
		},
		meta: { wp_version: '6.7.2', php_version: '8.2', plugin_version: '1.1.92', maintenance_until: 1760003600 },
		extra_junk: { nope: true },
	} );
	assert.equal( result.ok, true );
	const payload = result.payload;
	assert.equal( payload.site_url, 'https://example.com' );
	assert.equal( payload.checks.overall, 'degraded_performance' );
	assert.deepEqual( Object.keys( payload.checks.components ).sort(), [ 'ai_providers', 'bad_slug' ] );
	assert.equal( payload.checks.components.ai_providers.status, 'operational' );
	assert.equal( payload.checks.components.bad_slug.status, 'unknown' );
	assert.ok( payload.checks.components.ai_providers.message.length <= 500 );
	assert.equal( payload.meta.wp_version, '6.7.2' );
	assert.equal( payload.meta.maintenance_until, 1760003600 );
	assert.equal( payload.extra_junk, undefined );
} );

test( 'rejects payloads exceeding the size cap', () => {
	const big = { v: 1, blob: 'x'.repeat( MAX_PAYLOAD_BYTES + 10 ) };
	const result = validateHeartbeat( big );
	assert.equal( result.ok, false );
	assert.match( result.error, /size/i );
} );

test( 'rejects credential-shaped content anywhere in the payload', () => {
	assert.equal(
		validateHeartbeat( { v: 1, checks: { overall: 'operational', components: { x: { status: 'operational', message: 'token: sk-abcdef1234567890' } } } } ).ok,
		false
	);
	assert.equal(
		validateHeartbeat( { v: 1, meta: { wp_version: 'api_key=AKIA1234567890ABCDEF' } } ).ok,
		false
	);
	// Benign content passes.
	assert.ok(
		validateHeartbeat( { v: 1, checks: { overall: 'operational', components: { x: { status: 'operational', message: 'All good.' } } } } ).ok
	);
} );

test( 'looksLikeCredential detects common secret shapes', () => {
	assert.equal( looksLikeCredential( 'sk-abcdef123456789' ), true );
	assert.equal( looksLikeCredential( 'AIzaSy0123456789012345678901234567890' ), true );
	assert.equal( looksLikeCredential( 'authorization: Bearer eyJhbGciOiJIUzI1NiJ9.abc' ), true );
	assert.equal( looksLikeCredential( 'api_key=supersecretvalue123' ), true );
	assert.equal( looksLikeCredential( 'All providers operational.' ), false );
	assert.equal( looksLikeCredential( '' ), false );
	assert.equal( looksLikeCredential( 42 ), false );
} );

test( 'validates the shared status taxonomy', () => {
	assert.deepEqual( VALID_STATUSES, [
		'operational',
		'under_maintenance',
		'degraded_performance',
		'partial_outage',
		'major_outage',
	] );
} );
