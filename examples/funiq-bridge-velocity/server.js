#!/usr/bin/env node
/**
 * Cloudways Velocity default entry point.
 *
 * Velocity defaults to executing `server.js` at the app root when no entry
 * file is configured — pointing the setting at `scripts/serve.mjs` also
 * works, but this shim makes the app deploy correctly with ZERO Velocity
 * configuration. All logic lives in scripts/serve.mjs (the same
 * zero-dependency static server + WordPress reverse proxy).
 *
 * ESM dynamic import: package.json declares "type": "module", so this file
 * is a module and the import resolves to scripts/serve.mjs.
 */
import('./scripts/serve.mjs');
