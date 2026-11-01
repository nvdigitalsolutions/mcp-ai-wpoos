import { defineConfig } from 'astro/config';
import manifest from './nvoos.json';

// The canonical host for SEO surfaces (sitemap, robots, canonical links).
// Set ASTRO_SITE at build/deploy time to the production hostname.
const site = process.env.ASTRO_SITE || 'http://localhost:4321';

// Remote image domains for Astro Image: the WordPress source host, derived
// from the same env-first rule the content layer uses (WP_SITE_URL overrides
// the scaffolded profile URL). Images on other hosts fall back to plain
// <img> tags in SmartImage — never a build failure.
const wpUrl = process.env.WP_SITE_URL || manifest.url;
const wpHost = wpUrl ? new URL(wpUrl).hostname : '';

export default defineConfig({
	site,
	image: {
		domains: wpHost ? [wpHost] : [],
	},
});
