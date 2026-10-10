/**
 * Dev launcher — starts the Vite dev server with a proxy target.
 *
 * Defaults to the repo's docker-compose WordPress (http://localhost:8000).
 * Override with NVOOS_TARGET_SITE in the shell or in a .env file (same file
 * list and precedence as vite.config.ts — the shell wins over .env).
 *
 *   node scripts/dev.mjs
 */
import { fileURLToPath } from 'node:url';

const { loadEnv, createServer } = await import('vite');

// Resolve NVOOS_TARGET_SITE the same way vite.config.ts does, so the default
// below never overrides a value configured in .env and the printed target
// matches what the proxy actually uses.
const appDir = fileURLToPath(new URL('..', import.meta.url));
const fileEnv = loadEnv('development', appDir, '');

process.env.NVOOS_TARGET_SITE =
	process.env.NVOOS_TARGET_SITE || fileEnv.NVOOS_TARGET_SITE || 'http://localhost:8000';

const server = await createServer({ server: { port: 5199, strictPort: true } });
await server.listen();
server.printUrls();
console.log(`[nvoos-pro-spa-vite] proxying /wp-json → ${process.env.NVOOS_TARGET_SITE}`);
