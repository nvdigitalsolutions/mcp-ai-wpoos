import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	encryptSecret,
	decryptSecret,
	fingerprintSecret,
	maskToken,
	constantTimeEqual,
} from './secrets.js';

const KEY = 'test-encryption-key-0123456789';

test( 'encrypt/decrypt round-trips and rejects tampering', () => {
	const ciphertext = encryptSecret( 'op_live.SECRETVALUE', KEY );
	assert.match( ciphertext, /^v1\./ );
	assert.equal( decryptSecret( ciphertext, KEY ), 'op_live.SECRETVALUE' );
	assert.throws( () => decryptSecret( ciphertext, 'wrong-key' ), /Failed to decrypt/ );

	const tampered = ciphertext.replace( /\.(\w{4})/, '.AAAA' );
	assert.throws( () => decryptSecret( tampered, KEY ) );
} );

test( 'empty secrets are refused', () => {
	assert.throws( () => encryptSecret( '', KEY ), /empty secret/ );
} );

test( 'fingerprints are stable and non-reversible', () => {
	const a = fingerprintSecret( 'op_abc.SECRET' );
	const b = fingerprintSecret( 'op_abc.SECRET' );
	assert.equal( a, b );
	assert.match( a, /^sha256:[0-9a-f]{12}$/ );
	assert.ok( ! a.includes( 'SECRET' ) );
} );

test( 'maskToken hides both halves', () => {
	assert.equal( maskToken( 'op_abcd.SECRETVALUE' ), 'op_a…ALUE' );
	assert.equal( maskToken( 'short' ), '••••' );
	assert.equal( maskToken( '' ), '' );
} );

test( 'constantTimeEqual compares safely', () => {
	assert.equal( constantTimeEqual( 'abc', 'abc' ), true );
	assert.equal( constantTimeEqual( 'abc', 'abd' ), false );
	assert.equal( constantTimeEqual( '', '' ), false );
	assert.equal( constantTimeEqual( 'a', 'ab' ), false );
} );
