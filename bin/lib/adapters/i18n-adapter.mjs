/**
 * i18n Adapter — stub (manual process)
 *
 * i18n conversion is not fully automatable without NLP. This stub provides
 * guidance and helper utilities.
 *
 * @since 1.2.0
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

import fs from 'node:fs';
import path from 'node:path';

/**
 * @typedef {object} I18nAdapterOptions
 * @property {string} slug
 * @property {string} srcDir
 * @property {boolean} [dryRun]
 */

/**
 * Apply the i18n adapter.
 *
 * NOTE: This is a semi-automated process. The adapter:
 *   1. Scans for hardcoded strings (catalogued by the analyzer)
 *   2. Provides manual steps for wp.i18n integration
 *   3. Generates a POT template stub
 *
 * For full automation, a human should review each string for context.
 *
 * @param {I18nAdapterOptions} options
 * @returns {{patched: boolean, warnings: string[], manualSteps: string[]}}
 */
export function applyI18nAdapter( options ) {
	const { slug, srcDir, dryRun = false } = options;
	const warnings   = [];
	const manualSteps = [];

	manualSteps.push( '📝 i18n conversion requires human review:' );
	manualSteps.push( '  1. Import @wordpress/i18n:  import { __, _n, sprintf } from "@wordpress/i18n";' );
	manualSteps.push( '  2. Wrap hardcoded strings:  <h1>Dashboard</h1>  →  <h1>{ __("Dashboard", "nvoos-' + slug + '") }</h1>' );
	manualSteps.push( '  3. Run:  npm run typecheck  to catch missing imports.' );
	manualSteps.push( '  4. Verify wp_set_script_translations() is called in the shortcode (it is if scaffolded).' );
	manualSteps.push( '  5. Generate POT:  wp i18n make-pot addons/' + slug + ' addons/' + slug + '/languages/nvoos-' + slug + '.pot' );

	// Generate a minimal POT stub.
	const potPath = path.resolve( srcDir, '..', 'languages', `nvoos-${ slug }.pot` );
	const potStub = generatePotStub( slug );

	if ( ! dryRun ) {
		fs.mkdirSync( path.dirname( potPath ), { recursive: true } );
		if ( ! fs.existsSync( potPath ) ) {
			fs.writeFileSync( potPath, potStub, 'utf-8' );
			manualSteps.push( '  ℹ️  Generated stub POT at ' + potPath );
		}
	}

	return {
		patched:     false,
		warnings,
		manualSteps,
	};
}

function generatePotStub( slug ) {
	return `# NV oOS ${ slug } — Translation Template
# Copyright (C) 2026 NV Digital Solutions
# This file is distributed under the GPLv3 or later.
msgid ""
msgstr ""
"Project-Id-Version: NV oOS ${ slug } 0.1.0\\n"
"Report-Msgid-Bugs-To: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues\\n"
"POT-Creation-Date: 2026-05-25T00:00:00+00:00\\n"
"Language-Team: English\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Domain: nvoos-${ slug }\\n"

#. Placeholder — run "wp i18n make-pot" to populate
msgid "Dashboard"
msgstr ""
`;
}
