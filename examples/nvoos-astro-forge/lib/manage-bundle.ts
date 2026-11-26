// SPDX-License-Identifier: MIT
/**
 * @fileoverview Locates and fingerprints the Astro CMS bundle /manage injects.
 *
 * Invariant: the emitted page carries an SRI token for the exact bytes the
 * scaffold saw, so a stale copy fails loudly instead of running mismatched.
 * Never vendors the bundle into templates/blog/, which mirrors publicly.
 */
// ---------------------------------------------------------------------------
// manage-bundle.ts — the Astro CMS bundle the `/manage` page injects
// (Proposal 165 §7b).
//
// The bundle is BUILD OUTPUT of `addons/nvoos-astro-bridge` (its standalone
// esbuild target), so this module never compiles anything: it locates the two
// committed files, fingerprints them, and hands the bytes to the scaffold.
//
// Three rules, all from the 165 runbook §4:
//
//   1. **Fail closed.** A profile that opts into the bundle must not emit a
//      `/manage` page whose bundle 404s. The error names the fix.
//   2. **Record the fingerprint.** `integrity` (an SRI token) travels into the
//      page, so a browser REFUSES to execute a bundle that does not match the
//      one the scaffold saw. A stale edge copy is a visible failure, never a
//      silently mismatched admin.
//   3. **Never vendor into the template.** The bytes live in the customer
//      project (scaffold output), never in `templates/blog/` — that tree is
//      mirrored to the public forge repo. See the runbook's licensing note.
// ---------------------------------------------------------------------------

import { createHash } from 'node:crypto';
import { readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );

/** Where the committed standalone build lives inside the monorepo. */
export const DEFAULT_BRIDGE_RELATIVE = path.join(
	'addons',
	'nvoos-astro-bridge',
	'build',
	'standalone'
);

/** File names the bridge's `build:standalone` target emits. */
const BUNDLE_JS = 'astro-admin.js';
const BUNDLE_CSS = 'astro-admin.css';

export interface ManageBundle {
	/** Absolute path to the JS bundle. */
	jsPath: string;
	/** Absolute path to the CSS bundle. */
	cssPath: string;
	/** Raw bytes — the scaffold writes these. */
	js: Buffer;
	css: Buffer;
	/** SRI tokens (`sha384-<base64>`), one per file. */
	integrity: string;
	cssIntegrity: string;
	/** Hex digests, for the drift manifest and `nvoos.json`. */
	jsSha256: string;
	cssSha256: string;
}

/**
 * The bridge checkout the scaffold should read.
 *
 * `NVOOS_FORGE_BRIDGE_DIR` lets an out-of-tree checkout drive the scaffold
 * (the same escape hatch shape as the other forge env seams). It points at the
 * ADDON root, not at `build/standalone`.
 */
export function bridgeBundleDir( env: NodeJS.ProcessEnv = process.env ): string {
	const override = String( env.NVOOS_FORGE_BRIDGE_DIR ?? '' ).trim();
	if ( override ) {
		return path.resolve( override, 'build', 'standalone' );
	}
	// lib/ → package root → examples/ → repo root.
	return path.join( HERE, '..', '..', '..', DEFAULT_BRIDGE_RELATIVE );
}

/** An SRI token: `sha384-` + base64 digest (NOT hex — the browser base64-decodes). */
export function sriToken( bytes: Buffer, algorithm: 'sha384' = 'sha384' ): string {
	return `${ algorithm }-${ createHash( algorithm ).update( bytes ).digest( 'base64' ) }`;
}

/** The error a missing/short bundle raises. Names the fix, never just a path. */
export class ManageBundleError extends Error {
	constructor( message: string ) {
		super( message );
		this.name = 'ManageBundleError';
	}
}

/**
 * Read the two bundle files and fingerprint them.
 *
 * Throws `ManageBundleError` with an actionable message when the checkout is
 * absent (rule 1). Both files are required: shipping JS without CSS renders
 * the editor unstyled, which reads as "broken" rather than "incomplete".
 */
export async function resolveManageBundle( dir = bridgeBundleDir() ): Promise< ManageBundle > {
	const jsPath = path.join( dir, BUNDLE_JS );
	const cssPath = path.join( dir, BUNDLE_CSS );

	for ( const file of [ jsPath, cssPath ] ) {
		const exists = await stat( file ).catch( () => null );
		if ( ! exists || ! exists.isFile() || exists.size === 0 ) {
			throw new ManageBundleError(
				`deploy.manageBundle needs the Astro CMS standalone bundle, and ${ file } is missing or empty. ` +
					`Run "npm run build:standalone" in addons/nvoos-astro-bridge (it is committed, so a fresh ` +
					`checkout usually has it), or set NVOOS_FORGE_BRIDGE_DIR to the addon root. ` +
					`Drop deploy.manageBundle from the profile to emit the standalone /manage page instead.`
			);
		}
	}

	const [ js, css ] = await Promise.all( [ readFile( jsPath ), readFile( cssPath ) ] );

	return {
		jsPath,
		cssPath,
		js,
		css,
		integrity: sriToken( js ),
		cssIntegrity: sriToken( css ),
		jsSha256: createHash( 'sha256' ).update( js ).digest( 'hex' ),
		cssSha256: createHash( 'sha256' ).update( css ).digest( 'hex' ),
	};
}
