/**
 * Adapter Catalog — registry of transformation adapters for imported SPAs.
 *
 * Each adapter addresses one category of gap identified by
 * `template-analyzer.mjs`. Adapters are composable and independent.
 *
 * @since 1.2.0
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

/**
 * @typedef {object} AdapterDef
 * @property {string}  id          Unique adapter ID
 * @property {string}  label       Human-readable label
 * @property {string}  description What it does
 * @property {string}  category    Gap category it addresses
 * @property {'critical'|'high'|'medium'|'low'} severity
 * @property {boolean} automated   Whether it can run without user input
 * @property {boolean} reversible  Whether the operation has an undo
 */

/** @type {AdapterDef[]} */
const CATALOG = [
	{
		id:          'auth-adapter',
		label:       'Authentication Adapter',
		description: 'Replaces standard auth (JWT, session tokens) with WordPress nonce-based X-WP-Nonce header injection. Adds wp_localize_script() config for nonce + API URL.',
		category:    'auth',
		severity:    'critical',
		automated:   true,
		reversible:  true,
	},
	{
		id:          'api-adapter',
		label:       'API Service Adapter',
		description: 'Rewrites API service files — replaces mock-data / axios / fetch calls targeting /api/* with WordPress REST calls to /wp-json/mcp-ai-pro/v1/*. Updates TypeScript interfaces to match WP REST response shapes.',
		category:    'data_plane',
		severity:    'high',
		automated:   false,
		reversible:  true,
	},
	{
		id:          'mount-adapter',
		label:       'Mount Container Adapter',
		description: 'Wraps the template entry point to mount inside <div class="nvoos-{slug}-root" data-config="..."> instead of #root or <body>. Preserves the template App component unchanged.',
		category:    'mount',
		severity:    'critical',
		automated:   true,
		reversible:  true,
	},
	{
		id:          'build-adapter',
		label:       'Build System Adapter',
		description: 'Generates esbuild.config.cjs (IIFE bundle, wp.i18n external, CSS extraction) from the template\'s original webpack/vite/cra config. Produces assets/dist/{slug}.{js,css}.',
		category:    'build',
		severity:    'high',
		automated:   true,
		reversible:  true,
	},
	{
		id:          'i18n-adapter',
		label:       'i18n Adapter',
		description: 'Extracts hardcoded strings to a POT template, injects @wordpress/i18n (__(), _n(), sprintf()), and configures wp_set_script_translations() in the shortcode.',
		category:    'i18n',
		severity:    'medium',
		automated:   false,
		reversible:  true,
	},
	{
		id:          'css-scope-adapter',
		label:       'CSS Scoping Adapter',
		description: 'Scopes global CSS rules (body, html, *) to .nvoos-{slug}-root namespace. Handles Tailwind prefix configuration and MUI ThemeProvider scoping.',
		category:    'css',
		severity:    'medium',
		automated:   false,
		reversible:  true,
	},
	{
		id:          'vetting-runner',
		label:       'Vetting Runner',
		description: 'Runs the full 10-gate + ingestion addendum checklist and produces a pass/warn/fail report. Blocks the import if critical gates fail (license, security).',
		category:    'quality',
		severity:    'critical',
		automated:   true,
		reversible:  false,
	},
	{
		id:          'bundle-optimizer',
		label:       'Bundle Weight Optimizer',
		description: 'Analyzes the esbuild bundle, identifies heavy chunks, suggests lazy-loaded routes and tree-shaking opportunities to stay under the tier threshold.',
		category:    'build',
		severity:    'low',
		automated:   false,
		reversible:  true,
	},
];

/**
 * Get the full adapter catalog.
 * @returns {AdapterDef[]}
 */
export function getCatalog() {
	return CATALOG;
}

/**
 * Get adapters that address a specific gap severity or category.
 *
 * @param {object}   filter
 * @param {string}  [filter.category]  Match by gap category.
 * @param {string}  [filter.severity]  Match by severity.
 * @returns {AdapterDef[]}
 */
export function findAdapters( filter = {} ) {
	return CATALOG.filter( a => {
		if ( filter.category && a.category !== filter.category ) return false;
		if ( filter.severity && a.severity !== filter.severity ) return false;
		return true;
	} );
}

/**
 * Given an analysis report, return the ordered list of adapters that should
 * be applied, in dependency order.
 *
 * @param {object} analysisReport  The report from template-analyzer.mjs
 * @returns {string[]} Adapter IDs in execution order.
 */
export function planAdapters( analysisReport ) {
	const plan = [];

	// Vetting first — if license or security fail, stop.
	const vet = analysisReport.vetting_results || {};
	if ( vet.security?.status === 'fail' || vet.license?.status === 'fail' ) {
		return [ 'vetting-runner' ]; // Stop — unrecoverable.
	}

	// Critical path: mount + auth + build (these must work for the SPA to render).
	plan.push( 'mount-adapter' );
	plan.push( 'auth-adapter' );
	plan.push( 'build-adapter' );

	// High: API mapping after the core is working.
	if ( analysisReport.api_calls?.length > 0 ) {
		plan.push( 'api-adapter' );
	}

	// Medium: polish.
	if ( analysisReport.i18n?.hardcodedCount > 0 ) {
		plan.push( 'i18n-adapter' );
	}
	if ( analysisReport.css_conflicts?.conflicts > 0 ) {
		plan.push( 'css-scope-adapter' );
	}

	// Final: optimize.
	plan.push( 'bundle-optimizer' );

	return plan;
}
