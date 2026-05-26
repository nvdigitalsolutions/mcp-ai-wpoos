/**
 * Authentication Adapter
 *
 * Replaces standard auth (JWT, session tokens, hardcoded API keys) with
 * WordPress nonce-based X-WP-Nonce header injection.
 *
 * Strategy:
 *   1. Find API service files (axios instances, fetch wrappers, ky instances)
 *   2. Inject X-WP-Nonce header into every outgoing request
 *   3. Add bootstrap global config reader for nonce + API URLs
 *
 * @since 1.2.0
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

import fs from 'node:fs';
import path from 'node:path';

/**
 * @typedef {object} AuthAdapterOptions
 * @property {string} slug          Addon slug (kebab-case).
 * @property {string} srcDir        The addon's src/ directory.
 * @property {string} restNamespace REST namespace for the addon (default: nvoos-{slug}/v1).
 * @property {boolean} [dryRun]
 */

/**
 * Apply the auth adapter to all API service files in src/.
 *
 * @param {AuthAdapterOptions} options
 * @returns {{patched: number, files: string[], warnings: string[]}}
 */
export function applyAuthAdapter( options ) {
	const { slug, srcDir, restNamespace, dryRun = false } = options;
	const upperSnake = slug.replace( /-/g, '_' ).toUpperCase();
	const globalName = `NVOOS_${ upperSnake }`;
	const patched    = [];
	const warnings   = [];

	// Find all files that look like API service files.
	const candidateFiles = findApiServiceFiles( srcDir );

	for ( const rel of candidateFiles ) {
		const fullPath = path.resolve( srcDir, rel );
		let content;
		try {
			content = fs.readFileSync( fullPath, 'utf-8' );
		} catch {
			continue;
		}

		const result = patchAuthInFile( content, globalName, slug );
		if ( result.changed ) {
			if ( dryRun ) {
				patched.push( rel + ' (dry-run)' );
			} else {
				fs.writeFileSync( fullPath + '.bak', content, 'utf-8' );
				fs.writeFileSync( fullPath, result.content, 'utf-8' );
				patched.push( rel );
			}
			if ( result.warning ) {
				warnings.push( `${ rel }: ${ result.warning }` );
			}
		}
	}

	// Also generate the auth helper module.
	const authHelperPath = path.resolve( srcDir, 'nvoos-auth.ts' );
	const authHelper = generateAuthHelper( slug, upperSnake, globalName );
	if ( ! dryRun ) {
		fs.writeFileSync( authHelperPath, authHelper, 'utf-8' );
	}

	return {
		patched: patched.length,
		files:   patched,
		warnings,
	};
}

function findApiServiceFiles( srcDir ) {
	// Crawl src/ for files that contain axios, fetch, or ky in service/api directories.
	const candidates = [];
	crawlDir( srcDir, srcDir, candidates, ( rel ) => {
		return rel.includes( '/api/' ) ||
			rel.includes( '/services/' ) ||
			rel.includes( '/service/' ) ||
			/axios|fetch|ky|api-client|http-client/i.test( rel );
	} );
	return candidates;
}

function crawlDir( dir, base, results, predicate ) {
	let entries;
	try {
		entries = fs.readdirSync( dir, { withFileTypes: true } );
	} catch {
		return;
	}
	for ( const entry of entries ) {
		if ( entry.name.startsWith( '.' ) || entry.name === 'node_modules' ) continue;
		const full = path.join( dir, entry.name );
		const rel  = path.relative( base, full ).replace( /\\/g, '/' );
		if ( entry.isDirectory() ) {
			crawlDir( full, base, results, predicate );
		} else if ( /\.(ts|tsx|js|jsx)$/.test( entry.name ) && predicate( rel ) ) {
			results.push( rel );
		}
	}
}

function patchAuthInFile( content, globalName, slug ) {
	let changed    = false;
	let warning    = null;
	let patched    = content;

	// Strategy 1: Find axios.create() and inject headers interceptor.
	if ( /axios\s*\.\s*create\s*\(/.test( patched ) ) {
		const newContent = patched.replace(
			/(const\s+\w+\s*=\s*axios\s*\.\s*create\s*\(\s*\{)/,
			`$1
  headers: {
    common: {
      'X-WP-Nonce': (window.${ globalName } && window.${ globalName }.nonce) || '',
    },
  },`
		);
		if ( newContent !== patched ) {
			patched = newContent;
			changed = true;
		}

		// Also inject a request interceptor for dynamic nonce refresh.
		if ( ! patched.includes( 'interceptors.request' ) ) {
			patched = patched.replace(
				/(const\s+\w+\s*=\s*axios\s*\.\s*create\s*\(\s*\{[^}]*\}\s*\))/s,
				`$1

// NV oOS auth — inject X-WP-Nonce on every request.
$1.replace(/\\)\\s*;?$/, '').match(/const\\s+(\\w+)/); const axiosInstance = $1;
if (window.${ globalName }) {
  axiosInstance.interceptors.request.use(config => {
    config.headers = config.headers || {};
    config.headers['X-WP-Nonce'] = window.${ globalName }.nonce || '';
    return config;
  });
}`
			);
			if ( patched !== content ) {
				changed = true;
				warning = 'Axios interceptor was manually injected — review the generated code';
			}
		}
	}

	// Strategy 2: Find fetch() calls and add headers.
	const fetchCalls = patched.matchAll( /fetch\s*\(\s*([^,)]+)/g );
	for ( const m of fetchCalls ) {
		const urlArg = m[ 1 ].trim();
		// Only patch internal API calls.
		if ( urlArg.includes( '/api/' ) || urlArg.includes( 'wp-json' ) || urlArg.includes( 'mcp-ai' ) ) {
			patched = patched.replace(
				`fetch(${ urlArg }`,
				`fetch(${ urlArg }, { headers: { ...getNvoosHeaders(), 'Content-Type': 'application/json' } }`
			);
			changed = true;
		}
	}

	// Strategy 3: Find ky.create() and inject hooks.
	if ( /ky\s*\.\s*create\s*\(/.test( patched ) ) {
		patched = patched.replace(
			/(ky\s*\.\s*create\s*\(\s*\{)/,
			`$1
  hooks: {
    beforeRequest: [
      request => {
        request.headers.set('X-WP-Nonce', (window.${ globalName } && window.${ globalName }.nonce) || '');
      }
    ],
  },`
		);
		if ( patched !== content ) {
			changed = true;
		}
	}

	// Strategy 4: Replace hardcoded Authorization headers.
	if ( /Authorization\s*:\s*['"`]Bearer\s+/.test( patched ) ) {
		patched = patched.replace(
			/Authorization\s*:\s*['"`]Bearer\s+[^'"`]+['"`]/g,
			`'X-WP-Nonce': (window.${ globalName } && window.${ globalName }.nonce) || ''`
		);
		changed = true;
		warning = 'Replaced hardcoded bearer token with WP nonce — verify auth flow';
	}

	return { content: patched, changed, warning };
}

function generateAuthHelper( slug, upperSnake, globalName ) {
	return `/**
 * NV oOS Auth Helper — WordPress nonce-based authentication.
 *
 * Reads nonce + API URLs from wp_localize_script() global (window.${ globalName })
 * and provides a headers factory for use with fetch/axios/ky.
 *
 * @generated by auth-adapter — do not edit manually.
 * @since 1.2.0
 */

interface NvoosConfig {
	apiUrl: string;
	proApi: string;
	baseApi: string;
	nonce: string;
	config: Record<string, unknown>;
}

function getConfig(): NvoosConfig | null {
	return ( window as any ).${ globalName } || null;
}

/**
 * Return the standard NV oOS request headers (X-WP-Nonce + Accept: application/json).
 */
export function getNvoosHeaders(): Record<string, string> {
	const cfg = getConfig();
	return {
		'X-WP-Nonce': cfg?.nonce || '',
		'Accept': 'application/json',
	};
}

/**
 * Return a full HeadersInit object including Content-Type.
 */
export function getNvoosHeadersWithContentType(): Record<string, string> {
	return {
		...getNvoosHeaders(),
		'Content-Type': 'application/json',
	};
}

/**
 * Build a URL for an NV oOS REST endpoint.
 *
 * @param namespace  REST namespace, e.g. 'nvoos-${ slug }/v1' or 'mcp-ai-pro/v1'.
 * @param path       Path relative to the namespace, e.g. '/contacts'.
 */
export function nvoosUrl( namespace: string, path: string ): string {
	const cfg = getConfig();
	if ( ! cfg ) return '';
	if ( namespace === 'mcp-ai-pro/v1' ) {
		return \`\${ cfg.proApi }\${ path }\`;
	}
	if ( namespace === 'mcp-ai/v1' ) {
		return \`\${ cfg.baseApi }\${ path }\`;
	}
	return \`\${ cfg.apiUrl.replace( /\/nvoos-${ slug }\/v1$/, '/' + namespace ) }\${ path }\`;
}

/**
 * Fetch wrapper with NV oOS auth headers baked in.
 */
export async function nvoosFetch( url: string, options: RequestInit = {} ): Promise<Response> {
	return fetch( url, {
		...options,
		headers: {
			...getNvoosHeaders(),
			...( options.headers || {} ),
		},
	} );
}
`;
}
