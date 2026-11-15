/**
 * Velocity entry shim. Cloudways Velocity (PM2) defaults to `server.js` at
 * the app root; this one-liner delegates to the real zero-dependency server
 * in scripts/serve.mjs. `package.json`'s "type": "module" makes the root
 * file ESM, so the dynamic import works without configuration.
 */
import('./scripts/serve.mjs');
