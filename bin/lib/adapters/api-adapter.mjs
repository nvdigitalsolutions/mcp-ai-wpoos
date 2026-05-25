/**
 * API Service Adapter
 *
 * Rewrites API service files — replaces mock-data / axios / fetch calls
 * targeting /api/* with WordPress REST calls to /wp-json/mcp-ai-pro/v1/*.
 * Updates TypeScript interfaces to match WP REST response shapes.
 *
 * Strategy:
 *   1. Parse analysis report's api_calls[] to find every endpoint.
 *   2. For each affected file, replace the API call with nvoosFetch().
 *   3. Inject WP nonce headers via the auth helper (if already present).
 *   4. Generate a typed API client module (nvoos-api.ts) with all endpoints.
 *   5. Update TypeScript interfaces with WP REST pagination/convention types.
 *
 * @since 1.2.0
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

import fs from 'node:fs';
import path from 'node:path';

/**
 * @typedef {object} ApiAdapterOptions
 * @property {string}  slug           Addon slug (kebab-case).
 * @property {string}  srcDir         The addon's src/ directory.
 * @property {string}  restNamespace  REST namespace (default: nvoos-{slug}/v1).
 * @property {string}  proNamespace   Pro REST namespace (default: mcp-ai-pro/v1).
 * @property {object}  analysis       Full analysis report from template-analyzer.
 * @property {boolean} [dryRun]
 * @property {boolean} [generateClient] Generate a typed nvoos-api.ts client module.
 */

/**
 * Apply the API adapter.
 *
 * @param {ApiAdapterOptions} options
 * @returns {{patched: number, files: string[], clientGenerated: boolean, warnings: string[], manualReview: string[]}}
 */
export function applyApiAdapter( options ) {
	const {
		slug,
		srcDir,
		restNamespace = `nvoos-${ slug }/v1`,
		proNamespace  = 'mcp-ai-pro/v1',
		analysis,
		dryRun         = false,
		generateClient = true,
	} = options;

	const upperSnake = slug.replace( /-/g, '_' ).toUpperCase();
	const globalName = `NVOOS_${ upperSnake }`;
	const patched    = [];
	const warnings   = [];
	const manualReview = [];

	// Get API calls from the analysis report.
	const apiCalls = analysis?.api_calls || [];
	if ( apiCalls.length === 0 ) {
		return {
			patched: 0,
			files: [],
			clientGenerated: false,
			warnings: [ 'No API calls found in analysis — nothing to adapt' ],
			manualReview: [],
		};
	}

	// Group calls by source file.
	const callsByFile = new Map();
	for ( const call of apiCalls ) {
		if ( ! callsByFile.has( call.file ) ) {
			callsByFile.set( call.file, [] );
		}
		callsByFile.get( call.file ).push( call );
	}

	// Build a route map: external endpoint → WP REST route.
	const routeMap = buildRouteMap( apiCalls, restNamespace, proNamespace );

	// Patch each file.
	for ( const [ rel, calls ] of callsByFile ) {
		const fullPath = path.resolve( srcDir, rel );
		let content;
		try {
			content = fs.readFileSync( fullPath, 'utf-8' );
		} catch {
			warnings.push( `Could not read ${ rel } — skipping` );
			continue;
		}

		const result = patchApiFile( content, calls, routeMap, globalName, upperSnake, rel );
		if ( result.changed ) {
			if ( dryRun ) {
				patched.push( rel + ' (dry-run)' );
			} else {
				fs.writeFileSync( fullPath + '.bak', content, 'utf-8' );
				fs.writeFileSync( fullPath, result.content, 'utf-8' );
				patched.push( rel );
			}
			warnings.push( ...result.warnings );
			manualReview.push( ...result.manualReview );
		}
	}

	// Generate the typed API client module.
	let clientGenerated = false;
	if ( generateClient && apiCalls.length > 0 ) {
		const clientCode = generateApiClient( slug, upperSnake, globalName, apiCalls, routeMap, restNamespace );
		const clientPath = path.resolve( srcDir, 'nvoos-api.ts' );

		if ( ! dryRun ) {
			fs.writeFileSync( clientPath, clientCode, 'utf-8' );
			clientGenerated = true;
		} else {
			clientGenerated = true;
			warnings.push( 'Dry run — nvoos-api.ts not written' );
		}
	}

	// Generate REST resource type definitions.
	if ( analysis?.typescript_interfaces?.length > 0 ) {
		const typesCode = generateRestTypes( analysis.typescript_interfaces, slug, upperSnake );
		const typesPath = path.resolve( srcDir, 'nvoos-types.ts' );

		if ( ! dryRun ) {
			fs.writeFileSync( typesPath, typesCode, 'utf-8' );
		} else {
			warnings.push( 'Dry run — nvoos-types.ts not written' );
		}
	}

	return {
		patched: patched.length,
		files: patched,
		clientGenerated,
		warnings,
		manualReview,
	};
}

/**
 * Build a mapping from external API endpoints to WP REST routes.
 */
function buildRouteMap( apiCalls, restNamespace, proNamespace ) {
	const map = {};

	for ( const call of apiCalls ) {
		const endpoint = call.endpoint;

		// Try to infer the WordPress REST route from the API path.
		let wpRoute = null;

		// Pattern: /api/users → /wp-json/{ns}/users
		if ( endpoint.startsWith( '/api/' ) ) {
			const resource = endpoint.replace( '/api/', '' );
			wpRoute = `${ restNamespace }/${ resource }`;
		}
		// Pattern: /v1/contacts → /wp-json/{ns}/contacts
		else if ( endpoint.startsWith( '/v1/' ) ) {
			const resource = endpoint.replace( '/v1/', '' );
			wpRoute = `${ restNamespace }/${ resource }`;
		}
		// Already a WP REST path — keep it.
		else if ( endpoint.includes( 'wp-json' ) ) {
			wpRoute = endpoint.replace( /.*wp-json\//, '' );
		}
		// Plain resource name.
		else {
			const clean = endpoint.replace( /^\//, '' );
			wpRoute = `${ restNamespace }/${ clean }`;
		}

		map[ endpoint ] = {
			wpRoute,
			namespace: restNamespace,
			method: call.method,
			file: call.file,
		};
	}

	return map;
}

/**
 * Patch a single API service file.
 */
function patchApiFile( content, calls, routeMap, globalName, upperSnake, rel ) {
	let patched    = content;
	let changed    = false;
	const warnings = [];
	const manualReview = [];

	// Strategy 1: Replace axios.{method}('/api/...') with nvoosFetch().
	for ( const call of calls ) {
		const mapping  = routeMap[ call.endpoint ];
		if ( ! mapping ) continue;

		const method   = call.method.toLowerCase();
		const wpRoute  = mapping.wpRoute;
		const ns       = mapping.namespace;
		const endpoint = call.endpoint;

		// Build the replacement patterns.
		const axiosPatterns = [
			// axios.get('/api/users')
			new RegExp( `axios\\s*\\.\\s*${ method }\\s*\\(\\s*['"\`]${ escapeRegex( endpoint ) }['"\`]`, 'gi' ),
		];

		for ( const pattern of axiosPatterns ) {
			if ( pattern.test( patched ) ) {
				const replacement = `nvoosFetch( nvoosUrl( '${ ns }', '${ stripEndpoint( endpoint, ns ) }' ), { method: '${ call.method }' } )`;
				patched = patched.replace(
					new RegExp( `axios\\s*\\.\\s*${ method }\\s*\\(\\s*['"\`]${ escapeRegex( endpoint ) }['"\`]([^)]*)\\)`, 'gi' ),
					( match, rest ) => {
						// Preserve any config/body arguments.
						if ( rest.trim() ) {
							return `${ replacement.replace( ' )', '' ) }, body: JSON.stringify${ rest } )`;
						}
						return replacement;
					}
				);
				changed = true;
			}
		}

		// Strategy 2: Replace fetch('/api/...') with nvoosFetch()
		const fetchPatterns = [
			new RegExp( `fetch\\s*\\(\\s*['"\`]${ escapeRegex( endpoint ) }['"\`]`, 'gi' ),
		];
		for ( const pattern of fetchPatterns ) {
			if ( pattern.test( patched ) ) {
				patched = patched.replace(
					new RegExp( `fetch\\s*\\(\\s*['"\`]${ escapeRegex( endpoint ) }['"\`]([^)]*)\\)`, 'gi' ),
					( match, rest ) => {
						if ( rest.trim() ) {
							return `nvoosFetch( nvoosUrl( '${ ns }', '${ stripEndpoint( endpoint, ns ) }' ), { method: '${ call.method }', ...${ rest.trim() } })`;
						}
						return `nvoosFetch( nvoosUrl( '${ ns }', '${ stripEndpoint( endpoint, ns ) }' ), { method: '${ call.method }' } )`;
					}
				);
				changed = true;
			}
		}

		// Strategy 3: Replace ky.{method}('/api/...')
		const kyPatterns = [
			new RegExp( `ky\\s*\\.\\s*${ method }\\s*\\(\\s*['"\`]${ escapeRegex( endpoint ) }['"\`]`, 'gi' ),
		];
		for ( const pattern of kyPatterns ) {
			if ( pattern.test( patched ) ) {
				patched = patched.replace(
					new RegExp( `ky\\s*\\.\\s*${ method }\\s*\\(\\s*['"\`]${ escapeRegex( endpoint ) }['"\`]([^)]*)\\)`, 'gi' ),
					( match, rest ) => {
						if ( rest.trim() ) {
							return `nvoosFetch( nvoosUrl( '${ ns }', '${ stripEndpoint( endpoint, ns ) }' ), { method: '${ call.method }', json: ${ rest.trim() } })`;
						}
						return `nvoosFetch( nvoosUrl( '${ ns }', '${ stripEndpoint( endpoint, ns ) }' ), { method: '${ call.method }' } )`;
					}
				);
				changed = true;
			}
		}

		// Strategy 4: Replace TanStack Query URL
		if ( call.source === 'tanstack-query' ) {
			const tanstackPattern = new RegExp(
				`(useQuery|useMutation)\\s*\\(\\s*\\{[^}]*url\\s*:\\s*['"\`]${ escapeRegex( endpoint ) }['"\`]`,
				'gi'
			);
			if ( tanstackPattern.test( patched ) ) {
				patched = patched.replace(
					new RegExp( `(useQuery|useMutation)\\s*\\(\\s*\\{[^}]*url\\s*:\\s*['"\`]${ escapeRegex( endpoint ) }['"\`]([^}]*)\\}[^)]*\\)`, 'gi' ),
					( match, hookType, rest ) => {
						return `nvoos${ hookType }( '${ ns }', '${ stripEndpoint( endpoint, ns ) }'${ rest ? ', ' + rest.trim() : '' } )`;
					}
				);
				changed = true;
				manualReview.push( `${ rel }: TanStack Query hook replaced — verify the nvoosQuery/nvoosMutation wrapper` );
			}
		}
	}

	// Inject nvoosFetch + nvoosUrl imports if we made changes and they're not already present.
	if ( changed && ! patched.includes( 'nvoosFetch' ) ) {
		patched = `import { nvoosFetch, nvoosUrl } from './nvoos-auth';\n${ patched }`;
	}

	// Remove unused axios/ky/fetch imports if fully migrated.
	// (Soft — just warn, don't auto-remove to avoid breaking non-API usage.)
	const stillHasAxios  = /axios\s*\.\s*(get|post|put|patch|delete)/i.test( patched );
	const stillHasKy     = /ky\s*\.\s*(get|post|put|patch|delete)/i.test( patched );
	if ( changed && ! stillHasAxios && content.includes( 'import axios' ) ) {
		warnings.push( `${ rel }: axios import may be unused — review and remove if no remaining calls` );
	}

	return { content: patched, changed, warnings, manualReview };
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function stripEndpoint( endpoint, namespace ) {
	const nsPart = namespace.split( '/' ).pop() || 'v1';
	// /api/users → /users
	let stripped = endpoint
		.replace( /^\/api/, '' )
		.replace( /^\/v1/, '' )
		.replace( /^\//, '' );
	return stripped;
}

function escapeRegex( str ) {
	return str.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

/**
 * Generate a typed API client module with all discovered endpoints.
 */
function generateApiClient( slug, upperSnake, globalName, apiCalls, routeMap, restNamespace ) {
	const resourceGroups = groupByResource( apiCalls );
	const lines = [];

	lines.push( `/**` );
	lines.push( ` * NV oOS ${ slug } — Auto-generated API client.` );
	lines.push( ` *` );
	lines.push( ` * @generated by api-adapter.mjs — edits may be overwritten.` );
	lines.push( ` * @since 0.1.0` );
	lines.push( ` */` );
	lines.push( '' );
	lines.push( `import { nvoosFetch, nvoosUrl, getNvoosHeaders } from './nvoos-auth';` );
	lines.push( '' );

	// Generate typed fetch functions per resource.
	for ( const [ resource, calls ] of resourceGroups ) {
		const pascalResource = toPascalCase( resource );
		const resourcePath   = calls[ 0 ]?.endpoint
			? stripEndpoint( calls[ 0 ].endpoint, restNamespace )
			: resource;

		lines.push( `// ── ${ pascalResource } ──` );
		lines.push( '' );

		// GET (list)
		if ( calls.some( c => c.method === 'GET' ) ) {
			lines.push( `/** Fetch ${ resource } list. */` );
			lines.push( `export async function fetch${ pascalResource }( params?: Record<string, string> ): Promise<any> {` );
			lines.push( `  const qs = params ? '?' + new URLSearchParams( params ).toString() : '';` );
			lines.push( `  return nvoosFetch( nvoosUrl( '${ restNamespace }', '/${ resourcePath }' + qs ) );` );
			lines.push( `}` );
			lines.push( '' );
		}

		// GET (single by ID)
		lines.push( `/** Fetch ${ resource } by ID. */` );
		lines.push( `export async function fetch${ pascalResource }ById( id: number | string ): Promise<any> {` );
		lines.push( `  return nvoosFetch( nvoosUrl( '${ restNamespace }', '/${ resourcePath }/' + id ) );` );
		lines.push( `}` );
		lines.push( '' );

		// POST (create)
		if ( calls.some( c => c.method === 'POST' ) ) {
			lines.push( `/** Create a new ${ resource }. */` );
			lines.push( `export async function create${ pascalResource }( data: Record<string, unknown> ): Promise<any> {` );
			lines.push( `  return nvoosFetch( nvoosUrl( '${ restNamespace }', '/${ resourcePath }' ), {` );
			lines.push( `    method: 'POST',` );
			lines.push( `    body: JSON.stringify( data ),` );
			lines.push( `    headers: { 'Content-Type': 'application/json' },` );
			lines.push( `  } );` );
			lines.push( `}` );
			lines.push( '' );
		}

		// PUT (update)
		if ( calls.some( c => [ 'PUT', 'PATCH' ].includes( c.method ) ) ) {
			lines.push( `/** Update a ${ resource }. */` );
			lines.push( `export async function update${ pascalResource }( id: number | string, data: Record<string, unknown> ): Promise<any> {` );
			lines.push( `  return nvoosFetch( nvoosUrl( '${ restNamespace }', '/${ resourcePath }/' + id ), {` );
			lines.push( `    method: 'PUT',` );
			lines.push( `    body: JSON.stringify( data ),` );
			lines.push( `    headers: { 'Content-Type': 'application/json' },` );
			lines.push( `  } );` );
			lines.push( `}` );
			lines.push( '' );
		}

		// DELETE
		if ( calls.some( c => c.method === 'DELETE' ) ) {
			lines.push( `/** Delete a ${ resource }. */` );
			lines.push( `export async function delete${ pascalResource }( id: number | string ): Promise<any> {` );
			lines.push( `  return nvoosFetch( nvoosUrl( '${ restNamespace }', '/${ resourcePath }/' + id ), {` );
			lines.push( `    method: 'DELETE',` );
			lines.push( `  } );` );
			lines.push( `}` );
			lines.push( '' );
		}
	}

	return lines.join( '\n' );
}

/**
 * Group API calls by resource name extracted from endpoint.
 */
function groupByResource( apiCalls ) {
	const groups = new Map();

	for ( const call of apiCalls ) {
		let resource = 'resources';

		// /api/users/123 → users
		// /api/users → users
		const parts = call.endpoint.replace( /^\//, '' ).split( '/' );
		if ( parts.length >= 2 ) {
			resource = parts[ parts.length - 2 ] === parts[ parts.length - 1 ]
				? parts[ parts.length - 1 ]
				: parts[ parts.length - 2 ] || parts[ 1 ] || resource;
		} else if ( parts.length === 2 ) {
			resource = parts[ 1 ];
		}

		// Singularize simple plurals.
		if ( resource.endsWith( 'ies' ) ) {
			resource = resource.slice( 0, -3 ) + 'y';
		} else if ( resource.endsWith( 'ses' ) || resource.endsWith( 'xes' ) || resource.endsWith( 'ches' ) || resource.endsWith( 'shes' ) ) {
			resource = resource.slice( 0, -2 );
		} else if ( resource.endsWith( 's' ) && ! resource.endsWith( 'ss' ) ) {
			resource = resource.slice( 0, -1 );
		}

		if ( ! groups.has( resource ) ) {
			groups.set( resource, [] );
		}
		groups.get( resource ).push( call );
	}

	return groups;
}

/**
 * Generate WP REST response type definitions from TypeScript interfaces.
 */
function generateRestTypes( interfaces, slug, upperSnake ) {
	const lines = [];

	lines.push( `/**` );
	lines.push( ` * NV oOS ${ slug } — WP REST response types.` );
	lines.push( ` *` );
	lines.push( ` * @generated by api-adapter.mjs` );
	lines.push( ` * @since 0.1.0` );
	lines.push( ` */` );
	lines.push( '' );

	// WP REST collection response wrapper.
	lines.push( `/** Standard WP REST API collection response. */` );
	lines.push( `export interface WpRestCollection<T> {` );
	lines.push( `  data: T[];` );
	lines.push( `  total: number;` );
	lines.push( `  totalPages: number;` );
	lines.push( `  _links?: Record<string, Array<{ href: string }>>;` );
	lines.push( `}` );
	lines.push( '' );

	// WP REST single response wrapper.
	lines.push( `/** Standard WP REST API single-resource response. */` );
	lines.push( `export interface WpRestSingle<T> {` );
	lines.push( `  data: T;` );
	lines.push( `  _links?: Record<string, Array<{ href: string }>>;` );
	lines.push( `}` );
	lines.push( '' );

	// Mirror each TS interface.
	for ( const iface of interfaces ) {
		lines.push( `/** Auto-generated from template interface: ${ iface.name } */` );
		lines.push( `export interface ${ iface.name } {` );

		for ( const field of ( iface.fields || [] ) ) {
			const optional = field.required ? '' : '?';
			const tsType   = field.inferred || field.tsType || 'string';
			lines.push( `  ${ field.name }${ optional }: ${ tsType };` );
		}

		lines.push( `}` );
		lines.push( '' );

		// Generate WP REST wrapper types.
		lines.push( `export type ${ iface.name }Collection = WpRestCollection<${ iface.name }>;` );
		lines.push( `export type ${ iface.name }Single = WpRestSingle<${ iface.name }>;` );
		lines.push( '' );
	}

	return lines.join( '\n' );
}

function toPascalCase( str ) {
	return str
		.split( /[-_]/ )
		.map( w => w.charAt( 0 ).toUpperCase() + w.slice( 1 ) )
		.join( '' );
}
