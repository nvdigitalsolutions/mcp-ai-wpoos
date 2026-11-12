// ---------------------------------------------------------------------------
// text-parity.test.ts — normalisation, shingling, coverage direction, extraction.
//
// Content parity is a ONE-DIRECTIONAL gate: coverage of the source decides.
// Added copy must never fail a rebuild (precision is reported, not gated).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	compareText,
	extractSectionTexts,
	normalizeText,
	shingles,
	textBaselineFromHtml,
	textFromHtml,
	tokenize,
} from '../lib/text-parity.ts';

describe( 'normalizeText', () => {
	it( 'folds smart punctuation and invisible whitespace', () => {
		const input = 'It\u2019s \u201cquoted\u201d \u2014 really\u2026\u00a0yes\u200b!';
		expect( normalizeText( input ) ).toBe( 'it\'s "quoted" - really... yes!' );
	} );

	it( 'collapses whitespace and lowercases', () => {
		expect( normalizeText( '  Alpha \n\n  BETA\t ' ) ).toBe( 'alpha beta' );
	} );

	it( 'applies NFKC (ligatures and full-width forms)', () => {
		expect( normalizeText( '\uFB01ne \uFF21' ) ).toBe( 'fine a' );
	} );
} );

describe( 'tokenize', () => {
	it( 'keeps internal apostrophes and hyphens', () => {
		expect( tokenize( "don't e-mail page2" ) ).toEqual( [ "don't", 'e-mail', 'page2' ] );
	} );

	it( 'drops punctuation-only tokens', () => {
		expect( tokenize( 'a - b | c' ) ).toEqual( [ 'a', 'b', 'c' ] );
	} );
} );

describe( 'shingles', () => {
	it( 'emits overlapping 3-word shingles', () => {
		expect( [ ...shingles( [ 'a', 'b', 'c', 'd' ], 3 ) ] ).toEqual( [ 'a b c', 'b c d' ] );
	} );

	it( 'collapses short documents to one shingle', () => {
		expect( [ ...shingles( [ 'only', 'two' ], 3 ) ] ).toEqual( [ 'only two' ] );
	} );

	it( 'returns nothing for empty input', () => {
		expect( shingles( [], 3 ).size ).toBe( 0 );
	} );
} );

describe( 'compareText', () => {
	const source =
		'Cloud managed wifi for every room. Fast installation and local support across the island.';

	it( 'passes an identical sample with coverage 1', () => {
		const report = compareText( source, source );
		expect( report.coverage ).toBe( 1 );
		expect( report.jaccard ).toBe( 1 );
		expect( report.verdict ).toBe( 'pass' );
	} );

	it( 'fails when source content is omitted', () => {
		const report = compareText( source, 'Cloud managed wifi for every room.' );
		expect( report.coverage ).toBeLessThan( 0.75 );
		expect( report.verdict ).toBe( 'fail' );
		expect( report.missing.length ).toBeGreaterThan( 0 );
	} );

	it( 'warns on a partial omission', () => {
		// 12 tokens → 10 shingles; the first 10 tokens → 8 shingles → 80%.
		const long = 'alpha bravo charlie delta echo foxtrot golf hotel india juliet kilo lima';
		const report = compareText( long, 'alpha bravo charlie delta echo foxtrot golf hotel india juliet' );
		expect( report.coverage ).toBeCloseTo( 0.8, 5 );
		expect( report.verdict ).toBe( 'warn' );
	} );

	it( 'never fails a rebuild for ADDED copy (coverage is the gate)', () => {
		const report = compareText( source, `${ source } Book a survey today and save.` );
		expect( report.coverage ).toBe( 1 );
		expect( report.precision ).toBeLessThan( 1 );
		expect( report.verdict ).toBe( 'pass' );
	} );

	it( 'is case- and punctuation-insensitive', () => {
		const report = compareText( source, source.toUpperCase().replace( /\./g, '\u2019' ) );
		expect( report.verdict ).toBe( 'pass' );
	} );

	it( 'honours custom thresholds and shingle width', () => {
		const built = 'Cloud managed wifi for every room.';
		expect( compareText( source, built, { passAt: 0.3, warnAt: 0.2 } ).verdict ).toBe( 'pass' );
		expect( compareText( source, built, { shingleSize: 1, warnAt: 0.1, passAt: 0.9 } ).verdict ).toBe( 'warn' );
	} );
} );

describe( 'textFromHtml', () => {
	const html = `<!doctype html><html><head><title>Ignore me</title><style>.a{color:red}</style></head>
		<body>
			<h1>Hello <em>world</em></h1>
			<p>Second block.</p>
			<script>var leak = 'nope';</script>
			<noscript>Noscript copy</noscript>
			<div hidden>Hidden copy</div>
			<div aria-hidden="true">Aria hidden</div>
			<img src="a.jpg" alt="A product photo">
		</body></html>`;

	it( 'keeps visible block text and drops non-visible surfaces', () => {
		const text = textFromHtml( html );
		expect( text ).toContain( 'hello world' );
		expect( text ).toContain( 'second block' );
		expect( text ).not.toContain( 'ignore me' );
		expect( text ).not.toContain( 'color:red' );
		expect( text ).not.toContain( 'nope' );
		expect( text ).not.toContain( 'noscript copy' );
		expect( text ).not.toContain( 'hidden copy' );
		expect( text ).not.toContain( 'aria hidden' );
		expect( text ).not.toContain( 'product photo' );
	} );

	it( 'includes alt text only when asked', () => {
		expect( textFromHtml( html, { includeAlt: true } ) ).toContain( 'product photo' );
	} );

	it( 'separates blocks so words do not fuse', () => {
		expect( textFromHtml( '<p>Alpha</p><p>Beta</p>' ) ).toBe( 'alpha beta' );
	} );

	it( 'returns an empty string for empty input', () => {
		expect( textFromHtml( '   ' ) ).toBe( '' );
	} );
} );

describe( 'extractSectionTexts', () => {
	it( 'finds built bands by data-section-id', () => {
		const html = '<section data-section-id="hero"><h1>Hero copy</h1></section>';
		expect( extractSectionTexts( html, [ 'hero' ] ).texts.hero ).toBe( 'hero copy' );
	} );

	it( 'finds source bands by Elementor class, plain id, or class name', () => {
		const html = [
			'<div class="elementor-element elementor-element-abc"><p>Elementor band</p></div>',
			'<div id="about"><p>Id band</p></div>',
			'<div class="vc_row about-band"><p>Class band</p></div>',
		].join( '' );
		const found = extractSectionTexts( html, [ 'abc', 'about' ] );
		expect( found.texts.abc ).toBe( 'elementor band' );
		expect( found.texts.about ).toBe( 'id band' );
		expect( extractSectionTexts( html, [ 'about-band' ] ).texts[ 'about-band' ] ).toBe( 'class band' );
	} );

	it( 'locates an outline band through its source DOM-id alias', () => {
		// A Flatsome band is keyed by the stable hash while its node carries
		// `section_<digits>`; without the alias the baseline went page-level and
		// the per-band words check could not run (monicarajiv.com, 2026-11-11).
		const html =
			'<section class="section" id="section_150552065"><p>Band copy</p></section>';
		const found = extractSectionTexts( html, [ '84a6c14' ], {
			selectors: { '84a6c14': 'section_150552065' },
		} );
		expect( found.texts[ '84a6c14' ] ).toBe( 'band copy' );
		expect( found.missing ).toEqual( [] );
	} );

	it( 'keeps the id lookup first when a band and its alias disagree', () => {
		const html = [
			'<div id="about"><p>Real band</p></div>',
			'<div id="alias"><p>Wrong node</p></div>',
		].join( '' );
		const found = extractSectionTexts( html, [ 'about' ], {
			selectors: { about: 'alias' },
		} );
		expect( found.texts.about ).toBe( 'real band' );
	} );

	it( 'reports bands it cannot locate instead of failing', () => {
		const result = extractSectionTexts( '<div></div>', [ 'nope' ] );
		expect( result.texts ).toEqual( {} );
		expect( result.missing ).toEqual( [ 'nope' ] );
	} );
} );

describe( 'textBaselineFromHtml', () => {
	it( 'records page text, band text and skipped ids', () => {
		const html = `
			<body>
				<section data-section-id="hero"><h1>Hero copy</h1></section>
				<section data-section-id="empty"><span>   </span></section>
			</body>`;
		const baseline = textBaselineFromHtml( html, {
			url: 'https://example.test',
			ids: [ 'hero', 'empty', 'absent' ],
			capturedAt: '2026-01-01T00:00:00.000Z',
		} );
		expect( baseline.page ).toBe( 'hero copy' );
		expect( baseline.sections ).toEqual( [ { id: 'hero', text: 'hero copy' } ] );
		expect( baseline.skipped ).toEqual( [ 'empty', 'absent' ] );
		expect( baseline.capturedAt ).toBe( '2026-01-01T00:00:00.000Z' );
	} );
} );
