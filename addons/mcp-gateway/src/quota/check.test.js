/**
 * Quota engine unit tests (Phase 1).
 *
 * Asserts the token-bucket math, the compact TOOL_QUOTAS grammar with
 * most-specific-wins ordering, per-key isolation, and the RFC 9558 header
 * set on breach.
 */

import { test } from 'node:test';
import assert from 'node:assert';
import { TokenBucket, createQuotaStore } from './token-bucket.js';
import {
	parseWindowMs,
	compileToolPattern,
	patternSpecificity,
	parseToolQuotas,
	resolveQuota,
} from './config.js';
import { consumeToolQuota, quotaHeaders } from './check.js';

const T0 = 1_800_000_000_000; // Arbitrary fixed epoch ms.

test( 'parseWindowMs parses s/m/h/d and rejects garbage', () => {
	assert.strictEqual( parseWindowMs( '5s' ), 5000 );
	assert.strictEqual( parseWindowMs( '1m' ), 60000 );
	assert.strictEqual( parseWindowMs( '1h' ), 3600000 );
	assert.strictEqual( parseWindowMs( '2d' ), 172800000 );
	assert.strictEqual( parseWindowMs( '0m' ), null );
	assert.strictEqual( parseWindowMs( '5x' ), null );
	assert.strictEqual( parseWindowMs( '' ), null );
} );

test( 'tool pattern matching anchors on both ends', () => {
	const matcher = compileToolPattern( 'woo_*' );
	assert.match( 'woo_get_products', matcher );
	assert.match( 'woo_x', matcher );
	assert.doesNotMatch( 'xwoo_get_products', matcher );
	assert.doesNotMatch( 'woo', matcher ); // star requires ≥1 char here
} );

test( 'pattern specificity ranks literal prefixes above globs', () => {
	assert.ok( patternSpecificity( 'delete_post' ) > patternSpecificity( 'delete_*' ) );
	assert.ok( patternSpecificity( 'delete_*' ) > patternSpecificity( '*' ) );
} );

test( 'parseToolQuotas parses the compact form most-specific first', () => {
	const errors = [];
	const rules = parseToolQuotas( 'delete_post:5/1h:2,woo_*:60/1m:*,*:600/5m:*', errors );
	assert.deepStrictEqual( errors, [] );
	assert.strictEqual( rules.length, 3 );
	assert.strictEqual( rules[ 0 ].pattern, 'delete_post' );
	assert.strictEqual( rules[ 0 ].rate, 5 );
	assert.strictEqual( rules[ 0 ].windowMs, 3600000 );
	assert.strictEqual( rules[ 0 ].burst, 2 );
	assert.strictEqual( rules[ 1 ].pattern, 'woo_*' );
	assert.strictEqual( rules[ 1 ].burst, 60 );
	assert.strictEqual( rules[ 2 ].pattern, '*' );
} );

test( 'parseToolQuotas collects errors for malformed entries', () => {
	const errors = [];
	assert.strictEqual( parseToolQuotas( 'bad_entry,x:0/1m:1,y:5/1z:1', errors ), null );
	assert.ok( errors.length >= 3 );
} );

test( 'resolveQuota picks the most-specific matching rule', () => {
	const errors = [];
	const rules = parseToolQuotas( 'delete_post:5/1h:2,delete_*:10/1h:1,*:100/5m:*', errors );
	assert.strictEqual( resolveQuota( rules, 'delete_post' ).pattern, 'delete_post' );
	assert.strictEqual( resolveQuota( rules, 'delete_page' ).pattern, 'delete_*' );
	assert.strictEqual( resolveQuota( rules, 'get_post' ).pattern, '*' );
	assert.strictEqual( resolveQuota( rules, 'unknown_tool' )?.pattern, '*' );
	assert.strictEqual( resolveQuota( parseToolQuotas( 'a_b:1/1h:1', [] ), 'other' ), null );
} );

test( 'TokenBucket refills lazily and exhausts at capacity', () => {
	// 2 tokens, refill 1 per 1000 ms.
	const bucket = new TokenBucket( 2, 1 / 1000, T0 );
	assert.deepStrictEqual( bucket.consume( T0 ), { allowed: true, remaining: 1, resetSeconds: 1 } );
	assert.deepStrictEqual( bucket.consume( T0 ), { allowed: true, remaining: 0, resetSeconds: 2 } );
	const denied = bucket.consume( T0 );
	assert.strictEqual( denied.allowed, false );
	assert.strictEqual( denied.remaining, 0 );
	// After 2.5 s the bucket has refilled fully (2 tokens).
	const later = bucket.consume( T0 + 2500 );
	assert.strictEqual( later.allowed, true );
	assert.strictEqual( later.remaining, 1 );
} );

test( 'consumeToolQuota scopes buckets per key, site, and tool', () => {
	const rules = parseToolQuotas( 'slow_tool:2/1m:2', [] );
	const storeA = createQuotaStore();
	const storeB = createQuotaStore();

	assert.strictEqual( consumeToolQuota( storeA, rules, 'key-a', 'site-a', 'slow_tool', T0 ).allowed, true );
	assert.strictEqual( consumeToolQuota( storeA, rules, 'key-a', 'site-a', 'slow_tool', T0 ).allowed, true );
	const denied = consumeToolQuota( storeA, rules, 'key-a', 'site-a', 'slow_tool', T0 );
	assert.strictEqual( denied.allowed, false );
	assert.strictEqual( denied.limit, 2 );
	assert.strictEqual( denied.windowSeconds, 60 );
	assert.strictEqual( denied.policy, '2;w=60' );
	assert.ok( denied.resetSeconds >= 1 );

	// A different key gets its own bucket — never exhausted by key-a.
	assert.strictEqual( consumeToolQuota( storeB, rules, 'key-b', 'site-a', 'slow_tool', T0 ).allowed, true );
	// A different tool is unscoped by this rule set.
	assert.strictEqual( consumeToolQuota( storeA, rules, 'key-a', 'site-a', 'fast_tool', T0 ).scoped, false );
} );

test( 'quotaHeaders emits RFC 9558 fields only on a scoped breach', () => {
	const rules = parseToolQuotas( 'x:1/1h:1', [] );
	const store = createQuotaStore();
	consumeToolQuota( store, rules, 'k', 's', 'x', T0 );
	const breach = consumeToolQuota( store, rules, 'k', 's', 'x', T0 );
	const headers = quotaHeaders( breach );
	assert.strictEqual( headers[ 'RateLimit-Limit' ], '1' );
	assert.strictEqual( headers[ 'RateLimit-Remaining' ], '0' );
	assert.ok( Number( headers[ 'RateLimit-Reset' ] ) >= 1 );
	assert.strictEqual( headers[ 'RateLimit-Policy' ], '1;w=3600' );
	assert.ok( headers[ 'Retry-After' ] );

	const ok = consumeToolQuota( createQuotaStore(), rules, 'k2', 's', 'x', T0 );
	assert.deepStrictEqual( quotaHeaders( ok ), {} );
} );
