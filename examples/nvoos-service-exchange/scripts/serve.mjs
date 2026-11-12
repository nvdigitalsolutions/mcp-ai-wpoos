#!/usr/bin/env node
/**
 * NV oOS Service Exchange — Velocity entry server.
 *
 * Zero-dependency HTTP server:
 *   - serves the Vite build from dist/ with SPA fallback;
 *   - mounts the control-plane API at /api/* (routing lives in
 *     server/routes/api.js — this file is transport only);
 *   - loads seed blueprints from blueprints/ at boot (invalid seeds are
 *     skipped with a warning, never a boot failure);
 *   - runs an advisory reconcile loop (default 15 min, disable with
 *     EXCHANGE_RECONCILE_MS=0).
 *
 * Velocity injects PORT. Security posture: no-store on API responses,
 * nosniff + referrer-policy everywhere, request logs contain method/path/
 * status only (never headers or bodies).
 */

import { createServer } from 'node:http';
import { readFile, stat, readdir } from 'node:fs/promises';
import { extname, join, normalize, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadConfig } from '../server/config.js';
import { validateBlueprint } from '../server/blueprint/validate.js';
import { createEstateStore } from '../server/estates/store.js';
import { createEstateService } from '../server/estates/service.js';
import { createProvisionRunner } from '../server/provision/runner.js';
import { createApiRouter } from '../server/routes/api.js';
import { createCloudwaysClient } from '../server/clients/cloudways.js';
import { createGithubClient } from '../server/clients/github.js';
import { createGatewayClient } from '../server/clients/gateway.js';

const MIME = {
	'.html': 'text/html; charset=utf-8',
	'.js': 'text/javascript; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.json': 'application/json; charset=utf-8',
	'.svg': 'image/svg+xml',
	'.png': 'image/png',
	'.jpg': 'image/jpeg',
	'.webp': 'image/webp',
	'.ico': 'image/x-icon',
	'.woff2': 'font/woff2',
	'.txt': 'text/plain; charset=utf-8',
};

const BASE_HEADERS = {
	'X-Content-Type-Options': 'nosniff',
	'Referrer-Policy': 'strict-origin-when-cross-origin',
	'Strict-Transport-Security': 'max-age=86400',
};

export async function loadSeeds( dir ) {
	let names = [];
	try {
		names = ( await readdir( dir ) ).filter(
			( name ) => name.endsWith( '.json' ) && name !== 'schema.json'
		);
	} catch {
		return [];
	}
	const seeds = [];
	for ( const name of names ) {
		try {
			const raw = await readFile( join( dir, name ), 'utf8' );
			const result = validateBlueprint( JSON.parse( raw ) );
			if ( result.ok ) {
				seeds.push( result.blueprint );
			} else {
				console.warn( `[exchange] skipping invalid seed ${ name }: ${ result.errors[ 0 ]?.message }` );
			}
		} catch ( error ) {
			console.warn( `[exchange] skipping unreadable seed ${ name }: ${ error.message }` );
		}
	}
	return seeds;
}

function readBody( request, limitBytes = 1_000_000 ) {
	return new Promise( ( resolve, reject ) => {
		let size = 0;
		const chunks = [];
		request.on( 'data', ( chunk ) => {
			size += chunk.length;
			if ( size > limitBytes ) {
				reject( new Error( 'request body too large' ) );
				request.destroy();
				return;
			}
			chunks.push( chunk );
		} );
		request.on( 'end', () => resolve( Buffer.concat( chunks ).toString( 'utf8' ) ) );
		request.on( 'error', reject );
	} );
}

function sendJson( response, status, payload ) {
	response.writeHead( status, {
		...BASE_HEADERS,
		'Content-Type': 'application/json; charset=utf-8',
		'Cache-Control': 'no-store',
	} );
	response.end( JSON.stringify( payload ) );
}

export function createExchangeServer( options = {} ) {
	const config = options.config ?? loadConfig();
	const distDir = options.distDir ?? fileURLToPath( new URL( '../dist', import.meta.url ) );

	const clients = options.clients ?? {
		cloudways: createCloudwaysClient( { config } ),
		github: createGithubClient( { config } ),
		gateway: createGatewayClient( { config } ),
	};
	const store = options.store ?? createEstateStore( { eventsFile: config.jobsFile } );
	const provisioner = createProvisionRunner( { config, store, clients } );
	const service = createEstateService( { config, store, provisioner, clients, seeds: options.seeds ?? [] } );
	const router = createApiRouter( { config, service, seeds: options.seeds ?? [], version: options.version ?? '0.1.0' } );

	const server = createServer( async ( request, response ) => {
		const startedAt = Date.now();
		const url = new URL( request.url, 'http://exchange.local' );
		const ip = request.socket.remoteAddress ?? 'unknown';

		const finish = ( status ) => {
			console.log(
				`[exchange] ${ request.method } ${ url.pathname } ${ status } ${ Date.now() - startedAt }ms`
			);
		};

		try {
			if ( url.pathname.startsWith( '/api/' ) ) {
				let body = null;
				if ( request.method !== 'GET' && request.method !== 'HEAD' ) {
					const raw = await readBody( request );
					if ( raw ) {
						try {
							body = JSON.parse( raw );
						} catch {
							sendJson( response, 400, {
								ok: false,
								error: { code: 'bad_request', message: 'Invalid JSON body' },
							} );
							finish( 400 );
							return;
						}
					}
				}
				const result = await router.handle( {
					method: request.method,
					path: url.pathname + url.search,
					headers: request.headers,
					body,
					ip,
				} );
				sendJson( response, result.status, result.body );
				finish( result.status );
				return;
			}

			if ( request.method !== 'GET' && request.method !== 'HEAD' ) {
				response.writeHead( 405, { ...BASE_HEADERS, Allow: 'GET, HEAD' } );
				response.end( 'Method Not Allowed' );
				finish( 405 );
				return;
			}

			// Static branch with SPA fallback.
			const requested = decodeURIComponent( url.pathname );
			let filePath = normalize( join( distDir, requested ) );
			if ( ! filePath.startsWith( distDir ) ) {
				response.writeHead( 403, BASE_HEADERS );
				response.end( 'Forbidden' );
				finish( 403 );
				return;
			}

			let fileStat = null;
			try {
				fileStat = await stat( filePath );
			} catch {
				fileStat = null;
			}
			if ( ! fileStat || fileStat.isDirectory() ) {
				filePath = join( distDir, 'index.html' );
				try {
					fileStat = await stat( filePath );
				} catch {
					response.writeHead( 503, { ...BASE_HEADERS, 'Content-Type': 'text/plain; charset=utf-8' } );
					response.end( 'Exchange UI is not built. Run `npm run build` (or use `npm run dev`).' );
					finish( 503 );
					return;
				}
			}

			const extension = extname( filePath ).toLowerCase();
			const contentType = MIME[ extension ] ?? 'application/octet-stream';
			const isEntry = filePath.endsWith( 'index.html' );
			const isHashedAsset = requested.startsWith( '/assets/' );
			const headers = {
				...BASE_HEADERS,
				'Content-Type': contentType,
				'Cache-Control': isHashedAsset
					? 'public, max-age=31536000, immutable'
					: isEntry
						? 'no-cache'
						: 'public, max-age=86400',
			};
			response.writeHead( 200, headers );
			response.end( await readFile( filePath ) );
			finish( 200 );
		} catch ( error ) {
			console.error( `[exchange] request failed: ${ error.message }` );
			if ( ! response.headersSent ) {
				sendJson( response, 500, {
					ok: false,
					error: { code: 'internal_error', message: 'Unexpected server error' },
				} );
			} else {
				response.end();
			}
			finish( 500 );
		}
	} );

	return { server, config, service, router, store };
}

export async function startExchangeServer( options = {} ) {
	const config = options.config ?? loadConfig();
	const seeds = options.seeds ?? ( await loadSeeds( fileURLToPath( new URL( '../blueprints', import.meta.url ) ) ) );
	const app = createExchangeServer( { ...options, config, seeds } );

	const port = config.port;
	await new Promise( ( resolve ) => app.server.listen( port, resolve ) );
	console.log(
		`[exchange] listening on :${ port } mode=${ config.mode } seeds=${ seeds.length } version=0.1.0`
	);

	const reconcileMs = Number( process.env.EXCHANGE_RECONCILE_MS ?? 900_000 );
	if ( reconcileMs > 0 ) {
		const timer = setInterval( () => {
			app.service.reconcileAll().catch( ( error ) => {
				console.error( `[exchange] reconcile loop error: ${ error.message }` );
			} );
		}, reconcileMs );
		timer.unref?.();
		app.reconcileTimer = timer;
	}

	return app;
}

const isMain =
	Boolean( process.argv[ 1 ] ) && resolve( process.argv[ 1 ] ) === fileURLToPath( import.meta.url );
if ( isMain || process.env.EXCHANGE_AUTOSTART === '1' ) {
	startExchangeServer().catch( ( error ) => {
		console.error( `[exchange] failed to start: ${ error.message }` );
		process.exit( 1 );
	} );
}
