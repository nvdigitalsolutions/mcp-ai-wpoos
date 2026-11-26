// SPDX-License-Identifier: MIT
/**
 * @fileoverview Pins whether postbuild replaces documents with the gate page.
 *
 * Invariant: a SERVER build keeps every prerendered document, because the front
 * controller answers the gate and must still serve the real site to a signed-in
 * manager. Only the static tier substitutes the holding page. Asserts on the
 * surviving CONTENT, never on bytes — postbuild also stamps each document.
 */

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

const REAL = '<!doctype html><html><head><title>Real</title></head><body><h1>Real site</h1></body></html>';
const GATE =
	'<!doctype html><html><head><meta name="robots" content="noindex, nofollow"><title>Coming soon</title></head><body><h1>Coming soon</h1></body></html>';

const roots: string[] = [];

afterEach( async () => {
	await Promise.all( roots.splice( 0 ).map( ( dir ) => rmTemp( dir ) ) );
} );

async function fixture( serverBuild: boolean ): Promise< string > {
	const root = await mkdtemp( join( tmpdir(), 'forge-postbuild-gate-' ) );
	roots.push( root );

	await mkdir( join( root, 'dist', 'client', 'about' ), { recursive: true } );
	await mkdir( join( root, 'dist', 'client', 'coming-soon' ), { recursive: true } );
	await mkdir( join( root, 'dist', 'client', 'maintenance' ), { recursive: true } );
	if ( serverBuild ) {
		await mkdir( join( root, 'dist', 'server' ), { recursive: true } );
		await writeFile( join( root, 'dist', 'server', 'entry.mjs' ), 'export {};\n' );
	}

	await writeFile( join( root, 'nvoos.json' ), JSON.stringify( { url: 'https://example.test' } ) );
	await writeFile(
		join( root, 'gate.generated.json' ),
		JSON.stringify( { mode: 'coming_soon', policy: 'replace', page: 'coming-soon', honored: true } )
	);
	await writeFile( join( root, 'dist', 'client', 'index.html' ), REAL );
	await writeFile( join( root, 'dist', 'client', 'about', 'index.html' ), REAL );
	await writeFile( join( root, 'dist', 'client', 'coming-soon', 'index.html' ), GATE );
	await writeFile( join( root, 'dist', 'client', 'maintenance', 'index.html' ), GATE );

	return root;
}

const read = ( root: string, ...parts: string[] ): Promise< string > =>
	readFile( join( root, 'dist', 'client', ...parts ), 'utf8' );

describe( 'postbuild — the publication gate and prerendered documents', () => {
	it( 'keeps every document on a server build, so the manager still sees the site', async () => {
		const root = await fixture( true );

		await execFileAsync( process.execPath, [ SCRIPT ], { cwd: root } );

		const home = await read( root, 'index.html' );
		expect( home ).toContain( 'Real site' );
		expect( home ).not.toContain( 'Coming soon' );

		expect( await read( root, 'about', 'index.html' ) ).toContain( 'Real site' );
		// The gate documents themselves stay as built.
		expect( await read( root, 'coming-soon', 'index.html' ) ).toContain( 'Coming soon' );
	} );

	it( 'replaces the documents on a static build, where nothing else can answer the gate', async () => {
		const root = await fixture( false );

		await execFileAsync( process.execPath, [ SCRIPT ], { cwd: root } );

		const home = await read( root, 'index.html' );
		expect( home ).toContain( 'Coming soon' );
		expect( home ).not.toContain( 'Real site' );

		expect( await read( root, 'about', 'index.html' ) ).toContain( 'Coming soon' );
	} );
} );
