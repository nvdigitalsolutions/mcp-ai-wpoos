// ---------------------------------------------------------------------------
// pins.ts — operator-owned facts inside the ANALYZED `design` block.
//
// `analyze --write` refreshes `design` wholesale (Proposal 118 §3 keeps the
// operator's collections/tokens/frontPage/forms and lets the analyzed blocks
// refresh). Anything hand-tuned INSIDE `design` therefore disappears on the
// next regenerate — jayainc lost its `5e78df1` marginTop and the catalog's
// bottom padding exactly this way.
//
// A pin declares one fact operator-owned. Pins live at the profile's TOP
// level (`profile.pins`), never inside `design`, because `design` is the block
// being replaced. `mergeProfileDraft` re-applies them over the fresh design.
//
// Path grammar — dot paths into the design block, with one shorthand:
//
//   sections.<sectionId>.<dot.path>  -> design.sections[id=<sectionId>].<dot.path>
//   <dot.path>                       -> design.<dot.path>   (e.g. chrome.footerInk)
//
// A pin whose section is absent from the fresh design is REPORTED, never
// invented: re-creating a whole section from a single fact would fabricate
// source content.
// ---------------------------------------------------------------------------

import type { DesignPin, ForgeDesign } from './types.ts';

/** Result of resolving a pin path against a concrete design block. */
type Resolution =
	| { ok: true; segments: string[] }
	| { ok: false };

function deepClone< T >( value: T ): T {
	if ( value === undefined || value === null || typeof value !== 'object' ) {
		return value;
	}
	return JSON.parse( JSON.stringify( value ) ) as T;
}

/**
 * Structural equality. JSON.stringify would be order-sensitive, and a pinned
 * object fact must match regardless of key order (the profile's key order is
 * whatever the last generator pass emitted).
 */
function deepEqual( a: unknown, b: unknown ): boolean {
	if ( a === b ) {
		return true;
	}
	if ( typeof a !== 'object' || typeof b !== 'object' || a === null || b === null ) {
		return false;
	}
	if ( Array.isArray( a ) !== Array.isArray( b ) ) {
		return false;
	}
	const aKeys = Object.keys( a );
	const bKeys = Object.keys( b );
	if ( aKeys.length !== bKeys.length ) {
		return false;
	}
	const aRecord = a as Record< string, unknown >;
	const bRecord = b as Record< string, unknown >;
	return aKeys.every(
		( key ) =>
			Object.prototype.hasOwnProperty.call( bRecord, key ) &&
			deepEqual( aRecord[ key ], bRecord[ key ] )
	);
}

/**
 * Resolve a pin path to concrete segments on `design`. Section paths resolve
 * the id to the current array index, so a reordered `sections` array cannot
 * write a fact onto the wrong band.
 */
function resolvePin( design: ForgeDesign, path: string ): Resolution {
	const parts = path
		.split( '.' )
		.map( ( part ) => part.trim() )
		.filter( ( part ) => part.length > 0 );
	if ( parts.length === 0 ) {
		return { ok: false };
	}
	if ( parts[ 0 ] !== 'sections' ) {
		return { ok: true, segments: parts };
	}
	const sectionId = parts[ 1 ];
	if ( ! sectionId ) {
		return { ok: false };
	}
	const sections = design.sections ?? [];
	const index = sections.findIndex( ( section ) => section.id === sectionId );
	if ( index === -1 ) {
		return { ok: false };
	}
	return { ok: true, segments: [ 'sections', String( index ), ...parts.slice( 2 ) ] };
}

/** Write `value` at `segments`, creating intermediate objects as needed. */
function setIn( root: Record< string, unknown >, segments: string[], value: unknown ): void {
	if ( segments.length === 0 ) {
		return;
	}
	let node = root;
	for ( let i = 0; i < segments.length - 1; i++ ) {
		const key = segments[ i ];
		const next = node[ key ];
		if ( typeof next !== 'object' || next === null ) {
			node[ key ] = {};
		}
		node = node[ key ] as Record< string, unknown >;
	}
	node[ segments[ segments.length - 1 ] ] = value;
}

function readIn( root: unknown, segments: string[] ): unknown {
	let node = root;
	for ( const key of segments ) {
		if ( typeof node !== 'object' || node === null ) {
			return undefined;
		}
		node = ( node as Record< string, unknown > )[ key ];
	}
	return node;
}

/**
 * Apply the operator's pins over a design block. Returns a clone — callers
 * never mutate the analyzed design. Pins whose section is gone are skipped
 * here; `findDroppedPins` reports them.
 */
export function applyDesignPins(
	design: ForgeDesign | undefined,
	pins: DesignPin[] | undefined
): ForgeDesign | undefined {
	if ( ! design || ! pins || pins.length === 0 ) {
		return design;
	}
	const next = deepClone( design );
	for ( const pin of pins ) {
		const target = resolvePin( next, pin.path );
		if ( target.ok ) {
			setIn( next as unknown as Record< string, unknown >, target.segments, deepClone( pin.value ) );
		}
	}
	return next;
}

/**
 * The guard: every pin that the design no longer satisfies. An empty array
 * means the profile still carries every operator-owned fact.
 */
export function findDroppedPins(
	design: ForgeDesign | undefined,
	pins: DesignPin[] | undefined
): DesignPin[] {
	if ( ! pins || pins.length === 0 ) {
		return [];
	}
	if ( ! design ) {
		return [ ...pins ];
	}
	return pins.filter( ( pin ) => {
		const target = resolvePin( design, pin.path );
		if ( ! target.ok ) {
			return true;
		}
		return ! deepEqual( readIn( design, target.segments ), pin.value );
	} );
}
