/**
 * Tool namespacing tests.
 */

import { test } from 'node:test';
import assert from 'node:assert';
import { prefixTool, splitToolName, isNamespacedFor, assertUniqueSlugs } from './namespacer.js';

test( 'prefixTool namespaces the name and records provenance', () => {
	const tool = { name: 'create_post', description: 'x', inputSchema: { type: 'object' } };
	const prefixed = prefixTool( 'site-a', tool );
	assert.strictEqual( prefixed.name, 'site-a_create_post' );
	assert.deepStrictEqual( prefixed._meta, { gateway: { site: 'site-a' } } );
	assert.strictEqual( prefixed.description, 'x' );
} );

test( 'splitToolName round-trips an underscore-prefixed name', () => {
	const slugs = new Set( [ 'site-a' ] );
	assert.deepStrictEqual( splitToolName( 'site-a_create_post', slugs ), { slug: 'site-a', tool: 'create_post' } );
	assert.deepStrictEqual( splitToolName( 'site-a_multi_part_tool', slugs ), { slug: 'site-a', tool: 'multi_part_tool' } );
	assert.strictEqual( splitToolName( 'site-b_create_post', slugs ), null );
} );

test( 'splitToolName accepts legacy dot names when the slug is known', () => {
	const slugs = new Set( [ 'site-a' ] );
	assert.deepStrictEqual( splitToolName( 'site-a.create_post', slugs ), { slug: 'site-a', tool: 'create_post' } );
	assert.strictEqual( splitToolName( 'site-b.create_post', slugs ), null );
	assert.deepStrictEqual( splitToolName( 'a.b.c' ), { slug: 'a', tool: 'b.c' } );
} );

test( 'splitToolName prefers the longest configured slug', () => {
	const slugs = new Set( [ 'demo', 'demo2' ] );
	assert.deepStrictEqual( splitToolName( 'demo2_tool', slugs ), { slug: 'demo2', tool: 'tool' } );
	assert.deepStrictEqual( splitToolName( 'demo_tool', slugs ), { slug: 'demo', tool: 'tool' } );
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
	assert.ok( isNamespacedFor( 'site-a_tool', slugs ) );
	assert.ok( isNamespacedFor( 'site-a.tool', slugs ), 'legacy dot form stays callable' );
	assert.ok( ! isNamespacedFor( 'site-b_tool', slugs ) );
	assert.ok( ! isNamespacedFor( 'bare_tool', slugs ) );
} );

test( 'assertUniqueSlugs throws on duplicates', () => {
	assert.deepStrictEqual( assertUniqueSlugs( [ 'a', 'b' ] ), [ 'a', 'b' ] );
	assert.throws( () => assertUniqueSlugs( [ 'a', 'a' ] ), /duplicate/ );
} );
