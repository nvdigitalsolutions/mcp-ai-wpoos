/**
 * Tool namespacing tests.
 */

import { test } from 'node:test';
import assert from 'node:assert';
import { prefixTool, splitToolName, isNamespacedFor, assertUniqueSlugs } from './namespacer.js';

test( 'prefixTool namespaces the name and records provenance', () => {
	const tool = { name: 'create_post', description: 'x', inputSchema: { type: 'object' } };
	const prefixed = prefixTool( 'site-a', tool );
	assert.strictEqual( prefixed.name, 'site-a.create_post' );
	assert.deepStrictEqual( prefixed._meta, { gateway: { site: 'site-a' } } );
	assert.strictEqual( prefixed.description, 'x' );
} );

test( 'splitToolName round-trips a prefixed name', () => {
	assert.deepStrictEqual( splitToolName( 'site-a.create_post' ), { slug: 'site-a', tool: 'create_post' } );
	assert.deepStrictEqual( splitToolName( 'a.b.c' ), { slug: 'a', tool: 'b.c' } );
} );

test( 'splitToolName returns null for bare or degenerate names', () => {
	assert.strictEqual( splitToolName( 'bare_tool' ), null );
	assert.strictEqual( splitToolName( '.leading' ), null );
	assert.strictEqual( splitToolName( 'trailing.' ), null );
	assert.strictEqual( splitToolName( '.' ), null );
	assert.strictEqual( splitToolName( '' ), null );
} );

test( 'isNamespacedFor only accepts known site prefixes', () => {
	const slugs = new Set( [ 'site-a' ] );
	assert.ok( isNamespacedFor( 'site-a.tool', slugs ) );
	assert.ok( ! isNamespacedFor( 'site-b.tool', slugs ) );
	assert.ok( ! isNamespacedFor( 'bare_tool', slugs ) );
} );

test( 'assertUniqueSlugs throws on duplicates', () => {
	assert.deepStrictEqual( assertUniqueSlugs( [ 'a', 'b' ] ), [ 'a', 'b' ] );
	assert.throws( () => assertUniqueSlugs( [ 'a', 'a' ] ), /duplicate/ );
} );
