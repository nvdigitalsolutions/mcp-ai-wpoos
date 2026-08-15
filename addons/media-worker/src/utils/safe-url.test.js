/**
 * Tests for the SSRF-safe URL validator (OWASP SSRF Prevention in Node.js).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	isPrivateIPv4,
	isPrivateIPv6,
	isPrivateAddress,
	isPrivateHostname,
	validatePublicUrl,
	resolvePublicUrl,
} from './safe-url.js';

test( 'public http/https URLs pass', () => {
	for ( const raw of [ 'https://example.com/page', 'http://example.com:8080/x', 'https://api.openai.com/v1/images' ] ) {
		assert.doesNotThrow( () => validatePublicUrl( raw ) );
	}
} );

test( 'non-http protocols are rejected', () => {
	for ( const raw of [ 'ftp://example.com/file', 'file:///etc/passwd', 'gopher://localhost', 'javascript:alert(1)' ] ) {
		assert.throws( () => validatePublicUrl( raw ), /Protocol not allowed/ );
	}
} );

test( 'invalid URLs are rejected', () => {
	assert.throws( () => validatePublicUrl( 'not a url' ), /Invalid URL/ );
	assert.throws( () => validatePublicUrl( '' ), /Invalid URL/ );
} );

test( 'private IPv4 literals are rejected', () => {
	for ( const host of [
		'127.0.0.1',
		'10.0.0.5',
		'172.16.0.1',
		'172.31.255.255',
		'192.168.1.1',
		'169.254.169.254', // cloud metadata endpoint
		'0.0.0.0',
		'100.64.0.1',
		'192.0.2.1',
		'198.18.0.1',
		'198.51.100.7',
		'203.0.113.9',
		'224.0.0.1',
	] ) {
		assert.equal( isPrivateHostname( host ), true, `${ host } should be private` );
		assert.throws( () => validatePublicUrl( `http://${ host }/` ), /not publicly routable/, host );
	}
} );

test( 'public IPv4 literals pass', () => {
	for ( const host of [ '8.8.8.8', '1.1.1.1', '104.18.1.2' ] ) {
		assert.equal( isPrivateHostname( host ), false, `${ host } should be public` );
		assert.doesNotThrow( () => validatePublicUrl( `http://${ host }/` ) );
	}
} );

test( 'private IPv6 literals are rejected', () => {
	for ( const host of [ '::1', '::', 'fc00::1', 'fd12::1', 'fe80::1', 'ff02::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1' ] ) {
		assert.equal( isPrivateHostname( host ), true, `${ host } should be private` );
		assert.throws( () => validatePublicUrl( `http://[${ host }]/` ), /not publicly routable/, host );
	}
} );

test( 'obfuscated IPv4 forms are normalised and rejected', () => {
	for ( const host of [
		'2130706433', // 127.0.0.1 decimal
		'0x7f000001', // hex
		'017700000001', // octal
		'2886729728', // 172.16.0.0 decimal
	] ) {
		assert.equal( isPrivateHostname( host ), true, `${ host } should be private` );
		assert.throws( () => validatePublicUrl( `http://${ host }/` ), /not publicly routable/, host );
	}
} );

test( 'hostnames resolving to private addresses are rejected', async () => {
	// localhost resolves to 127.0.0.1 in virtually every environment.
	await assert.rejects( () => resolvePublicUrl( 'http://localhost:8080/x' ), /private address/ );
	await assert.rejects( () => resolvePublicUrl( 'http://127.0.0.1.nip.io/' ), /private address/ );
} );

test( 'public hostnames resolve and pass', async () => {
	const url = await resolvePublicUrl( 'https://example.com/' );
	assert.equal( url.hostname, 'example.com' );
} );

test( 'allowPrivate option bypasses the blocklist', () => {
	assert.doesNotThrow( () => validatePublicUrl( 'http://127.0.0.1:3100/api/health', { allowPrivate: true } ) );
} );

test( 'isPrivateAddress covers both families', () => {
	assert.equal( isPrivateAddress( '10.1.2.3' ), true );
	assert.equal( isPrivateAddress( '8.8.8.8' ), false );
	assert.equal( isPrivateAddress( 'fe80::1' ), true );
	assert.equal( isPrivateAddress( '2606:4700:4700::1111' ), false );
} );

test( 'isPrivateIPv4 rejects malformed input without throwing', () => {
	assert.equal( isPrivateIPv4( '999.1.1.1' ), false );
	assert.equal( isPrivateIPv4( 'not-an-ip' ), false );
} );
