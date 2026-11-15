// ---------------------------------------------------------------------------
// theme.test.ts — the template's runtime theme module (Proposal 104 §4.2):
// the forge port of the velocity theme contract, unit-covered here by
// importing the pure template module directly.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	applyContrast,
	applyTheme,
	CONTRAST_STORAGE_KEY,
	persistContrast,
	persistTheme,
	resolveStoredTheme,
	THEME_STORAGE_KEY,
} from '../templates/blog/src/lib/theme/theme.ts';
import { ALL_THEMES, nextTheme } from '../templates/blog/src/lib/theme/registry.ts';
import {
	DEFAULT_POLICY_DOC,
	isValidPolicyValue,
	parsePolicyDoc,
	resolvePolicyTheme,
	type PolicyDoc,
} from '../templates/blog/src/lib/theme/policy.ts';

/** Minimal HTMLElement double — the module only touches these surfaces. */
function fakeRoot(): HTMLElement {
	const attributes = new Map<string, string>();
	return {
		style: {} as CSSStyleDeclaration,
		setAttribute: (name: string, value: string) => attributes.set( name, value ),
		removeAttribute: (name: string) => {
			attributes.delete( name );
		},
		hasAttribute: (name: string) => attributes.has( name ),
		getAttribute: (name: string) => attributes.get( name ) ?? null,
	} as unknown as HTMLElement;
}

function fakeStorage( initial: Record<string, string> = {} ): { storage: Storage; written: Record<string, string> } {
	const values = { ...initial };
	return {
		storage: {
			getItem: ( key: string ) => values[key] ?? null,
			setItem: ( key: string, value: string ) => {
				values[key] = value;
			},
		} as unknown as Storage,
		written: values,
	};
}

describe( 'resolveStoredTheme', () => {
	it( 'returns a stored theme when it is registered', () => {
		const { storage } = fakeStorage( { [THEME_STORAGE_KEY]: 'dark' } );
		expect( resolveStoredTheme( storage, { matches: false }, ALL_THEMES ) ).toBe( 'dark' );
		expect( resolveStoredTheme( storage, { matches: true }, ALL_THEMES ) ).toBe( 'dark' );
	} );

	it( 'accepts the implicit light value', () => {
		const { storage } = fakeStorage( { [THEME_STORAGE_KEY]: 'light' } );
		expect( resolveStoredTheme( storage, { matches: false }, ALL_THEMES ) ).toBe( 'light' );
	} );

	it( 'ignores unregistered stored values (registry is the allowlist)', () => {
		const { storage } = fakeStorage( { [THEME_STORAGE_KEY]: 'brand-x' } );
		expect( resolveStoredTheme( storage, { matches: false }, ALL_THEMES ) ).toBe( 'dark' );
	} );

	it( 'falls back to the media preference when nothing is stored', () => {
		expect( resolveStoredTheme( null, { matches: true }, ALL_THEMES ) ).toBe( 'light' );
		expect( resolveStoredTheme( null, { matches: false }, ALL_THEMES ) ).toBe( 'dark' );
		expect( resolveStoredTheme( null, null, ALL_THEMES ) ).toBe( 'dark' );
	} );

	it( 'survives an unavailable storage (privacy mode)', () => {
		const throwing = { getItem: () => {
			throw new Error( 'denied' );
		} } as unknown as Storage;
		expect( resolveStoredTheme( throwing, { matches: true }, ALL_THEMES ) ).toBe( 'light' );
	} );
} );

describe( 'applyTheme', () => {
	it( 'light removes the attribute and syncs color-scheme (absent = light contract)', () => {
		const root = fakeRoot();
		applyTheme( 'dark', root );
		applyTheme( 'light', root );
		expect( root.hasAttribute( 'data-theme' ) ).toBe( false );
		expect( root.style.colorScheme ).toBe( 'light' );
	} );

	it( 'dark sets the attribute and syncs color-scheme', () => {
		const root = fakeRoot();
		applyTheme( 'dark', root );
		expect( root.getAttribute( 'data-theme' ) ).toBe( 'dark' );
		expect( root.style.colorScheme ).toBe( 'dark' );
	} );

	it( 'brand themes set the attribute with a light color-scheme', () => {
		const root = fakeRoot();
		applyTheme( 'brand-x', root );
		expect( root.getAttribute( 'data-theme' ) ).toBe( 'brand-x' );
		expect( root.style.colorScheme ).toBe( 'light' );
	} );
} );

describe( 'applyContrast', () => {
	it( 'high sets the axis, standard removes it', () => {
		const root = fakeRoot();
		applyContrast( 'high', root );
		expect( root.getAttribute( 'data-contrast' ) ).toBe( 'high' );
		applyContrast( 'standard', root );
		expect( root.hasAttribute( 'data-contrast' ) ).toBe( false );
	} );
} );

describe( 'persistTheme / persistContrast', () => {
	it( 'writes both keys through the storage seam', () => {
		const { storage, written } = fakeStorage();
		persistTheme( 'dark', storage );
		persistContrast( 'high', storage );
		expect( written[THEME_STORAGE_KEY] ).toBe( 'dark' );
		expect( written[CONTRAST_STORAGE_KEY] ).toBe( 'high' );
	} );

	it( 'survives an unavailable storage', () => {
		const throwing = { setItem: () => {
			throw new Error( 'denied' );
		} } as unknown as Storage;
		expect( () => persistTheme( 'dark', throwing ) ).not.toThrow();
		expect( () => persistContrast( 'high', throwing ) ).not.toThrow();
	} );
} );

describe( 'registry (forge variant)', () => {
	it( 'declares the static light/dark list', () => {
		expect( ALL_THEMES ).toEqual( ['light', 'dark'] );
	} );

	it( 'cycles light → dark → light', () => {
		expect( nextTheme( 'light' ) ).toBe( 'dark' );
		expect( nextTheme( 'dark' ) ).toBe( 'light' );
	} );

	it( 'unknown current themes fall back to the top of the cycle', () => {
		expect( nextTheme( 'brand-x' ) ).toBe( 'light' );
	} );

	it( 'accepts an extended registry (brand themes ride the same cycle)', () => {
		const registry = ['light', 'dark', 'brand-x'];
		expect( nextTheme( 'light', registry ) ).toBe( 'dark' );
		expect( nextTheme( 'dark', registry ) ).toBe( 'brand-x' );
		expect( nextTheme( 'brand-x', registry ) ).toBe( 'light' );
	} );
} );

// --------------------------------------------------------------------------
// Theme policy (Proposal 105 §3) — the forge-canonical module the velocity
// storefront mirrors. The ladder: forced brand (registry-validated) /
// light / dark; auto → stored → media → defaultTheme; contrast forced
// standard/high or auto → stored high → standard.
// --------------------------------------------------------------------------

const BRAND_REGISTRY = ['light', 'dark', 'lenas-chair'];

function doc( overrides: Partial<PolicyDoc> = {} ): PolicyDoc {
	return { ...DEFAULT_POLICY_DOC, ...overrides };
}

describe( 'isValidPolicyValue / parsePolicyDoc', () => {
	it( 'validates the enum + brand-slug regex', () => {
		expect( isValidPolicyValue( 'auto' ) ).toBe( true );
		expect( isValidPolicyValue( 'light' ) ).toBe( true );
		expect( isValidPolicyValue( 'dark' ) ).toBe( true );
		expect( isValidPolicyValue( 'brand:lenas-chair' ) ).toBe( true );
		expect( isValidPolicyValue( 'brand:' ) ).toBe( false );
		expect( isValidPolicyValue( 'brand:Lenas' ) ).toBe( false );
		expect( isValidPolicyValue( 'sepia' ) ).toBe( false );
	} );

	it( 'parses a valid doc verbatim (updatedAt + $extensions survive)', () => {
		const raw = {
			policy: 'dark',
			contrast: 'high',
			defaultTheme: 'light',
			updatedAt: '2026-11-03T09:00:00Z',
			$extensions: {},
		};
		expect( parsePolicyDoc( raw ) ).toEqual( raw );
	} );

	it( 'is lenient: bad shapes and bad values fall back to the default doc', () => {
		expect( parsePolicyDoc( null ) ).toEqual( DEFAULT_POLICY_DOC );
		expect( parsePolicyDoc( 'auto' ) ).toEqual( DEFAULT_POLICY_DOC );
		expect( parsePolicyDoc( { policy: 'sepia', contrast: 'neon', defaultTheme: 'blue' } ) ).toEqual( DEFAULT_POLICY_DOC );
	} );
} );

describe( 'resolvePolicyTheme — the ladder', () => {
	it( 'forced light ignores the stored preference', () => {
		expect( resolvePolicyTheme( doc( { policy: 'light' } ), 'dark', false, BRAND_REGISTRY ) ).toEqual( {
			theme: 'light',
			contrast: 'standard',
			forced: true,
		} );
	} );

	it( 'forced dark ignores the stored preference', () => {
		expect( resolvePolicyTheme( doc( { policy: 'dark' } ), 'light', true, BRAND_REGISTRY ) ).toEqual( {
			theme: 'dark',
			contrast: 'standard',
			forced: true,
		} );
	} );

	it( 'a registered brand slug forces the brand theme', () => {
		expect( resolvePolicyTheme( doc( { policy: 'brand:lenas-chair' } ), 'dark', false, BRAND_REGISTRY ) ).toEqual( {
			theme: 'lenas-chair',
			contrast: 'standard',
			forced: true,
		} );
	} );

	it( 'an unknown brand slug fails open to the auto ladder', () => {
		const resolved = resolvePolicyTheme( doc( { policy: 'brand:nope' } ), 'dark', false, BRAND_REGISTRY );
		expect( resolved.forced ).toBe( false );
		expect( resolved.theme ).toBe( 'dark' ); // stored wins under auto
	} );

	it( 'auto honours a stored registered theme', () => {
		expect( resolvePolicyTheme( doc(), 'lenas-chair', false, BRAND_REGISTRY ).theme ).toBe( 'lenas-chair' );
		expect( resolvePolicyTheme( doc(), 'light', false, BRAND_REGISTRY ).theme ).toBe( 'light' );
	} );

	it( 'auto ignores an unregistered stored value', () => {
		const resolved = resolvePolicyTheme( doc(), 'sepia', true, BRAND_REGISTRY );
		expect( resolved.theme ).toBe( 'light' ); // media light
		expect( resolved.forced ).toBe( false );
	} );

	it( 'auto falls back to the media query', () => {
		expect( resolvePolicyTheme( doc(), null, true, BRAND_REGISTRY ).theme ).toBe( 'light' );
		expect( resolvePolicyTheme( doc(), null, false, BRAND_REGISTRY ).theme ).toBe( 'dark' );
	} );

	it( 'auto falls back to defaultTheme when no media API exists', () => {
		expect( resolvePolicyTheme( doc(), null, null, BRAND_REGISTRY ).theme ).toBe( 'dark' ); // the default
		expect( resolvePolicyTheme( doc( { defaultTheme: 'light' } ), null, null, BRAND_REGISTRY ).theme ).toBe( 'light' );
	} );

	it( 'contrast forced standard/high beats the stored axis', () => {
		expect( resolvePolicyTheme( doc( { contrast: 'high' } ), null, null, BRAND_REGISTRY, 'standard' ).contrast ).toBe( 'high' );
		expect( resolvePolicyTheme( doc( { contrast: 'standard' } ), null, null, BRAND_REGISTRY, 'high' ).contrast ).toBe( 'standard' );
	} );

	it( 'contrast auto uses the stored high axis, else standard', () => {
		expect( resolvePolicyTheme( doc(), null, null, BRAND_REGISTRY, 'high' ).contrast ).toBe( 'high' );
		expect( resolvePolicyTheme( doc(), null, null, BRAND_REGISTRY, 'standard' ).contrast ).toBe( 'standard' );
		expect( resolvePolicyTheme( doc(), null, null, BRAND_REGISTRY, null ).contrast ).toBe( 'standard' );
	} );
} );
