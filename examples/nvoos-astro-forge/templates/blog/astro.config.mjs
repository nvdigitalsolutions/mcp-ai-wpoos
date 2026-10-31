import { defineConfig } from 'astro/config';

// The canonical host for SEO surfaces (sitemap, robots, canonical links).
// Set ASTRO_SITE at build/deploy time to the production hostname.
export default defineConfig({
	site: process.env.ASTRO_SITE || 'http://localhost:4321',
});
