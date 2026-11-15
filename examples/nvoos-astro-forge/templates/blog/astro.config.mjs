import { defineConfig } from 'astro/config';
import mdx from '@astrojs/mdx';
// FORGE:adapter-import
import manifest from './nvoos.json';

// The canonical host for SEO surfaces (sitemap, robots, canonical links).
// Set ASTRO_SITE at build/deploy time to the production hostname.
const site = process.env.ASTRO_SITE || 'http://localhost:4321';

// Remote image domains for Astro Image: the WordPress source host, derived
// from the same env-first rule the content layer uses (WP_SITE_URL overrides
// the scaffolded profile URL). `profile.media.hosts` and
// `NVOOS_FORGE_MEDIA_HOSTS` add extra authorized hosts (a CDN, or a legacy
// domain during a migration) so those images still get downloaded +
// optimized at build time instead of falling back to a hotlinked <img>.
// Images on any other host fall back to plain <img> tags in SmartImage —
// never a build failure. Keep the host rules in sync with src/lib/media.ts.
const wpUrl = process.env.WP_SITE_URL || manifest.url;
const wpHost = wpUrl ? new URL(wpUrl).hostname : '';
const manifestHosts = Array.isArray(manifest.media?.hosts) ? manifest.media.hosts : [];
const envHosts = (process.env.NVOOS_FORGE_MEDIA_HOSTS || '')
	.split(/[,\s]+/)
	.map((host) => host.trim().toLowerCase())
	.filter(Boolean);
const extraImageHosts = [
	...new Set([...manifestHosts.map(String), ...envHosts].map((host) => host.toLowerCase())),
].filter(Boolean);
const imageDomains = [...new Set([...(wpHost ? [wpHost] : []), ...extraImageHosts])];

export default defineConfig({
	site,
	// Hybrid local content (Proposal 106 P2): the src/content/*.mdx glob
	// collections need the MDX integration.
	integrations: [mdx()],
	// FORGE:render-config
	// Scroll-driven animations (Proposal 121) declare `animation-timeline`
	// as a SEPARATE longhand — Chrome (as of 157) drops the entire
	// `animation` declaration when a timeline value (scroll()/view())
	// appears inside the shorthand. Vite 8 defaults to lightningcss when
	// the package is present (astro pulls it transitively), and
	// lightningcss 1.33 merges the longhand back into the shorthand — the
	// deployed jayainc build lost every parallax + reveal animation to
	// that merge (2026-11-06). Pin the minifier to esbuild, which
	// preserves the separate longhands (regression-pinned in the e2e).
	// Vite imports the `esbuild` package at build time for this path, so
	// the template devDependencies must carry it.
	vite: {
		build: { cssMinify: 'esbuild' },
	},
	image: {
		domains: imageDomains,
	},
});
