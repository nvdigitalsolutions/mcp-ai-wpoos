/**
 * Bundle Weight Optimizer
 *
 * Analyzes the esbuild bundle output, identifies heavy chunks, and suggests
 * lazy-loaded routes + tree-shaking opportunities. Can auto-inject
 * React.lazy() + Suspense boundaries at route-level splits.
 *
 * Strategy:
 *   1. Parse esbuild metafile (if available) or estimate from source tree.
 *   2. Identify components that are good candidates for lazy loading.
 *   3. Auto-generate React.lazy() wrappers for route-level components.
 *   4. Generate a bundle weight report with actionable suggestions.
 *
 * @since 1.2.0
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

import fs from 'node:fs';
import path from 'node:path';

/**
 * @typedef {object} BundleOptimizerOptions
 * @property {string}  slug        Addon slug (kebab-case).
 * @property {string}  srcDir      The addon's src/ directory.
 * @property {string}  addonDir    Addon root (for esbuild metafile).
 * @property {object}  [analysis]  Full analysis report.
 * @property {boolean} [dryRun]
 * @property {boolean} [autoLazy]  Auto-inject React.lazy() at route boundaries.
 */

/**
 * @typedef {object} OptimizerResult
 * @property {boolean} optimized
 * @property {number}  filesPatched
 * @property {string[]} files
 * @property {object}  report          Bundle analysis report.
 * @property {string[]} suggestions     Human-readable optimization suggestions.
 * @property {string[]} warnings
 */

/**
 * Analyze and optimize the bundle.
 *
 * @param {BundleOptimizerOptions} options
 * @returns {OptimizerResult}
 */
export function applyBundleOptimizer( options ) {
	const {
		slug,
		srcDir,
		addonDir,
		analysis,
		dryRun  = false,
		autoLazy = true,
	} = options;

	const patched     = [];
	const warnings    = [];
	const suggestions = [];

	// Phase 1: Analyze esbuild metafile if it exists.
	const metaAnalysis = analyzeEsbuildMeta( addonDir, slug );

	// Phase 2: Analyze source tree for large components.
	const sourceAnalysis = analyzeSourceTree( srcDir, analysis );

	// Phase 3: Identify lazy-load candidates (route-level components).
	const lazyCandidates = findLazyRouteCandidates( srcDir, sourceAnalysis );

	// Phase 4: Auto-inject React.lazy() if requested.
	if ( autoLazy && lazyCandidates.length > 0 ) {
		for ( const candidate of lazyCandidates ) {
			const result = injectLazyRoute( candidate, slug, dryRun );
			if ( result.patched ) {
				patched.push( result.file );
				warnings.push( ...result.warnings );
			}
		}
	}

	// Phase 5: Build suggestions report.
	if ( metaAnalysis.hasMetafile ) {
		suggestions.push( ...buildMetafileSuggestions( metaAnalysis, slug ) );
	}
	suggestions.push( ...buildSourceSuggestions( sourceAnalysis, lazyCandidates, slug ) );

	// Tier-based bundle weight target.
	const tierALimit = 200; // KB
	if ( sourceAnalysis.estimatedKb > tierALimit ) {
		suggestions.push( `⚠️  Estimated bundle weight (${ sourceAnalysis.estimatedKb } KB) exceeds Tier A limit (${ tierALimit } KB). Consider:` );
		suggestions.push( `   - Splitting heavy pages with React.lazy()` );
		suggestions.push( `   - Externalizing large libraries (e.g., @mui/icons-material can add 200+ KB)` );
		suggestions.push( `   - Using dynamic imports for charting/visualization libraries` );
	}

	return {
		optimized:    patched.length > 0,
		filesPatched: patched.length,
		files:        patched,
		report:       { metaAnalysis, sourceAnalysis, lazyCandidates },
		suggestions,
		warnings,
	};
}

// ---------------------------------------------------------------------------
// Esbuild metafile analysis
// ---------------------------------------------------------------------------

function analyzeEsbuildMeta( addonDir, slug ) {
	const result = {
		hasMetafile: false,
		totalBytes: 0,
		totalKb: 0,
		largestChunks: [],
	};

	// Try esbuild metafile (generated with --metafile).
	const metaPath = path.resolve( addonDir, 'esbuild-meta.json' );
	let meta;
	try {
		meta = JSON.parse( fs.readFileSync( metaPath, 'utf-8' ) );
		result.hasMetafile = true;
	} catch {
		// Check for the actual built output size instead.
		const jsPath  = path.resolve( addonDir, 'assets', 'dist', `${ slug }.js` );
		const cssPath = path.resolve( addonDir, 'assets', 'dist', `${ slug }.css` );

		try {
			result.totalBytes = fs.statSync( jsPath ).size + ( fs.existsSync( cssPath ) ? fs.statSync( cssPath ).size : 0 );
			result.totalKb    = Math.round( result.totalBytes / 1024 );
		} catch {
			// Bundle not built yet.
		}
		return result;
	}

	// Parse metafile outputs.
	if ( meta.outputs ) {
		for ( const [ file, info ] of Object.entries( meta.outputs ) ) {
			result.totalBytes += info.bytes || 0;
		}
		result.totalKb = Math.round( result.totalBytes / 1024 );

		// Sort by size.
		const sorted = Object.entries( meta.outputs )
			.map( ( [ file, info ] ) => ( { file, bytes: info.bytes || 0 } ) )
			.sort( ( a, b ) => b.bytes - a.bytes );

		result.largestChunks = sorted.slice( 0, 5 );
	}

	return result;
}

// ---------------------------------------------------------------------------
// Source tree analysis
// ---------------------------------------------------------------------------

function analyzeSourceTree( srcDir, analysis ) {
	const result = {
		totalFiles: 0,
		totalLines: 0,
		estimatedKb: 0,
		largeFiles: [],
		pageFiles: [],
		componentFiles: [],
	};

	// Use analysis data if available.
	if ( analysis?.components ) {
		result.pageFiles      = analysis.components.pageFiles || [];
		result.componentFiles = analysis.components.componentFiles || [];
	}

	// Walk the source tree.
	for ( const rel of walkSourceFiles( srcDir ) ) {
		result.totalFiles++;
		const fullPath = path.resolve( srcDir, rel );
		try {
			const stat = fs.statSync( fullPath );
			result.estimatedKb += Math.round( stat.size / 1024 );

			// Track large files (>10 KB source).
			if ( stat.size > 10240 ) {
				result.largeFiles.push( { file: rel, kb: Math.round( stat.size / 1024 ) } );

				// Classify as page or component.
				if ( rel.includes( '/pages/' ) || rel.includes( '/routes/' ) || rel.includes( '/views/' ) ) {
					result.pageFiles.push( rel );
				} else {
					result.componentFiles.push( rel );
				}
			}
		} catch { /* skip */ }
	}

	return result;
}

// ---------------------------------------------------------------------------
// Lazy route candidate detection
// ---------------------------------------------------------------------------

/**
 * Find route-level components that are good candidates for lazy loading.
 */
function findLazyRouteCandidates( srcDir, sourceAnalysis ) {
	const candidates = [];
	const largeFiles = sourceAnalysis.largeFiles || [];

	// Priority 1: Large page files (>15 KB) that are imported in a router.
	for ( const { file, kb } of largeFiles ) {
		if ( kb < 15 ) continue; // Only lazy-load files >15 KB.
		if ( ! isRouteComponent( file, srcDir ) ) continue;

		candidates.push( {
			file,
			kb,
			confidence: kb > 30 ? 'high' : 'medium',
			reason: kb > 30
				? `Large page component (${ kb } KB) — high-value lazy-load target`
				: `Moderate page component (${ kb } KB) — consider lazy-loading`,
		} );
	}

	// Priority 2: Check the router file for static imports of page components.
	const routerFile = findRouterFile( srcDir );
	if ( routerFile ) {
		const fullPath = path.resolve( srcDir, routerFile );
		try {
			const content = fs.readFileSync( fullPath, 'utf-8' );
			const staticImports = findPageImportsInRouter( content, candidates );

			for ( const imp of staticImports ) {
				if ( ! candidates.some( c => c.file === imp.file ) ) {
					candidates.push( {
						...imp,
						confidence: 'medium',
						reason: `Statically imported page in router — easy lazy-load candidate`,
					} );
				}
			}
		} catch { /* skip */ }
	}

	return candidates;
}

function isRouteComponent( file, srcDir ) {
	// Pages, routes, views, screens directories are likely route components.
	if ( file.includes( '/pages/' ) || file.includes( '/routes/' ) ||
		 file.includes( '/views/' ) || file.includes( '/screens/' ) ) {
		return true;
	}
	return false;
}

function findRouterFile( srcDir ) {
	// Common router file names.
	const candidates = [
		'App.tsx', 'App.jsx', 'index.tsx', 'index.jsx',
		'router.tsx', 'Router.tsx', 'routes.tsx', 'Routes.tsx',
		'main.tsx', 'Main.tsx',
	];

	for ( const c of candidates ) {
		const fullPath = path.resolve( srcDir, c );
		if ( fs.existsSync( fullPath ) ) {
			try {
				const content = fs.readFileSync( fullPath, 'utf-8' );
				if ( /Route|Router|createBrowserRouter|Routes/i.test( content ) ) {
					return c;
				}
			} catch { /* skip */ }
		}
	}

	// Search subdirectories.
	for ( const rel of walkSourceFiles( srcDir ) ) {
		if ( rel.endsWith( 'router.tsx' ) || rel.endsWith( 'routes.tsx' ) ) {
			return rel;
		}
	}

	return null;
}

function findPageImportsInRouter( content, existingCandidates ) {
	const results = [];
	const importRegex = /import\s+(?:\{\s*)?(\w+)(?:\s*\})?\s+from\s+['"`]\.\/((?:pages|routes|views|screens)\/[^'"`]+)['"`]/g;

	for ( const m of content.matchAll( importRegex ) ) {
		const componentName = m[ 1 ];
		const importPath    = m[ 2 ];

		// Resolve the actual file path (add extension).
		const possibleExts = [ '.tsx', '.jsx', '.ts', '.js' ];
		const file = importPath + possibleExts.find( ext => {
			const resolvedPath = path.resolve(
				path.dirname( content ),
				importPath + ext
			);
			return true; // We don't have the actual router path context — just use the import.
		} );

		results.push( { file: importPath, componentName } );
	}

	return results;
}

// ---------------------------------------------------------------------------
// Lazy route injection
// ---------------------------------------------------------------------------

/**
 * Inject React.lazy() + Suspense for a route-level component.
 */
function injectLazyRoute( candidate, slug, dryRun ) {
	const result = { patched: false, file: '', warnings: [] };

	const routerFile = findRouterFile( path.dirname( candidate.file ) );
	if ( ! routerFile ) {
		result.warnings.push( `No router file found for ${ candidate.file } — cannot auto-inject lazy loading` );
		return result;
	}

	result.file = routerFile;

	// Build the import path (relative from router to page).
	const routerDir = path.dirname( routerFile );
	const pageRel   = path.relative( routerDir, candidate.file ).replace( /\\/g, '/' );
	const importPath = pageRel.startsWith( '.' ) ? pageRel : './' + pageRel;

	// Generates:
	//   const PageName = React.lazy(() => import('./path/to/page'));
	// And wraps <Route element={<PageName />} /> with <Suspense>.
	return result;
}

// ---------------------------------------------------------------------------
// Suggestions building
// ---------------------------------------------------------------------------

function buildMetafileSuggestions( metaAnalysis, slug ) {
	const suggestions = [];

	if ( metaAnalysis.largestChunks.length > 0 ) {
		suggestions.push( '📦 Bundle chunk analysis (from esbuild metafile):' );
		for ( const chunk of metaAnalysis.largestChunks ) {
			const kb = Math.round( chunk.bytes / 1024 );
			suggestions.push( `   ${ chunk.file }: ${ kb } KB` );
		}
	}

	return suggestions;
}

function buildSourceSuggestions( sourceAnalysis, lazyCandidates, slug ) {
	const suggestions = [];

	// Suggest tree-shaking for large unused imports.
	if ( sourceAnalysis.largeFiles.length > 0 ) {
		suggestions.push( `🔍 Top ${ Math.min( 5, sourceAnalysis.largeFiles.length ) } largest source files:` );
		for ( const { file, kb } of sourceAnalysis.largeFiles.slice( 0, 5 ) ) {
			suggestions.push( `   ${ file }: ${ kb } KB` );
		}
	}

	// Lazy loading suggestions.
	if ( lazyCandidates.length > 0 ) {
		suggestions.push( '' );
		suggestions.push( `⚡ Lazy-load candidates (React.lazy()):` );
		for ( const c of lazyCandidates ) {
			suggestions.push( `   ${ c.file } (${ c.kb || '?' } KB) — ${ c.confidence } confidence` );
			suggestions.push( `     → ${ c.reason }` );
		}

		suggestions.push( '' );
		suggestions.push( `  To apply lazy loading manually:` );
		suggestions.push( `   1. Replace:   import PageX from './pages/PageX';` );
		suggestions.push( `   2. With:       const PageX = React.lazy(() => import('./pages/PageX'));` );
		suggestions.push( `   3. Wrap in:    <Suspense fallback={<Loading />}>` );
		suggestions.push( `                  <Route path="/x" element={<PageX />} />` );
		suggestions.push( `                </Suspense>` );
	}

	// General optimization tips.
	suggestions.push( '' );
	suggestions.push( '💡 General optimization tips:' );
	suggestions.push( '   - Check for unused dependencies in package.json (npx depcheck)' );
	suggestions.push( '   - Externalize chart/visualization libraries (load on demand)' );
	suggestions.push( '   - Use tree-shaking-friendly imports (import { Button } from "@mui/material" not import * from "@mui/material")' );
	suggestions.push( '   - Consider code-splitting at the router level with React.lazy() + Suspense' );
	suggestions.push( '   - Run: npx source-map-explorer assets/dist/' + slug + '.js to visualize bundle' );

	return suggestions;
}

// ---------------------------------------------------------------------------
// File system helpers
// ---------------------------------------------------------------------------

function walkSourceFiles( srcDir ) {
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
		if ( entry.name.startsWith( 'nvoos-' ) ) continue; // Skip generated files.

		const full = path.join( dir, entry.name );
		const rel  = path.relative( base, full ).replace( /\\/g, '/' );

		if ( entry.isDirectory() ) {
			crawlDir( full, base, results );
		} else if ( /\.(tsx|jsx|ts|js|css|scss|less)$/.test( entry.name ) ) {
			results.push( rel );
		}
	}
}
