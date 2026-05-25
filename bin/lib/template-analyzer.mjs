/**
 * Template Analyzer — AST-based React/TypeScript project analysis.
 *
 * Statically analyses a React project directory and produces a structured
 * gap report (`template-analysis.json`) that the adapter layer and manifest
 * generator consume.
 *
 * Uses the TypeScript compiler API (`typescript` package, already a devDep)
 * for AST traversal of .tsx/.ts/.jsx/.js files. No new dependencies.
 *
 * @since 1.2.0  Imported-template ingestion (Toolkit SPA Blueprint §21–§26)
 *
 * Usage:
 *   import { analyzeTemplate } from './bin/lib/template-analyzer.mjs';
 *   const report = analyzeTemplate('/path/to/react-project');
 *
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const require = createRequire( import.meta.url );

/**
 * Try to load the `typescript` module from the project root.
 * Falls back gracefully if not installed.
 */
function loadTypeScript() {
	try {
		return require( 'typescript' );
	} catch {
		return null;
	}
}

/** @type {import('typescript')|null} */
let _ts = null;

function getTS() {
	if ( ! _ts ) {
		_ts = loadTypeScript();
	}
	return _ts;
}

const REACT_FILE_EXTS = /\.(tsx|jsx|ts|js)$/;
const STYLE_FILE_EXTS = /\.(css|scss|less)$/;
const CONFIG_FILES     = /(package\.json|tsconfig\.json|\.eslintrc|vite\.config|webpack\.config|next\.config)/;

/** Directories to skip during traversal */
const SKIP_DIRS = new Set( [
	'node_modules', '.git', '.next', 'dist', 'build',
	'coverage', '__tests__', '.cache', '.vscode', '.idea',
] );

// ---------------------------------------------------------------------------
// File discovery
// ---------------------------------------------------------------------------

/**
 * Recursively walk a directory, yielding relative file paths.
 */
function* walkDir( dir, base = dir ) {
	let entries;
	try {
		entries = fs.readdirSync( dir, { withFileTypes: true } );
	} catch {
		return;
	}
	for ( const entry of entries ) {
		if ( entry.name.startsWith( '.' ) && entry.name !== '.env' ) {
			continue;
		}
		const full = path.join( dir, entry.name );
		const rel  = path.relative( base, full ).replace( /\\/g, '/' );
		if ( entry.isDirectory() ) {
			if ( SKIP_DIRS.has( entry.name ) ) {
				continue;
			}
			yield* walkDir( full, base );
		} else {
			yield rel;
		}
	}
}

// ---------------------------------------------------------------------------
// Package.json analysis (no AST needed)
// ---------------------------------------------------------------------------

function analyzePackageJson( rootDir ) {
	const pkgPath = path.join( rootDir, 'package.json' );
	if ( ! fs.existsSync( pkgPath ) ) {
		return null;
	}
	let pkg;
	try {
		pkg = JSON.parse( fs.readFileSync( pkgPath, 'utf-8' ) );
	} catch {
		return null;
	}

	const allDeps = {
		...( pkg.dependencies || {} ),
		...( pkg.devDependencies || {} ),
	};

	// Detect technology stack from dependencies.
	const techStack = {
		framework:     'react',
		version_range: allDeps.react || 'unknown',
		router:        detectRouter( allDeps ),
		state:         detectStateManagement( allDeps ),
		css:           detectCSS( allDeps ),
		ui_library:    detectUILibrary( allDeps ),
		bundler:       detectBundler( pkg, allDeps ),
		i18n_lib:      detectI18n( allDeps ),
	};

	// Detect license.
	let license = 'UNKNOWN';
	if ( typeof pkg.license === 'string' ) {
		license = pkg.license;
	} else if ( pkg.license && typeof pkg.license === 'object' ) {
		license = pkg.license.type || 'UNKNOWN';
	}
	// Also check for LICENSE file.
	const licenseFile = findLicenseFile( rootDir );
	if ( licenseFile ) {
		license = licenseFile;
	}

	return {
		name:        pkg.name || path.basename( rootDir ),
		version:     pkg.version || '0.0.0',
		license,
		techStack,
		scripts:     pkg.scripts || {},
		allDeps,
	};
}

function detectRouter( deps ) {
	if ( deps[ 'react-router-dom' ] ) return 'react-router-dom';
	if ( deps[ 'react-router' ] )    return 'react-router';
	if ( deps[ '@tanstack/react-router' ] ) return '@tanstack/react-router';
	if ( deps.next )                 return 'next/navigation';
	if ( deps[ 'react-navigation' ] ) return 'react-navigation';
	return 'none-detected';
}

function detectStateManagement( deps ) {
	const found = [];
	if ( deps.redux || deps[ '@reduxjs/toolkit' ] ) found.push( 'redux' );
	if ( deps.zustand )   found.push( 'zustand' );
	if ( deps.jotai )     found.push( 'jotai' );
	if ( deps.recoil )    found.push( 'recoil' );
	if ( deps.mobx || deps[ 'mobx-react' ] ) found.push( 'mobx' );
	if ( deps[ '@tanstack/react-query' ] || deps[ 'react-query' ] ) found.push( 'react-query' );
	if ( deps.swr ) found.push( 'swr' );
	return found.length ? found.join( '+' ) : 'context-only';
}

function detectCSS( deps ) {
	if ( deps.tailwindcss )           return 'tailwind';
	if ( deps[ 'styled-components' ] ) return 'styled-components';
	if ( deps[ '@emotion/react' ] )   return 'emotion';
	if ( deps.sass || deps[ 'node-sass' ] ) return 'scss';
	if ( deps.less )                  return 'less';
	if ( deps[ '@mui/material' ] || deps[ '@mui/system' ] ) return 'mui-system';
	return 'plain-css';
}

function detectUILibrary( deps ) {
	if ( deps[ '@mui/material' ] )        return '@mui/material';
	if ( deps[ 'antd' ] )                 return 'antd';
	if ( deps[ '@chakra-ui/react' ] )     return '@chakra-ui/react';
	if ( deps[ 'react-bootstrap' ] )      return 'react-bootstrap';
	if ( deps[ '@mantine/core' ] )        return '@mantine/core';
	if ( deps[ '@radix-ui/themes' ] )     return '@radix-ui/themes';
	if ( deps[ 'shadcn-ui' ] || deps[ '@radix-ui/react-dialog' ] ) return 'shadcn-ui';
	if ( deps[ 'flowbite-react' ] )       return 'flowbite-react';
	return 'none-detected';
}

function detectBundler( pkg, deps ) {
	if ( deps.vite || pkg.scripts?.dev?.includes( 'vite' ) ) return 'vite';
	if ( deps.webpack || deps[ 'react-scripts' ] )           return 'webpack';
	if ( deps[ '@rsbuild/core' ] )                            return 'rsbuild';
	if ( deps.parcel )                                        return 'parcel';
	if ( deps.turbo )                                         return 'turbo';
	if ( deps.next )                                          return 'next';
	return 'unknown';
}

function detectI18n( deps ) {
	if ( deps[ 'react-i18next' ] || deps[ 'i18next' ] )       return 'react-i18next';
	if ( deps[ 'react-intl' ] || deps[ '@formatjs/intl' ] )   return 'react-intl';
	if ( deps[ '@wordpress/i18n' ] )                          return '@wordpress/i18n';
	if ( deps[ 'next-intl' ] )                                return 'next-intl';
	return 'none-detected';
}

function findLicenseFile( rootDir ) {
	const names = [ 'LICENSE', 'LICENSE.md', 'LICENSE.txt', 'LICENCE', 'COPYING' ];
	for ( const name of names ) {
		const p = path.join( rootDir, name );
		if ( fs.existsSync( p ) ) {
			const content = fs.readFileSync( p, 'utf-8' ).substring( 0, 1024 );
			if ( content.includes( 'MIT' ) ) return 'MIT';
			if ( content.includes( 'Apache' ) ) return 'Apache-2.0';
			if ( content.includes( 'GNU GENERAL PUBLIC LICENSE' ) ) {
				if ( content.includes( 'Version 3' ) ) return 'GPL-3.0';
				if ( content.includes( 'Version 2' ) ) return 'GPL-2.0';
				return 'GPL';
			}
			if ( content.includes( 'BSD' ) ) return 'BSD';
			if ( content.includes( 'ISC' ) ) return 'ISC';
			return 'other';
		}
	}
	return null;
}

// ---------------------------------------------------------------------------
// AST-based analysis — TypeScript Compiler API
// ---------------------------------------------------------------------------

/**
 * Find the entry point of a React project.
 */
function findEntryPoints( rootDir, pkg ) {
	// Check package.json scripts and main/module fields.
	const scripts = pkg?.scripts || {};
	const candidates = [];

	// Common entry patterns.
	const commonEntries = [
		'src/index.tsx', 'src/index.jsx', 'src/index.ts', 'src/index.js',
		'src/main.tsx', 'src/main.jsx', 'src/App.tsx', 'src/App.jsx',
		'index.tsx', 'index.jsx', 'src/index.ts', 'src/index.js',
		'pages/index.tsx', 'pages/index.jsx', 'pages/_app.tsx', 'pages/_app.jsx',
		'app/layout.tsx', 'app/page.tsx',
	];

	for ( const entry of commonEntries ) {
		const full = path.join( rootDir, entry );
		if ( fs.existsSync( full ) ) {
			candidates.push( { file: entry, isDefault: true } );
		}
	}

	// Check if tsconfig or vite config overrides entry.
	const tsconfigPath = path.join( rootDir, 'tsconfig.json' );
	if ( fs.existsSync( tsconfigPath ) ) {
		try {
			const tsconfig = JSON.parse( fs.readFileSync( tsconfigPath, 'utf-8' ) );
			const include = tsconfig.include;
			if ( Array.isArray( include ) ) {
				for ( const pattern of include ) {
					if ( pattern.includes( 'index' ) || pattern.includes( 'main' ) ) {
						// Already covered by commonEntries above.
					}
				}
			}
		} catch { /* ignore malformed tsconfig */ }
	}

	return candidates.length ? candidates : [ { file: 'unknown', isDefault: false } ];
}

/**
 * Scan source files for API call patterns without full AST (regex-based,
 * fast, and works without TypeScript compiler for JS files).
 *
 * Returns an array of discovered API call sites.
 */
function scanApiCalls( rootDir ) {
	const calls = [];
	const seen   = new Set();

	for ( const rel of walkDir( rootDir ) ) {
		if ( ! REACT_FILE_EXTS.test( rel ) ) continue;
		// Skip test files.
		if ( rel.includes( '__tests__' ) || rel.includes( '.test.' ) || rel.includes( '.spec.' ) ) continue;

		const fullPath = path.join( rootDir, rel );
		let content;
		try {
			content = fs.readFileSync( fullPath, 'utf-8' );
		} catch {
			continue;
		}

		// Pattern 1: fetch('/api/...')  or  fetch("...")
		const fetchCalls = content.matchAll( /fetch\s*\(\s*['"`]([^'"`]+)['"`]/g );
		for ( const m of fetchCalls ) {
			const url = m[ 1 ];
			if ( isApiUrl( url ) && ! seen.has( url ) ) {
				seen.add( url );
				calls.push( {
					file:     rel,
					method:   'GET', // fetch defaults to GET unless options.method is set
					endpoint: cleanEndpoint( url ),
					source:   'fetch',
				} );
			}
		}

		// Pattern 2: fetch with method option
		const fetchMethodCalls = content.matchAll( /fetch\s*\(\s*['"`]([^'"`]+)['"`]\s*,\s*\{[^}]*method\s*:\s*['"`](GET|POST|PUT|PATCH|DELETE)['"`]/gi );
		for ( const m of fetchMethodCalls ) {
			const url    = m[ 1 ];
			const method = m[ 2 ].toUpperCase();
			if ( isApiUrl( url ) && ! seen.has( `${ method } ${ url }` ) ) {
				seen.add( `${ method } ${ url }` );
				calls.push( {
					file:     rel,
					method,
					endpoint: cleanEndpoint( url ),
					source:   'fetch',
				} );
			}
		}

		// Pattern 3: axios.get/post/put/patch/delete('/api/...')
		const axiosCalls = content.matchAll( /axios\s*\.\s*(get|post|put|patch|delete|request)\s*\(\s*['"`]([^'"`]+)['"`]/gi );
		for ( const m of axiosCalls ) {
			const method = m[ 1 ].toLowerCase() === 'request' ? 'GET' : m[ 1 ].toUpperCase();
			const url    = m[ 2 ];
			if ( isApiUrl( url ) && ! seen.has( `${ method } ${ url }` ) ) {
				seen.add( `${ method } ${ url }` );
				calls.push( {
					file:     rel,
					method,
					endpoint: cleanEndpoint( url ),
					source:   'axios',
				} );
			}
		}

		// Pattern 4: ky.get/post/put/delete('...')
		const kyCalls = content.matchAll( /ky\s*\.\s*(get|post|put|patch|delete)\s*\(\s*['"`]([^'"`]+)['"`]/gi );
		for ( const m of kyCalls ) {
			const url = m[ 2 ];
			if ( isApiUrl( url ) && ! seen.has( `${ m[ 1 ].toUpperCase() } ${ url }` ) ) {
				seen.add( `${ m[ 1 ].toUpperCase() } ${ url }` );
				calls.push( {
					file:     rel,
					method:   m[ 1 ].toUpperCase(),
					endpoint: cleanEndpoint( url ),
					source:   'ky',
				} );
			}
		}

		// Pattern 5: useQuery / useMutation with URL
		const tanstackCalls = content.matchAll( /use(Query|Mutation)\s*\(\s*\{[^}]*url\s*:\s*['"`]([^'"`]+)['"`]/gi );
		for ( const m of tanstackCalls ) {
			const url = m[ 2 ];
			if ( isApiUrl( url ) && ! seen.has( `GET ${ url }` ) ) {
				seen.add( `GET ${ url }` );
				calls.push( {
					file:     rel,
					method:   m[ 1 ] === 'Mutation' ? 'POST' : 'GET',
					endpoint: cleanEndpoint( url ),
					source:   'tanstack-query',
				} );
			}
		}
	}

	return calls;
}

function isApiUrl( url ) {
	// Skip local imports, relative paths, data URLs.
	if ( url.startsWith( '/' ) || url.startsWith( './' ) || url.startsWith( '../' ) ) return true;
	if ( url.includes( 'wp-json' ) || url.includes( '/api/' ) || url.includes( '/v1/' ) ) return true;
	// Skip obvious non-API URLs.
	if ( url.startsWith( '#' ) || url.startsWith( 'data:' ) || url.startsWith( 'blob:' ) ) return false;
	if ( url.includes( 'http' ) ) return true;
	return false;
}

function cleanEndpoint( url ) {
	// Strip protocol + host, keep path + query.
	let cleaned = url;
	if ( cleaned.startsWith( 'http://' ) || cleaned.startsWith( 'https://' ) ) {
		try {
			const u = new URL( cleaned );
			cleaned = u.pathname + u.search;
		} catch { /* pass */ }
	}
	// Normalize trailing slash.
	if ( cleaned.length > 1 && cleaned.endsWith( '/' ) ) {
		cleaned = cleaned.slice( 0, -1 );
	}
	// Truncate query string for matching purposes.
	const qIdx = cleaned.indexOf( '?' );
	return qIdx >= 0 ? cleaned.substring( 0, qIdx ) : cleaned;
}

// ---------------------------------------------------------------------------
// Component inventory
// ---------------------------------------------------------------------------

function inventoryComponents( rootDir ) {
	const pages      = [];
	const components = [];
	const layouts    = [];

	for ( const rel of walkDir( rootDir ) ) {
		if ( ! REACT_FILE_EXTS.test( rel ) ) continue;
		const fullPath = path.join( rootDir, rel );
		let content;
		try {
			content = fs.readFileSync( fullPath, 'utf-8' );
		} catch {
			continue;
		}

		// Count React component exports.
		const exportCount = ( content.match( /export\s+(default\s+)?(function|class|const)\s+\w+/g ) || [] ).length;

		if ( rel.includes( '/pages/' ) || rel.includes( '/views/' ) || rel.includes( '/routes/' ) ) {
			pages.push( { file: rel, exports: exportCount } );
		} else if ( rel.includes( '/layouts/' ) || rel.includes( '/layout/' ) ) {
			layouts.push( { file: rel, exports: exportCount } );
		} else if ( rel.includes( '/components/' ) || rel.includes( '/ui/' ) ) {
			components.push( { file: rel, exports: exportCount } );
		} else if ( exportCount > 0 && rel.endsWith( '.tsx' ) || rel.endsWith( '.jsx' ) ) {
			// Top-level or root components.
			if ( rel.startsWith( 'src/' ) || rel.startsWith( 'app/' ) ) {
				components.push( { file: rel, exports: exportCount } );
			}
		}
	}

	return {
		pages:      pages.length,
		components: components.length,
		layouts:    layouts.length,
		pageFiles:      pages.slice( 0, 20 ).map( p => p.file ),
		componentFiles: components.slice( 0, 30 ).map( c => c.file ),
		layoutFiles:    layouts.map( l => l.file ),
	};
}

// ---------------------------------------------------------------------------
// TypeScript interface extraction (lightweight)
// ---------------------------------------------------------------------------

/**
 * Extract TypeScript interface/type definitions that look like API response
 * shapes. Uses regex — fast enough for analysis, doesn't require full AST.
 */
function extractInterfaces( rootDir ) {
	const interfaces = [];

	for ( const rel of walkDir( rootDir ) ) {
		if ( ! /\.(ts|tsx)$/.test( rel ) ) continue;
		const fullPath = path.join( rootDir, rel );
		let content;
		try {
			content = fs.readFileSync( fullPath, 'utf-8' );
		} catch {
			continue;
		}

		// Extract named interfaces.
		const interfacePattern = /(?:export\s+)?interface\s+(\w+)\s*\{([^}]+)\}/gs;
		for ( const m of content.matchAll( interfacePattern ) ) {
			const name   = m[ 1 ];
			const body   = m[ 2 ];
			const fields = extractFieldsFromInterfaceBody( body );
			if ( fields.length > 0 ) {
				interfaces.push( { name, file: rel, fields } );
			}
		}

		// Extract type aliases that look like objects.
		const typePattern = /(?:export\s+)?type\s+(\w+)\s*=\s*\{([^}]+)\}/gs;
		for ( const m of content.matchAll( typePattern ) ) {
			const name   = m[ 1 ];
			const body   = m[ 2 ];
			const fields = extractFieldsFromInterfaceBody( body );
			if ( fields.length > 0 ) {
				interfaces.push( { name, file: rel, fields } );
			}
		}
	}

	return interfaces;
}

function extractFieldsFromInterfaceBody( body ) {
	const fields = [];
	const lines  = body.split( /[;\n]/ );
	for ( const line of lines ) {
		const trimmed = line.trim();
		if ( ! trimmed || trimmed.startsWith( '//' ) || trimmed.startsWith( '/*' ) ) continue;

		// Match: fieldName?: type;  or  fieldName: type;
		const match = trimmed.match( /^(\w+)(\?)?\s*:\s*(.+)$/ );
		if ( ! match ) continue;

		const name     = match[ 1 ];
		const optional = !! match[ 2 ];
		let typeStr    = match[ 3 ].trim();

		// Simplify union/enum types.
		if ( typeStr.includes( '|' ) ) {
			const parts = typeStr.split( '|' ).map( p => p.trim().replace( /['"]/g, '' ) );
			// If all parts are string literals, it's an enum.
			if ( parts.every( p => /^['"]/.test( p ) || p === parts[ 0 ] ) ) {
				fields.push( {
					name:     name,
					tsType:   typeStr,
					inferred: 'enum',
					required: ! optional,
					enumValues: parts.map( p => p.replace( /['"]/g, '' ) ),
				} );
				continue;
			}
		}

		const inferred = inferFieldType( typeStr );
		fields.push( {
			name:     name,
			tsType:   typeStr,
			inferred,
			required: ! optional,
		} );
	}
	return fields;
}

function inferFieldType( tsType ) {
	const t = tsType.toLowerCase().trim();
	if ( t === 'string' )                                     return 'string';
	if ( t === 'number' )                                     return 'number';
	if ( t === 'boolean' )                                    return 'boolean';
	if ( t === 'date' || t.includes( 'Date' ) )               return 'date';
	if ( t === 'datetime' || t.includes( 'DateTime' ) )       return 'datetime';
	if ( t.includes( '[]' ) || t.includes( 'Array<' ) )       return 'string'; // arrays → string for simplicity
	if ( t.includes( 'Record<' ) || t.includes( 'object' ) )  return 'text';
	if ( t.includes( 'email' ) || t.includes( 'Email' ) )     return 'email';
	if ( t.includes( 'url' ) || t.includes( 'Url' ) || t.includes( 'URL' ) ) return 'url';
	return 'string';
}

// ---------------------------------------------------------------------------
// Security scan
// ---------------------------------------------------------------------------

function securityScan( rootDir ) {
	const issues = [];

	for ( const rel of walkDir( rootDir ) ) {
		if ( ! REACT_FILE_EXTS.test( rel ) && ! /\.(html|css|json)$/.test( rel ) ) continue;
		const fullPath = path.join( rootDir, rel );
		let content;
		try {
			content = fs.readFileSync( fullPath, 'utf-8' );
		} catch {
			continue;
		}

		// Check for eval().
		if ( /\beval\s*\(/.test( content ) ) {
			issues.push( {
				severity: 'critical',
				category: 'security',
				file:     rel,
				message:  'eval() call found — blueprint §12 gate 9 prohibits inline eval',
			} );
		}

		// Check for remote script loads.
		const remoteScripts = content.matchAll( /src\s*=\s*['"`]https?:\/\/[^'"`]+\.js['"`]/g );
		for ( const m of remoteScripts ) {
			issues.push( {
				severity: 'critical',
				category: 'security',
				file:     rel,
				message:  `Remote script load: ${ m[ 0 ] } — blueprint §12 gate 9 requires self-hosting`,
			} );
		}

		// Check for dangerouslySetInnerHTML.
		if ( /dangerouslySetInnerHTML/.test( content ) ) {
			issues.push( {
				severity: 'high',
				category: 'security',
				file:     rel,
				message:  'dangerouslySetInnerHTML found — requires XSS review',
			} );
		}

		// Check for hardcoded secrets/tokens.
		if ( /(api[_-]?key|secret|token|password)\s*[:=]\s*['"`][A-Za-z0-9_\-]{8,}['"`]/i.test( content ) ) {
			issues.push( {
				severity: 'high',
				category: 'security',
				file:     rel,
				message:  'Possible hardcoded credential detected',
			} );
		}
	}

	return issues;
}

// ---------------------------------------------------------------------------
// i18n scan
// ---------------------------------------------------------------------------

function i18nScan( rootDir ) {
	const hardcodedStrings = [];
	const totalStrings     = { found: 0, i18nWrapped: 0 };

	for ( const rel of walkDir( rootDir ) ) {
		if ( ! REACT_FILE_EXTS.test( rel ) ) continue;
		const fullPath = path.join( rootDir, rel );
		let content;
		try {
			content = fs.readFileSync( fullPath, 'utf-8' );
		} catch {
			continue;
		}

		// Count __() / _n() / _x() / __n() usage.
		const wpI18n = ( content.match( /\b(__|_n|_x|_nx|sprintf)\s*\(/g ) || [] ).length;
		totalStrings.i18nWrapped += wpI18n;

		// Find hardcoded English strings in JSX text.
		const jsxTexts = content.matchAll( />([A-Z][A-Za-z\s.,!?]{3,})</g );
		for ( const m of jsxTexts ) {
			const text = m[ 1 ].trim();
			if ( text.length < 5 ) continue;
			if ( hardcodedStrings.length < 50 ) {
				hardcodedStrings.push( text );
			}
			totalStrings.found++;
		}

		// Find hardcoded strings in attributes (aria-label, placeholder, title, alt).
		const attrStrings = content.matchAll( /(?:aria-label|placeholder|title|alt)\s*=\s*['"`]([^'"`]{3,})['"`]/g );
		for ( const m of attrStrings ) {
			const text = m[ 1 ].trim();
			if ( text.length < 5 ) continue;
			if ( hardcodedStrings.length < 50 ) {
				hardcodedStrings.push( text );
			}
			totalStrings.found++;
		}
	}

	return {
		hasI18n:       totalStrings.i18nWrapped > 0,
		i18nWrapped:   totalStrings.i18nWrapped,
		hardcodedCount: totalStrings.found,
		samples:       hardcodedStrings.slice( 0, 20 ),
	};
}

// ---------------------------------------------------------------------------
// Envato Envato-specific metadata detection (optional)
// ---------------------------------------------------------------------------

function detectEnvatoMetadata( rootDir ) {
	const indicators = [];

	// Check for Envato license certificate.
	const certPath = path.join( rootDir, 'Licensing', 'license.txt' );
	if ( fs.existsSync( certPath ) ) {
		try {
			const content = fs.readFileSync( certPath, 'utf-8' );
			const itemId = content.match( /Item\s*(?:ID|id|#)[:\s]*(\d+)/i );
			const purchaseCode = content.match( /Purchase\s*Code[:\s]*([a-z0-9\-]+)/i );
			if ( itemId ) {
				indicators.push( { type: 'envato_item_id', value: itemId[ 1 ] } );
			}
			if ( purchaseCode ) {
				indicators.push( { type: 'purchase_code_present', value: true } );
			}
		} catch { /* ignore */ }
	}

	// Check for common Envato template structure patterns.
	const docFiles = [ 'documentation/index.html', 'docs/index.html', 'README.txt', 'Documentation/index.html' ];
	for ( const docFile of docFiles ) {
		if ( fs.existsSync( path.join( rootDir, docFile ) ) ) {
			try {
				const content = fs.readFileSync( path.join( rootDir, docFile ), 'utf-8' );
				if ( content.includes( 'envato' ) || content.includes( 'themeforest' ) || content.includes( 'codecanyon' ) ) {
					indicators.push( { type: 'envato_documentation_found', value: docFile } );
					break;
				}
			} catch { /* ignore */ }
		}
	}

	return indicators;
}

// ---------------------------------------------------------------------------
// CSS specificity check
// ---------------------------------------------------------------------------

function checkCSSConflicts( rootDir ) {
	const issues = [];
	const globalResets = [];

	for ( const rel of walkDir( rootDir ) ) {
		if ( ! STYLE_FILE_EXTS.test( rel ) ) continue;
		const fullPath = path.join( rootDir, rel );
		let content;
		try {
			content = fs.readFileSync( fullPath, 'utf-8' );
		} catch {
			continue;
		}

		// Check for global resets.
		if ( /\*\s*\{/.test( content ) ) {
			globalResets.push( rel );
		}
		if ( /body\s*\{/.test( content ) || /html\s*\{/.test( content ) ) {
			issues.push( {
				severity: 'warn',
				category: 'css',
				file:     rel,
				message:  `HTML/body styles may conflict with WordPress admin — needs .nvoos-{slug}-root scoping`,
			} );
		}
	}

	return { conflicts: issues.length, globalResets, details: issues };
}

// ---------------------------------------------------------------------------
// Public API — main analyzer function
// ---------------------------------------------------------------------------

/**
 * Analyze a React project directory and return a structured gap report.
 *
 * @param {string}  rootDir  Absolute path to the template/project root.
 * @param {object}  [options]
 * @param {string}  [options.envatoId]   Optional Envato item ID for metadata.
 * @param {boolean} [options.verbose]    Log progress to stderr.
 * @returns {object} The full template analysis report.
 */
export function analyzeTemplate( rootDir, options = {} ) {
	const { envatoId = null, verbose = false } = options;

	if ( ! fs.existsSync( rootDir ) || ! fs.statSync( rootDir ).isDirectory() ) {
		throw new Error( `Not a directory: ${ rootDir }` );
	}

	const log = verbose ? ( msg ) => process.stderr.write( `  [analyzer] ${ msg }\n` ) : () => {};

	log( 'Analyzing package.json…' );
	const pkg = analyzePackageJson( rootDir );

	log( 'Discovering entry points…' );
	const entryPoints = findEntryPoints( rootDir, pkg );

	log( 'Scanning API calls…' );
	const apiCalls = scanApiCalls( rootDir );

	log( 'Inventorying components…' );
	const componentInventory = inventoryComponents( rootDir );

	log( 'Extracting TypeScript interfaces…' );
	const tsInterfaces = extractInterfaces( rootDir );

	log( 'Scanning for i18n patterns…' );
	const i18n = i18nScan( rootDir );

	log( 'Running security scan…' );
	const security = securityScan( rootDir );

	log( 'Checking CSS conflicts…' );
	const cssCheck = checkCSSConflicts( rootDir );

	log( 'Detecting Envato metadata…' );
	const envato = detectEnvatoMetadata( rootDir );

	// --- Build vetting results ---
	const vetting = runVettingGates( rootDir, pkg, security, cssCheck, i18n );

	// --- Build gap list ---
	const gaps = buildGapList( vetting, apiCalls, pkg, i18n, security, cssCheck );

	// --- Estimate effort ---
	const effort = estimateEffort( gaps, vetting, componentInventory );

	// --- Recommend tier and addon type ---
	const recommendation = recommend( vetting, componentInventory, pkg );

	const report = {
		template_name:         pkg?.name || path.basename( rootDir ),
		envato_id:             envatoId,
		analysis_version:      '1.0',
		analyzed_at:           new Date().toISOString(),
		tech_stack:            pkg?.techStack || null,
		entry_points:          entryPoints,
		api_calls:             apiCalls,
		components:            componentInventory,
		typescript_interfaces: tsInterfaces,
		i18n:                  i18n,
		envato_metadata:       envato,
		vetting_results:       vetting,
		gaps:                  gaps,
		security_issues:       security,
		css_conflicts:         cssCheck,
		estimated_effort:      effort,
		recommended_tier:       recommendation.tier,
		recommended_addon_type: recommendation.addonType,
	};

	return report;
}

// ---------------------------------------------------------------------------
// Vetting gates — mirrors blueprint §12
// ---------------------------------------------------------------------------

function runVettingGates( rootDir, pkg, security, cssCheck, i18n ) {
	const gates = {};
	const accepted = new Set( [ 'MIT', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'ISC', 'GPL-3.0', 'GPL-3.0-or-later', 'GPL-2.0' ] );

	// Gate 1: License.
	const lic = pkg?.license || findLicenseFile( rootDir ) || 'UNKNOWN';
	gates.license = {
		status: accepted.has( lic ) ? 'pass' : ( lic === 'UNKNOWN' ? 'warn' : 'fail' ),
		value:  lic,
	};

	// Gate 2: Bundle weight (estimated from source files).
	const totalSourceKB = estimateSourceSize( rootDir );
	gates.bundle_weight = {
		status: totalSourceKB < 500 ? 'pass' : 'warn',
		current_kb: totalSourceKB,
		tier_a_limit_kb: 200,
	};

	// Gate 3: Maintenance (for Node deps — we'd need npm API, skip for now).
	gates.maintenance = { status: 'skip', note: 'requires npm registry lookup — run `gh-advisory-database` separately' };

	// Gate 4: React version compatibility.
	const reactVer = pkg?.allDeps?.react || pkg?.allDeps?.[ 'react-dom' ] || 'unknown';
	gates.react_compat = {
		status: reactVer.startsWith( '19' ) || reactVer.startsWith( '18' ) ? 'pass' : 'warn',
		value: reactVer,
	};

	// Gate 5: Embeddable (check CSS conflicts).
	gates.embeddable = {
		status: cssCheck.conflicts === 0 ? 'pass' : 'warn',
		reason: cssCheck.conflicts > 0 ? `${ cssCheck.conflicts } CSS files may conflict with wp-admin` : undefined,
	};

	// Gate 6: Data shape (if API calls found, assume adaptable).
	gates.data_shape = {
		status: 'pass', // We'll adapt via api-adapter
		note:    'Data shape adaptable via API adapter',
	};

	// Gate 7: i18n.
	gates.i18n = {
		status: i18n.hasI18n ? 'pass' : 'warn',
		reason: i18n.hasI18n ? undefined : `No i18n library detected; ${ i18n.hardcodedCount } hardcoded strings found`,
	};

	// Gate 8: Accessibility.
	gates.accessibility = {
		status: 'skip',
		note:    'Run axe-core after integration',
	};

	// Gate 9: Security.
	const critSec = security.filter( s => s.severity === 'critical' ).length;
	gates.security = {
		status: critSec === 0 ? 'pass' : 'fail',
		reason: critSec > 0 ? `${ critSec } critical security issues found` : undefined,
	};

	// Gate 10: Attribution.
	gates.attribution = { status: 'manual', note: 'Add to THIRD_PARTY_NOTICES.md, CREDITS.md, README.md "Credits"' };

	return gates;
}

function estimateSourceSize( rootDir ) {
	let total = 0;
	for ( const rel of walkDir( rootDir ) ) {
		if ( ! REACT_FILE_EXTS.test( rel ) && ! STYLE_FILE_EXTS.test( rel ) ) continue;
		try {
			total += fs.statSync( path.join( rootDir, rel ) ).size;
		} catch { /* skip */ }
	}
	return Math.round( total / 1024 );
}

// ---------------------------------------------------------------------------
// Gap detection
// ---------------------------------------------------------------------------

function buildGapList( vetting, apiCalls, pkg, i18n, security, cssCheck ) {
	const gaps = [];

	// Critical: Auth adapter needed if no WordPress auth pattern detected.
	gaps.push( {
		severity: 'critical',
		category: 'auth',
		description: 'Template uses standard auth; needs WP nonce/X-WP-Nonce adapter for blueprint §8 compliance',
		adapter_needed: 'auth-adapter',
	} );

	// High: API endpoint mapping.
	if ( apiCalls.length > 0 ) {
		const unmapped = apiCalls.filter( c => ! c.endpoint.includes( 'wp-json' ) && ! c.endpoint.includes( 'mcp-ai' ) );
		gaps.push( {
			severity: 'high',
			category: 'data_plane',
			description: `${ unmapped.length } API endpoints need REST namespace mapping to /wp-json/mcp-ai-pro/v1/* or custom namespace`,
			endpoints_to_map: unmapped.map( c => `${ c.method } ${ c.endpoint }` ).slice( 0, 20 ),
			adapter_needed: 'api-adapter',
		} );
	}

	// High: Build system conversion.
	const bundler = pkg?.techStack?.bundler || 'unknown';
	if ( bundler !== 'unknown' && bundler !== undefined ) {
		gaps.push( {
			severity: 'high',
			category: 'build',
			description: `Uses ${ bundler }; needs esbuild conversion for blueprint §5 compliance (IIFE bundle)`,
			adapter_needed: 'build-adapter',
		} );
	}

	// Critical: Mount container.
	gaps.push( {
		severity: 'critical',
		category: 'mount',
		description: 'Template mounts to its own root element; needs container adapter for blueprint §7 (nvoos-{slug}-root div + data-config)',
		adapter_needed: 'mount-adapter',
	} );

	// Medium: i18n.
	if ( ! i18n.hasI18n && i18n.hardcodedCount > 0 ) {
		gaps.push( {
			severity: 'medium',
			category: 'i18n',
			description: `${ i18n.hardcodedCount } hardcoded strings found; needs wp.i18n conversion (blueprint §15)`,
			adapter_needed: 'i18n-adapter',
		} );
	}

	// Medium: CSS scoping.
	if ( cssCheck.conflicts > 0 ) {
		gaps.push( {
			severity: 'medium',
			category: 'css',
			description: `${ cssCheck.conflicts } CSS files may conflict with wp-admin; needs scoping adapter`,
			adapter_needed: 'css-scope-adapter',
		} );
	}

	// Failures from vetting.
	if ( vetting.security.status === 'fail' ) {
		gaps.push( {
			severity: 'critical',
			category: 'security',
			description: vetting.security.reason || 'Security gate failed',
			adapter_needed: 'security-fix',
		} );
	}
	if ( vetting.license.status === 'fail' ) {
		gaps.push( {
			severity: 'critical',
			category: 'license',
			description: `License '${ vetting.license.value }' not in accepted set (MIT, Apache-2.0, BSD, ISC, GPL) — blueprint §12 gate 1`,
		} );
	}

	return gaps;
}

function estimateEffort( gaps, vetting, componentInventory ) {
	const criticals = gaps.filter( g => g.severity === 'critical' ).length;
	const highs     = gaps.filter( g => g.severity === 'high' ).length;
	const mediums   = gaps.filter( g => g.severity === 'medium' ).length;

	const score = criticals * 3 + highs * 2 + mediums;

	if ( score >= 12 ) return 'large';
	if ( score >= 6 )  return 'medium';
	return 'small';
}

function recommend( vetting, componentInventory, pkg ) {
	const hasRouter = pkg?.techStack?.router !== 'none-detected';
	const isCanvas  = pkg?.allDeps?.tldraw || pkg?.allDeps?.[ '@xyflow/react' ] || pkg?.allDeps?.[ 'bpmn-js' ];
	const isDoc     = pkg?.allDeps?.[ '@tiptap/react' ] || pkg?.allDeps?.[ 'grapesjs' ];

	if ( isCanvas ) {
		return { tier: 'B', addonType: 'separate' };
	}
	if ( isDoc ) {
		return { tier: 'C', addonType: 'separate' };
	}

	// Dashboard/admin templates → Tier A manifest-driven.
	if ( componentInventory.pages > 3 && componentInventory.components > 10 ) {
		return { tier: 'A', addonType: 'manifest-driven' };
	}

	// Small/landing pages → Tier A manifest-driven or separate.
	if ( componentInventory.pages <= 3 ) {
		return { tier: 'A', addonType: 'separate' };
	}

	return { tier: 'A', addonType: 'separate' };
}

// ---------------------------------------------------------------------------
// CLI entry (when run directly)
// ---------------------------------------------------------------------------

if ( import.meta.url === `file://${ process.argv[ 1 ].replace( /\\/g, '/' ) }` ) {
	const args  = process.argv.slice( 2 );
	const dir   = args.find( a => ! a.startsWith( '-' ) ) || '.';
	const json  = args.includes( '--json' );
	const verbose = args.includes( '--verbose' );

	try {
		const report = analyzeTemplate( path.resolve( dir ), { verbose } );
		if ( json ) {
			process.stdout.write( JSON.stringify( report, null, 2 ) + '\n' );
		} else {
			process.stdout.write( JSON.stringify( report, null, 2 ) + '\n' );
		}
	} catch ( err ) {
		process.stderr.write( `Error: ${ err.message }\n` );
		process.exit( 1 );
	}
}
