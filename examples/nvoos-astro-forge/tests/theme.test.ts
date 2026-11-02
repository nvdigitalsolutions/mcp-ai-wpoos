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
