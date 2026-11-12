/**
 * Framework-agnostic API router.
 *
 * `handle({ method, path, headers, body, ip })` → `{ status, body }`.
 * The node:http adapter in scripts/serve.mjs does transport only; all
 * routing, auth, rate limiting and envelopes live here so they are
 * testable without sockets.
 *
 * v1 auth: a single admin bearer token (`EXCHANGE_ADMIN_TOKEN`). Mock mode
 * without a token is open for local development only; live mode fails
 * closed. Ownership scoping is structured in the service but not enforced
 * until the multi-owner phase (documented limitation).
 */

import { ApiError, fail, ok, statusForCode } from '../util/envelope.js';
import { constantTimeEqual } from '../util/secrets.js';

export function createRateLimiter( { windowMs = 60_000, max = 120 } = {} ) {
	const buckets = new Map();

	function check( key, now = Date.now() ) {
		const bucket = buckets.get( key );
		if ( ! bucket || now >= bucket.resetAt ) {
			buckets.set( key, { count: 1, resetAt: now + windowMs } );
			return { allowed: true };
		}
		bucket.count += 1;
		if ( bucket.count > max ) {
			return { allowed: false, retryAfterMs: bucket.resetAt - now };
		}
		return { allowed: true };
	}

	return { check, size: () => buckets.size };
}

function getHeader( headers, name ) {
	if ( ! headers ) {
		return '';
	}
	const target = name.toLowerCase();
	for ( const [ key, value ] of Object.entries( headers ) ) {
		if ( key.toLowerCase() === target ) {
			return String( value );
		}
	}
	return '';
}

export function createApiRouter( {
	config,
	service,
	seeds = [],
	version = '0.1.0',
	limiter = createRateLimiter( config.rateLimit ),
} ) {
	function authorize( headers ) {
		if ( config.mode === 'mock' && ! config.adminToken ) {
			return true;
		}
		const authorization = getHeader( headers, 'authorization' );
		const token = authorization.startsWith( 'Bearer ' ) ? authorization.slice( 7 ).trim() : '';
		return constantTimeEqual( token, config.adminToken );
	}

	async function handle( request ) {
		const { method = 'GET', path = '/', headers = {}, body = null, ip = 'unknown' } = request;

		try {
			const url = new URL( path, 'http://exchange.local' );
			const segments = url.pathname.split( '/' ).filter( Boolean );

			if ( segments[ 0 ] !== 'api' || segments[ 1 ] !== 'v1' ) {
				throw new ApiError( 'not_found', 'Unknown API path', { path: url.pathname }, 404 );
			}

			// Health is public — probes must work before credentials exist.
			if ( method === 'GET' && url.pathname === '/api/v1/health' ) {
				return { status: 200, body: ok( { service: 'nvoos-service-exchange', version, mode: config.mode } ) };
			}

			const rate = limiter.check( `${ ip }:${ segments.slice( 0, 3 ).join( '/' ) }` );
			if ( ! rate.allowed ) {
				return {
					status: 429,
					body: fail( 'rate_limited', 'Too many requests', {
						retryAfterMs: rate.retryAfterMs,
					} ),
				};
			}

			if ( ! authorize( headers ) ) {
				return { status: 401, body: fail( 'unauthorized', 'A valid admin bearer token is required' ) };
			}

			// GET /api/v1/blueprints
			if ( method === 'GET' && url.pathname === '/api/v1/blueprints' ) {
				return { status: 200, body: ok( seeds ) };
			}

			// GET /api/v1/catalogue
			if ( method === 'GET' && url.pathname === '/api/v1/catalogue' ) {
				return { status: 200, body: ok( service.catalogue() ) };
			}

			// GET/POST /api/v1/estates
			if ( url.pathname === '/api/v1/estates' ) {
				if ( method === 'GET' ) {
					return { status: 200, body: ok( service.list() ) };
				}
				if ( method === 'POST' ) {
					const estate = await service.createEstate( {
						blueprint: body?.blueprint ?? null,
						blueprintSlug: body?.blueprint_slug ?? null,
						owner: body?.owner ?? null,
					} );
					return { status: 201, body: ok( service.summary( estate ) ) };
				}
			}

			// /api/v1/estates/:slug[/action]
			if ( segments.length >= 4 && segments[ 2 ] === 'estates' ) {
				const slug = decodeURIComponent( segments[ 3 ] );
				const action = segments[ 4 ] ?? null;

				if ( method === 'GET' && ! action ) {
					const estate = service.get( slug );
					if ( ! estate ) {
						throw new ApiError( 'not_found', `Estate "${ slug }" not found`, { slug }, 404 );
					}
					const reveal = url.searchParams.get( 'reveal' ) === '1';
					return { status: 200, body: ok( service.detail( estate, { reveal } ) ) };
				}

				if ( method === 'POST' && action === 'advance' ) {
					const result = await service.advance( slug );
					return { status: 200, body: ok( { state: result.state, steps: result.steps, advanced: result.advanced } ) };
				}

				if ( method === 'POST' && action === 'teardown' ) {
					const result = await service.teardown( slug, {
						confirm: body?.confirm,
						slugEcho: body?.slug,
					} );
					return { status: 200, body: ok( result ) };
				}

				if ( method === 'POST' && action === 'commerce' ) {
					const summary = await service.linkEstatePrice( slug, body?.stripe_price_id );
					return { status: 200, body: ok( summary ) };
				}

				if ( method === 'GET' && action === 'envblock' ) {
					const reveal = url.searchParams.get( 'reveal' ) === '1';
					const estate = service.get( slug );
					if ( ! estate ) {
						throw new ApiError( 'not_found', `Estate "${ slug }" not found`, { slug }, 404 );
					}
					return { status: 200, body: ok( service.envBlockFor( estate, { reveal } ) ) };
				}
			}

			throw new ApiError( 'not_found', 'Unknown API route', { path: url.pathname, method }, 404 );
		} catch ( error ) {
			if ( error instanceof ApiError ) {
				return { status: error.status, body: error.toEnvelope() };
			}
			// Never leak internals (stack traces can contain paths/env names).
			return {
				status: 500,
				body: fail( 'internal_error', 'Unexpected control-plane error' ),
			};
		}
	}

	return { handle };
}
