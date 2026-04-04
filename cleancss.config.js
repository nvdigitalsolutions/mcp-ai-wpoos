/**
 * cleancss configuration for WP MCP AI CSS files
 *
 * Processes all CSS source files for the base plugin and pro addon,
 * generating minified .min.css versions.
 *
 * Run with: node cleancss.config.js
 *
 * @package WP_MCP_AI
 */

const CleanCSS = require( 'clean-css' );
const fs = require( 'fs' );
const path = require( 'path' );

// Base plugin CSS files (assets/css/)
const baseCssFiles = [
	// Core UI
	'assets/css/admin-settings.css',
	'assets/css/chat.css',
	'assets/css/settings-dashboard.css',
	'assets/css/user-chats.css',
	'assets/css/mcp-diagnostic.css',
	'assets/css/tools-manager.css',

	// Admin page styles
	'assets/css/admin-add-assistant.css',
	'assets/css/admin-add-team.css',
	'assets/css/admin-build-assistant.css',
	'assets/css/admin-content-assistant.css',
	'assets/css/admin-create-assistant-modal.css',
	'assets/css/admin-create-team-modal.css',
	'assets/css/admin-monitor-shared.css',
	'assets/css/admin-multi-agent-dashboard.css',
	'assets/css/admin-orchestration-dashboard.css',
	'assets/css/admin-responsive-utilities.css',
	'assets/css/admin-slash-commands-dashboard.css',
	'assets/css/admin-test-assistant.css',
	'assets/css/admin-test-model.css',
	'assets/css/admin-test-profession.css',
	'assets/css/admin-test-team.css',
	'assets/css/admin/admin-create-assistant-button.css',
	'assets/css/admin/admin-create-team-button.css',
	'assets/css/admin/metaboxes/profession-expertise.css',
	'assets/css/admin/widgets/token-performance-stats.css',

	// Dashboard and page styles
	'assets/css/analytics-dashboard.css',
	'assets/css/asset-inventory.css',
	'assets/css/datasets-admin.css',
	'assets/css/elementor-quick-actions-widget.css',
	'assets/css/enhanced-research-page.css',
	'assets/css/orchestration-dashboard.css',
	'assets/css/phase8-ux.css',
	'assets/css/pro-dashboard.css',
	'assets/css/professional-selector.css',
	'assets/css/security-audit-admin.css',
	'assets/css/security-training.css',
	'assets/css/supplier-security.css',
	'assets/css/workflow-editor.css',

	// Feature styles
	'assets/css/blocks/assistant-builder-blocks.css',
	'assets/css/cron-status.css',
];

// Pro addon CSS files (addons/pro/assets/css/)
const proCssFiles = [
	'addons/pro/assets/css/admin-health-wellness-management.css',
	'addons/pro/assets/css/admin-pm-ai-assistant.css',
	'addons/pro/assets/css/admin-project-management.css',
	'addons/pro/assets/css/admin-webchat.css',
	'addons/pro/assets/css/chat-channels-inbox.css',
	'addons/pro/assets/css/cpt-assistant.css',
	'addons/pro/assets/css/health-consolidate.css',
	'addons/pro/assets/css/imaging-viewer.css',
	'addons/pro/assets/css/media-template-admin.css',
	'addons/pro/assets/css/orchestration-dashboard.css',
	'addons/pro/assets/css/password-vault-admin.css',
	'addons/pro/assets/css/place-admin.css',
	'addons/pro/assets/css/quiz-admin.css',
	'addons/pro/assets/css/remote-sites-admin.css',
	'addons/pro/assets/css/research-page.css',
	'addons/pro/assets/css/skill-manager-admin.css',
];

const cleancss = new CleanCSS( { sourceMap: true, returnPromise: true } );

/**
 * Minify a single CSS file and write the .min.css output.
 *
 * @param {string} inputPath Relative path to the source CSS file.
 * @return {Promise<object>} Build result with input/output sizes.
 */
async function minifyFile( inputPath ) {
	const outputPath = inputPath.replace( /\.css$/, '.min.css' );
	const source = fs.readFileSync( inputPath, 'utf8' );

	const result = await cleancss.minify( source );

	if ( result.errors && result.errors.length ) {
		throw new Error( `CleanCSS errors in ${ inputPath }: ${ result.errors.join( ', ' ) }` );
	}

	// Ensure output directory exists.
	const outputDir = path.dirname( outputPath );
	if ( fs.existsSync( outputDir ) === false ) {
		fs.mkdirSync( outputDir, { recursive: true } );
	}

	// Write minified CSS with source map reference.
	const mapPath = outputPath + '.map';
	const sourceMapComment = `\n/*# sourceMappingURL=${ path.basename( mapPath ) } */`;
	fs.writeFileSync( outputPath, result.styles + sourceMapComment );
	if ( result.sourceMap ) {
		fs.writeFileSync( mapPath, result.sourceMap.toString() );
	}

	const inputSize = Buffer.byteLength( source, 'utf8' );
	const outputSize = Buffer.byteLength( result.styles, 'utf8' );

	return {
		input: path.basename( inputPath ),
		output: path.basename( outputPath ),
		inputSize: ( inputSize / 1024 ).toFixed( 1 ) + ' KB',
		outputSize: ( outputSize / 1024 ).toFixed( 1 ) + ' KB',
		reduction: inputSize > 0
			? ( ( 1 - outputSize / inputSize ) * 100 ).toFixed( 1 ) + '%'
			: '0.0%',
	};
}

/**
 * Build all CSS files.
 */
async function buildAll() {
	// Determine which files to process based on CLI argument.
	const args = process.argv.slice( 2 );
	const proOnly = args.includes( '--pro' );
	const baseOnly = args.includes( '--base' );

	let files;
	let label;

	if ( proOnly ) {
		files = proCssFiles;
		label = 'Pro addon';
	} else if ( baseOnly ) {
		files = baseCssFiles;
		label = 'Base plugin';
	} else {
		files = [ ...baseCssFiles, ...proCssFiles ];
		label = 'All';
	}

	console.log( `🎨 Minifying ${ label } CSS files with CleanCSS...\n` );

	const startTime = Date.now();
	const results = [];
	let hasError = false;

	for ( const inputPath of files ) {
		if ( fs.existsSync( inputPath ) === false ) {
			console.warn( `⚠️  Skipping (not found): ${ inputPath }` );
			continue;
		}

		try {
			const result = await minifyFile( inputPath );
			results.push( result );
			console.log( `✅ ${ result.input } → ${ result.output }` );
		} catch ( error ) {
			console.error( `❌ Error minifying ${ inputPath }:`, error.message );
			hasError = true;
		}
	}

	const duration = ( ( Date.now() - startTime ) / 1000 ).toFixed( 2 );

	console.log( '\n📊 CSS Build Summary:' );
	console.log( '┌─────────────────────────────────────────┬────────────┬─────────────┬────────────┐' );
	console.log( '│ File                                    │ Original   │ Minified    │ Reduction  │' );
	console.log( '├─────────────────────────────────────────┼────────────┼─────────────┼────────────┤' );

	for ( const r of results ) {
		const file = r.input.padEnd( 39 );
		const original = r.inputSize.padStart( 10 );
		const minified = r.outputSize.padStart( 11 );
		const reduction = r.reduction.padStart( 10 );
		console.log( `│ ${ file } │ ${ original } │ ${ minified } │ ${ reduction } │` );
	}

	console.log( '└─────────────────────────────────────────┴────────────┴─────────────┴────────────┘' );
	console.log( `\n⚡ CSS build completed in ${ duration }s` );

	if ( hasError ) {
		process.exit( 1 );
	}
}

buildAll().catch( ( error ) => {
	console.error( 'CSS build failed:', error );
	process.exit( 1 );
} );
