import { defineConfig } from 'astro/config';
import node from '@astrojs/node';
import react from '@astrojs/react';
import mcpBuildIntegration from './scripts/lib/mcp-build-integration.mjs';

// ---------------------------------------------------------------------------
// Proposal 092 — NV-authored headless storefront.
//
// Rendering model (hybrid):
//   - PDPs are prerendered at build time over the full catalog.
//   - PLPs, home, and dynamic pockets are on-demand SSR with Cache-Control
//     headers (see src/lib/commerce/client.ts) so the CDN caches them.
//   - Marketing pages are static markdown.
//
// The runtime binds ONLY to the same-origin /api/* proxy; the build binds to
// NVOOS_API_BASE (full base URL of the NV commerce contract). Neither this
// file nor src/ may mention backend-specific URLs — the CI grep gate
// (tests/unit/contract-grep.test.ts) enforces it.
// ---------------------------------------------------------------------------

export default defineConfig({
	// Canonical host for sitemap.xml.ts, robots.txt.ts and canonical tags.
	site: process.env.ASTRO_SITE || 'https://store.nvoos.cloud',

	output: 'server',
	trailingSlash: 'always',

	adapter: node({ mode: 'standalone' }),

	// The runtime /_image endpoint fetches absolute same-origin hrefs; the
	// site host must be allowlisted. Deriving it from ASTRO_SITE keeps test
	// builds (localhost) and production builds (real host) both working.
	image: {
		domains: [
			new URL(process.env.ASTRO_SITE || 'https://store.nvoos.cloud').hostname,
		],
	},

	integrations: [
		react(),
		// Rebuilds dist/mcp-entry.mjs on astro:build:done — astro build
		// wipes dist/, so the /mcp bundle must be written after it finishes.
		mcpBuildIntegration(),
	],
});
