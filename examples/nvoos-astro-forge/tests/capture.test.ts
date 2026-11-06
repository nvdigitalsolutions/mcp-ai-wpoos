// ---------------------------------------------------------------------------
// capture.test.ts — the screenshot contract (Proposal 118 §2): endpoint
// resolution, payload normalization, dropped-artifact reads.
// ---------------------------------------------------------------------------

import { mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { afterEach, describe, expect, it } from 'vitest';

import { captureEndpoint, captureRemote, listArtifacts, normalizeImagePayload, readArtifact } from '../lib/capture.ts';

const tempDirs: string[] = [];
async function tempDir(): Promise<string> {
	const dir = await mkdtemp( join( tmpdir(), 'forge-capture-' ) );
	tempDirs.push( dir );
	return dir;
}
afterEach( () => {
	for ( const dir of tempDirs.splice( 0 ) ) {
		// Best-effort cleanup; OS temp sweeps leftovers.
		void import( 'node:fs/promises' ).then( ( { rm } ) => rm( dir, { recursive: true, force: true } ) );
	}
} );

describe( 'captureEndpoint', () => {
	it( 'resolves the configured endpoint and strips the trailing slash', () => {
		expect( captureEndpoint( { NVOOS_FORGE_CAPTURE_URL: 'https://capture.local/' } ) ).toEqual( { url: 'https://capture.local' } );
		expect( captureEndpoint( {} ) ).toBeUndefined();
	} );
} );

describe( 'normalizeImagePayload', () => {
	it( 'accepts data URLs and { base64, mime } objects, rejects junk', () => {
		expect( normalizeImagePayload( 'data:image/png;base64,aGVsbG8=' ) ).toEqual( { mime: 'image/png', data: 'aGVsbG8=' } );
		expect( normalizeImagePayload( { base64: 'eA==', mime: 'image/jpeg' } ) ).toEqual( { mime: 'image/jpeg', data: 'eA==' } );
		expect( normalizeImagePayload( 'https://example.com/x.png' ) ).toBeUndefined();
		expect( normalizeImagePayload( { base64: 'eA==' } ) ).toBeUndefined();
		expect( normalizeImagePayload( null ) ).toBeUndefined();
	} );
} );

describe( 'captureRemote', () => {
	it( 'posts the target and normalizes the image payload', async () => {
		let captured: { url?: string; body?: string } = {};
		const fetchImpl = ( async ( url: string, init: RequestInit ) => {
			captured = { url, body: String( init.body ) };
			return new Response( JSON.stringify( { image: 'data:image/png;base64,QUJD' } ), { status: 200 } );
		} ) as typeof fetch;
		const image = await captureRemote( { url: 'https://capture.local' }, 'https://jayainc.com', 'jayainc-source', 1440, fetchImpl );
		expect( captured.url ).toBe( 'https://capture.local/capture' );
		expect( JSON.parse( captured.body ?? '{}' ) ).toEqual( { url: 'https://jayainc.com', width: 1440, label: 'jayainc-source' } );
		expect( image ).toEqual( { mime: 'image/png', data: 'QUJD' } );
	} );

	it( 'warns and returns undefined on refused/network failures', async () => {
		const failing = ( async () => new Response( 'no', { status: 500 } ) ) as unknown as typeof fetch;
		expect( await captureRemote( { url: 'https://capture.local' }, 'https://x.com', 'x-source', 1440, failing ) ).toBeUndefined();
		const throwing = ( async () => {
			throw new Error( 'down' );
		} ) as unknown as typeof fetch;
		expect( await captureRemote( { url: 'https://capture.local' }, 'https://x.com', 'x-source', 1440, throwing ) ).toBeUndefined();
	} );
} );

describe( 'readArtifact / listArtifacts', () => {
	it( 'reads a dropped png/jpg/webp by label and lists the directory', async () => {
		const dir = await tempDir();
		await writeFile( join( dir, 'jayainc-source.png' ), Buffer.from( 'png-bytes' ) );
		await writeFile( join( dir, 'jayainc-built.jpg' ), Buffer.from( 'jpg-bytes' ) );
		await writeFile( join( dir, 'notes.txt' ), 'ignore me' );
		expect( await readArtifact( dir, 'jayainc-source' ) ).toEqual( { data: Buffer.from( 'png-bytes' ).toString( 'base64' ), mime: 'image/png' } );
		expect( await readArtifact( dir, 'jayainc-built' ) ).toEqual( { data: Buffer.from( 'jpg-bytes' ).toString( 'base64' ), mime: 'image/jpeg' } );
		expect( await readArtifact( dir, 'missing' ) ).toBeUndefined();
		expect( ( await listArtifacts( dir ) ).sort() ).toEqual( [ 'jayainc-built.jpg', 'jayainc-source.png' ] );
	} );

	it( 'returns undefined for a missing directory', async () => {
		expect( await readArtifact( join( await tempDir(), 'nope' ), 'x' ) ).toBeUndefined();
		expect( await listArtifacts( join( await tempDir(), 'nope' ) ) ).toEqual( [] );
	} );
} );
