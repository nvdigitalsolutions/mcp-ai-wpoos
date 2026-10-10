/**
 * Dev launcher — starts the Vite dev server with a proxy target.
 *
 * Defaults to the repo's docker-compose WordPress (http://localhost:8000).
 * Override with NVOOS_TARGET_SITE.
 *
 *   node scripts/dev.mjs
 */
process.env.NVOOS_TARGET_SITE = process.env.NVOOS_TARGET_SITE || 'http://localhost:8000';

const { createServer } = await import('vite');

const server = await createServer({ server: { port: 5199, strictPort: true } });
await server.listen();
server.printUrls();
console.log(`[nvoos-pro-spa-vite] proxying /wp-json → ${process.env.NVOOS_TARGET_SITE}`);
