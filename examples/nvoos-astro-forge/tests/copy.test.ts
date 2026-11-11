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
		expect( en.theme.label ).toBe( 'Theme' );
		expect( en.theme.forcedNote ).toContain( 'policy' );
		expect( en.search.placeholder ).toContain( 'Search' );
		expect( en.search.searching ).toBeTruthy();
		expect( en.search.noResults ).toBeTruthy();
		expect( en.search.unavailable ).toBeTruthy();
		expect( en.consent.acceptAll ).toBe( 'Accept all' );
		expect( en.consent.rejectAll ).toContain( 'Reject' );
		expect( en.consent.categories.necessary ).toContain( 'always on' );
	} );

	it( 'a second locale shaped as Copy typechecks (the completeness gate)', () => {
		// The real gate is `astro check`; this asserts the shape the type
		// derives from — every key path must exist in any `Copy` value.
		const fr: Copy = {
			nav: { primaryLabel: 'Principale', home: 'Accueil', products: 'Produits', posts: 'Articles', search: 'Rechercher', menu: 'Menu' },
			footer: { generatedBy: 'Généré par NV oOS Astro Forge.' },
			theme: { label: 'Thème', forcedNote: 'Le thème est défini par la politique du site.' },
			catalog: { open: 'Voir le catalogue' },
			search: { placeholder: 'Rechercher…', searching: 'Recherche…', noResults: 'Aucun résultat.', unavailable: 'Recherche indisponible.' },
			consent: {
				regionLabel: 'Consentement aux cookies',
				title: 'Préférences de cookies',
				body: 'Nous utilisons des cookies pour mesurer le trafic et améliorer ce site.',
				acceptAll: 'Tout accepter',
				rejectAll: 'Refuser le non-essentiel',
				preferences: 'Préférences',
				save: 'Enregistrer',
				privacyLink: 'Politique de confidentialité',
				categories: {
					necessary: 'Nécessaire (toujours actif)',
					analytics: 'Analytique',
					advertising: 'Publicité',
					functional: 'Fonctionnel',
					personalization: 'Personnalisation',
				},
			},
		};
		expect( fr.nav.home ).toBe( 'Accueil' );
	} );
} );
