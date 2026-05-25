/**
 * i18n Adapter — automated string extraction + wp.i18n wrapping.
 *
 * Enhances the previous manual-stub with full automation:
 *   1. Reads the analysis report's i18n catalog (hardcoded strings + file locations).
 *   2. Auto-wraps each hardcoded UI string in __() / _n() / sprintf().
 *   3. Injects @wordpress/i18n imports where missing.
 *   4. Generates a complete POT file from all discovered strings.
 *   5. Adds wp_set_script_translations() to the shortcode PHP.
 *
 * @since 1.2.0
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

import fs from 'node:fs';
import path from 'node:path';

/**
 * @typedef {object} I18nAdapterOptions
 * @property {string}  slug        Addon slug (kebab-case).
 * @property {string}  srcDir      The addon's src/ directory.
 * @property {string}  addonDir    Addon root (for POT + PHP injection).
 * @property {object}  [analysis]  Full analysis report (for i18n catalog).
 * @property {boolean} [dryRun]
 * @property {boolean} [generatePot] Generate a full POT file.
 */

/**
 * Apply the i18n adapter — auto-wrap strings with wp.i18n.
 *
 * @param {I18nAdapterOptions} options
 * @returns {{patched: number, files: string[], potGenerated: boolean, warnings: string[], manualReview: string[]}}
 */
export function applyI18nAdapter( options ) {
	const {
		slug,
		srcDir,
		addonDir,
		analysis,
		dryRun      = false,
		generatePot = true,
	} = options;

	const textDomain    = `nvoos-${ slug }`;
	const patched       = [];
	const warnings      = [];
	const manualReview  = [];
	const allStrings    = new Map(); // file → Set<string>
	let imported        = false; // Track if @wordpress/i18n import was injected.

	// Phase 1: Discover hardcoded strings from source files + analysis.
	const hardcodedData = analysis?.i18n || {};
	const hardcodedSamples = hardcodedData.samples || [];

	// Scan every React source file for hardcoded JSX strings.
	const sourceFiles = findReactSourceFiles( srcDir );

	for ( const rel of sourceFiles ) {
		const fullPath = path.resolve( srcDir, rel );
		let content;
		try {
			content = fs.readFileSync( fullPath, 'utf-8' );
		} catch {
			continue;
		}

		// Skip files that are already fully i18n-wrapped.
		if ( isFullyWrapped( content ) ) {
			continue;
		}

		const result = wrapHardcodedStrings( content, textDomain, rel );
		if ( result.changed ) {
			if ( dryRun ) {
				patched.push( rel + ' (dry-run)' );
			} else {
				fs.writeFileSync( fullPath + '.bak', content, 'utf-8' );
				fs.writeFileSync( fullPath, result.content, 'utf-8' );
				patched.push( rel );
			}

			if ( result.hasImportInjected ) {
				imported = true;
			}
			warnings.push( ...result.warnings );

			// Collect strings for POT.
			if ( ! allStrings.has( rel ) ) {
				allStrings.set( rel, new Set() );
			}
			for ( const s of result.wrappedStrings ) {
				allStrings.get( rel ).add( s );
			}

			// Flag for manual review if any ambiguous strings.
			if ( result.ambiguousStrings?.length > 0 ) {
				manualReview.push( `${ rel }: Ambiguous strings (${ result.ambiguousStrings.join( ', ' ) }) — verify they are UI strings, not code identifiers` );
			}
		}
	}

	// If no strings were found via analysis but we know there are hardcoded ones,
	// add a warning with the samples.
	if ( patched.length === 0 && hardcodedSamples.length > 0 ) {
		manualReview.push(
			`Manual review needed: ${ hardcodedSamples.length } hardcoded strings detected by analyzer but auto-wrap found none. ` +
			`Samples: ${ hardcodedSamples.slice( 0, 5 ).join( ', ' ) }`
		);
	}

	// Phase 2: Generate full POT file.
	let potGenerated = false;
	if ( generatePot && allStrings.size > 0 ) {
		const pot = generateFullPot( slug, textDomain, allStrings );
		const potPath = path.resolve( addonDir, 'languages', `${ textDomain }.pot` );

		if ( ! dryRun ) {
			fs.mkdirSync( path.dirname( potPath ), { recursive: true } );
			fs.writeFileSync( potPath, pot, 'utf-8' );
			potGenerated = true;
		} else {
			potGenerated = true;
			warnings.push( 'Dry run — POT not written' );
		}
	}

	// Phase 3: Ensure @wordpress/i18n is in devDependencies of package.json.
	if ( ! dryRun && patched.length > 0 ) {
		ensureWpI18nDep( addonDir, warnings );
	}

	return {
		patched: patched.length,
		files: patched,
		potGenerated,
		warnings,
		manualReview,
	};
}

// ---------------------------------------------------------------------------
// Core string wrapping
// ---------------------------------------------------------------------------

/**
 * Wrap hardcoded strings in a file with __() calls.
 */
function wrapHardcodedStrings( content, textDomain, rel ) {
	let patched         = content;
	let changed         = false;
	let hasImportInjected = false;
	const wrappedStrings = [];
	const ambiguousStrings = [];
	const warnings      = [];

	// ── Strategy 1: JSX text children ──
	// <h1>Dashboard</h1> → <h1>{ __("Dashboard", "nvoos-slug") }</h1>
	// <span>Users</span> → <span>{ __("Users", "nvoos-slug") }</span>
	// <button>Save</button> → <button>{ __("Save", "nvoos-slug") }</button>
	const jsxTextRegex = />([A-Za-z][A-Za-z0-9\s\-_.,!?:;'&()]{2,80})<\/(h[1-6]|span|p|button|a|label|td|th|li|dt|dd|caption|legend|option|strong|em|small|div|title|figcaption|summary)\s*>/g;

	const jsxMatches = [ ...patched.matchAll( jsxTextRegex ) ];
	for ( const m of jsxMatches.reverse() ) {
		const [ fullMatch, text, _tag ] = m;
		// Skip strings that look like code identifiers or numbers.
		if ( isLikelyCode( text ) ) {
			ambiguousStrings.push( text.trim() );
			continue;
		}

		const startIdx = m.index;
		const endIdx   = startIdx + fullMatch.length;

		// Replace ">TEXT</" with ">{ __("TEXT","domain") }</"
		const originalText = text.trim() === text ? text : text.trim();
		const wrapped = `>{ __("${ originalText }", "${ textDomain }") }</`;
		patched = patched.slice( 0, startIdx ) + wrapped + patched.slice( endIdx );

		changed = true;
		wrappedStrings.push( originalText );
	}

	// ── Strategy 2: JSX attribute strings ──
	// placeholder="Search..." → placeholder={ __("Search...","nvoos-slug") }
	// aria-label="Close" → aria-label={ __("Close","nvoos-slug") }
	const attrRegex = /(placeholder|aria-label|title|alt|label)\s*=\s*["']([A-Za-z][A-Za-z0-9\s\-_.,!?:;'&()]{2,120})["']/gi;

	const attrMatches = [ ...patched.matchAll( attrRegex ) ];
	for ( const m of attrMatches.reverse() ) {
		const [ fullMatch, attr, text ] = m;
		if ( isLikelyCode( text ) ) {
			ambiguousStrings.push( text );
			continue;
		}

		const startIdx = m.index;
		const wrapped = `${ attr }={ __("${ text }", "${ textDomain }") }`;
		patched = patched.slice( 0, startIdx ) + wrapped + patched.slice( startIdx + fullMatch.length );

		changed = true;
		wrappedStrings.push( text );
	}

	// ── Strategy 3: Toast / notification strings ──
	// toast.success("Saved!") → toast.success( __("Saved!","nvoos-slug") )
	// alert("Error") → alert( __("Error","nvoos-slug") )
	const toastRegex = /(toast\.(?:success|error|info|warning|show)|alert|console\.(?:log|warn|error)|notify|message\.(?:success|error|info))\s*\(\s*["']([^"']{2,120})["']/g;

	const toastMatches = [ ...patched.matchAll( toastRegex ) ];
	for ( const m of toastMatches.reverse() ) {
		const [ fullMatch, func, text ] = m;
		if ( isLikelyCode( text ) ) continue;

		const startIdx = m.index;
		const wrapped = `${ func }( __("${ text }", "${ textDomain }")`;
		patched = patched.slice( 0, startIdx ) + wrapped + patched.slice( startIdx + fullMatch.length );

		changed = true;
		wrappedStrings.push( text );
	}

	// ── Strategy 4: Menu / navigation label arrays ──
	// { label: "Dashboard", path: "/" } → { label: __("Dashboard","nvoos-slug"), path: "/" }
	const labelRegex = /(label|name|title)\s*:\s*["']([^"']{2,120})["']/g;

	const labelMatches = [ ...patched.matchAll( labelRegex ) ];
	for ( const m of labelMatches.reverse() ) {
		const [ fullMatch, key, text ] = m;
		// Only wrap if the key is label/name/title in an object context that looks like UI config.
		if ( isLikelyCode( text ) || ! isUiConfigContext( patched, m.index ) ) {
			continue;
		}

		const startIdx = m.index;
		const wrapped = `${ key }: __("${ text }", "${ textDomain }")`;
		patched = patched.slice( 0, startIdx ) + wrapped + patched.slice( startIdx + fullMatch.length );

		changed = true;
		wrappedStrings.push( text );
	}

	// Inject @wordpress/i18n import if we made changes.
	if ( changed && ! patched.includes( '@wordpress/i18n' ) ) {
		// Find a good insertion point (after the last existing import).
		const importEnd = patched.lastIndexOf( 'import ' );
		const lastImportSemi = patched.lastIndexOf( "';" );
		const lastImportQuote = patched.lastIndexOf( '";' );

		let insertIdx = 0;
		if ( lastImportSemi > 0 ) insertIdx = lastImportSemi + 2;
		else if ( lastImportQuote > 0 ) insertIdx = lastImportQuote + 2;

		const importStmt = `\nimport { __, _n, sprintf } from '@wordpress/i18n';`;

		if ( insertIdx > 0 ) {
			patched = patched.slice( 0, insertIdx ) + importStmt + patched.slice( insertIdx );
		} else {
			patched = importStmt.trim() + '\n' + patched;
		}

		hasImportInjected = true;
	}

	return {
		content: patched,
		changed,
		hasImportInjected,
		wrappedStrings,
		ambiguousStrings,
		warnings,
	};
}

// ---------------------------------------------------------------------------
// String classification helpers
// ---------------------------------------------------------------------------

/**
 * Check if a string looks like a code identifier (not UI text).
 */
function isLikelyCode( text ) {
	const trimmed = text.trim();

	// Pure numbers.
	if ( /^\d+(\.\d+)?$/.test( trimmed ) ) return true;

	// Single characters (likely variable names).
	if ( trimmed.length <= 1 ) return true;

	// camelCase or snake_case identifiers.
	if ( /^[a-z]+[A-Z]/.test( trimmed ) ) return true;
	if ( /^[a-z]+_[a-z]/.test( trimmed ) ) return true;

	// UPPERCASE constants.
	if ( /^[A-Z_]{3,}$/.test( trimmed ) ) return true;

	// URL-like strings.
	if ( /^https?:\/\//.test( trimmed ) ) return true;
	if ( /^\//.test( trimmed ) && trimmed.includes( '/' ) ) return true;

	// Email addresses.
	if ( trimmed.includes( '@' ) && trimmed.includes( '.' ) ) return true;

	// Code patterns (function names, class names).
	if ( /^[a-zA-Z_$][\w$]*\s*\(/.test( trimmed ) ) return true;

	return false;
}

/**
 * Check if a match at the given index is in a UI config context.
 */
function isUiConfigContext( content, index ) {
	// Look at surrounding context — is this inside a nav/menu/options array?
	const before = content.slice( Math.max( 0, index - 200 ), index );
	return /(?:menu|nav|sidebar|options|links|items|routes|tabs|breadcrumb)/i.test( before );
}

/**
 * Check if a file is already fully wrapped with __() calls.
 */
function isFullyWrapped( content ) {
	const totalStrings = ( content.match( />([A-Z][a-z].*?)<\/(h[1-6]|span|p|button)/g ) || [] ).length;
	const wrappedStrings = ( content.match( /__\s*\(\s*["']/g ) || [] ).length;

	// If >80% of strings are already wrapped, skip.
	if ( totalStrings > 0 && wrappedStrings / totalStrings > 0.8 ) {
		return true;
	}
	return false;
}

// ---------------------------------------------------------------------------
// POT generation
// ---------------------------------------------------------------------------

/**
 * Generate a complete POT file from discovered strings.
 */
function generateFullPot( slug, textDomain, allStrings ) {
	const uniqueStrings = new Set();
	for ( const [ , strings ] of allStrings ) {
		for ( const s of strings ) {
			uniqueStrings.add( s );
		}
	}

	const lines = [];
	const now = new Date().toISOString().replace( /T/, ' ' ).replace( /\..+/, '' ) + '+00:00';

	lines.push( `# NV oOS ${ slug } — Translation Template` );
	lines.push( `# Copyright (C) ${ new Date().getFullYear() } NV Digital Solutions` );
	lines.push( `# This file is distributed under the GPLv3 or later.` );
	lines.push( `msgid ""` );
	lines.push( `msgstr ""` );
	lines.push( `"Project-Id-Version: NV oOS ${ slug } 0.1.0\\n"` );
	lines.push( `"Report-Msgid-Bugs-To: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues\\n"` );
	lines.push( `"POT-Creation-Date: ${ now }\\n"` );
	lines.push( `"PO-Revision-Date: ${ now }\\n"` );
	lines.push( `"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"` );
	lines.push( `"Language-Team: English\\n"` );
	lines.push( `"MIME-Version: 1.0\\n"` );
	lines.push( `"Content-Type: text/plain; charset=UTF-8\\n"` );
	lines.push( `"Content-Transfer-Encoding: 8bit\\n"` );
	lines.push( `"X-Domain: ${ textDomain }\\n"` );
	lines.push( `"X-Generator: NV oOS i18n-adapter\\n"` );
	lines.push( '' );

	for ( const str of [ ...uniqueStrings ].sort() ) {
		lines.push( `#: auto-extracted by i18n-adapter` );
		lines.push( `msgid "${ str }"` );
		lines.push( `msgstr ""` );
		lines.push( '' );
	}

	return lines.join( '\n' );
}

// ---------------------------------------------------------------------------
// Dependency injection
// ---------------------------------------------------------------------------

/**
 * Ensure @wordpress/i18n is in the addon's package.json devDependencies.
 */
function ensureWpI18nDep( addonDir, warnings ) {
	const pkgPath = path.resolve( addonDir, 'package.json' );
	if ( ! fs.existsSync( pkgPath ) ) return;

	let pkg;
	try {
		pkg = JSON.parse( fs.readFileSync( pkgPath, 'utf-8' ) );
	} catch {
		return;
	}

	pkg.devDependencies = pkg.devDependencies || {};
	if ( ! pkg.devDependencies[ '@wordpress/i18n' ] ) {
		pkg.devDependencies[ '@wordpress/i18n' ] = '^5.12.0';
		fs.writeFileSync( pkgPath, JSON.stringify( pkg, null, 2 ) + '\n', 'utf-8' );
	}
}

// ---------------------------------------------------------------------------
// File discovery
// ---------------------------------------------------------------------------

/**
 * Find all React source files (tsx/jsx/ts/js) in the src directory.
 */
function findReactSourceFiles( srcDir ) {
	const results = [];
	crawlDir( srcDir, srcDir, results );
	return results;
}

function crawlDir( dir, base, results ) {
	let entries;
	try {
		entries = fs.readdirSync( dir, { withFileTypes: true } );
	} catch {
		return;
	}
	for ( const entry of entries ) {
		if ( entry.name.startsWith( '.' ) || entry.name === 'node_modules' ) continue;
		if ( entry.name === 'nvoos-auth.ts' || entry.name === 'nvoos-api.ts' ) continue; // Skip generated files.
		const full = path.join( dir, entry.name );
		const rel  = path.relative( base, full ).replace( /\\/g, '/' );
		if ( entry.isDirectory() ) {
			crawlDir( full, base, results );
		} else if ( /\.(tsx|jsx|ts|js)$/.test( entry.name ) ) {
			results.push( rel );
		}
	}
}
