/**
 * Tool policy tests: wildcard matching, deny-wins filtering, list rewriting.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import {
	matches,
	isAllowed,
	isDenied,
	filterTools,
	isToolsListRequest,
	applyPolicyToToolsList,
} from '../src/tool-policy.js';

const TOOLS = [
	{ name: 'wp_list_posts' },
	{ name: 'wp_list_users' },
	{ name: 'wp_create_post' },
	{ name: 'wp_delete_user' },
	{ name: 'seo_analyze_content' },
];

test( 'matches supports exact, prefix, and star patterns', () => {
	assert.equal( matches( '*', 'anything' ), true );
	assert.equal( matches( 'wp_*', 'wp_list_posts' ), true );
	assert.equal( matches( 'wp_*', 'seo_analyze_content' ), false );
	assert.equal( matches( 'wp_list_posts', 'wp_list_posts' ), true );
	assert.equal( matches( 'wp_list_posts', 'wp_list_users' ), false );
} );

test( 'isAllowed admits everything for an empty or star allow list', () => {
	assert.equal( isAllowed( [], 'wp_list_posts' ), true );
	assert.equal( isAllowed( [ '*' ], 'wp_list_posts' ), true );
	assert.equal( isAllowed( [ 'wp_list_*' ], 'wp_list_posts' ), true );
	assert.equal( isAllowed( [ 'wp_list_*' ], 'wp_create_post' ), false );
} );

test( 'isDenied matches deny patterns', () => {
	assert.equal( isDenied( [], 'wp_delete_user' ), false );
	assert.equal( isDenied( [ 'wp_delete_*' ], 'wp_delete_user' ), true );
	assert.equal( isDenied( [ 'wp_delete_*' ], 'wp_create_post' ), false );
} );

test( 'filterTools applies allow and deny with deny winning', () => {
	assert.deepEqual(
		filterTools( TOOLS, [ '*' ], [ 'wp_delete_*' ] ).map( ( tool ) => tool.name ),
		[ 'wp_list_posts', 'wp_list_users', 'wp_create_post', 'seo_analyze_content' ]
	);

	assert.deepEqual(
		filterTools( TOOLS, [ 'wp_list_*', 'wp_create_post' ], [ 'wp_create_post' ] ).map( ( tool ) => tool.name ),
		[ 'wp_list_posts', 'wp_list_users' ]
	);
} );

test( 'filterTools tolerates malformed entries', () => {
	assert.deepEqual( filterTools( null, [ '*' ], [] ), [] );
	assert.deepEqual(
		filterTools( [ null, { name: 'ok' }, { nope: true } ], [ '*' ], [] ).map( ( tool ) => tool.name ),
		[ 'ok' ]
	);
} );

test( 'isToolsListRequest detects JSON-RPC tools/list', () => {
	assert.equal( isToolsListRequest( { method: 'tools/list' } ), true );
	assert.equal( isToolsListRequest( { method: 'tools/call' } ), false );
	assert.equal( isToolsListRequest( null ), false );
	assert.equal( isToolsListRequest( 'tools/list' ), false );
} );

test( 'applyPolicyToToolsList rewrites the result set', () => {
	const payload = { jsonrpc: '2.0', id: 1, result: { tools: TOOLS } };
	const rewritten = applyPolicyToToolsList( payload, [ 'wp_list_*' ], [] );

	assert.deepEqual( rewritten.result.tools.map( ( tool ) => tool.name ), [ 'wp_list_posts', 'wp_list_users' ] );
	assert.equal( rewritten.jsonrpc, '2.0' );
	assert.equal( rewritten.id, 1 );
} );

test( 'applyPolicyToToolsList passes non-conforming payloads through', () => {
	const payload = { jsonrpc: '2.0', id: 2, result: { other: true } };
	assert.equal( applyPolicyToToolsList( payload, [ '*' ], [] ), payload );
	assert.equal( applyPolicyToToolsList( null, [ '*' ], [] ), null );
} );
