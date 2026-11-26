// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// media-url-cache-bust.test.ts — the proxied media path, EXECUTED.
//
// `template-chrome-media.test.ts` pins the source text; this runs the real
// function against a manifest, because the shape it returns is a cache key.
// `/media/<uploads path>` is the CMS's own path, not content-hashed, so a CDN
// keeps it for as long as the CMS says — often a year. Without a per-build
// stamp, a corrected response never reaches a visitor that cached the broken
// one (eca, 2026-11-26: the fixed logo stayed a 0-byte edge HIT with a year
// left to run, on a shared zone that is not ours to purge).
//
// The template cannot be imported in-process — `src/lib/media.ts` resolves
// `../../nvoos.json` — so each case runs in a throwaway project.
// ---------------------------------------------------------------------------

import { spawn } from 'node:child_process';
import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

import { describe, expect, it } from 'vitest';

import { rmTemp } from './helpers/tmp.ts';

const TEMPLATE = path.join( process.cwd(), 'templates', 'blog' );
const STAMP = '2026-11-26T05:56:33.123Z';
/** `STAMP` with every non-digit removed, truncated to 14 — the token contract. */
const TOKEN = '20261126055633';

interface ProbeResult {
	value?: string;
	error?: string;
}

/** Run the template's `mediaUrl` inside a throwaway project. */
async function probe( manifest: Record< string, unknown >, url: string ): Promise< ProbeResult > {
	const dir = await mkdtemp( path.join( os.tmpdir(), 'forge-media-url-' ) );
	try {
		await mkdir( path.join( dir, 'src', 'lib' ), { recursive: true } );
		const source = await readFile( path.join( TEMPLATE, 'src', 'lib', 'media.ts' ), 'utf8' );
		if ( ! source.includes( MANIFEST_IMPORT ) ) {
			throw new Error(
				'media.ts no longer imports nvoos.json the way this fixture shims — update MANIFEST_IMPORT'
			);
		}
		await writeFile(
			path.join( dir, 'src', 'lib', 'media.ts' ),
			source.replace( MANIFEST_IMPORT, MANIFEST_SHIM ),
			'utf8'
		);
		await writeFile( path.join( dir, 'nvoos.json' ), JSON.stringify( manifest ), 'utf8' );
		await writeFile(
			path.join( dir, 'driver.mjs' ),
			[
				"import { mediaUrl } from './src/lib/media.ts';",
				// `{}` stands in for `import.meta.env`, which only exists under Astro.
				`const value = mediaUrl(${ JSON.stringify( url ) }, {});`,
				'process.stdout.write(JSON.stringify({ value }));',
			].join( '\n' ),
			'utf8'
		);

		return await new Promise< ProbeResult >( ( resolve, reject ) => {
			const child = spawn(
				process.execPath,
				[ '--experimental-strip-types', path.join( dir, 'driver.mjs' ) ],
				{ cwd: dir }
			);
			let out = '';
			let err = '';
			child.stdout?.on( 'data', ( chunk ) => ( out += chunk ) );
			child.stderr?.on( 'data', ( chunk ) => ( err += chunk ) );
			child.on( 'close', ( code ) => {
				if ( code !== 0 ) {
					reject( new Error( `driver exited ${ code }: ${ err }` ) );
					return;
				}
				resolve( JSON.parse( out ) as ProbeResult );
			} );
		} );
	} finally {
		await rmTemp( dir );
	}
}

const UPLOAD = 'https://cms.example/wp-content/uploads/2025/08/logo.svg';

/**
 * The template's manifest import, as the fixture has to shim it.
 *
 * Astro's bundler resolves a bare JSON import; plain Node 24 requires
 * `with { type: 'json' }`. Only this one line is rewritten — every function
 * under test runs verbatim.
 */
const MANIFEST_IMPORT = "import manifest from '../../nvoos.json';";
const MANIFEST_SHIM = [
	"import { readFileSync } from 'node:fs';",
	"const manifest = JSON.parse(readFileSync(new URL('../../nvoos.json', import.meta.url), 'utf8'));",
].join( '\n' );

describe( 'template mediaUrl — cache-busting stamp', () => {
	it( 'stamps a managed upload with the build token', async () => {
		const result = await probe(
			{ url: 'https://cms.example', runtime: 'managed', generatedAt: STAMP },
			UPLOAD
		);
		expect( result.value ).toBe( `/media/2025/08/logo.svg?v=${ TOKEN }` );
	} );

	it( 'keeps an existing query string and appends the token', async () => {
		const result = await probe(
			{ url: 'https://cms.example', runtime: 'managed', generatedAt: STAMP },
			`${ UPLOAD }?ver=1.0`
		);
		expect( result.value ).toBe( `/media/2025/08/logo.svg?ver=1.0&v=${ TOKEN }` );
	} );

	it( 'leaves a materialized upload alone so the localizer can map it', async () => {
		// The mirror keys on the exact CMS URL; a stamp here would be a miss.
		const result = await probe(
			{
				url: 'https://cms.example',
				runtime: 'managed',
				contentSource: 'materialized',
				generatedAt: STAMP,
			},
			UPLOAD
		);
		expect( result.value ).toBe( UPLOAD );
	} );

	it( 'leaves a static build alone — nothing serves /media there', async () => {
		const result = await probe( { url: 'https://cms.example', generatedAt: STAMP }, UPLOAD );
		expect( result.value ).toBe( UPLOAD );
	} );

	it( 'drops the stamp when the manifest carries none', async () => {
		const result = await probe( { url: 'https://cms.example', runtime: 'managed' }, UPLOAD );
		expect( result.value ).toBe( '/media/2025/08/logo.svg' );
	} );
} );
