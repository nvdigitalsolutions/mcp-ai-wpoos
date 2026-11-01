// ---------------------------------------------------------------------------
// project-files.ts — collect a forged project's source files for pushing
// (Phase 3.2/4). Shared by the CLI `deploy` command and the MCP
// `github_commit` tool. Build/runtime artifacts and binary assets are
// excluded; guards match lib/github.ts (MAX_FILES / MAX_TOTAL_BYTES).
// ---------------------------------------------------------------------------

import { readFile, readdir } from 'node:fs/promises';
import { join, relative } from 'node:path';

import { MAX_FILES, MAX_TOTAL_BYTES } from './github.ts';

/** Directories a forged project never deploys (build/runtime artifacts). */
export const SKIP_DIRS = new Set( [ 'node_modules', 'dist', '.astro', '.git' ] );

/** Binary extensions that must not be read as UTF-8. */
export const BINARY_EXTENSIONS = new Set( [
	'png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'ico',
	'woff', 'woff2', 'ttf', 'eot', 'mp4', 'webm', 'pdf', 'zip',
] );

/** Slugify a display value into a repo/site-safe name. */
export function slugify( value: string ): string {
	return (
		value
			.toLowerCase()
			.replace( /^https?:\/\//, '' )
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' )
			.slice( 0, 40 ) || 'forge-site'
	);
}

/** Recursively collect text files under `root`, relative to it, with guards. */
export async function collectProjectFiles( root: string ): Promise<Record<string, string>> {
	const files: Record<string, string> = {};
	let total = 0;
	async function walk( dir: string ): Promise<void> {
		for ( const entry of await readdir( dir, { withFileTypes: true } ) ) {
			const full = join( dir, entry.name );
			if ( entry.isDirectory() ) {
				if ( SKIP_DIRS.has( entry.name ) ) {
					continue;
				}
				await walk( full );
				continue;
			}
			const extension = entry.name.split( '.' ).pop()?.toLowerCase() ?? '';
			if ( BINARY_EXTENSIONS.has( extension ) ) {
				continue;
			}
			const content = await readFile( full, 'utf8' );
			total += Buffer.byteLength( content, 'utf8' );
			if ( Object.keys( files ).length + 1 > MAX_FILES ) {
				throw new Error( `Payload exceeds the ${ MAX_FILES }-file guard — is the target a forge project?` );
			}
			if ( total > MAX_TOTAL_BYTES ) {
				throw new Error( `Payload exceeds the ${ Math.round( MAX_TOTAL_BYTES / 1024 / 1024 ) } MB guard.` );
			}
			files[ relative( root, full ).split( '\\' ).join( '/' ) ] = content;
		}
	}
	await walk( root );
	return files;
}
