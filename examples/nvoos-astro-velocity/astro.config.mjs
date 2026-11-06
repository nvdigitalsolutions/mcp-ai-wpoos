import { defineConfig, memoryCache } from 'astro/config';
import node from '@astrojs/node';
import react from '@astrojs/react';
import mcpBuildIntegration from './scripts/lib/mcp-build-integration.mjs';

// ---------------------------------------------------------------------------
// Proposal 092 — NV-authored headless storefront.
//
// Rendering model (hybrid, revised by Proposal 104 §3.2):
//   - PDPs are on-demand SSR with route caching (Astro 7 route cache:
//     memoryCache provider + per-page tags) — build-time prerendering was
//     dropped because the hermetic mirror build baked fixture data (Defect B).
//   - PLPs, home, and dynamic pockets are on-demand SSR with Cache-Control
//     headers (see src/lib/commerce/client.ts) so the CDN caches them.
//   - Marketing pages are static markdown.
//
// The runtime binds ONLY to the same-origin /api/* proxy; the build no
// longer binds to anything (fully hermetic — only ASTRO_SITE is needed).
// Neither this file nor src/ may mention backend-specific URLs — the CI
// grep gate (tests/unit/contract-grep.test.ts) enforces it.
// ---------------------------------------------------------------------------

export default defineConfig({
	// Canonical host for sitemap.xml.ts, robots.txt.ts and canonical tags.
	site: process.env.ASTRO_SITE || 'https://store.nvoos.cloud',

	output: 'server',
	trailingSlash: 'always',

	adapter: node({ mode: 'standalone' }),

	// Proposal 104 §3.2 / D-series: Astro 7 route caching. Config-level
	// defaults merge with per-page Astro.cache.set (tags accumulate). The
	// in-process provider covers SSR compute; the CDN's s-maxage header is
	// the edge layer. Invalidation: webhook → redeploy (Velocity) / tag
	// purge within a deploy (optional /internal/revalidate, owner Q3).
	cache: { provider: memoryCache() },
	routeRules: {
		'/products/[...path]': { maxAge: 300, swr: 600, tags: ['products'] },
	},

	// The runtime /_image endpoint fetches absolute same-origin hrefs; the
	// site host must be allowlisted. Deriving it from ASTRO_SITE keeps test
	// builds (localhost) and production builds (real host) both working.
	image: {
		domains: [
			new URL(process.env.ASTRO_SITE || 'https://store.nvoos.cloud').hostname,
		],
	},

	// Scroll-driven reveals (Proposal 126 cross-pollination) declare
	// `animation-timeline` as a SEPARATE longhand — lightningcss (Vite 8's
	// default minifier) merges it into the `animation` shorthand, and Chrome
	// drops the whole declaration (the forge jayainc outage, 2026-11-06).
	// Pin the minifier to esbuild, which preserves the longhand; `esbuild`
	// is already a devDependency (Vite imports it at build time).
	vite: {
		build: { cssMinify: 'esbuild' },
	},

	integrations: [
		react(),
		// Rebuilds dist/mcp-entry.mjs on astro:build:done — astro build
		// wipes dist/, so the /mcp bundle must be written after it finishes.
		mcpBuildIntegration(),
	],
});
