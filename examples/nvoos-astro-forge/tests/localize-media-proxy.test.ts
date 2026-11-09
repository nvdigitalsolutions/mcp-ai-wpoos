// ---------------------------------------------------------------------------
// localize-media-proxy.test.ts — the managed (proxy) tier, end to end.
//
// Proposal 140 §3 — the proxy branch used to return before the stylesheet emit
// step, so a managed site kept its font chain cross-origin AND paid for a
// download it then discarded. This runs the real post-build script against a
// synthetic dist/ with a local server standing in for WordPress, and asserts
// the split: uploads are rewritten to /media (never fetched), the stylesheet
// and its font travel with the build.
// ---------------------------------------------------------------------------

import { spawn } from 'node:child_process';
import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

const FORGE_ROOT = fileURLToPath( new URL( '..', import.meta.url ) );
const SCRIPT = path.join( FORGE_ROOT, 'templates', 'blog', 'scripts', 'localize-media.mjs' );

interface FixtureFile {
	body: string | Buffer;
	type: string;
}

interface FixtureServer {
	origin: string;
	hits: string[];
	close: () => Promise<void>;
}

/** A throwaway CMS: serves the given files and records every path requested. */
async function fixtureServer( files: Record<string, FixtureFile> ): Promise<FixtureServer> {
	const hits: string[] = [];
	const server = http.createServer( ( req, res ) => {
		const url = new URL( req.url ?? '/', 'http://localhost' );
		hits.push( url.pathname );
		const file = files[ url.pathname ];
		if ( ! file ) {
			res.writeHead( 404 );
			res.end();
			return;
		}
		res.writeHead( 200, { 'content-type': file.type } );
		res.end( file.body );
	} );
	await new Promise<void>( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
	const address = server.address();
	if ( ! address || typeof address === 'string' ) {
		throw new Error( 'fixture server failed to bind' );
	}
	return {
		origin: `http://127.0.0.1:${ address.port }`,
		hits,
		close: () => new Promise<void>( ( resolve ) => server.close( () => resolve() ) ),
	};
}


/**
 * Run the real pass as a child process.
 *
 * Async on purpose: the fixture server lives in THIS process, so a blocking
 * spawn would deadlock — the child would wait for a response the parent's
 * event loop can never deliver.
 */
function runScript(
	cwd: string,
	env: NodeJS.ProcessEnv
): Promise<{ status: number | null; stdout: string; stderr: string }> {
	return new Promise( ( resolve ) => {
		const child = spawn( process.execPath, [ SCRIPT ], { cwd, env } );
		let stdout = '';
		let stderr = '';
		child.stdout?.on( 'data', ( chunk ) => {
			stdout += chunk;
		} );
		child.stderr?.on( 'data', ( chunk ) => {
			stderr += chunk;
		} );
		child.on( 'close', ( status ) => resolve( { status, stdout, stderr } ) );
	} );
}

describe( 'localize-media — managed (proxy) tier', () => {
	it( 'proxies uploads and still mirrors the stylesheet and its font', async () => {
		const iconCss = '@font-face{font-family:Icons;src:url(fonts/icons.woff)}.icon{font-family:Icons}';
		const server = await fixtureServer( {
			'/wp-content/themes/x/icon.css': { body: iconCss, type: 'text/css' },
			'/wp-content/themes/x/fonts/icons.woff': { body: Buffer.from( 'wOF2-fixture-font' ), type: 'font/woff' },
			'/wp-content/uploads/2020/01/photo.jpg': { body: Buffer.from( 'not-a-real-jpeg' ), type: 'image/jpeg' },
		} );

		try {
			const root = await mkdtemp( path.join( os.tmpdir(), 'forge-proxy-' ) );
			await mkdir( path.join( root, 'dist' ), { recursive: true } );
			await writeFile(
				path.join( root, 'nvoos.json' ),
				JSON.stringify( { url: server.origin, runtime: 'managed', media: { localize: true } } )
			);
			await writeFile(
				path.join( root, 'dist', 'index.html' ),
				[
					'<link rel="preconnect" href="https://fonts.googleapis.com">',
					`<link rel="stylesheet" href="${ server.origin }/wp-content/themes/x/icon.css">`,
					`<img src="${ server.origin }/wp-content/uploads/2020/01/photo.jpg" alt="p">`,
				].join( '' )
			);

			const run = await runScript( root, { ...process.env, NVOOS_FORGE_MEDIA_MODE: 'proxy' } );
			expect( run.status, `${ run.stdout }\n${ run.stderr }` ).toBe( 0 );

			const html = await readFile( path.join( root, 'dist', 'index.html' ), 'utf8' );
			const report = JSON.parse(
				await readFile( path.join( root, '.forge-cache', 'media-localize.json' ), 'utf8' )
			);

			// The media library stays behind the proxy: the URL is rewritten to
			// the runtime's same-origin route and the bytes are never fetched.
			expect( html ).toContain( '/media/2020/01/photo.jpg' );
			expect( server.hits.filter( ( hit ) => hit.indexOf( '/uploads/' ) !== -1 ) ).toEqual( [] );

			expect( report.mode ).toBe( 'proxy' );
			expect( report.proxied ).toHaveLength( 1 );

			// The render-blocking chain cannot be proxied (the route only matches
			// uploads), so it must travel with the artifact.
			const cssEntry = report.localized.find( ( entry: { url: string } ) =>
				entry.url.endsWith( 'icon.css' )
			);
			const fontEntry = report.localized.find( ( entry: { url: string } ) =>
				entry.url.endsWith( 'icons.woff' )
			);
			if ( ! cssEntry || ! fontEntry ) {
				throw new Error( `expected icon.css + icons.woff in the report, got ${ JSON.stringify( report ) }` );
			}

			expect( html ).toContain( `href="${ cssEntry.file }"` );

			const css = await readFile( path.join( root, 'dist', cssEntry.file.slice( 1 ) ), 'utf8' );
			expect( css ).toContain( 'font-display:swap' );
			expect( css ).toContain( 'url(/media/' );
			// No hotlink survives inside the mirrored sheet.
			expect( css ).not.toContain( server.origin );

			// The font itself landed on disk, not just in the map.
			const font = await readFile( path.join( root, 'dist', fontEntry.file.slice( 1 ) ), 'utf8' );
			expect( font ).toContain( 'wOF2' );

			// A host we did not localize keeps its hint.
			expect( html ).toContain( 'fonts.googleapis.com' );
		} finally {
			await server.close();
		}
	} );
} );
