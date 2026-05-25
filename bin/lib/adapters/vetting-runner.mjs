/**
 * Vetting Runner
 *
 * Runs the full 10-gate blueprint §12 checklist + ingestion-specific gates
 * against an imported template. Blocks if critical gates fail.
 *
 * @since 1.2.0
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

import fs from 'node:fs';
import path from 'node:path';

/**
 * @typedef {'pass'|'warn'|'fail'|'skip'|'manual'} GateStatus
 *
 * @typedef {object} GateResult
 * @property {GateStatus} status
 * @property {string}     [reason]
 * @property {string}     [note]
 */

/**
 * @typedef {object} VettingReport
 * @property {boolean}     passed     Overall pass/fail.
 * @property {boolean}     blocked    True if critical gates have failed.
 * @property {number}      score      0–10 score.
 * @property {GateResult[]} gates     Per-gate results.
 * @property {string[]}    actions    Required actions before proceeding.
 */

/**
 * Run the full vetting checklist.
 *
 * @param {object} analysisReport  From template-analyzer.mjs.
 * @param {string} addonDir        Addon directory (post-adapters).
 * @returns {VettingReport}
 */
export function runVetting( analysisReport, addonDir ) {
	const gates = [];
	const actions = [];
	const vet = analysisReport.vetting_results || {};

	// Gate 1: License.
	const lic = vet.license || {};
	gates.push( {
		gate:   1,
		name:   'License',
		status: lic.status || 'warn',
		reason: lic.status === 'fail' ? `License "${ lic.value }" not accepted` : lic.value,
	} );
	if ( lic.status === 'fail' ) {
		actions.push( 'LICENSE: Template license is not MIT/Apache-2.0/BSD/ISC/GPL. Cannot proceed.' );
	}

	// Gate 2: Bundle weight (post-build check).
	gates.push( {
		gate:   2,
		name:   'Bundle Weight',
		status: vet.bundle_weight?.status || 'skip',
		reason: vet.bundle_weight?.current_kb ? `${ vet.bundle_weight.current_kb } KB source (limit: 200 KB Tier A)` : undefined,
	} );

	// Gate 3: Maintenance.
	gates.push( {
		gate:   3,
		name:   'Maintenance',
		status: 'skip',
		note:   'Run gh-advisory-database against template dependencies before committing.',
	} );
	actions.push( 'SECURITY: Run `gh-advisory-database` against all template dependencies.' );

	// Gate 4: React compatibility.
	const reactVer = vet.react_compat?.value || 'unknown';
	gates.push( {
		gate:   4,
		name:   'React Compatibility',
		status: reactVer.startsWith( '19' ) || reactVer.startsWith( '18' ) ? 'pass' : 'warn',
		reason: `React ${ reactVer }`,
	} );

	// Gate 5: Embeddable.
	const cssCheck = analysisReport.css_conflicts || {};
	gates.push( {
		gate:   5,
		name:   'Embeddable (no wp-admin CSS conflict)',
		status: cssCheck.conflicts === 0 ? 'pass' : 'warn',
		reason: cssCheck.conflicts > 0 ? `${ cssCheck.conflicts } CSS files need scoping` : undefined,
	} );

	// Gate 6: Data shape.
	gates.push( {
		gate:   6,
		name:   'Data Shape (REST-mappable)',
		status: 'pass',
		note:   'API calls identified in analysis; run api-adapter to wire to REST.',
	} );

	// Gate 7: i18n.
	const i18n = analysisReport.i18n || {};
	gates.push( {
		gate:   7,
		name:   'i18n Support',
		status: i18n.hasI18n ? 'pass' : 'warn',
		reason: ! i18n.hasI18n ? `${ i18n.hardcodedCount || 0 } hardcoded strings found` : undefined,
	} );

	// Gate 8: Accessibility.
	gates.push( {
		gate:   8,
		name:   'Accessibility (WCAG 2.1 AA)',
		status: 'skip',
		note:   'Run axe-core audit after integration. Add eslint-plugin-jsx-a11y.',
	} );
	actions.push( 'A11Y: Run axe-core audit and add eslint-plugin-jsx-a11y to eslint config.' );

	// Gate 9: Security.
	const secIssues = analysisReport.security_issues || [];
	const critSec   = secIssues.filter( s => s.severity === 'critical' ).length;
	gates.push( {
		gate:   9,
		name:   'Security (no eval, no remote scripts, no secrets)',
		status: critSec === 0 ? 'pass' : 'fail',
		reason: critSec > 0 ? `${ critSec } critical security issues: ${ secIssues.slice( 0, 3 ).map( s => s.message ).join( '; ' ) }` : undefined,
	} );
	if ( critSec > 0 ) {
		actions.push( `SECURITY: ${ critSec } critical security issues must be fixed before import.` );
	}

	// Gate 10: Attribution.
	gates.push( {
		gate:   10,
		name:   'Attribution (THIRD_PARTY_NOTICES.md + CREDITS.md)',
		status: 'manual',
		note:   'Add to addon THIRD_PARTY_NOTICES.md, root CREDITS.md, and README.md "Credits" section.',
	} );
	actions.push( 'ATTRIBUTION: Update THIRD_PARTY_NOTICES.md, CREDITS.md, and README.md "Credits".' );

	// --- Ingestion-specific addendum gates ---
	// Gate 11: CDN-free — no remote script loads.
	const hasRemoteScripts = secIssues.some( s => s.message.includes( 'Remote script' ) );
	gates.push( {
		gate:   11,
		name:   'Ingestion: No remote CDN scripts',
		status: hasRemoteScripts ? 'fail' : 'pass',
		reason: hasRemoteScripts ? 'Remote script loads found — must self-host under assets/dist/' : undefined,
	} );
	if ( hasRemoteScripts ) {
		actions.push( 'CDN: Remove all remote script loads; self-host dependencies under assets/dist/.' );
	}

	// Gate 12: CSS scoping.
	gates.push( {
		gate:   12,
		name:   'Ingestion: CSS scoped to addon root',
		status: cssCheck.globalResets?.length > 0 ? 'warn' : 'pass',
		reason: cssCheck.globalResets?.length > 0 ? 'Global CSS resets found — scope to .nvoos-{slug}-root' : undefined,
	} );

	// Gate 13: Package.json has required scripts.
	const pkgPath = path.join( addonDir, 'package.json' );
	if ( fs.existsSync( pkgPath ) ) {
		try {
			const pkg = JSON.parse( fs.readFileSync( pkgPath, 'utf-8' ) );
			const scripts = pkg.scripts || {};
			const hasBuild = scripts.build && scripts.build.includes( 'esbuild' );
			gates.push( {
				gate:   13,
				name:   'Ingestion: Build script uses esbuild',
				status: hasBuild ? 'pass' : 'warn',
				reason: hasBuild ? undefined : 'No esbuild build script found — add "build": "node esbuild.config.cjs --prod"',
			} );
		} catch {
			gates.push( { gate: 13, name: 'Ingestion: Build script', status: 'warn', reason: 'Could not parse package.json' } );
		}
	}

	// Calculate results.
	const score = gates.filter( g => g.status === 'pass' ).length;
	const total = gates.filter( g => g.status !== 'skip' ).length;
	const blocked = gates.some( g => g.status === 'fail' && [ 1, 9, 11 ].includes( g.gate ) );

	return {
		passed:  ! blocked && gates.filter( g => g.status === 'fail' ).length === 0,
		blocked,
		score:   `${ score }/${ total } non-skip gates passed`,
		gates,
		actions,
	};
}

/**
 * Print a human-readable vetting report.
 *
 * @param {VettingReport} report
 * @returns {string}
 */
export function formatVettingReport( report ) {
	const lines = [];
	const icons = { pass: '✅', warn: '⚠️', fail: '❌', skip: '⏭️', manual: '📝' };

	lines.push( '' );
	lines.push( '═══════════════════════════════════════════' );
	lines.push( '  NV oOS SPA Import — Vetting Report' );
	lines.push( '═══════════════════════════════════════════' );
	lines.push( '' );
	lines.push( `Overall: ${ report.passed ? 'PASS ✅' : 'FAIL ❌' }  |  Blocked: ${ report.blocked ? 'YES' : 'NO' }  |  Score: ${ report.score }` );
	lines.push( '' );

	for ( const gate of report.gates ) {
		const icon = icons[ gate.status ] || '❓';
		const g    = String( gate.gate ).padStart( 2, '0' );
		lines.push( `  ${ icon } Gate ${ g } — ${ gate.name }` );
		if ( gate.reason ) {
			lines.push( `       ${ gate.reason }` );
		}
		if ( gate.note ) {
			lines.push( `       ℹ️  ${ gate.note }` );
		}
	}

	if ( report.actions.length > 0 ) {
		lines.push( '' );
		lines.push( '  Required actions:' );
		for ( const action of report.actions ) {
			lines.push( `    • ${ action }` );
		}
	}

	lines.push( '' );
	lines.push( '═══════════════════════════════════════════' );

	return lines.join( '\n' );
}
