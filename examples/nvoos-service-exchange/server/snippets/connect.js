/**
 * Customer connect snippets.
 *
 * All snippets mask credentials by default. A caller may opt into the raw
 * token with `redact: false` — used only in the authenticated estate owner's
 * UI response, never in catalogue/public responses.
 */

import { maskToken } from '../util/secrets.js';

export function normaliseBaseUrl( url ) {
	return String( url ).replace( /\/+$/, '' );
}

export function mcpEndpointFor( siteUrl ) {
	return `${ normaliseBaseUrl( siteUrl ) }/wp-json/mcp-ai/v1/mcp`;
}

export function maskForSnippet( token, redact ) {
	return redact ? maskToken( token ) : token;
}

/**
 * Stdio bridge config for Zed / Claude Desktop / Cursor / Codex.
 */
export function buildBridgeConfig( { slug, siteUrl, token, redact = true } ) {
	return {
		mcpServers: {
			[ slug ]: {
				command: 'npx',
				args: [ '-y', '@nvdigitalsolutions/nvoos-mcp-bridge' ],
				env: {
					MCP_AI_BASE_URL: mcpEndpointFor( siteUrl ),
					MCP_AI_TOKEN: maskForSnippet( token, redact ),
				},
			},
		},
	};
}

export function buildChatGptConnector( { gatewayUrl = 'https://mcp.nvoos.pro/mcp' } = {} ) {
	return {
		url: gatewayUrl,
		steps: [
			'Open ChatGPT → Settings → Connectors → Add MCP server.',
			`Enter the server URL: ${ gatewayUrl }`,
			'Authorize through the gateway OAuth flow (or paste your gateway key when prompted).',
			'Tools appear namespaced as <site-slug>.<tool>.',
		],
	};
}

export function buildClaudeCodeCommands() {
	return [
		'/plugin marketplace add nvdigitalsolutions/nvoos-mcp-gateway',
		'/plugin install nvoos-mcp-gateway@nvoos-marketplace',
	];
}

export function buildCurlExample( { siteUrl, token, redact = true } ) {
	const masked = maskForSnippet( token, redact );
	return [
		`curl -s -X POST ${ mcpEndpointFor( siteUrl ) } \\`,
		'  -H "Content-Type: application/json" \\',
		'  -H "Accept: application/json, text/event-stream" \\',
		`  -H "Authorization: Bearer ${ masked }" \\`,
		'  -d \'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}\'',
	].join( '\n' );
}

/**
 * Bundle every snippet for the listing page.
 */
export function buildConnectSnippets( {
	slug,
	siteUrl,
	token,
	gatewayUrl = 'https://mcp.nvoos.pro/mcp',
	redact = true,
} ) {
	return {
		bridge: buildBridgeConfig( { slug, siteUrl, token, redact } ),
		chatgpt: buildChatGptConnector( { gatewayUrl } ),
		claudeCode: buildClaudeCodeCommands(),
		curl: buildCurlExample( { siteUrl, token, redact } ),
	};
}
