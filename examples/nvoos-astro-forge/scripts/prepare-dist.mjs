#!/usr/bin/env node
/**
 * Bundle the three bin entries into dist/ for the npm artifact.
 *
 * The in-repo entry points (scripts/forge.mjs, scripts/forge-mcp.mjs,
 * mcp/stdio.ts) import .ts sources directly and rely on Node's native type
 * stripping — which works from a checkout but NOT from an installed package
 * (Node refuses type stripping for files under node_modules/, with
 * ERR_UNSUPPORTED_NODE_MODULES_TYPE_STRIPPING). The published package
 * therefore ships esbuild-bundled entries in dist/ instead.
 *
 * Path safety: FORGE_ROOT is derived from import.meta.url with `..` — from
 * dist/<bundle>.mjs that still resolves to the package root, so the
 * templates/ and resources lookups behave identically.
 *
 * Run automatically on `npm publish` via the prepublishOnly script; run
 * manually with `node scripts/prepare-dist.mjs` before local `npm pack`.
 */

import { build } from 'esbuild';
import { mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const root = join( here, '..' );
const out = join( root, 'dist' );

const entries = {
	'forge': 'scripts/forge.mjs',
	'forge-mcp': 'scripts/forge-mcp.mjs',
	'forge-mcp-stdio': 'mcp/stdio.ts',
};

rmSync( out, { recursive: true, force: true } );
mkdirSync( out, { recursive: true } );

await build( {
	entryPoints: Object.entries( entries ).map( ( [ name, src ] ) => ( {
		in: join( root, src ),
		out: name,
	} ) ),
	outdir: out,
	outExtension: { '.js': '.mjs' },
	bundle: true,
	platform: 'node',
	format: 'esm',
	target: 'node22',
	// No banner — the three entry sources already carry their own
	// `#!/usr/bin/env node` first line, which esbuild preserves; adding
	// one here would double it and break the ESM parse.
	sourcemap: false,
	legalComments: 'none',
	logLevel: 'info',
} );

console.log( `Wrote ${ Object.keys( entries ).length } bundles to ${ out }` );
