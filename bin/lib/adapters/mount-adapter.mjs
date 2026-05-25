/**
 * Mount Container Adapter
 *
 * Wraps a template's entry point so the SPA mounts inside the blueprint's
 * standard container: <div class="nvoos-{slug}-root" data-config="...">
 * instead of #root or document.body.
 *
 * This adapter:
 *   1. Finds the entry point (src/index.tsx or similar)
 *   2. Wraps ReactDOM.createRoot / ReactDOM.render in an NV oOS bootstrap
 *   3. Reads config from data-config attribute + window.NVOOS_{SLUG} global
 *
 * @since 1.2.0
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

import fs from 'node:fs';
import path from 'node:path';

/**
 * @typedef {object} MountAdapterOptions
 * @property {string} slug        The addon slug (kebab-case).
 * @property {string} targetDir   The addon's src/ directory.
 * @property {string} entryFile   Relative path to the entry file (from analyzer).
 * @property {boolean} [dryRun]   If true, print changes instead of applying.
 */

/**
 * Apply the mount adapter.
 *
 * @param {MountAdapterOptions} options
 * @returns {{patched: boolean, entryFile: string, warnings: string[]}}
 */
export function applyMountAdapter( options ) {
	const { slug, targetDir, entryFile, dryRun = false } = options;
	const upperSnake = slug.replace( /-/g, '_' ).toUpperCase();
	const globalName = `NVOOS_${ upperSnake }`;
	const rootClass  = `nvoos-${ slug }-root`;
	const warnings   = [];

	// Find the actual entry point.
	const entryPath = path.resolve( targetDir, entryFile );
	if ( ! fs.existsSync( entryPath ) ) {
		return {
			patched:   false,
			entryFile,
			warnings: [ `Entry file not found: ${ entryPath }` ],
		};
	}

	let content;
	try {
		content = fs.readFileSync( entryPath, 'utf-8' );
	} catch {
		return {
			patched:   false,
			entryFile,
			warnings: [ `Could not read entry file: ${ entryPath }` ],
		};
	}

	// Detect the mount pattern.
	const hasCreateRoot = /ReactDOM\s*\.\s*createRoot\s*\(/.test( content );
	const hasRender     = /ReactDOM\s*\.\s*render\s*\(/.test( content );

	if ( ! hasCreateRoot && ! hasRender ) {
		// No standard mount pattern — create a bootstrap wrapper.
		const newEntry = generateBootstrapFile( slug, upperSnake, globalName, rootClass, entryFile );
		const bootstrapPath = path.resolve( targetDir, 'nv-oos-bootstrap.tsx' );

		if ( dryRun ) {
			return {
				patched:   true,
				entryFile: 'nv-oos-bootstrap.tsx (new file)',
				warnings:  [ `No ReactDOM mount found in ${ entryFile }; creating bootstrap wrapper` ],
			};
		}

		fs.writeFileSync( bootstrapPath, newEntry, 'utf-8' );
		return {
			patched:   true,
			entryFile: 'nv-oos-bootstrap.tsx',
			warnings:  [ `Created bootstrap wrapper at nv-oos-bootstrap.tsx instead of ${ entryFile }` ],
		};
	}

	// Replace the mount target.
	let patched = content;

	// Replace document.getElementById('root') / querySelector('#root') with root class lookup.
	patched = patched.replace(
		/document\.getElementById\s*\(\s*['"`]root['"`]\s*\)/g,
		`document.querySelector('.${ rootClass }')`
	);
	patched = patched.replace(
		/document\.querySelector\s*\(\s*['"`]#root['"`]\s*\)/g,
		`document.querySelector('.${ rootClass }')`
	);

	// If it mounts to document.body, replace.
	patched = patched.replace(
		/ReactDOM\s*\.\s*createRoot\s*\(\s*document\.body\s*\)/g,
		`ReactDOM.createRoot( document.querySelector('.${ rootClass }') || document.body )`
	);

	// Add fallback warning if container not found.
	if ( ! patched.includes( rootClass ) ) {
		patched = patched.replace(
			/(ReactDOM\s*\.\s*(?:createRoot|render)\s*\()/,
			`$1 document.querySelector('.${ rootClass }') || `
		);
		warnings.push( `Manually verify mount target — added fallback for .${ rootClass }` );
	}

	// Inject config reader.
	const configReader = `
// NV oOS bootstrap — reads config from wp_localize_script() global.
const __nvoosConfig__ = ( window.${ globalName } && window.${ globalName }.config ) || {};
try {
	const rootEl = document.querySelector('.${ rootClass }');
	if ( rootEl && rootEl.dataset.config ) {
		Object.assign( __nvoosConfig__, JSON.parse( rootEl.dataset.config ) );
	}
} catch ( e ) { /* ignore malformed config */ }
`;

	// Insert the config reader before the mount call.
	if ( ! patched.includes( '__nvoosConfig__' ) ) {
		const mountIdx = patched.search( /ReactDOM\s*\.\s*(createRoot|render)\s*\(/ );
		if ( mountIdx >= 0 ) {
			patched = patched.slice( 0, mountIdx ) + configReader + patched.slice( mountIdx );
		} else {
			patched = configReader + '\n' + patched;
		}
	}

	if ( dryRun ) {
		return {
			patched:   true,
			entryFile,
			warnings:  [ `Would patch ${ entryFile } — dry run, no changes applied` ],
		};
	}

	// Backup original.
	fs.writeFileSync( entryPath + '.bak', content, 'utf-8' );
	fs.writeFileSync( entryPath, patched, 'utf-8' );

	return {
		patched:   true,
		entryFile,
		warnings,
	};
}

/**
 * Generate a bootstrap file that wraps the original entry.
 */
function generateBootstrapFile( slug, upperSnake, globalName, rootClass, originalEntry ) {
	const importPath = './' + path.basename( originalEntry ).replace( /\.(tsx|jsx|ts|js)$/, '' );

	return `/**
 * NV oOS Bootstrap — mounts the imported SPA into the blueprint container.
 *
 * Wraps the original entry (${ originalEntry }) so it mounts inside
 * <div class="${ rootClass }" data-config="..."> with wp_localize_script()
 * config from window.${ globalName }.
 *
 * @generated by mount-adapter — do not edit manually.
 * @since 1.2.0
 */

import React from 'react';
import ReactDOM from 'react-dom/client';
import OriginalApp from '${ importPath }';

// Read config from wp_localize_script() global.
const __nvoosConfig__ = ( window.${ globalName } && window.${ globalName }.config ) || {};

// Also read from data-config attribute.
try {
	const rootEl = document.querySelector('.${ rootClass }');
	if ( rootEl && rootEl.dataset.config ) {
		Object.assign( __nvoosConfig__, JSON.parse( rootEl.dataset.config ) );
	}
} catch ( e ) { /* ignore */ }

// Find the NV oOS root container.
const container = document.querySelector('.${ rootClass }');

if ( container ) {
	const root = ReactDOM.createRoot( container );
	root.render(
		<React.StrictMode>
			<OriginalApp config={ __nvoosConfig__ } />
		</React.StrictMode>
	);
} else {
	console.error(
		'NV oOS ${ slug }: root container .${ rootClass } not found. ' +
		'Make sure the shortcode [nvoos_${ slug.replace( /-/g, '_' ) }_app] is on the page.'
	);
}
`;
}
