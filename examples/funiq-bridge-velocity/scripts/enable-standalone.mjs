#!/usr/bin/env node
/**
 * Writes the standalone Next.js config into a Payload CMS checkout.
 *
 * The licensed template ships `next.config.mjs` WITHOUT `output:
 * 'standalone'` (its own Dockerfile comment says the flag must be added to
 * use that Dockerfile). Cloudways Velocity serves `node server.js` from the
 * repo root, which is Next's standalone entry — so the sync workflow and
 * the local recipe both call this before `npm run build`.
 *
 * The vendor config's only customisation is a `webpack` identity function
 * (returns the config unchanged), so the replacement below is
 * behaviour-equivalent.
 *
 * Usage: node scripts/enable-standalone.mjs <cms-dir>
 */

import { writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const cmsDir = resolve(process.argv[2] || '.');

const nextConfig = [
	"import { withPayload } from '@payloadcms/next/withPayload'",
	'',
	'const nextConfig = {',
	"	output: 'standalone',",
	'}',
	'',
	'export default withPayload(nextConfig)',
	'',
].join( '\n' );

writeFileSync( join( cmsDir, 'next.config.mjs' ), nextConfig );
console.log( '[enable-standalone] wrote next.config.mjs with output: standalone' );
