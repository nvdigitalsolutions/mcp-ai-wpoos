// SPDX-License-Identifier: MIT
import { defineConfig } from 'astro/config';
import mdx from '@astrojs/mdx';
// FORGE:adapter-import
import manifest from './nvoos.json';

// The canonical host for SEO surfaces (sitemap, robots, canonical links).
// Set ASTRO_SITE at build/deploy time to the production hostname.
const site = process.env.ASTRO_SITE || 'http://localhost:4321';

// CSRF origin check (Astro security.checkOrigin is on by default). Behind the
// Velocity proxy the Node process sees an internal Host, so Astro compares the
// browser's Origin with the wrong host and refuses every POST — "Cross-site
// POST form submissions are forbidden" (2026-11-26). Naming the public host
// lets Astro trust the x-forwarded-host the front controller pins, so
// Astro.url — and therefore the origin check — resolves to the real origin.
const rawPublicHost = (() => {
	try {
		return new URL(site).hostname;
	} catch {
		return '';
	}
})();
const publicHost = rawPublicHost.startsWith('www.') ? rawPublicHost.slice(4) : rawPublicHost;
const allowedDomains = publicHost
	? [
			{ hostname: publicHost, protocol: 'https' },
			{ hostname: 'www.' + publicHost, protocol: 'https' },
	  ]
	: [];

// Remote image domains for Astro Image: the WordPress source host, derived
// from the same env-first rule the content layer uses (WP_SITE_URL overrides
// the scaffolded profile URL). `profile.media.hosts` and
// `NVOOS_FORGE_MEDIA_HOSTS` add extra authorized hosts (a CDN, or a legacy
// domain during a migration) so those images still get downloaded +
// optimized at build time instead of falling back to a hotlinked <img>.
// Images on any other host fall back to plain <img> tags in SmartImage —
// never a build failure. Keep the host rules in sync with src/lib/media.ts.
//
// A materialized profile has NO CMS (`content.source: materialized`): the
// committed snapshot is the only source, so the old origin must NOT stay an
// authorized image host — Astro would try to download a dead wp-content URL
// and fail the whole build (virtuallysrilanka, 2026-11-22). With the host
// dropped, SmartImage renders a plain <img> and the post-build localizer
// mirrors the snapshot (media.snapshot) into /media/.
const materialized = manifest.contentSource === 'materialized';
const wpUrl = materialized ? '' : process.env.WP_SITE_URL || manifest.url;
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

// Route-conflict policy (2026-11-22). Astro's default is `warn`, and a
// duplicate prerender path keeps whichever route ran FIRST — so a two-source
// slug collision silently publishes one document and drops the other. A
// profile can opt into `build.strictRoutes` to fail the build instead. Astro
// gates duplicate content ids in a collection with the same setting.
const prerenderConflictBehavior = manifest.build?.strictRoutes ? 'error' : 'warn';

export default defineConfig({
	site,
	security: { allowedDomains },
	prerenderConflictBehavior,
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
