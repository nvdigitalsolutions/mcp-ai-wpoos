// ---------------------------------------------------------------------------
// copy.test.ts — the template's typed copy tables (Proposal 104 §4.5):
// the reference table, the Copy derivation, and the useCopy() lookup. The
// completeness gate (a second locale must satisfy `Copy`) is the type
// itself — `astro check` on the generated project enforces it; the runtime
// surface is pinned here.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { en } from '../templates/blog/src/copy/en.ts';
import { copy, DEFAULT_LOCALE, useCopy, type Copy } from '../templates/blog/src/copy/index.ts';

describe( 'copy tables', () => {
	it( 'registers the reference locale', () => {
		expect( copy.en ).toBe( en );
		expect( DEFAULT_LOCALE ).toBe( 'en' );
	} );

	it( 'useCopy returns the reference table', () => {
		expect( useCopy() ).toBe( en );
		expect( useCopy( 'en' ) ).toBe( en );
		// Unknown locales fall back — no partial tables ever render.
		expect( useCopy( 'fr' ) ).toBe( en );
	} );

	it( 'carries the nav, footer, aria and empty-state keys', () => {
		expect( en.nav.primaryLabel ).toBe( 'Primary' );
		expect( en.nav.home ).toBe( 'Home' );
		expect( en.nav.products ).toBe( 'Products' );
		expect( en.nav.posts ).toBe( 'Posts' );
		expect( en.nav.search ).toBe( 'Search' );
		expect( en.footer.generatedBy ).toContain( 'NV oOS Astro Forge' );
		expect( en.search.placeholder ).toContain( 'Search' );
		expect( en.search.searching ).toBeTruthy();
		expect( en.search.noResults ).toBeTruthy();
		expect( en.search.unavailable ).toBeTruthy();
	} );

	it( 'a second locale shaped as Copy typechecks (the completeness gate)', () => {
		// The real gate is `astro check`; this asserts the shape the type
		// derives from — every key path must exist in any `Copy` value.
		const fr: Copy = {
			nav: { primaryLabel: 'Principale', home: 'Accueil', products: 'Produits', posts: 'Articles', search: 'Rechercher' },
			footer: { generatedBy: 'Généré par NV oOS Astro Forge.' },
			search: { placeholder: 'Rechercher…', searching: 'Recherche…', noResults: 'Aucun résultat.', unavailable: 'Recherche indisponible.' },
		};
		expect( fr.nav.home ).toBe( 'Accueil' );
	} );
} );
