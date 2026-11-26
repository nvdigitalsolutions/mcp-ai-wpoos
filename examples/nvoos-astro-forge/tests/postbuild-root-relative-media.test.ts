// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// postbuild-root-relative-media.test.ts — a root-relative CMS path is rescued.
//
// The localizer resolves ABSOLUTE urls only, so `/wp-content/uploads/x.png`
// survives every pass. It is invisible to the retired-cms gate as well, which
// matches `//host/wp-content/` — so nothing catches it, yet it 404s the moment
// the forge origin serves the document (virtuallyjamaica ships seven lightbox
// hrefs of exactly this shape while the destination file is already mirrored
// beside them, 2026-11-26).
//
// Only paths the localizer actually mirrored are rewritten: an unmapped path
// stays put, so a snapshot hole remains visible instead of being swallowed, and
// another host's `/wp-content/` is never touched.
// ---------------------------------------------------------------------------

import { execFile } from 'node:child_process';
import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';

import { afterEach, describe, expect, it } from 'vitest';

import { rmTemp } from './helpers/tmp.ts';

const execFileAsync = promisify( execFile );
const SCRIPT = resolve(
	dirname( fileURLToPath( import.meta.url ) ),
	'../templates/blog/scripts/postbuild.mjs'
);

const ORIGIN = 'https://virtuallyjamaica.com';
const SOURCE_PATH = '/wp-content/uploads/2017/09/6.png';
const MIRROR = '/media/48ad1823840d-6.webp';

const roots: string[] = [];

/** A throwaway project: manifest, localizer report, and one document. */
async function project( html: string, localized: Array< { url: string; file: string } > ): Promise< string > {
	const root = await mkdtemp( join( tmpdir(), 'forge-postbuild-rr-' ) );
	roots.push( root );
	await mkdir( join( root, 'dist' ), { recursive: true } );
	await mkdir( join( root, '.forge-cache' ), { recursive: true } );
	await writeFile( join( root, 'nvoos.json' ), JSON.stringify( { url: ORIGIN } ) );
	await writeFile(
		join( root, '.forge-cache', 'media-localize.json' ),
		JSON.stringify( { localized } )
	);
	await writeFile( join( root, 'dist', 'index.html' ), html );
	return root;
}

afterEach( async () => {
	await Promise.all( roots.splice( 0 ).map( ( dir ) => rmTemp( dir ) ) );
} );

describe( 'postbuild — root-relative CMS assets', () => {
	it( 're-points a mirrored root-relative asset at its local copy', async () => {
		const root = await project( `<a href="${ SOURCE_PATH }"><img src="${ MIRROR }" alt=""></a>`, [
			{ url: `${ ORIGIN }${ SOURCE_PATH }`, file: MIRROR },
		] );

		await execFileAsync( process.execPath, [ SCRIPT ], { cwd: root } );

		const html = await readFile( join( root, 'dist', 'index.html' ), 'utf8' );
		expect( html ).toContain( `href="${ MIRROR }"` );
		expect( html ).not.toContain( '/wp-content/' );
	} );

	it( 'leaves an unmapped path alone, so a snapshot hole stays visible', async () => {
		const missing = '/wp-content/uploads/2017/09/missing.png';
		const root = await project( `<a href="${ missing }">x</a>`, [
			{ url: `${ ORIGIN }${ SOURCE_PATH }`, file: MIRROR },
		] );

		await execFileAsync( process.execPath, [ SCRIPT ], { cwd: root } );

		const html = await readFile( join( root, 'dist', 'index.html' ), 'utf8' );
		expect( html ).toContain( `href="${ missing }"` );
	} );

	it( 'never rewrites another host, even when the path matches', async () => {
		const foreign = `https://elsewhere.test${ SOURCE_PATH }`;
		const root = await project( `<a href="${ foreign }">x</a>`, [
			{ url: `${ ORIGIN }${ SOURCE_PATH }`, file: MIRROR },
		] );

		await execFileAsync( process.execPath, [ SCRIPT ], { cwd: root } );

		const html = await readFile( join( root, 'dist', 'index.html' ), 'utf8' );
		expect( html ).toContain( `href="${ foreign }"` );
	} );
} );
