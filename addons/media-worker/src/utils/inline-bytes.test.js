// SPDX-License-Identifier: LicenseRef-Proprietary
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'fs';
import os from 'os';
import path from 'path';
import { inlineFile, MAX_INLINE_BYTES } from './inline-bytes.js';

function withFile( bytes, run ) {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'inline-bytes-' ) );
	const file = path.join( dir, 'fixture.bin' );

	fs.writeFileSync( file, bytes );

	try {
		return run( file );
	} finally {
		fs.rmSync( dir, { recursive: true, force: true } );
	}
}

test( 'the bytes round-trip through base64 and the size is the file size', () => {
	const bytes = Buffer.from( [ 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a ] );

	withFile( bytes, ( file ) => {
		const result = inlineFile( file, 'image/png' );

		assert.equal( result.mime, 'image/png' );
		assert.equal( result.size, bytes.length );
		assert.deepEqual( Buffer.from( result.b64, 'base64' ), bytes );
	} );
} );

test( 'a file past the cap is refused with 413, not truncated', () => {
	withFile( Buffer.alloc( 32 ), ( file ) => {
		assert.throws(
			() => inlineFile( file, 'image/png', 16 ),
			( error ) => 413 === error.status && /inline cap/.test( error.message )
		);
	} );
} );

test( 'the default cap is 24 MiB', () => {
	assert.equal( MAX_INLINE_BYTES, 24 * 1024 * 1024 );
} );
