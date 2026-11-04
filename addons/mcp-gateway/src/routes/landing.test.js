/**
 * Landing page tests — placeholder behavior without a demo key, and the
 * `PUBLIC_DEMO_KEY` rendering path (substitution + HTML escaping).
 */

import { test } from 'node:test';
import assert from 'node:assert';
import { createApp } from '../index.js';
import { startTestServer } from '../utils/test-server.js';

const KEY = 'key-1234567890abcdef';

function makeConfig( demoKey = '' ) {
	return {
		keys: new Map( [ [ KEY, [ 'site-a' ] ] ] ),
		previousKeys: new Map(),
		sites: new Map( [
			[ 'site-a', { slug: 'site-a', url: 'https://a.example.com/wp-json/mcp-ai/v1/mcp', token: 'op_a.SECRET' } ],
		] ),
		demoKey,
		errors: [],
	};
}

test( 'GET / without a demo key serves the placeholder page', async () => {
	const { baseUrl, close } = await startTestServer( createApp( { config: makeConfig() } ) );
	const res = await fetch( `${ baseUrl }/` );
	assert.strictEqual( res.status, 200 );
	assert.match( res.headers.get( 'content-type' ), /text\/html/ );
	const body = await res.text();
	assert.match( body, /YOUR_KEY/ );
	assert.match( body, /Keys are issued by the operator/ );
	assert.doesNotMatch( body, /public demo key/i );
	assert.doesNotMatch( body, /Bearer key-1234567890abcdef/ );
	await close();
} );

test( 'GET / with a demo key advertises it and fills the config snippets', async () => {
	const { baseUrl, close } = await startTestServer(
		createApp( { config: makeConfig( 'demo-key-1234567890abcdef' ) } )
	);
	const res = await fetch( `${ baseUrl }/` );
	assert.strictEqual( res.status, 200 );
	const body = await res.text();
	assert.match( body, /public demo key/i );
	assert.match( body, /demo_get_environment_status/ );
	assert.match( body, /demo_deep_research/ );
	assert.match( body, /Authorization: Bearer demo-key-1234567890abcdef/ );
	// Every copy-paste snippet carries the real key, none keeps a placeholder.
	assert.doesNotMatch( body, /YOUR_KEY/ );
	// The stdio-relay snippet is parameterized and stays a placeholder.
	assert.match( body, /MCP_AI_TOKEN=&lt;your-key&gt;/ );
	await close();
} );

test( 'GET / escapes HTML metacharacters in the demo key', async () => {
	const raw = 'demo<>&"\'';
	const { baseUrl, close } = await startTestServer( createApp( { config: makeConfig( raw ) } ) );
	const body = await ( await fetch( `${ baseUrl }/` ) ).text();
	assert.match( body, /Authorization: Bearer demo&lt;&gt;&amp;&quot;&#39;/ );
	assert.doesNotMatch( body, /Authorization: Bearer demo<>&"\'/ );
	await close();
} );
