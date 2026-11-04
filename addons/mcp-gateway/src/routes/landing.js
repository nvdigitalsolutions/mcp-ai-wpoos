/**
 * Landing page (GET /).
 *
 * Human-readable entry point for directory reviewers and prospective users:
 * what the endpoint is, how auth works, copy-paste client configs. No
 * secrets, ever — with one deliberate exception: the operator-published
 * demo key (`PUBLIC_DEMO_KEY`), a public-by-design, site-scoped credential.
 * When unset, the page falls back to `YOUR_KEY` placeholders.
 */

import express from 'express';

const PAGE = `<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>NV oOS MCP Gateway</title>
	<link rel="icon" type="image/svg+xml" href="/assets/mcp-gateway.svg">
	<style>
		body { font-family: system-ui, sans-serif; max-width: 720px; margin: 3rem auto; padding: 0 1rem; line-height: 1.6; background: #f6f0de; color: #1b1e2e; }
		code, pre { background: #efe7cd; border-radius: 6px; }
		code { padding: 0.1rem 0.35rem; }
		pre { padding: 1rem; overflow-x: auto; border: 1px solid #e4dabd; }
		h1 { border-bottom: 2px solid #e4dabd; padding-bottom: 0.5rem; }
		a { color: #053c5b; }
		header { display: flex; align-items: center; gap: 1rem; margin: 1rem 0 0.5rem; }
		header img { width: 88px; height: 88px; }
		header h1 { border-bottom: 0; padding-bottom: 0; margin: 0; }
	</style>
</head>
<body>
	<header>
		<img src="/assets/mcp-gateway.svg" alt="NV oOS MCP Gateway icon" width="88" height="88">
		<h1>NV oOS MCP Gateway</h1>
	</header>
	<p>
		A public, fleet-scoped <strong>Model Context Protocol</strong> endpoint for the
		NV oOS (Open Operator System) WordPress platform. One key, every bound site:
		tools appear namespaced as <code>&lt;site-slug&gt;_&lt;tool&gt;</code>.
	</p>
	<h2>Endpoint</h2>
	<pre>POST /mcp   (JSON-RPC 2.0 · streamable HTTP · MCP 2026-07-28)
GET  /mcp   (server discovery JSON)</pre>
	__DEMO_SECTION__
	<h2>Authentication</h2>
	<p>
		Every request requires a public API key:
		<code>Authorization: Bearer &lt;your-key&gt;</code>.
		Keys are issued by the operator of this gateway and map to a set of
		sites; tool scoping is enforced server-side by each site's Fleet
		Operator allowlist.
	</p>
	<h2>Example (Claude Desktop)</h2>
		<pre>{
	  "mcpServers": {
	    "nvoos": {
	      "type": "http",
	      "url": "https://mcp.nvoos.pro/mcp",
	      "headers": { "Authorization": "Bearer YOUR_KEY" }
	    }
	  }
	}</pre>
		<h2>Example (Claude Code)</h2>
		<pre>claude mcp add --transport http nvoos https://mcp.nvoos.pro/mcp --header "Authorization: Bearer YOUR_KEY"
	# or install the plugin: /plugin marketplace add nvdigitalsolutions/nvoos-mcp-gateway</pre>
	<p>For editors, the <code>nvoos-mcp-bridge</code> npm package provides a stdio relay:</p>
	<pre>npx -y @nvdigitalsolutions/nvoos-mcp-bridge
# env: MCP_AI_BASE_URL=https://mcp.nvoos.pro/mcp
# env: MCP_AI_TOKEN=&lt;your-key&gt;</pre>
	<h2>Health</h2>
	<p><a href="/health">GET /health</a> — public status (no auth required).</p>
	<footer>
		<p>
			Built on <a href="https://nvdigitalsolutions.com/wpoos">NV oOS</a> ·
			<a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos">GitHub</a>
		</p>
	</footer>
</body>
</html>
`;

const DEMO_SECTION = `<h2>Try it now — public demo key</h2>
	<p>
		No account needed. This read-only demo key is bound to the public
		<code>demo</code> site (<a href="https://nvoos.pro">nvoos.pro</a>) and
		exposes exactly two namespaced tools:
		<code>demo_get_environment_status</code> (live WordPress/PHP/plugin
		report) and <code>demo_deep_research</code> (multi-step web research
		with citations).
	</p>
	<pre>Authorization: Bearer __DEMO_KEY__</pre>
`;

/**
 * Escape a value for HTML text context. Gateway keys are base64url, but
 * the env value is operator-controlled — escape defensively.
 *
 * @param {string} value Raw value.
 * @return {string} Escaped value.
 */
function escapeHtml( value ) {
	return String( value )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#39;' );
}

/**
 * Build the landing router. When the config carries `demoKey`
 * (`PUBLIC_DEMO_KEY`), the page advertises it and substitutes it into the
 * copy-paste configs; otherwise the page keeps `YOUR_KEY` placeholders.
 *
 * @return {import('express').Router} Router.
 */
export function landingRouter() {
	const router = express.Router();
	router.get( '/', ( req, res ) => {
		const demoKey = req.app.get( 'gatewayConfig' ).demoKey || '';
		const page = demoKey
			? PAGE.replace( '__DEMO_SECTION__', DEMO_SECTION.replace( '__DEMO_KEY__', escapeHtml( demoKey ) ) ).replaceAll(
					'YOUR_KEY',
					escapeHtml( demoKey )
			  )
			: PAGE.replace( '__DEMO_SECTION__', '' );
		res.type( 'html' ).send( page );
	} );
	return router;
}
