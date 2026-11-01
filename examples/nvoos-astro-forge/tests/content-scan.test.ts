// ---------------------------------------------------------------------------
// content-scan.test.ts — the audit scanner (096 §3.3 long-tail): a real
// htmlparser2 pass over canned HTML — images/hosts/alt, embeds, links,
// shortcode artifacts, and unprocessed shortcode brackets in text.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { scanHtml } from '../lib/content-scan.ts';

const CANNED = `
<article>
	<h1>Care Guide</h1>
	<p>Fold your knits.</p>
	<figure class="wp-block-image"><img src="https://shop.example.com/wp-content/uploads/a.jpg" alt="Folded knits" /></figure>
	<p>See the <a href="https://shop.example.com/posts/2/care-guide/">guide</a> or <a href="https://cdn.example.org/x">external</a>.</p>
	<img src="https://cdn.example.org/b.jpg" />
	<iframe src="https://www.youtube-nocookie.com/embed/abc123"></iframe>
	<table><tr><td>cell</td></tr></table>
	<div class="wp-caption aligncenter">[caption]A caption[/caption]</div>
	<blockquote>quote</blockquote>
	<figure><img src="https://shop.example.com/wp-content/uploads/c.jpg" /></figure>
	<img data-src="https://cdn.example.org/d.jpg" />
</article>
`;

describe( 'scanHtml', () => {
	it( 'counts structure: headings, paragraphs, figures, tables, quotes', () => {
		const scan = scanHtml( CANNED, 'shop.example.com' );
		expect( scan.headings ).toBe( 1 );
		expect( scan.paragraphs ).toBe( 2 );
		expect( scan.figures ).toBe( 2 );
		expect( scan.tables ).toBe( 1 );
		expect( scan.blockquotes ).toBe( 1 );
	} );

	it( 'classifies links internal vs external', () => {
		const scan = scanHtml( CANNED, 'shop.example.com' );
		expect( scan.links.internal ).toBe( 1 );
		expect( scan.links.external ).toBe( 1 );
	} );

	it( 'audits images: hosts, same-host pipeline candidates, missing alt', () => {
		const scan = scanHtml( CANNED, 'shop.example.com' );
		expect( scan.images.total ).toBe( 4 );
		expect( scan.images.missingAlt ).toBe( 3 ); // b.jpg, c.jpg, d.jpg (data-src with no alt)
		expect( scan.images.sameHost ).toBe( 2 ); // a.jpg + c.jpg
		expect( scan.images.hosts[ 'shop.example.com' ] ).toBe( 2 );
		expect( scan.images.hosts[ 'cdn.example.org' ] ).toBe( 2 );
	} );

	it( 'reports embed hosts and shortcode artifacts', () => {
		const scan = scanHtml( CANNED, 'shop.example.com' );
		expect( scan.embeds ).toEqual( [ 'www.youtube-nocookie.com' ] );
		expect( scan.shortcodeArtifacts ).toContain( 'wp-caption' );
	} );

	it( 'finds unprocessed shortcode brackets in text', () => {
		const scan = scanHtml( '<p>Oops [gallery ids="1,2"] here</p>', 'shop.example.com' );
		expect( scan.unprocessedShortcodes ).toContain( 'gallery' );
		const clean = scanHtml( '<p>Nothing to see</p>', 'shop.example.com' );
		expect( clean.unprocessedShortcodes ).toEqual( [] );
	} );

	it( 'treats relative and anchor hrefs as internal', () => {
		const scan = scanHtml( '<a href="/about/">about</a><a href="#top">top</a><a href="https://elsewhere.com">x</a>', 'shop.example.com' );
		expect( scan.links.internal ).toBe( 2 );
		expect( scan.links.external ).toBe( 1 );
	} );
} );
