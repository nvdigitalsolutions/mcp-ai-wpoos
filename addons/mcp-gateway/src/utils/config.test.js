/**
 * Tests for the OAuth configuration parsing (config.js).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { loadConfig, parseOAuthConfig, normalizeIssuer, issuerUrl } from './config.js';

test( 'OAuth is inert when GATEWAY_OAUTH_ISSUER is unset', () => {
	const cfg = loadConfig( { AUTH_MODE: 'open' } );
	assert.equal( cfg.oauth.enabled, false );
	assert.equal( cfg.oauth.resource, 'https://mcp.nvoos.pro' );
	assert.deepEqual( cfg.errors, [] );
} );

test( 'normalizeIssuer strips whitespace and keeps one trailing slash', () => {
	assert.equal( normalizeIssuer( '  https://auth.example.com/  ' ), 'https://auth.example.com/' );
	assert.equal( normalizeIssuer( 'https://auth.example.com' ), 'https://auth.example.com/' );
	assert.equal( normalizeIssuer( '' ), '/' );
} );

test( 'issuerUrl joins without doubled slashes', () => {
	assert.equal( issuerUrl( 'https://auth.example.com/', '.well-known/jwks.json' ), 'https://auth.example.com/.well-known/jwks.json' );
} );

test( 'a configured issuer derives the default JWKS URI and enables OAuth', () => {
	const cfg = loadConfig( { GATEWAY_OAUTH_ISSUER: 'https://auth.example.com' } );
	assert.equal( cfg.oauth.enabled, true );
	assert.equal( cfg.oauth.issuer, 'https://auth.example.com/' );
	assert.equal( cfg.oauth.jwksUri, 'https://auth.example.com/.well-known/jwks.json' );
	assert.equal( cfg.oauth.resource, 'https://mcp.nvoos.pro' );
} );

test( 'GATEWAY_OAUTH_JWKS_URI and GATEWAY_OAUTH_RESOURCE override the defaults', () => {
	const cfg = loadConfig( {
		AUTH_MODE: 'open',
		GATEWAY_OAUTH_ISSUER: 'https://auth.example.com/',
		GATEWAY_OAUTH_JWKS_URI: 'https://keys.example.com/jwks',
		GATEWAY_OAUTH_RESOURCE: 'https://mcp.example.org',
	} );
	assert.equal( cfg.oauth.jwksUri, 'https://keys.example.com/jwks' );
	assert.equal( cfg.oauth.resource, 'https://mcp.example.org' );
	assert.deepEqual( cfg.errors, [] );
} );

test( 'invalid OAuth URLs are collected as config errors', () => {
	const errors = [];
	parseOAuthConfig( { GATEWAY_OAUTH_ISSUER: 'not a url' }, errors );
	// The garbage issuer also invalidates the derived JWKS URI.
	assert.equal( errors.length, 2 );
	assert.match( errors[ 0 ], /GATEWAY_OAUTH_ISSUER must be an absolute https URL/ );
	assert.match( errors[ 1 ], /GATEWAY_OAUTH_JWKS_URI must be an absolute https URL/ );

	const errors2 = [];
	parseOAuthConfig(
		{ GATEWAY_OAUTH_ISSUER: 'https://auth.example.com/', GATEWAY_OAUTH_RESOURCE: 'http://insecure.example' },
		errors2
	);
	assert.equal( errors2.length, 1 );
	assert.match( errors2[ 0 ], /GATEWAY_OAUTH_RESOURCE must be an absolute https URL/ );
} );

test( 'loadConfig carries OAuth errors through to assert-time', () => {
	const cfg = loadConfig( { GATEWAY_OAUTH_ISSUER: 'garbage' } );
	assert.equal( cfg.oauth.enabled, true, 'parsing continues but the error blocks boot' );
	assert.ok( cfg.errors.some( ( error ) => error.includes( 'GATEWAY_OAUTH_ISSUER' ) ) );
} );
