// ---------------------------------------------------------------------------
// font-audit.ts — did the icon fonts actually ship and render?
//
// The localizer can drop a font family without failing the build (Proposal 140,
// 2026-11-15: one 404'd ttf removed virtuallyjamaica's et-line face and every
// icon became a blank box while every existing gate passed). Two checks close
// that hole:
//
//   1. REPORT — `media-localize.json` now records every family whose last face
//      was pruned, plus whether a built glyph class still uses it. Pure and
//      browser-free, so it runs in `verify --minimal`.
//   2. RENDER — a browser loads the built page and asks `document.fonts` for
//      each icon family. The report cannot prove the font FILE is valid or
//      that the face survived a rewrite; this can. Browser-backed, so it joins
//      the other Playwright gates and degrades to `skip` without Chromium.
// ---------------------------------------------------------------------------

/** One family the localizer could not ship (from the media-localize report). */
export interface DroppedFontFamily {
	family: string;
	/** A sample of the glyph classes the source sheet drew with this family. */
	classes?: string[];
	/** A built document still carries one of those classes. */
	used?: boolean;
	/** The mirrored stylesheet the family was declared in. */
	sheet?: string;
}

export interface FontCoverageReport {
	droppedFontFamilies?: DroppedFontFamily[];
}

/** A family the built page references but the browser has no usable face for. */
export interface MissingIconFont {
	family: string;
	elements: number;
	sampleClass: string;
	glyph: string;
}

export interface IconFontMeasurement {
	/** How many icon markers the browser inspected. */
	checked: number;
	/** Families that are referenced but unavailable. */
	missing: MissingIconFont[];
}

/**
 * The dropped families a reader can actually see: their glyph classes are in
 * the built HTML. A dropped Google face nobody uses is a fallback, not a bug.
 */
export function usedDroppedFamilies( report: FontCoverageReport | undefined ): DroppedFontFamily[] {
	return ( report?.droppedFontFamilies ?? [] ).filter( ( entry ) => entry.used === true && entry.family );
}

/**
 * Load the built page and ask the browser which referenced icon families have
 * no usable face. `document.fonts.ready` resolves after the faces the page
 * actually uses have loaded, so a missing face is a real absence, not a race.
 *
 * FAIL-SOFT at the caller: a browser that cannot launch is a `skip` gate, not a
 * build failure.
 */
export async function measureIconFonts( options: { url: string } ): Promise< IconFontMeasurement > {
	const { chromium } = await import( 'playwright' );
	const browser = await chromium.launch();
	const page = await browser.newPage( { viewport: { width: 1440, height: 900 } } );
	try {
		await page.goto( options.url, { waitUntil: 'domcontentloaded', timeout: 30_000 } );
		await page.evaluate( () => document.fonts.ready );
		const families = await page.evaluate( async () => {
			const selector = [
				'i[class*="icon-"]',
				'i[class~="fa"]',
				'i[class*="fa-"]',
				'i[class*="auxicon"]',
				'i[class*="dashicons"]',
				'i[class*="eicon"]',
				'span[class*="icon-"]',
			].join( ',' );
			const generic = /^(?:inherit|initial|unset|revert|serif|sans-serif|monospace|cursive|fantasy|system-ui)$/i;
			const entries = new Map<
				string,
				{ family: string; elements: number; sampleClass: string; glyph: string; query: string }
			>();
			for ( const element of document.querySelectorAll( selector ) ) {
				const style = getComputedStyle( element );
				const family = ( style.fontFamily.split( ',' )[ 0 ] ?? '' ).replace( /["']/g, '' ).trim();
				if ( ! family || generic.test( family ) ) {
					continue;
				}
				const pseudo = getComputedStyle( element, '::before' );
				const glyph = String( pseudo.content ?? '' ).replace( /^["']|["']$/g, '' );
				const size = style.fontSize || '16px';
				// A family can carry several faces (FA's 400/900, a theme's weights).
				// Load the element's OWN style/weight before judging it: `check()`
				// reports an unloaded face as false, which is a false blank icon.
				const query = `${ style.fontStyle || 'normal' } ${ style.fontWeight || '400' } ${ size } "${ family }"`;
				const key = family.toLowerCase();
				const entry =
					entries.get( key ) ?? {
						family,
						elements: 0,
						sampleClass: String( element.className ?? element.tagName ),
						glyph,
						query,
					};
				entry.elements += 1;
				entries.set( key, entry );
			}

			const missing = [];
			for ( const entry of entries.values() ) {
				let available = false;
				try {
					const faces = await document.fonts.load( entry.query );
					available = faces.length > 0 && faces.every( ( face ) => face.status !== 'error' );
				} catch {
					available = false;
				}
				if ( ! available ) {
					missing.push( entry );
				}
			}
			return {
				checked: [ ...entries.values() ].reduce( ( total, entry ) => total + entry.elements, 0 ),
				missing: missing.map( ( { family, elements, sampleClass, glyph } ) => ( {
					family,
					elements,
					sampleClass,
					glyph,
				} ) ),
			};
		} );
		return families;
	} finally {
		await browser.close();
	}
}
