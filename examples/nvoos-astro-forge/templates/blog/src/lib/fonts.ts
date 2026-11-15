// ---------------------------------------------------------------------------
// fonts.ts — canonical Google Fonts family names.
//
// The analyzer records the source's families lower-case ("roboto", "dm sans"),
// because CSS font-family matching is case-insensitive. Google's CSS API is
// not: `family=roboto` answers 400 "Missing font family" while `family=Roboto`
// returns the sheet — on both /css and /css2.
//
// Emitting the harvested name verbatim therefore produced a render-blocking
// request that always failed, and every forged site silently fell back to the
// system stack (visible in GTmetrix as `css2?family=rubik:… (failed) 0 B`).
// ---------------------------------------------------------------------------

/**
 * Families whose canonical Google name is not plain title case.
 *
 * Google treats these as literal identifiers, so guessing must not be
 * arithmetic on the words: `ibm plex sans` title-cases to `Ibm Plex Sans`,
 * which 400s exactly like the lower-case form did.
 */
const CANONICAL: Record< string, string > = {
	'ibm plex sans': 'IBM Plex Sans',
	'ibm plex serif': 'IBM Plex Serif',
	'ibm plex mono': 'IBM Plex Mono',
	'dm sans': 'DM Sans',
	'dm serif display': 'DM Serif Display',
	'dm serif text': 'DM Serif Text',
	'dm mono': 'DM Mono',
	'pt sans': 'PT Sans',
	'pt serif': 'PT Serif',
	'pt mono': 'PT Mono',
	'noto sans sc': 'Noto Sans SC',
	'noto serif sc': 'Noto Serif SC',
	'noto sans tc': 'Noto Sans TC',
	'noto serif tc': 'Noto Serif TC',
	'noto sans jp': 'Noto Sans JP',
	'noto serif jp': 'Noto Serif JP',
	'noto sans kr': 'Noto Sans KR',
	'noto serif kr': 'Noto Serif KR',
	'noto sans hk': 'Noto Sans HK',
	'noto sans arabic': 'Noto Sans Arabic',
	'noto sans devanagari': 'Noto Sans Devanagari',
};

/**
 * The family name Google expects for a harvested CSS family.
 *
 * Whitespace is collapsed before lookup so `"Open  Sans "` resolves. An empty
 * or whitespace-only input returns an empty string, which the caller treats as
 * "emit no link" rather than building a URL that cannot work.
 *
 * @param name The family as harvested from the source stylesheet.
 * @returns The canonical Google family name.
 */
export function canonicalFontFamily( name: string ): string {
	const words = String( name ?? '' )
		.trim()
		.split( ' ' )
		.filter( ( word ) => word.length > 0 );
	if ( words.length === 0 ) {
		return '';
	}
	const key = words.join( ' ' ).toLowerCase();
	const known = CANONICAL[ key ];
	if ( known ) {
		return known;
	}
	return words
		.map( ( word ) => word.charAt( 0 ).toUpperCase() + word.slice( 1 ) )
		.join( ' ' );
}
