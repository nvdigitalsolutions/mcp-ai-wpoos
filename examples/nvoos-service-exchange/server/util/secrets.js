/**
 * Secret handling: AES-256-GCM at rest, fingerprints for display, masking
 * for snippets. Nothing in this module ever logs.
 */

import {
	createCipheriv,
	createDecipheriv,
	createHash,
	randomBytes,
	timingSafeEqual,
} from 'node:crypto';

const PREFIX = 'v1';

function keyFrom( secret ) {
	if ( ! secret || typeof secret !== 'string' ) {
		throw new Error( 'A non-empty encryption secret is required' );
	}
	// scrypt-free deterministic derivation: the env secret is already a
	// high-entropy platform value; sha-256 yields the 32-byte AES key.
	return createHash( 'sha256' ).update( secret, 'utf8' ).digest();
}

function b64url( buffer ) {
	return Buffer.from( buffer ).toString( 'base64url' );
}

function fromB64url( value ) {
	return Buffer.from( value, 'base64url' );
}

/**
 * Encrypt a secret string (tokens, SSH keys) for storage.
 *
 * @param {string} plaintext
 * @param {string} secret Env-level encryption secret.
 * @return {string} `v1.<iv>.<tag>.<ciphertext>` (base64url parts).
 */
export function encryptSecret( plaintext, secret ) {
	if ( typeof plaintext !== 'string' || plaintext === '' ) {
		throw new Error( 'Cannot encrypt an empty secret' );
	}
	const iv = randomBytes( 12 );
	const cipher = createCipheriv( 'aes-256-gcm', keyFrom( secret ), iv );
	const ciphertext = Buffer.concat( [ cipher.update( plaintext, 'utf8' ), cipher.final() ] );
	const tag = cipher.getAuthTag();
	return [ PREFIX, b64url( iv ), b64url( tag ), b64url( ciphertext ) ].join( '.' );
}

/**
 * Decrypt a payload produced by encryptSecret.
 *
 * @param {string} payload
 * @param {string} secret
 * @return {string}
 */
export function decryptSecret( payload, secret ) {
	if ( typeof payload !== 'string' ) {
		throw new Error( 'Invalid encrypted payload' );
	}
	const parts = payload.split( '.' );
	if ( parts.length !== 4 || parts[ 0 ] !== PREFIX ) {
		throw new Error( 'Unsupported encrypted payload format' );
	}
	const [ , iv, tag, ciphertext ] = parts;
	try {
		const decipher = createDecipheriv( 'aes-256-gcm', keyFrom( secret ), fromB64url( iv ) );
		decipher.setAuthTag( fromB64url( tag ) );
		return Buffer.concat( [
			decipher.update( fromB64url( ciphertext ) ),
			decipher.final(),
		] ).toString( 'utf8' );
	} catch {
		throw new Error( 'Failed to decrypt payload (wrong key or tampered data)' );
	}
}

/**
 * Stable, non-reversible identifier for a secret — safe to store/display.
 *
 * @param {string} value
 * @return {string} `sha256:<12 hex chars>`
 */
export function fingerprintSecret( value ) {
	return 'sha256:' + createHash( 'sha256' ).update( String( value ), 'utf8' ).digest( 'hex' ).slice( 0, 12 );
}

/**
 * Mask a token for UI/snippet display. `op_abcd.SECRET` → `op_a…CRET`.
 *
 * @param {string} token
 * @return {string}
 */
export function maskToken( token ) {
	const value = String( token || '' );
	if ( value.length <= 8 ) {
		return value ? '••••' : '';
	}
	const dot = value.indexOf( '.' );
	if ( dot > 0 ) {
		const head = value.slice( 0, dot );
		const tail = value.slice( dot + 1 );
		return `${ head.slice( 0, 4 ) }…${ tail.slice( -4 ) }`;
	}
	return `${ value.slice( 0, 4 ) }…${ value.slice( -4 ) }`;
}

/**
 * Timing-safe equality for bearer-token checks.
 *
 * @param {string} a
 * @param {string} b
 * @return {boolean}
 */
export function constantTimeEqual( a, b ) {
	const left = Buffer.from( String( a || '' ), 'utf8' );
	const right = Buffer.from( String( b || '' ), 'utf8' );
	if ( left.length !== right.length || left.length === 0 ) {
		return false;
	}
	return timingSafeEqual( left, right );
}
