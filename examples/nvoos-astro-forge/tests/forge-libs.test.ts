// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// forge-libs.test.ts — the vendored-lib closure.
//
// `src/forge/` is the part of the forge that ships INSIDE every generated
// project, so an import between those modules is a promise that both files are
// copied. `lib/types.ts` importing `./eca.ts` while `eca.ts` was copied only for
// ECA profiles broke `astro check` (ts2307) in every non-ECA build — five e2e
// tests failed on one missing file. This test states the closure rule directly,
// so the next such import fails in a unit test instead of in CI.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { readFile } from 'node:fs/promises';

import { FORGE_LIB_FILES } from '../lib/scaffold.ts';

describe( 'vendored forge libs', () => {
	it( 'vendors every relative module the vendored libs import', async () => {
		const missing: string[] = [];

		for ( const file of FORGE_LIB_FILES ) {
			const raw = await readFile( new URL( `../lib/${ file }`, import.meta.url ), 'utf8' );
			// Comments quote import statements in prose (`llms.ts` documents a
			// multi-line `} from './x';` shape), so drop them before matching.
			const source = raw
				.replace( /\/\*[\s\S]*?\*\//g, '' )
				.replace( /(^|[^:])\/\/[^\n]*/g, '$1' );
			// `from './x.ts'` and `from './x'` both count; a type-only import is
			// still a module the generated project must be able to resolve.
			for ( const match of source.matchAll( /from\s+'(\.[^']+)'/g ) ) {
				const target = match[ 1 ].replace( /^\.\//, '' );
				// A vendored lib may re-export from a file that is itself vendored
				// under another name; only a genuinely absent file is a failure.
				if ( ! FORGE_LIB_FILES.includes( target ) ) {
					missing.push( `${ file } imports ${ match[ 1 ] }` );
				}
			}
		}

		expect( missing ).toEqual( [] );
	} );

	it( 'keeps the ECA module in the always-vendored set', () => {
		// Load-bearing: `lib/types.ts` imports `ForgeEca` from it, and types.ts is
		// vendored into every project regardless of preset.
		expect( FORGE_LIB_FILES ).toContain( 'eca.ts' );
		expect( FORGE_LIB_FILES ).toContain( 'types.ts' );
	} );
} );
