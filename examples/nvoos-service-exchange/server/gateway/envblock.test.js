import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	buildGatewayEnvBlock,
	renderEnvBlock,
	renderEnvBlockMasked,
	validateOperatorToken,
} from './envblock.js';

const TOKEN = 'op_abcdefgh.SECRETtoken1234';
const KEY = 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6';

test( 'builds the complete env set for one estate', () => {
	const { env, notes } = buildGatewayEnvBlock( {
		slug: 'clinic-ops',
		siteUrl: 'https://clinic.example.test/',
		operatorToken: TOKEN,
		publicKey: KEY,
		visibility: 'private',
		allowlist: [ 'get_environment_status' ],
	} );
	assert.equal( env.GATEWAY_PUBLIC_KEYS, `${ KEY }=clinic-ops` );
	assert.equal( env.NVOOS_SITE_CLINIC_OPS_URL, 'https://clinic.example.test' );
	assert.equal( env.NVOOS_SITE_CLINIC_OPS_TOKEN, TOKEN );
	assert.ok( notes.some( ( note ) => /own row/.test( note ) ) );
} );

test( 'public visibility adds a PUBLIC_SITE_SLUGS note', () => {
	const { notes } = buildGatewayEnvBlock( {
		slug: 'clinic-ops',
		siteUrl: 'https://clinic.example.test',
		operatorToken: TOKEN,
		publicKey: KEY,
		visibility: 'public',
		allowlist: [ 'get_environment_status' ],
	} );
	assert.ok( notes.some( ( note ) => /PUBLIC_SITE_SLUGS/.test( note ) ) );
} );

test( 'rejects invalid inputs', () => {
	assert.throws( () => validateOperatorToken( 'not-a-token' ), /operator token/ );
	assert.throws(
		() =>
			buildGatewayEnvBlock( {
				slug: 'Bad Slug',
				siteUrl: 'https://x.test',
				operatorToken: TOKEN,
				publicKey: KEY,
			} ),
		/Invalid gateway slug/
	);
	assert.throws(
		() =>
			buildGatewayEnvBlock( {
				slug: 'clinic-ops',
				siteUrl: 'http://insecure.test',
				operatorToken: TOKEN,
				publicKey: KEY,
			} ),
		/must use https/
	);
	assert.throws(
		() =>
			buildGatewayEnvBlock( {
				slug: 'clinic-ops',
				siteUrl: 'https://x.test',
				operatorToken: TOKEN,
				publicKey: 'short',
			} ),
		/base64url/
	);
} );

test( 'renders one line per variable and masks secrets on request', () => {
	const { env } = buildGatewayEnvBlock( {
		slug: 'clinic-ops',
		siteUrl: 'https://clinic.example.test',
		operatorToken: TOKEN,
		publicKey: KEY,
	} );
	const raw = renderEnvBlock( env );
	assert.equal( raw.split( '\n' ).length, 3 );
	assert.ok( raw.includes( TOKEN ) );

	const masked = renderEnvBlockMasked( env, ( value ) => `«${ value.length } chars»` );
	assert.ok( ! masked.includes( TOKEN ) );
	assert.ok( ! masked.includes( KEY ) );
	assert.ok( masked.includes( 'https://clinic.example.test' ) );
} );
