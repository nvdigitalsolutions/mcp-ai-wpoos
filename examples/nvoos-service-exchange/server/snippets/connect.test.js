import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	buildBridgeConfig,
	buildChatGptConnector,
	buildClaudeCodeCommands,
	buildCurlExample,
	buildConnectSnippets,
	mcpEndpointFor,
} from './connect.js';

const TOKEN = 'op_abcdefgh.SECRETtoken1234';

test( 'mcpEndpointFor normalises trailing slashes', () => {
	assert.equal(
		mcpEndpointFor( 'https://site.test/' ),
		'https://site.test/wp-json/mcp-ai/v1/mcp'
	);
} );

test( 'bridge config masks the token by default', () => {
	const config = buildBridgeConfig( {
		slug: 'clinic-ops',
		siteUrl: 'https://site.test',
		token: TOKEN,
	} );
	const entry = config.mcpServers[ 'clinic-ops' ];
	assert.equal( entry.command, 'npx' );
	assert.deepEqual( entry.args, [ '-y', '@nvdigitalsolutions/nvoos-mcp-bridge' ] );
	assert.equal( entry.env.MCP_AI_BASE_URL, 'https://site.test/wp-json/mcp-ai/v1/mcp' );
	assert.ok( ! entry.env.MCP_AI_TOKEN.includes( 'SECRETtoken1234' ) );
	assert.match( entry.env.MCP_AI_TOKEN, /…/ );
} );

test( 'redact:false returns the raw token for the authenticated owner response', () => {
	const config = buildBridgeConfig( {
		slug: 'clinic-ops',
		siteUrl: 'https://site.test',
		token: TOKEN,
		redact: false,
	} );
	assert.equal( config.mcpServers[ 'clinic-ops' ].env.MCP_AI_TOKEN, TOKEN );
} );

test( 'ChatGPT connector uses the gateway URL', () => {
	const connector = buildChatGptConnector();
	assert.equal( connector.url, 'https://mcp.nvoos.pro/mcp' );
	assert.ok( connector.steps.length >= 3 );
} );

test( 'Claude Code commands match the published marketplace names', () => {
	assert.deepEqual( buildClaudeCodeCommands(), [
		'/plugin marketplace add nvdigitalsolutions/nvoos-mcp-gateway',
		'/plugin install nvoos-mcp-gateway@nvoos-marketplace',
	] );
} );

test( 'curl example masks the token by default', () => {
	const curl = buildCurlExample( { siteUrl: 'https://site.test', token: TOKEN } );
	assert.ok( ! curl.includes( 'SECRETtoken1234' ) );
	assert.ok( curl.includes( '/wp-json/mcp-ai/v1/mcp' ) );
} );

test( 'buildConnectSnippets bundles every surface', () => {
	const snippets = buildConnectSnippets( {
		slug: 'clinic-ops',
		siteUrl: 'https://site.test',
		token: TOKEN,
	} );
	assert.ok( snippets.bridge );
	assert.ok( snippets.chatgpt );
	assert.ok( Array.isArray( snippets.claudeCode ) );
	assert.ok( typeof snippets.curl === 'string' );
	assert.ok( ! JSON.stringify( snippets ).includes( 'SECRETtoken1234' ) );
} );
