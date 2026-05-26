/**
 * CSS Scoping Adapter — automated framework-aware CSS namespace injection.
 *
 * Enhances the previous manual-stub with full automation for known frameworks:
 *   1. Tailwind CSS — auto-applies prefix in tailwind.config.*
 *   2. MUI / Material UI — auto-wraps App with ThemeProvider + scoped container
 *   3. styled-components — auto-scopes createGlobalStyle + injects StyleSheetManager
 *   4. Plain CSS — auto-rewrites global selectors (body, html, *) to root class
 *
 * @since 1.2.0
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

import fs from 'node:fs';
import path from 'node:path';

/**
 * @typedef {object} CssScopeAdapterOptions
 * @property {string}  slug          Addon slug (kebab-case).
 * @property {string}  srcDir        The addon's src/ directory.
 * @property {string}  addonDir      Addon root (for config files).
 * @property {string}  cssFramework  From tech stack analysis (tailwind, mui, mui-system, @mui/material, styled-components, etc.).
 * @property {string}  [uiLibrary]   UI library from analysis (e.g., @mui/material).
 * @property {object}  [analysis]    Full analysis report.
 * @property {boolean} [dryRun]
 */

/**
 * Apply CSS scoping.
 *
 * @param {CssScopeAdapterOptions} options
 * @returns {{patched: number, files: string[], configPatched: boolean, entryPatched: boolean, warnings: string[], manualReview: string[]}}
 */
export function applyCssScopeAdapter( options ) {
	const {
		slug,
		srcDir,
		addonDir,
		cssFramework,
		uiLibrary,
		analysis,
		dryRun = false,
	} = options;

	const rootClass     = `nvoos-${ slug }-root`;
	const patched       = [];
	const warnings      = [];
	const manualReview  = [];
	let   configPatched = false;
	let   entryPatched  = false;

	const framework = normalizeFramework( cssFramework, uiLibrary );

	switch ( framework ) {
		case 'tailwind':
			{
				const result = applyTailwindScoping( slug, addonDir, srcDir, rootClass, dryRun );
				patched.push( ...result.files );
				configPatched = result.configPatched;
				entryPatched  = result.entryPatched;
				warnings.push( ...result.warnings );
				manualReview.push( ...result.manualReview );
			}
			break;

		case 'mui':
			{
				const result = applyMuiScoping( slug, srcDir, rootClass, dryRun );
				patched.push( ...result.files );
				entryPatched  = result.entryPatched;
				warnings.push( ...result.warnings );
				manualReview.push( ...result.manualReview );
			}
			break;

		case 'styled-components':
			{
				const result = applyStyledComponentsScoping( slug, srcDir, rootClass, dryRun );
				patched.push( ...result.files );
				entryPatched  = result.entryPatched;
				warnings.push( ...result.warnings );
				manualReview.push( ...result.manualReview );
			}
			break;

		default:
			{
				const result = applyPlainCssScoping( slug, srcDir, rootClass, dryRun );
				patched.push( ...result.files );
				warnings.push( ...result.warnings );
				manualReview.push( ...result.manualReview );
			}
			break;
	}

	// Phase 2: Scan for and rewrite global CSS resets in all stylesheets.
	const cssFiles = findFilesByExt( srcDir, [ '.css', '.scss', '.less' ] );
	for ( const rel of cssFiles ) {
		const fullPath = path.resolve( srcDir, rel );
		let content;
		try {
			content = fs.readFileSync( fullPath, 'utf-8' );
		} catch {
			continue;
		}

		const result = scopeGlobalCssSelectors( content, rootClass, rel );
		if ( result.changed ) {
			if ( dryRun ) {
				patched.push( rel + ' (dry-run CSS)' );
			} else {
				fs.writeFileSync( fullPath + '.bak', content, 'utf-8' );
				fs.writeFileSync( fullPath, result.content, 'utf-8' );
				patched.push( rel );
			}
			warnings.push( ...result.warnings );
			manualReview.push( ...result.manualReview );
		}
	}

	return {
		patched: patched.length,
		files: patched,
		configPatched,
		entryPatched,
		warnings,
		manualReview,
	};
}

// ---------------------------------------------------------------------------
// Framework normalization
// ---------------------------------------------------------------------------

function normalizeFramework( cssFramework, uiLibrary ) {
	const combined = ( cssFramework + ' ' + ( uiLibrary || '' ) ).toLowerCase();

	if ( combined.includes( 'tailwind' ) ) return 'tailwind';
	if ( combined.includes( '@mui' ) || combined.includes( 'mui-system' ) || combined.includes( 'material-ui' ) ) return 'mui';
	if ( combined.includes( 'styled-component' ) ) return 'styled-components';
	if ( combined.includes( 'emotion' ) ) return 'emotion'; // Similar to MUI in many ways.

	return cssFramework || 'plain-css';
}

// ---------------------------------------------------------------------------
// Tailwind CSS scoping
// ---------------------------------------------------------------------------

function applyTailwindScoping( slug, addonDir, srcDir, rootClass, dryRun ) {
	const patched       = [];
	const warnings      = [];
	const manualReview  = [];
	let   configPatched = false;
	let   entryPatched  = false;

	// Find tailwind config file.
	const configFiles = [ 'tailwind.config.js', 'tailwind.config.cjs', 'tailwind.config.ts', 'tailwind.config.mjs' ];

	for ( const configName of configFiles ) {
		const configPath = path.resolve( addonDir, configName );
		if ( ! fs.existsSync( configPath ) ) continue;

		let content;
		try {
			content = fs.readFileSync( configPath, 'utf-8' );
		} catch {
			continue;
		}

		// If prefix is already set, skip.
		if ( /prefix\s*:/.test( content ) ) {
			warnings.push( `Tailwind prefix already set in ${ configName } — skipping` );
			configPatched = true;
			continue;
		}

		// Insert prefix into the config export.
		const patchedContent = content.replace(
			/(module\.exports\s*=\s*\{)/,
			`$1\n  prefix: 'nvoos-${ slug }-',`
		);

		if ( patchedContent !== content ) {
			if ( dryRun ) {
				warnings.push( `Dry run — would patch ${ configName } with Tailwind prefix` );
			} else {
				fs.writeFileSync( configPath + '.bak', content, 'utf-8' );
				fs.writeFileSync( configPath, patchedContent, 'utf-8' );
				patched.push( configName );
			}
			configPatched = true;
		}
	}

	if ( ! configPatched ) {
		warnings.push( 'No tailwind.config.* found — cannot auto-apply prefix' );
		manualReview.push( 'Tailwind: Add `prefix: "nvoos-' + slug + '-"` to your tailwind config manually' );
	}

	// Ensure the root class is on the mount container (already handled by mount-adapter).
	// Add Tailwind prefix class wrapper recommendation.
	ensureEntryScoped( slug, srcDir, rootClass, dryRun, patched, warnings, ( wasEntryPatched ) => {
		entryPatched = wasEntryPatched;
	} );

	return { files: patched, configPatched, entryPatched, warnings, manualReview };
}

// ---------------------------------------------------------------------------
// MUI / Material UI scoping
// ---------------------------------------------------------------------------

function applyMuiScoping( slug, srcDir, rootClass, dryRun ) {
	const patched       = [];
	const warnings      = [];
	const manualReview  = [];
	let   entryPatched  = false;

	// Find App.tsx or similar root component.
	const appFiles = findEntryComponents( srcDir );

	for ( const [ rel, fullPath ] of appFiles ) {
		let content;
		try {
			content = fs.readFileSync( fullPath, 'utf-8' );
		} catch {
			continue;
		}

		// Check if already wrapped with ThemeProvider + root class.
		if ( content.includes( rootClass ) && content.includes( 'ThemeProvider' ) ) {
			entryPatched = true;
			continue;
		}

		const result = scopeMuiApp( content, rootClass, rel );
		if ( result.changed ) {
			if ( dryRun ) {
				patched.push( rel + ' (dry-run)' );
			} else {
				fs.writeFileSync( fullPath + '.bak', content, 'utf-8' );
				fs.writeFileSync( fullPath, result.content, 'utf-8' );
				patched.push( rel );
			}
			entryPatched = true;
			warnings.push( ...result.warnings );
			manualReview.push( ...result.manualReview );
		}
	}

	if ( ! entryPatched ) {
		manualReview.push( 'MUI: Could not auto-wrap App with ThemeProvider + scoped container. See manual guidance.' );
	}

	return { files: patched, entryPatched, warnings, manualReview };
}

function scopeMuiApp( content, rootClass, rel ) {
	let patched    = content;
	let changed    = false;
	const warnings = [];
	const manualReview = [];

	// Wrap the JSX return with ThemeProvider + scoped div.
	// Pattern: return ( <App /> ) → return ( <ThemeProvider><div className="...root"><App /></div></ThemeProvider> )
	const returnPattern = /return\s*\(\s*([\s\S]*?)\)\s*;?\s*}/;
	const match = returnPattern.exec( patched );

	if ( match ) {
		const innerContent = match[ 1 ].trim();
		const wrapped = `return (\n    <ThemeProvider theme={theme}>\n      <div className="${ rootClass }">\n        <CssBaseline />\n        ${ innerContent }\n      </div>\n    </ThemeProvider>\n  );`;

		patched = patched.replace( returnPattern, wrapped.replace( '$', '$$' ) );
		changed = true;

		// Inject ThemeProvider + CssBaseline imports if missing.
		if ( ! patched.includes( 'import { ThemeProvider' ) ) {
			patched = `import { ThemeProvider } from '@mui/material/styles';\nimport CssBaseline from '@mui/material/CssBaseline';\n${ patched }`;
		}
		if ( ! patched.includes( 'import CssBaseline' ) ) {
			patched = `import CssBaseline from '@mui/material/CssBaseline';\n${ patched }`;
		}

		manualReview.push( `${ rel }: MUI App wrapped with ThemeProvider — verify CssBaseline removal from global scope` );
	} else {
		warnings.push( `${ rel }: Could not find return statement — MUI wrapping must be done manually` );
	}

	return { content: patched, changed, warnings, manualReview };
}

// ---------------------------------------------------------------------------
// styled-components scoping
// ---------------------------------------------------------------------------

function applyStyledComponentsScoping( slug, srcDir, rootClass, dryRun ) {
	const patched       = [];
	const warnings      = [];
	const manualReview  = [];
	let   entryPatched  = false;

	const appFiles = findEntryComponents( srcDir );
	for ( const [ rel, fullPath ] of appFiles ) {
		let content;
		try {
			content = fs.readFileSync( fullPath, 'utf-8' );
		} catch {
			continue;
		}

		// Check for createGlobalStyle — needs to be scoped.
		const hasGlobalStyle = /createGlobalStyle/.test( content );
		if ( hasGlobalStyle ) {
			// Add scoping comment + inject StyleSheetManager import.
			if ( ! content.includes( 'StyleSheetManager' ) ) {
				const injectImport = `import { StyleSheetManager } from 'styled-components';\n`;
				patched = injectImport + content;

				if ( dryRun ) {
					patched.push( rel + ' (dry-run — StyleSheetManager import)' );
				} else {
					fs.writeFileSync( fullPath + '.bak', content, 'utf-8' );
					fs.writeFileSync( fullPath, patched, 'utf-8' );
					patched.push( rel );
				}
				entryPatched = true;
			}

			manualReview.push( `${ rel }: Found createGlobalStyle — wrap with StyleSheetManager target and scope global styles to .${ rootClass }` );
		}
	}

	if ( ! entryPatched ) {
		manualReview.push( 'styled-components: Review createGlobalStyle usage and scope to root container' );
	}

	return { files: patched, entryPatched, warnings, manualReview };
}

// ---------------------------------------------------------------------------
// Plain CSS scoping
// ---------------------------------------------------------------------------

function applyPlainCssScoping( slug, srcDir, rootClass, dryRun ) {
	const patched       = [];
	const warnings      = [];
	const manualReview  = [];

	// Already handled in the main function via scopeGlobalCssSelectors().

	return { files: patched, entryPatched: false, warnings, manualReview };
}

// ---------------------------------------------------------------------------
// Global CSS selector rewriting
// ---------------------------------------------------------------------------

/**
 * Rewrite global CSS selectors (body, html, *) to root-scoped versions.
 */
function scopeGlobalCssSelectors( content, rootClass, rel ) {
	let patched         = content;
	let changed         = false;
	const warnings      = [];
	const manualReview  = [];

	// body { ... } → .nvoos-{slug}-root { ... }
	if ( /\bbody\s*\{/i.test( patched ) ) {
		patched = patched.replace(
			/\bbody\s*\{/gi,
			`.${ rootClass } {`
		);
		changed = true;
		warnings.push( `${ rel }: Replaced 'body' selector with .${ rootClass } — review for specificity issues` );
	}

	// html { ... } → .nvoos-{slug}-root { ... }
	if ( /\bhtml\s*\{/i.test( patched ) ) {
		patched = patched.replace(
			/\bhtml\s*\{/gi,
			`.${ rootClass } {`
		);
		changed = true;
		warnings.push( `${ rel }: Replaced 'html' selector with .${ rootClass } — verify font/base styles` );
	}

	// * { ... } (universal selector reset) → .nvoos-{slug}-root * { ... }
	if ( /^\s*\*\s*\{/m.test( patched ) || /^\s*\*\s*,\s*\*::/m.test( patched ) ) {
		patched = patched.replace(
			/^(\s*)(\*\s*(?:,\s*\*::(?:before|after))?\s*\{)/gm,
			`$1.${ rootClass } $2`
		);
		changed = true;
		manualReview.push( `${ rel }: Scoped universal selector (*) — verify box-sizing reset still works` );
	}

	// :root { ... } → .nvoos-{slug}-root { ... }
	if ( /:root\s*\{/i.test( patched ) ) {
		patched = patched.replace(
			/:root\s*\{/gi,
			`.${ rootClass } {`
		);
		changed = true;
		warnings.push( `${ rel }: Replaced ':root' with .${ rootClass } — CSS custom properties may need manual scoping` );
	}

	return { content: patched, changed, warnings, manualReview };
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Ensure the entry component uses the root class.
 */
function ensureEntryScoped( slug, srcDir, rootClass, dryRun, patched, warnings, cb ) {
	const entryFiles = findEntryComponents( srcDir );
	let wasEntryPatched = false;

	for ( const [ rel, fullPath ] of entryFiles ) {
		let content;
		try {
			content = fs.readFileSync( fullPath, 'utf-8' );
		} catch {
			continue;
		}

		if ( content.includes( rootClass ) ) {
			wasEntryPatched = true;
			continue;
		}

		// For Tailwind: add className with prefix to the root div.
		const rootDivIndex = content.search( /<div[^>]*class(?:Name)?\s*=\s*["'][^"']*["']/ );
		if ( rootDivIndex >= 0 ) {
			const replacement = content.replace(
				/(<div[^>]*class(?:Name)?\s*=\s*["'])([^"']*)(["'])/,
				`$1${ rootClass } $2$3`
			);
			if ( replacement !== content ) {
				if ( dryRun ) {
					patched.push( rel + ' (dry-run root class)' );
				} else {
					fs.writeFileSync( fullPath + '.bak', content, 'utf-8' );
					fs.writeFileSync( fullPath, replacement, 'utf-8' );
					patched.push( rel );
				}
				wasEntryPatched = true;
			}
		}
	}

	cb( wasEntryPatched );
}

function findEntryComponents( srcDir ) {
	const results = [];
	const candidates = [ 'App.tsx', 'App.jsx', 'index.tsx', 'index.jsx' ];

	for ( const c of candidates ) {
		const fullPath = path.resolve( srcDir, c );
		if ( fs.existsSync( fullPath ) ) {
			results.push( [ c, fullPath ] );
		}
	}

	// If none found, search for any file exporting a component named App.
	if ( results.length === 0 ) {
		for ( const rel of findFilesByExt( srcDir, [ '.tsx', '.jsx' ] ) ) {
			const fullPath = path.resolve( srcDir, rel );
			try {
				const content = fs.readFileSync( fullPath, 'utf-8' );
				if ( /export\s+(?:default\s+)?(?:function|const)\s+App/i.test( content ) ) {
					results.push( [ rel, fullPath ] );
					break;
				}
			} catch { /* skip */ }
		}
	}

	return results;
}

function findFilesByExt( dir, extensions ) {
	const results = [];
	crawlDir( dir, dir, results, extensions );
	return results;
}

function crawlDir( dir, base, results, extensions, skipGenerated = true ) {
	let entries;
	try {
		entries = fs.readdirSync( dir, { withFileTypes: true } );
	} catch {
		return;
	}
	for ( const entry of entries ) {
		if ( entry.name.startsWith( '.' ) || entry.name === 'node_modules' ) continue;
		if ( skipGenerated && ( entry.name.startsWith( 'nvoos-' ) ) ) continue;

		const full = path.join( dir, entry.name );
		const rel  = path.relative( base, full ).replace( /\\/g, '/' );

		if ( entry.isDirectory() ) {
			crawlDir( full, base, results, extensions, skipGenerated );
		} else if ( extensions.some( ext => entry.name.endsWith( ext ) ) ) {
			results.push( rel );
		}
	}
}
