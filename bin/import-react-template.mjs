#!/usr/bin/env node

/**
 * NV oOS React Template Import CLI
 *
 * End-to-end pipeline for importing external React SPA projects (Envato
 * templates, admin dashboards, landing pages, toolkit-specific apps) into
 * blueprint-compliant NV oOS addons.
 *
 * Usage:
 *   node bin/import-react-template.mjs --source ~/Downloads/template/ --slug my-dashboard --title "My Dashboard"
 *
 *   # Analyze only (no files written)
 *   node bin/import-react-template.mjs --source ~/Downloads/template/ --analyze-only
 *
 *   # Interactive mode (prompt for each adapter)
 *   node bin/import-react-template.mjs --source ~/Downloads/template/ --slug my-dashboard --title "My Dashboard" --interactive
 *
 *   # Auto-apply all adapters
 *   node bin/import-react-template.mjs --source ~/Downloads/template/ --slug my-dashboard --title "My Dashboard" --auto-fix
 *
 * Options:
 *   --source <dir>          Path to the React template directory (required)
 *   --source-zip <file>     Path to a ZIP file containing the template
 *   --slug <slug>           Addon slug, kebab-case (required for import)
 *   --title <title>         Human-readable title (required for import)
 *   --toolkit <slug>        Toolkit slug for manifest (defaults to --slug)
 *   --analyze-only          Run analysis only; do not scaffold or adapt
 *   --auto-fix              Automatically apply all adapters
 *   --interactive           Prompt before each adapter
 *   --dry-run               Show what would be done without writing files
 *   --verbose               Show detailed progress
 *   --skip-vetting          Skip the vetting checklist
 *   --output-report <path>  Save the analysis report to a JSON file
 *   --envato-id <id>        Envato item ID for metadata
 *   --help                  Show this help
 *
 * @since 1.2.0
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { analyzeTemplate } from './lib/template-analyzer.mjs';
import { getCatalog, planAdapters } from './lib/adapter-catalog.mjs';
import { integrateTemplate } from './lib/blueprint-integration.mjs';
import { runVetting, formatVettingReport } from './lib/adapters/vetting-runner.mjs';

// ---------------------------------------------------------------------------
// CLI argument parsing
// ---------------------------------------------------------------------------

const __filename = fileURLToPath( import.meta.url );
const __dirname  = path.dirname( __filename );

function printHelp() {
	const help = `
NV oOS React Template Import CLI
================================

End-to-end pipeline for importing external React SPA projects (Envato
templates, admin dashboards, landing pages, toolkit-specific apps) into
blueprint-compliant NV oOS addons.

USAGE:
  node bin/import-react-template.mjs [OPTIONS]

REQUIRED (for import mode):
  --source <dir>         Path to the React template root directory
  --slug <slug>          Addon slug (kebab-case, e.g. "crm-dashboard")
  --title <title>        Human-readable title (e.g. "CRM Dashboard")

OPTIONAL:
  --source-zip <file>    Path to a ZIP file containing the template
  --toolkit <slug>       Toolkit slug for manifest (defaults to --slug)
  --analyze-only         Run analysis only; do not scaffold or adapt
  --auto-fix             Automatically apply all adapters
  --interactive          Prompt before each adapter step
  --dry-run              Show what would be done without writing files
  --verbose              Show detailed progress
  --skip-vetting         Skip the vetting checklist
  --output-report <path> Save the analysis report to a JSON file
  --envato-id <id>       Envato item ID for metadata tracking
  --help                 Show this help

EXAMPLES:
  # Analyze a template and print the report
  node bin/import-react-template.mjs --source ~/Downloads/material-dashboard/ --analyze-only

  # Full import with auto-fix
  node bin/import-react-template.mjs --source ~/Downloads/crm-template/ \\
    --slug crm-dashboard --title "CRM Dashboard" --auto-fix

  # Import from ZIP with interactive prompts
  node bin/import-react-template.mjs --source-zip ~/Downloads/template.zip \\
    --slug analytics-panel --title "Analytics Panel" --interactive

  # Dry-run to see what would be done
  node bin/import-react-template.mjs --source ~/Downloads/template/ \\
    --slug my-app --title "My App" --dry-run --verbose
`;

	process.stdout.write( help );
}

function parseArgs( argv ) {
	const args = {
		source:        null,
		sourceZip:     null,
		slug:          null,
		title:         null,
		toolkit:       null,
		analyzeOnly:   false,
		autoFix:       false,
		interactive:   false,
		dryRun:        false,
		verbose:       false,
		skipVetting:   false,
		outputReport:  null,
		envatoId:      null,
		help:          false,
	};

	for ( let i = 0; i < argv.length; i++ ) {
		const arg = argv[ i ];
		switch ( arg ) {
			case '--source':         args.source       = argv[ ++i ] || null; break;
			case '--source-zip':     args.sourceZip    = argv[ ++i ] || null; break;
			case '--slug':           args.slug         = argv[ ++i ] || null; break;
			case '--title':          args.title        = argv[ ++i ] || null; break;
			case '--toolkit':        args.toolkit      = argv[ ++i ] || null; break;
			case '--output-report':  args.outputReport = argv[ ++i ] || null; break;
			case '--envato-id':      args.envatoId     = argv[ ++i ] || null; break;
			case '--analyze-only':   args.analyzeOnly  = true; break;
			case '--auto-fix':       args.autoFix      = true; break;
			case '--interactive':    args.interactive  = true; break;
			case '--dry-run':        args.dryRun       = true; break;
			case '--verbose':        args.verbose      = true; break;
			case '--skip-vetting':   args.skipVetting  = true; break;
			case '--help':           args.help         = true; break;
		}
	}

	return args;
}

function validateArgs( args ) {
	const errors = [];

	if ( args.source && args.sourceZip ) {
		errors.push( 'Cannot specify both --source and --source-zip. Choose one.' );
	}

	if ( ! args.analyzeOnly ) {
		if ( ! args.slug ) {
			errors.push( '--slug is required for import mode. Use --analyze-only to skip import.' );
		} else if ( ! /^[a-z][a-z0-9-]{1,62}$/.test( args.slug ) ) {
			errors.push( `Invalid slug "${ args.slug }": must be lowercase, kebab-case, 2-63 chars.` );
		}
		if ( ! args.title ) {
			errors.push( '--title is required for import mode.' );
		}
	}

	if ( ! args.source && ! args.sourceZip ) {
		errors.push( 'Either --source <dir> or --source-zip <file> is required.' );
	}

	if ( args.source && ! fs.existsSync( args.source ) ) {
		errors.push( `Source directory not found: ${ args.source }` );
	}

	if ( args.sourceZip && ! fs.existsSync( args.sourceZip ) ) {
		errors.push( `Source ZIP file not found: ${ args.sourceZip }` );
	}

	return errors;
}

// ---------------------------------------------------------------------------
// ZIP extraction helper
// ---------------------------------------------------------------------------

function extractZip( zipPath, destDir ) {
	// Try to use system unzip or a Node library.
	const { execSync } = require( 'node:child_process' );
	const tmpDir = path.join( destDir, '.tmp-extract' );
	fs.mkdirSync( tmpDir, { recursive: true } );

	try {
		execSync( `unzip -o "${ zipPath }" -d "${ tmpDir }"`, { stdio: 'pipe' } );
	} catch {
		// Try 7z as fallback.
		try {
			execSync( `7z x "${ zipPath }" -o"${ tmpDir }" -y`, { stdio: 'pipe' } );
		} catch {
			throw new Error( 'Could not extract ZIP. Install `unzip` or `7z`.' );
		}
	}

	// If the ZIP contained a single root folder, use that.
	const entries = fs.readdirSync( tmpDir );
	const topDirs = entries.filter( e => {
		const st = fs.statSync( path.join( tmpDir, e ) );
		return st.isDirectory() && ! e.startsWith( '.' );
	} );

	if ( topDirs.length === 1 ) {
		const inner = path.join( tmpDir, topDirs[ 0 ] );
		return inner;
	}
	return tmpDir;
}

// ---------------------------------------------------------------------------
// Interactive prompt helper
// ---------------------------------------------------------------------------

async function confirm( question ) {
	return new Promise( ( resolve ) => {
		process.stderr.write( `\n${ question } [y/N]: ` );
		const onData = ( data ) => {
			const answer = data.toString().trim().toLowerCase();
			process.stdin.removeListener( 'data', onData );
			process.stdin.pause();
			resolve( answer === 'y' || answer === 'yes' );
		};
		process.stdin.resume();
		process.stdin.once( 'data', onData );
	} );
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main() {
	const argv = process.argv.slice( 2 );
	const args = parseArgs( argv );

	if ( args.help ) {
		printHelp();
		process.exit( 0 );
	}

	const errors = validateArgs( args );
	if ( errors.length > 0 ) {
		process.stderr.write( 'ERROR:\n' );
		for ( const err of errors ) {
			process.stderr.write( `  • ${ err }\n` );
		}
		process.stderr.write( '\nRun with --help for usage.\n' );
		process.exit( 1 );
	}

	const repoRoot = path.resolve( __dirname, '..' );

	// -------------------------------------------------------------------
	// Phase 1: Resolve source (ZIP extraction if needed)
	// -------------------------------------------------------------------
	let sourceDir = args.source;
	let cleanup   = null;

	if ( args.sourceZip ) {
		process.stderr.write( `📦 Extracting ${ args.sourceZip }…\n` );
		const tmpBase = path.join( repoRoot, '.tmp-imports' );
		sourceDir = extractZip( args.sourceZip, tmpBase );
		cleanup   = () => {
			try { fs.rmSync( path.dirname( sourceDir ), { recursive: true, force: true } ); } catch { /* ok */ }
		};
		process.stderr.write( `   Extracted to: ${ sourceDir }\n` );
	}

	// -------------------------------------------------------------------
	// Phase 2: Analyze
	// -------------------------------------------------------------------
	process.stderr.write( '\n🔍 Phase 2: Analyzing template…\n' );
	const analysis = analyzeTemplate( sourceDir, {
		envatoId: args.envatoId,
		verbose:  args.verbose,
	} );

	// Print summary.
	const tech = analysis.tech_stack || {};
	process.stderr.write( `
  Template:       ${ analysis.template_name }
  Framework:      ${ tech.framework || 'unknown' }
  React version:  ${ tech.version_range || 'unknown' }
  Router:         ${ tech.router || 'none' }
  State:          ${ tech.state || 'none' }
  CSS:            ${ tech.css || 'none' }
  UI Library:     ${ tech.ui_library || 'none' }
  Bundler:        ${ tech.bundler || 'unknown' }
  Components:     ${ analysis.components.pages } pages, ${ analysis.components.components } components, ${ analysis.components.layouts } layouts
  API calls:      ${ analysis.api_calls.length } detected
  Gaps:           ${ analysis.gaps.length } identified (${ analysis.gaps.filter( g => g.severity === 'critical' ).length } critical, ${ analysis.gaps.filter( g => g.severity === 'high' ).length } high)
  Est. effort:    ${ analysis.estimated_effort }
  Rec. tier:      ${ analysis.recommended_tier } (${ analysis.recommended_addon_type })
` );

	// Print gap summary.
	if ( analysis.gaps.length > 0 ) {
		process.stderr.write( '  Gap summary:\n' );
		for ( const gap of analysis.gaps ) {
			const icons = { critical: '🔴', high: '🟠', medium: '🟡', low: '🔵' };
			process.stderr.write( `    ${ icons[ gap.severity ] || '⚪' } [${ gap.severity }] ${ gap.category }: ${ gap.description }\n` );
		}
	}

	// Save report if requested.
	if ( args.outputReport ) {
		const reportPath = path.resolve( args.outputReport );
		fs.writeFileSync( reportPath, JSON.stringify( analysis, null, 2 ), 'utf-8' );
		process.stderr.write( `\n📄 Analysis report saved to: ${ reportPath }\n` );
	}

	// If analyze-only, stop here.
	if ( args.analyzeOnly ) {
		process.stderr.write( '\n✅ Analysis complete. Use --auto-fix to proceed with import.\n' );
		if ( cleanup ) cleanup();
		process.exit( 0 );
	}

	// -------------------------------------------------------------------
	// Phase 3: Vetting
	// -------------------------------------------------------------------
	if ( ! args.skipVetting ) {
		process.stderr.write( '\n🛡️  Phase 3: Vetting checklist…\n' );
		const vetReport = runVetting( analysis, path.join( repoRoot, 'addons', args.slug ) );
		process.stderr.write( formatVettingReport( vetReport ) + '\n' );

		if ( vetReport.blocked ) {
			process.stderr.write( '\n❌ Import blocked by vetting gates. Fix the critical issues and retry.\n' );
			if ( cleanup ) cleanup();
			process.exit( 1 );
		}

		if ( args.interactive ) {
			const proceed = await confirm( 'Proceed with import?' );
			if ( ! proceed ) {
				process.stderr.write( 'Cancelled.\n' );
				if ( cleanup ) cleanup();
				process.exit( 0 );
			}
		}
	}

	// -------------------------------------------------------------------
	// Phase 4: Plan adapters
	// -------------------------------------------------------------------
	process.stderr.write( '\n🔧 Phase 4: Planning adapters…\n' );
	const adapterPlan = planAdapters( analysis );
	process.stderr.write( `  Adapter plan: ${ adapterPlan.join( ' → ' ) }\n` );

	const catalog = getCatalog();
	for ( const adapterId of adapterPlan ) {
		const def = catalog.find( a => a.id === adapterId );
		if ( ! def ) continue;
		const autoIcon = def.automated ? '🤖' : '👤';
		process.stderr.write( `    ${ autoIcon } ${ def.id }: ${ def.description.substring( 0, 80 ) }…\n` );
	}

	if ( args.interactive ) {
		const proceed = await confirm( '\nApply adapters and integrate?' );
		if ( ! proceed ) {
			process.stderr.write( 'Cancelled.\n' );
			if ( cleanup ) cleanup();
			process.exit( 0 );
		}
	}

	// -------------------------------------------------------------------
	// Phase 5: Integration
	// -------------------------------------------------------------------
	process.stderr.write( '\n🏗️  Phase 5: Integration…\n' );
	const result = await integrateTemplate( analysis, {
		sourceDir,
		slug:        args.slug,
		title:       args.title,
		toolkit:     args.toolkit || args.slug,
		repoRoot,
		dryRun:      args.dryRun,
		verbose:     args.verbose,
		skipVetting: args.skipVetting,
	} );

	// Print results.
	process.stderr.write( `  Steps completed: ${ result.steps.join( ' → ' ) }\n` );

	if ( result.warnings.length > 0 ) {
		process.stderr.write( `\n  ⚠️  ${ result.warnings.length } warnings:\n` );
		for ( const w of result.warnings ) {
			process.stderr.write( `    • ${ w }\n` );
		}
	}

	if ( result.errors.length > 0 ) {
		process.stderr.write( `\n  ❌ ${ result.errors.length } errors:\n` );
		for ( const e of result.errors ) {
			process.stderr.write( `    • ${ e }\n` );
		}
	}

	if ( result.success ) {
		process.stderr.write( `\n✅ Import successful! Addon created at: ${ result.addonDir }\n` );
		process.stderr.write( '\nNext steps:\n' );
		process.stderr.write( `  1. cd ${ path.relative( process.cwd(), result.addonDir ) }\n` );
		process.stderr.write( '  2. npm run build           # Build the SPA bundle\n' );
		process.stderr.write( '  3. Activate the addon in WordPress\n' );
		process.stderr.write( `  4. Add [nvoos_${ args.slug.replace( /-/g, '_' ) }_app] to a page\n` );
		process.stderr.write( '  5. Run axe-core a11y audit\n' );
		process.stderr.write( '  6. Add to THIRD_PARTY_NOTICES.md, CREDITS.md, README.md "Credits"\n' );
		process.stderr.write( '  7. Add .github/agents/<slug>-maintainer.agent.md (per AGENTS.md §2)\n' );
	} else {
		process.stderr.write( '\n❌ Import had errors. Review the warnings above and retry.\n' );
	}

	if ( cleanup ) {
		cleanup();
	}
}

main().catch( ( err ) => {
	process.stderr.write( `\n🔥 Fatal error: ${ err.message }\n` );
	if ( process.argv.includes( '--verbose' ) ) {
		process.stderr.write( err.stack + '\n' );
	}
	process.exit( 1 );
} );
