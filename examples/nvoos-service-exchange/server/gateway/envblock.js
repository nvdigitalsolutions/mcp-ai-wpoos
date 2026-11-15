/**
 * Gateway env-block generator.
 *
 * The gateway is env-configured and fail-closed: a key bound to an
 * unconfigured slug is a boot error. This module therefore generates the
 * *complete* set of variables for one estate and validates them against the
 * gateway's own parser rules:
 *   - one line per variable (duplicate rows do not merge);
 *   - key = `slug-a,slug-b` entries separated by `;`;
 *   - keys are base64url (no `;`, `,` or `=` inside);
 *   - site URLs and operator tokens must exist for every bound slug.
 */

import { ApiError } from '../util/envelope.js';

const SLUG_RE = /^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/;
const KEY_RE = /^[A-Za-z0-9_-]{16,}$/;
const TOOL_RE = /^[a-z0-9_]{2,64}$/i;
const OPERATOR_TOKEN_RE = /^op_[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}$/;

function assertSlug( slug ) {
	if ( typeof slug !== 'string' || ! SLUG_RE.test( slug ) ) {
		throw new ApiError( 'bad_request', 'Invalid gateway slug', { field: 'slug' }, 400 );
	}
	return slug;
}

function assertHttpsUrl( value, field ) {
	let url;
	try {
		url = new URL( value );
	} catch {
		throw new ApiError( 'bad_request', `Invalid ${ field }`, { field }, 400 );
	}
	if ( url.protocol !== 'https:' ) {
		throw new ApiError( 'bad_request', `${ field } must use https`, { field }, 400 );
	}
	return url.toString().replace( /\/+$/, '' );
}

export function validateOperatorToken( token ) {
	if ( typeof token !== 'string' || ! OPERATOR_TOKEN_RE.test( token ) ) {
		throw new ApiError(
			'bad_request',
			'Expected a full operator token (op_xxxx.SECRET)',
			{ field: 'operatorToken' },
			400
		);
	}
	return token;
}

export function validatePublicKey( key ) {
	if ( typeof key !== 'string' || ! KEY_RE.test( key ) ) {
		throw new ApiError( 'bad_request', 'Gateway keys are ≥16 base64url characters', { field: 'publicKey' }, 400 );
	}
	return key;
}

/**
 * @param {object} input
 * @param {string} input.slug
 * @param {string} input.siteUrl
 * @param {string} input.operatorToken Full `op_…` token (server-side only).
 * @param {string} input.publicKey     Base64url gateway key.
 * @param {'public'|'private'} [input.visibility]
 * @param {string[]} [input.allowlist]
 * @return {{ok:true, env:Record<string,string>, notes:string[]}}
 */
export function buildGatewayEnvBlock( {
	slug,
	siteUrl,
	operatorToken,
	publicKey,
	visibility = 'private',
	allowlist = [],
} ) {
	const safeSlug = assertSlug( slug );
	const url = assertHttpsUrl( siteUrl, 'siteUrl' );
	validateOperatorToken( operatorToken );
	validatePublicKey( publicKey );

	if ( ! [ 'public', 'private' ].includes( visibility ) ) {
		throw new ApiError( 'bad_request', 'visibility must be public or private', { field: 'visibility' }, 400 );
	}
	for ( const [ index, tool ] of allowlist.entries() ) {
		if ( typeof tool !== 'string' || ! TOOL_RE.test( tool ) ) {
			throw new ApiError( 'bad_request', 'Invalid tool allowlist entry', { field: `allowlist[${ index }]` }, 400 );
		}
	}

	const upper = safeSlug.replace( /-/g, '_' ).toUpperCase();
	const env = {
		GATEWAY_PUBLIC_KEYS: `${ publicKey }=${ safeSlug }`,
		[ `NVOOS_SITE_${ upper }_URL` ]: url,
		[ `NVOOS_SITE_${ upper }_TOKEN` ]: operatorToken,
	};

	const notes = [
		'Add each variable as its own row in the Velocity env editor (never replace the whole env set).',
		'A key bound to a slug without matching NVOOS_SITE_* variables is a boot error — add all three in the same edit.',
	];
	if ( visibility === 'public' ) {
		notes.push( `Append "${ safeSlug }" to the existing PUBLIC_SITE_SLUGS value (comma-separated) so /health includes it.` );
	}
	if ( allowlist.length ) {
		notes.push( `Confirm the operator token's Allowed tools cover: ${ allowlist.join( ', ' ) }.` );
	}

	return { ok: true, env, notes };
}

/**
 * Render `KEY=value` lines (one per variable). Values are emitted verbatim;
 * callers must not log the result — it contains the operator token.
 */
export function renderEnvBlock( env ) {
	return Object.entries( env )
		.map( ( [ key, value ] ) => `${ key }=${ value }` )
		.join( '\n' );
}

/**
 * Mask the secret-bearing rows for display.
 */
export function renderEnvBlockMasked( env, mask ) {
	return Object.entries( env )
		.map( ( [ key, value ] ) => {
			const sensitive = key.endsWith( '_TOKEN' ) || key === 'GATEWAY_PUBLIC_KEYS';
			const display = sensitive ? mask( value ) : value;
			return `${ key }=${ display }`;
		} )
		.join( '\n' );
}
