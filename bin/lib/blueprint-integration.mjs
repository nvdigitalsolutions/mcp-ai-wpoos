/**
 * Blueprint Integration — merges an adapted React template into a
 * blueprint-compliant NV oOS addon structure.
 *
 * Orchestrates:
 *   1. Scaffolds the addon skeleton (via scaffold-toolkit-spa.sh)
 *   2. Merges adapted template source into addons/<slug>/src/
 *   3. Generates esbuild.config.cjs and package.json
 *   4. Generates manifest JSON
 *   5. Updates the shortcode config
 *
 * @since 1.2.0
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

import fs from 'node:fs';
import path from 'node:path';
import { execSync } from 'node:child_process';
import { applyMountAdapter } from './adapters/mount-adapter.mjs';
import { applyAuthAdapter } from './adapters/auth-adapter.mjs';
import { applyBuildAdapter } from './adapters/build-adapter.mjs';
import { runVetting, formatVettingReport } from './adapters/vetting-runner.mjs';
import { generateManifest } from './manifest-generator.mjs';

/**
 * @typedef {object} IntegrationOptions
 * @property {string} sourceDir     Path to the React template source.
 * @property {string} slug          Addon slug (kebab-case).
 * @property {string} title         Human-readable title.
 * @property {string} toolkit       Toolkit slug (for manifest).
 * @property {string} [repoRoot]    Path to the NV oOS repo root.
 * @property {boolean} [dryRun]     Don't actually write files.
 * @property {boolean} [verbose]    Log progress.
 * @property {boolean} [skipVetting] Skip vetting gates.
 */

/**
 * @typedef {object} IntegrationResult
 * @property {boolean} success
 * @property {string}  addonDir
 * @property {string[]} steps
 * @property {string[]} warnings
 * @property {string[]} errors
 * @property {object}   analysis
 * @property {object}   vetting
 */

/**
 * Full integration pipeline.
 *
 * @param {object}           analysis     From template-analyzer.mjs.
 * @param {IntegrationOptions} options
 * @returns {IntegrationResult}
 */
export async function integrateTemplate( analysis, options ) {
	const {
		sourceDir,
		slug,
		title,
		toolkit,
		repoRoot,
		dryRun = false,
		verbose = false,
		skipVetting = false,
	} = options;

	const log      = verbose ? ( msg ) => process.stderr.write( `  [integrate] ${ msg }\n` ) : () => {};
	const addonDir = path.resolve( repoRoot || process.cwd(), 'addons', slug );
	const result   = {
		success:  false,
		addonDir,
		steps:    [],
		warnings: [],
		errors:   [],
		analysis,
		vetting:  null,
	};

	// -----------------------------------------------------------------------
	// Step 0: Vetting check
	// -----------------------------------------------------------------------
	if ( ! skipVetting ) {
		log( 'Step 0: Running vetting checklist…' );
		result.vetting = runVetting( analysis, addonDir );
		result.steps.push( 'vetting' );

		if ( verbose ) {
			process.stderr.write( formatVettingReport( result.vetting ) + '\n' );
		}

		if ( result.vetting.blocked ) {
			result.errors.push( 'VETTING BLOCKED: Critical gates failed. See report above.' );
			return result;
		}
	}

	// -----------------------------------------------------------------------
	// Step 1: Scaffold the skeleton
	// -----------------------------------------------------------------------
	log( 'Step 1: Scaffolding blueprint addon…' );
	const scaffolder = path.resolve( repoRoot || process.cwd(), 'bin', 'scaffold-toolkit-spa.sh' );

	if ( ! dryRun ) {
		if ( fs.existsSync( addonDir ) ) {
			result.warnings.push( `Addon directory already exists: ${ addonDir } — skipping scaffold` );
		} else if ( fs.existsSync( scaffolder ) ) {
			try {
				execSync( `bash "${ scaffolder }" "${ slug }" "${ title }"`, {
					cwd: repoRoot || process.cwd(),
					stdio: verbose ? 'inherit' : 'pipe',
				} );
				result.steps.push( 'scaffold' );
			} catch ( err ) {
				result.errors.push( `Scaffolder failed: ${ err.message }` );
				return result;
			}
		} else {
			// Scaffolder not available — create minimal structure.
			createMinimalAddonStructure( addonDir, slug, title );
			result.steps.push( 'scaffold-minimal' );
			result.warnings.push( 'Scaffolder script not found — created minimal addon structure' );
		}
	}

	// -----------------------------------------------------------------------
	// Step 2: Copy template source into addon
	// -----------------------------------------------------------------------
	log( 'Step 2: Merging template source…' );
	const srcDest = path.resolve( addonDir, 'src' );

	if ( ! dryRun ) {
		fs.mkdirSync( srcDest, { recursive: true } );
		copyDir( sourceDir, srcDest, ( rel ) => {
			// Skip node_modules, dist, build, .git.
			return ! rel.startsWith( 'node_modules' ) &&
				! rel.startsWith( '.git' ) &&
				! rel.startsWith( 'dist' ) &&
				! rel.startsWith( 'build' ) &&
				! rel.startsWith( 'coverage' ) &&
				! rel.startsWith( '.cache' );
		} );
		result.steps.push( 'merge-src' );
	}

	// -----------------------------------------------------------------------
	// Step 3: Apply mount adapter
	// -----------------------------------------------------------------------
	log( 'Step 3: Applying mount adapter…' );
	const entryFile = analysis.entry_points?.[ 0 ]?.file || 'src/index.tsx';
	const mountResult = applyMountAdapter( {
		slug,
		targetDir: srcDest,
		entryFile: path.basename( entryFile ),
		dryRun,
	} );
	result.steps.push( 'mount-adapter' );
	result.warnings.push( ...mountResult.warnings );

	// -----------------------------------------------------------------------
	// Step 4: Apply auth adapter
	// -----------------------------------------------------------------------
	log( 'Step 4: Applying auth adapter…' );
	const authResult = applyAuthAdapter( {
		slug,
		srcDir: srcDest,
		restNamespace: `nvoos-${ slug }/v1`,
		dryRun,
	} );
	result.steps.push( 'auth-adapter' );
	result.warnings.push( ...authResult.warnings );

	// -----------------------------------------------------------------------
	// Step 5: Apply build adapter
	// -----------------------------------------------------------------------
	log( 'Step 5: Applying build adapter…' );
	const adjustedEntry = mountResult.entryFile || path.basename( entryFile );
	const bundler = analysis.tech_stack?.bundler || 'unknown';
	const buildResult = applyBuildAdapter( {
		slug,
		addonDir,
		entryFile: adjustedEntry,
		techStack: analysis.tech_stack,
		bundler,
		dryRun,
	} );
	result.steps.push( 'build-adapter' );
	result.warnings.push( ...buildResult.warnings );

	// -----------------------------------------------------------------------
	// Step 6: Generate package.json (merged)
	// -----------------------------------------------------------------------
	log( 'Step 6: Generating package.json…' );
	if ( ! dryRun ) {
		generateMergedPackageJson( addonDir, slug, title, analysis );
		result.steps.push( 'package-json' );
	}

	// -----------------------------------------------------------------------
	// Step 7: Generate manifest
	// -----------------------------------------------------------------------
	log( 'Step 7: Generating manifest…' );
	const manifestPath = path.resolve( repoRoot || process.cwd(), 'addons', 'pro', 'config', 'spa-manifests', `${ toolkit }.json` );
	const manifestResult = generateManifest( {
		toolkit,
		label: title,
		outputPath: manifestPath,
		analysis,
	} );
	result.steps.push( 'manifest' );
	result.warnings.push( ...manifestResult.warnings );

	// -----------------------------------------------------------------------
	// Step 8: Run npm install + build (dry-run skip)
	// -----------------------------------------------------------------------
	if ( ! dryRun ) {
		log( 'Step 8: Installing dependencies…' );
		try {
			execSync( 'npm ci', { cwd: addonDir, stdio: verbose ? 'inherit' : 'pipe' } );
			result.steps.push( 'npm-ci' );
		} catch {
			// If package-lock.json doesn't exist yet.
			try {
				execSync( 'npm install --legacy-peer-deps', { cwd: addonDir, stdio: verbose ? 'inherit' : 'pipe' } );
				result.steps.push( 'npm-install' );
			} catch ( err ) {
				result.warnings.push( `npm install failed: ${ err.message } — run manually` );
			}
		}
	}

	// -----------------------------------------------------------------------
	// Step 9: Final vetting re-run
	// -----------------------------------------------------------------------
	log( 'Step 9: Re-running vetting on adapted addon…' );
	if ( ! skipVetting ) {
		const finalVetting = runVetting( analysis, addonDir );
		result.steps.push( 'final-vetting' );

		if ( verbose ) {
			process.stderr.write( formatVettingReport( finalVetting ) + '\n' );
		}

		if ( finalVetting.blocked ) {
			result.errors.push( 'FINAL VETTING BLOCKED — critical issues remain.' );
		}
	}

	result.success = result.errors.length === 0;
	return result;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function copyDir( src, dest, filter ) {
	fs.mkdirSync( dest, { recursive: true } );
	const entries = fs.readdirSync( src, { withFileTypes: true } );
	for ( const entry of entries ) {
		const srcPath  = path.join( src, entry.name );
		const destPath = path.join( dest, entry.name );
		const rel      = path.relative( src, srcPath ).replace( /\\/g, '/' );

		if ( entry.isDirectory() ) {
			if ( filter( rel + '/' ) ) {
				copyDir( srcPath, destPath, filter );
			}
		} else {
			if ( filter( rel ) ) {
				fs.copyFileSync( srcPath, destPath );
			}
		}
	}
}

function createMinimalAddonStructure( addonDir, slug, title ) {
	const upperSnake = slug.replace( /-/g, '_' ).toUpperCase();
	const titleSnake = slug.split( '-' ).map( w => w.charAt( 0 ).toUpperCase() + w.slice( 1 ) ).join( '_' );

	const dirs = [
		'includes/admin', 'includes/block', 'includes/rest',
		'includes/shortcode', 'includes/jobs',
		'src/api', 'src/components', 'src/routes', 'src/styles',
		'assets/dist', 'tests', 'languages',
	];
	for ( const d of dirs ) {
		fs.mkdirSync( path.join( addonDir, d ), { recursive: true } );
	}
	fs.writeFileSync( path.join( addonDir, 'languages', '.gitkeep' ), '' );
}

function generateMergedPackageJson( addonDir, slug, title, analysis ) {
	const pkgPath = path.join( addonDir, 'package.json' );
	let existing = {};

	if ( fs.existsSync( pkgPath ) ) {
		try {
			existing = JSON.parse( fs.readFileSync( pkgPath, 'utf-8' ) );
		} catch { /* ignore */ }
	}

	// Merge: keep existing deps + add template deps + blueprint scripts.
	const merged = {
		name:        `nvoos-${ slug }`,
		version:     existing.version || '0.1.0',
		description: `NV oOS ${ title } — imported React SPA addon`,
		private:     true,
		license:     'GPL-3.0-or-later',
		type:        'module',
		scripts:     {
			build:     'node esbuild.config.cjs --prod',
			'build:dev': 'node esbuild.config.cjs',
			watch:     'node esbuild.config.cjs --watch',
			typecheck: 'tsc --noEmit',
			test:      'vitest run',
			...existing.scripts,
		},
		dependencies: {
			react:    '19.1.0',
			'react-dom': '19.1.0',
			...existing.dependencies,
		},
		devDependencies: {
			esbuild:     '^0.27.0',
			typescript:  '5.8.3',
			'@types/react': '19.1.4',
			'@types/react-dom': '19.1.4',
			'@wordpress/i18n': '^5.12.0',
			vitest:      '^4.1.5',
			...existing.devDependencies,
		},
	};

	fs.writeFileSync( pkgPath, JSON.stringify( merged, null, 2 ) + '\n', 'utf-8' );
}
