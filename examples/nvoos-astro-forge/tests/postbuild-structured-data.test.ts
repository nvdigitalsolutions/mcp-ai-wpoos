// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// postbuild-structured-data.test.ts — a retired CMS must not survive in JSON-LD.
//
// The localizer rewrites ASSET attributes and deliberately leaves every other
// absolute URL alone: `og:image` has to stay absolute after a domain move. JSON-LD
// is the exception — a `logo`/`image` URL still naming a RETIRED CMS is a dead
// link a search engine indexes, and the built page carried it BESIDE the local
// copy the localizer had just written (virtuallyjamaica: three documents, one
// logo, both URLs present).
//
// The rewrite reads the localizer's own report, so the mapping cannot drift from
// the file it actually wrote, and it is confined to
// `<script type="application/ld+json">` bodies.
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
const SCRIPT = resolve( dirname( fileURLToPath( import.meta.url ) ), '../templates/blog/scripts/postbuild.mjs' );

const roots: string[] = [];
async function project(): Promise< string > {
	const root = await mkdtemp( join( tmpdir(), 'forge-postbuild-sd-' ) );
	roots.push( root );
	return root;
}

afterEach( async () => {
	await Promise.all( roots.splice( 0 ).map( ( dir ) => rmTemp( dir ) ) );
} );

const CMS_LOGO = 'https://virtuallyjamaica.com/wp-content/uploads/2018/11/VJ-Logo-V5-wht-gry.svg';
const LOCAL_LOGO = '/media/6f78e38a41ba-vj-logo-v5-wht-gry.svg';

const documentWith = ( logoUrl: string ): string =>
	[
		'<!doctype html><html><head>',
		`<meta property="og:image" content="${ logoUrl }">`,
		`<script type="application/ld+json">${ JSON.stringify( {
			'@context': 'https://schema.org',
			'@type': 'Organization',
			logo: { '@type': 'ImageObject', url: logoUrl },
		} ) }</script>`,
		'</head><body><img src="/media/6f78e38a41ba-vj-logo-v5-wht-gry.svg" alt=""></body></html>',
	].join( '' );

describe( 'postbuild — structured data', () => {
	it( 'rewrites a retired CMS logo to the local asset, and leaves og:image absolute', async () => {
		const root = await project();
		await mkdir( join( root, 'dist' ), { recursive: true } );
		await mkdir( join( root, '.forge-cache' ), { recursive: true } );
		await writeFile( join( root, 'nvoos.json' ), JSON.stringify( { url: 'https://virtuallyjamaica.com' } ) );
		await writeFile(
			join( root, '.forge-cache', 'media-localize.json' ),
			JSON.stringify( { localized: [ { url: CMS_LOGO, file: LOCAL_LOGO } ] } )
		);
		await writeFile( join( root, 'dist', 'index.html' ), documentWith( CMS_LOGO ) );

		await execFileAsync( process.execPath, [ SCRIPT ], { cwd: root } );

		const html = await readFile( join( root, 'dist', 'index.html' ), 'utf8' );
		// Structured data points at the LOCAL asset, absolutely.
		expect( html ).toContain( `"url":"https://virtuallyjamaica.com/media/6f78e38a41ba-vj-logo-v5-wht-gry.svg"` );
		// og:image keeps the source URL on purpose.
		expect( html ).toContain( `property="og:image" content="${ CMS_LOGO }"` );
	} );

	it( 'is a no-op when the localizer wrote no report', async () => {
		const root = await project();
		await mkdir( join( root, 'dist' ), { recursive: true } );
		await writeFile( join( root, 'nvoos.json' ), JSON.stringify( { url: 'https://example.test' } ) );
		await writeFile( join( root, 'dist', 'index.html' ), documentWith( CMS_LOGO ) );

		await execFileAsync( process.execPath, [ SCRIPT ], { cwd: root } );

		const html = await readFile( join( root, 'dist', 'index.html' ), 'utf8' );
		expect( html ).toContain( `"url":"${ CMS_LOGO }"` );
	} );
} );
