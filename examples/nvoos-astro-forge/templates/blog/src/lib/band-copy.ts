// ---------------------------------------------------------------------------
// band-copy.ts — the band's body paragraphs, without the text the measured
// heading already prints.
//
// The classifier names a band's title by picking its LARGEST heading, and the
// measurement can disagree: a long all-caps kicker out-masses the real title, so
// the promoted text stays behind in `slots.body` while `SectionTitle` prints the
// measured title above it. virtuallysrilanka's hardware band rendered
// "SUPPORTED HARDWARE" twice — once as the `<h2>`, once as body copy.
// ---------------------------------------------------------------------------

/** The measured heading facts a band body must not repeat. */
export interface BandHeadingFacts {
	titleText?: string;
	eyebrow?: { text?: string };
}

/** The band's paragraphs, minus any text its title or eyebrow already renders. */
export function bandParagraphs(
	body: string[] | undefined,
	theme?: BandHeadingFacts
): string[] {
	if ( ! body ) {
		return [];
	}
	const shown = new Set(
		[ theme?.titleText, theme?.eyebrow?.text ].filter(
			( value ): value is string => Boolean( value )
		)
	);
	if ( shown.size === 0 ) {
		return body;
	}
	return body.filter( ( paragraph ) => ! shown.has( paragraph ) );
}
