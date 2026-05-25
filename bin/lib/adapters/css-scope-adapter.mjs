/**
 * CSS Scoping Adapter — stub (manual process)
 *
 * CSS scoping is semi-automatable for simple cases but requires human
 * judgment for complex CSS architectures (Tailwind, MUI, styled-components).
 *
 * @since 1.2.0
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

import fs from 'node:fs';
import path from 'node:path';

/**
 * @typedef {object} CssScopeAdapterOptions
 * @property {string} slug
 * @property {string} srcDir
 * @property {string} cssFramework   From tech stack analysis (tailwind, mui, etc.)
 * @property {boolean} [dryRun]
 */

/**
 * Apply CSS scoping guidance.
 *
 * @param {CssScopeAdapterOptions} options
 * @returns {{patched: boolean, warnings: string[], manualSteps: string[]}}
 */
export function applyCssScopeAdapter( options ) {
	const { slug, srcDir, cssFramework, dryRun = false } = options;
	const manualSteps = [];

	manualSteps.push( '🎨 CSS scoping requires manual review:' );

	switch ( cssFramework ) {
		case 'tailwind':
			manualSteps.push( '  1. Add prefix to tailwind.config.js to avoid class collisions:' );
			manualSteps.push( `     module.exports = { prefix: 'nvoos-${ slug }-', ... }` );
			manualSteps.push( '  2. Wrap app in: <div className="' + `nvoos-${ slug }-root` + '">' );
			manualSteps.push( '  3. Rebuild with prefixed classes.' );
			break;
		case 'mui-system':
		case '@mui/material':
			manualSteps.push( '  1. Use MUI ThemeProvider with a scoped className prefix:' );
			manualSteps.push( '     <ThemeProvider theme={theme}>' );
			manualSteps.push( '       <div className="' + `nvoos-${ slug }-root` + '">' );
			manualSteps.push( '         <CssBaseline />  {/* Only inside root */}' );
			manualSteps.push( '         <App />' );
			manualSteps.push( '       </div>' );
			manualSteps.push( '     </ThemeProvider>' );
			manualSteps.push( '  2. Remove <CssBaseline /> from global scope if present.' );
			break;
		case 'styled-components':
			manualSteps.push( '  1. Use StyleSheetManager with a target container to scope styles.' );
			manualSteps.push( '  2. Replace createGlobalStyle with scoped versions.' );
			break;
		default:
			manualSteps.push( '  1. Wrap all app CSS in a namespace selector:' );
			manualSteps.push( `     .nvoos-${ slug }-root { /* scoped styles */ }` );
			manualSteps.push( '  2. Replace global selectors (body, html, *) with root-scoped versions.' );
			manualSteps.push( `  3. Example:  body { margin: 0 }  →  .nvoos-${ slug }-root { margin: 0 }` );
	}

	manualSteps.push( '' );
	manualSteps.push( '  ℹ️  Check for global reset conflicts:' );
	manualSteps.push( '     grep -r "body\s*{" ' + path.relative( process.cwd(), srcDir ) + '/**/*.css' );
	manualSteps.push( '     grep -r "html\s*{" ' + path.relative( process.cwd(), srcDir ) + '/**/*.css' );
	manualSteps.push( '     grep -r "\*\s*{" ' + path.relative( process.cwd(), srcDir ) + '/**/*.css' );

	return {
		patched:     false,
		warnings:    [ 'CSS scoping requires manual review — see steps below' ],
		manualSteps,
	};
}
