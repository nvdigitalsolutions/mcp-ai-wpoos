#!/usr/bin/env node
/**
 * Package the plugin folder as a ZIP for the OpenAI Agents API
 * `environment.plugins` contract (inline base64 uploads) and for the plugin
 * submission portal.
 *
 * OpenAI requires each ZIP to contain ONE plugin folder with the manifest
 * inside it, so this script stages the plugin root under a folder named
 * after the manifest `name` (e.g. nvoos-site-bridge/) before archiving.
 * The manifest name/description are printed for the session-create request,
 * whose name and description must match the manifest.
 *
 * Usage:
 *   node bin/package-plugin.mjs [output.zip]
 *
 * Cross-platform: `zip` on POSIX runners (mirror CI), PowerShell on Windows.
 * Dependency-free: Node built-ins only.
 *
 * @see https://developers.openai.com/api/docs/guides/agents-api/tools/plugins
 */
import {
	readFileSync,
	existsSync,
	mkdirSync,
	mkdtempSync,
	cpSync,
	rmSync,
} from 'node:fs';
import { dirname, join, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const manifestPath = join( root, 'plugin.json' );
if ( !existsSync( manifestPath ) ) {
	console.error( 'plugin.json not found in', root );
	process.exit( 1 );
}

const manifest = JSON.parse( readFileSync( manifestPath, 'utf8' ) );
const out = process.argv[2] || join( root, 'dist', 'nvoos-site-bridge.zip' );
mkdirSync( dirname( out ), { recursive: true } );

// Stage the plugin under a single folder inside a temp dir, excluding build
// junk and local secrets, then archive the staging dir so the ZIP opens as
// <name>/plugin.json, <name>/skills/..., etc.
const staging = mkdtempSync( join( tmpdir(), 'nvoos-plugin-' ) );
const folder = join( staging, manifest.name );
try {
	cpSync( root, folder, {
		recursive: true,
		filter: ( src ) => {
			const rel = src.slice( root.length );
			if ( rel === '' ) {
				return true;
			}
			const parts = rel.split( sep );
			return !parts.includes( 'dist' ) &&
				!parts.includes( 'node_modules' ) &&
				!parts.includes( '.git' ) &&
				!parts.some( ( p ) => p === '.env' || p.startsWith( '.env.' ) );
		},
	} );

	const isWindows = process.platform === 'win32';
	if ( isWindows ) {
		execFileSync(
			'powershell',
			[
				'-NoProfile',
				'-Command',
				`Compress-Archive -Path '${ join( staging, '*' ).replace( /\\/g, '/' ) }' -DestinationPath '${ out.replace( /\\/g, '/' ) }' -Force`,
			],
			{ stdio: 'inherit' }
		);
	} else {
		execFileSync( 'zip', [ '-r', '-q', out, '.' ], { cwd: staging, stdio: 'inherit' } );
	}
} catch ( error ) {
	console.error( 'Archive step failed.', error.message );
	process.exit( 1 );
} finally {
	rmSync( staging, { recursive: true, force: true } );
}

console.log( 'Archive written:', out );
console.log( 'Manifest name:', manifest.name );
console.log( 'Manifest description:', manifest.description );
