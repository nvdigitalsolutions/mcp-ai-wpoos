// ---------------------------------------------------------------------------
// compare.ts — measure the same element on the source and on the rebuild, and
// report the difference.
//
// Every defect found on 2026-11-19/20 was found the same way: by hand, with a
// throwaway Playwright script that printed a table. That work is the same every
// time, and it is the ONLY check that sees what the other gates cannot:
//
//   - a rule that is DECLARED but OUTRANKED (the banner photo froze at 900px of
//     a 1200px layer because a measured `img.bg{height}` tied the shim on
//     specificity and won on order);
//   - a value that is DROPPED as ambiguous and silently becomes 0
//     (`.col-inner` padding: 50/180/60/108 → 0);
//   - a layout slot that never engages (`grid-column` on a `display:contents`
//     wrapper, so the disclosure sat at x=524 instead of x=195).
//
// Fact audits read the profile; this reads the rendered box. Both are needed.
// ---------------------------------------------------------------------------

/** One element to compare, and the properties worth comparing on it. */
export interface CompareSpec {
	/** A short name for the report (`header toggle`). */
	name: string;
	/** The selector, resolved on both pages. */
	selector: string;
	/**
	 * Properties to read. A CSS property name, or one of the synthetic box
	 * metrics `rect.width` / `rect.height` / `rect.left` / `rect.centre`
	 * (rounded px), which is what catches a misplaced slot.
	 */
	properties: string[];
}

/** One property that disagrees (or matches) between the two pages. */
export interface CompareRow {
	element: string;
	property: string;
	source: string;
	rebuilt: string;
	/** Numeric difference for px-valued metrics; `undefined` otherwise. */
	delta?: number;
}

/** A page's readings: element name → property → value. */
export type CompareReadings = Record< string, Record< string, string > >;

/** `"50px   180px"` → `"50px 180px"`; trims and collapses whitespace. */
export function normaliseValue( value: string | undefined ): string {
	return String( value ?? '' ).replace( /\s+/g, ' ' ).trim();
}

/** The px magnitude of a declaration, when it is a single px length. */
function pxMagnitude( value: string ): number | undefined {
	const match = /^(-?\d+(?:\.\d+)?)px$/.exec( value );
	return match ? Number.parseFloat( match[ 1 ] ) : undefined;
}

/**
 * The numeric difference between two readings, or `undefined` when they are not
 * both single px lengths (a padding shorthand, a colour, a font stack).
 */
export function deltaOf( source: string, rebuilt: string ): number | undefined {
	const a = pxMagnitude( normaliseValue( source ) );
	const b = pxMagnitude( normaliseValue( rebuilt ) );
	if ( a === undefined || b === undefined ) {
		return undefined;
	}
	return Math.round( ( b - a ) * 100 ) / 100;
}

/**
 * Compare the two readings property by property.
 *
 * A property NEITHER page reports is skipped: the element may legitimately not
 * exist in one of them, and reporting an empty pair as a match would inflate the
 * result. A property one page reports and the other does not is a real
 * difference and is reported.
 */
export function compareFacts(
	specs: CompareSpec[],
	source: CompareReadings,
	rebuilt: CompareReadings
): CompareRow[] {
	const rows: CompareRow[] = [];
	for ( const spec of specs ) {
		const from = source[ spec.name ] ?? {};
		const to = rebuilt[ spec.name ] ?? {};
		for ( const property of spec.properties ) {
			const left = normaliseValue( from[ property ] );
			const right = normaliseValue( to[ property ] );
			if ( left === '' && right === '' ) {
				continue;
			}
			rows.push( {
				element: spec.name,
				property,
				source: left,
				rebuilt: right,
				...( deltaOf( left, right ) !== undefined ? { delta: deltaOf( left, right ) } : {} ),
			} );
		}
	}
	return rows;
}

/** The rows that actually disagree. */
export function mismatches( rows: CompareRow[] ): CompareRow[] {
	return rows.filter( ( row ) => row.source !== row.rebuilt );
}

/**
 * Render the report.
 *
 * `tolerance` absorbs sub-pixel layout rounding (a 720.4 font box against 720),
 * so a table full of ±1px noise does not hide the real differences.
 */
export function describeCompare( rows: CompareRow[], tolerance = 1 ): string[] {
	const lines: string[] = [];
	const width = Math.max( 0, ...rows.map( ( row ) => row.element.length ) );
	const propWidth = Math.max( 0, ...rows.map( ( row ) => row.property.length ) );
	for ( const row of rows ) {
		const numeric = typeof row.delta === 'number';
		const agrees = numeric ? Math.abs( row.delta as number ) <= tolerance : row.source === row.rebuilt;
		const mark = agrees ? '\u2713' : '\u2260';
		const delta = numeric && ! agrees ? ` (${ ( row.delta as number ) > 0 ? '+' : '' }${ row.delta })` : '';
		lines.push(
			`  ${ mark } ${ row.element.padEnd( width ) } ${ row.property.padEnd( propWidth ) } ` +
				`source ${ row.source.padEnd( 22 ) } rebuilt ${ row.rebuilt }${ delta }`
		);
	}
	return lines;
}

/** A one-line verdict: `undefined` when nothing disagrees beyond tolerance. */
export function describeCompareVerdict( rows: CompareRow[], tolerance = 1 ): string | undefined {
	const differing = mismatches( rows ).filter( ( row ) => {
		if ( typeof row.delta === 'number' ) {
			return Math.abs( row.delta ) > tolerance;
		}
		return true;
	} );
	if ( differing.length === 0 ) {
		return undefined;
	}
	const elements = [ ...new Set( differing.map( ( row ) => row.element ) ) ];
	return `${ differing.length } propert(ies) differ on ${ elements.length } element(s): ${ elements.join( ', ' ) }`;
}

/**
 * Read the specs from one page.
 *
 * Absent elements are omitted rather than reported as empty, so `compareFacts`
 * can tell "missing on both" from "missing on one".
 */
export async function measureCompareSpecs(
	page: { evaluate: ( fn: unknown, arg: unknown ) => Promise< unknown > },
	specs: CompareSpec[]
): Promise< CompareReadings > {
	const readings = await page.evaluate( ( list: CompareSpec[] ) => {
		const out: Record< string, Record< string, string > > = {};
		for ( const spec of list ) {
			let element: Element | null = null;
			try {
				element = document.querySelector( spec.selector );
			} catch {
				element = null;
			}
			if ( ! element ) {
				continue;
			}
			const style = getComputedStyle( element );
			const rect = element.getBoundingClientRect();
			const values: Record< string, string > = {};
			for ( const property of spec.properties ) {
				if ( property === 'rect.width' ) {
					values[ property ] = `${ Math.round( rect.width ) }px`;
				} else if ( property === 'rect.height' ) {
					values[ property ] = `${ Math.round( rect.height ) }px`;
				} else if ( property === 'rect.left' ) {
					values[ property ] = `${ Math.round( rect.left ) }px`;
				} else if ( property === 'rect.centre' ) {
					values[ property ] = `${ Math.round( rect.left + rect.width / 2 ) }px`;
				} else {
					values[ property ] = style.getPropertyValue( property );
				}
			}
			out[ spec.name ] = values;
		}
		return out;
	}, specs );

	return ( readings ?? {} ) as CompareReadings;
}
