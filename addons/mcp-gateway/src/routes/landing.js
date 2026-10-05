/**
 * Landing page (GET /).
 *
 * Human-readable entry point for directory reviewers and prospective users:
 * what the endpoint is, how auth works, copy-paste client configs. No
 * secrets, ever.
 */

import express from 'express';

const PAGE = `<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>NV oOS MCP Gateway</title>
	<style>
		body { font-family: system-ui, sans-serif; max-width: 720px; margin: 3rem auto; padding: 0 1rem; line-height: 1.6; color: #1f2937; }
		code, pre { background: #f3f4f6; border-radius: 6px; }
		code { padding: 0.1rem 0.35rem; }
		pre { padding: 1rem; overflow-x: auto; }
		h1 { border-bottom: 2px solid #e5e7eb; padding-bottom: 0.5rem; }
		a { color: #2563eb; }
	</style>
</head>
<body>
	<h1>NV oOS MCP Gateway</h1>
	<p>
		A public, fleet-scoped <strong>Model Context Protocol</strong> endpoint for the
		NV oOS (Open Operator System) WordPress platform. One key, every bound site:
		tools appear namespaced as <code>&lt;site-slug&gt;.&lt;tool&gt;</code>.
	</p>
	<h2>Endpoint</h2>
	<pre>POST /mcp   (JSON-RPC 2.0 · streamable HTTP · MCP 2026-07-28)
GET  /mcp   (server discovery JSON)</pre>
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

/**
 * Build the landing router.
 *
 * @return {import('express').Router} Router.
 */
export function landingRouter() {
	const router = express.Router();
	router.get( '/', ( _req, res ) => {
		res.type( 'html' ).send( PAGE );
	} );
	return router;
}
