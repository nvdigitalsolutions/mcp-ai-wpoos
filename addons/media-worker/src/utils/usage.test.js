/**
 * Tests for the per-site provider usage counters (proposal 027, Phase 2b).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { recordUsage, getUsage, resetUsage } from './usage.js';

test( 'recordUsage counts per site and provider', () => {
	resetUsage();
	recordUsage( 'site-a', 'openai', 'success' );
	recordUsage( 'site-a', 'openai', 'success' );
	recordUsage( 'site-a', 'openai', 'provider_error' );
	recordUsage( 'site-b', 'gemini', 'missing_key' );

	const usage = getUsage();
	assert.deepEqual( usage.sites[ 'site-a' ].openai, { success: 2, provider_error: 1, missing_key: 0 } );
	assert.deepEqual( usage.sites[ 'site-b' ].gemini, { success: 0, provider_error: 0, missing_key: 1 } );
	assert.deepEqual( usage.totals, { success: 2, provider_error: 1, missing_key: 1 } );
} );

test( 'recordUsage defaults to the "default" site and ignores bad outcomes', () => {
	resetUsage();
	recordUsage( undefined, 'openai', 'success' );
	recordUsage( null, 'openai', 'success' );
	recordUsage( 'site-a', 'openai', 'nonsense' );

	const usage = getUsage();
	assert.equal( usage.sites.default.openai.success, 2 );
	assert.equal( usage.sites[ 'site-a' ], undefined );
	assert.equal( usage.totals.success, 2 );
} );

test( 'resetUsage clears all counters', () => {
	resetUsage();
	recordUsage( 'site-a', 'openai', 'success' );
	assert.equal( getUsage().totals.success, 1 );
	resetUsage();
	assert.deepEqual( getUsage(), { sites: {}, totals: { success: 0, provider_error: 0, missing_key: 0 } } );
} );
