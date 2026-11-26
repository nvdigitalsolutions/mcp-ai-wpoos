// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// media-localize.test.ts — Proposal 138 media localization.
//
// Pins the rules that keep a built site self-contained: which URLs are
// candidates, how a stale WordPress host is repaired, deterministic file
// naming, and — critically — that SEO surfaces (`og:image`, JSON-LD) and
// document links are never rewritten to a relative path.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	addFontDisplaySwap,
	addImageDimensions,
	collectAssetUrls,
	collectCssAssetUrls,
	collectDocumentUrls,
	collectStylesheetUrls,
	classesForFontFamily,
	cssRules,
	documentExtension,
	droppedFontFamilies,
	dropUnavailableFontFaces,
	extensionForContentType,
	fontFaceFamilies,
	htmlClassTokens,
	isReencodableImage,
	isWebfontUrl,
	mediaFileName,
	parseHostList,
	proxyMediaPath,
	resolveDownloadUrl,
	resolveStylesheetAsset,
	rewriteCss,
	rewriteHtml,
	rewriteSrcset,
	rewriteStylesheet,
	shouldLocalizeUrl,
	srcsetUrls,
	stripPreconnects,
	stylesheetAssetUrls,
	svgIntrinsicSize,
	WEBFONT_HOSTS,
} from '../templates/blog/scripts/media-localize-lib.mjs';

const CMS = 'https://cms.jayainc.com';

describe('parseHostList', () => {
	it('splits on commas and whitespace, lower-cases, drops ports', () => {
		expect(parseHostList(' CDN.Example.com , http://legacy.test:8080 ')).toEqual([
			'cdn.example.com',
			'legacy.test',
		]);
	});

	it('returns an empty list for empty input', () => {
		expect(parseHostList(undefined)).toEqual([]);
		expect(parseHostList('')).toEqual([]);
	});
});

describe('shouldLocalizeUrl', () => {
	const options = { cmsHost: 'cms.jayainc.com', mediaHosts: ['cdn.example.com'], localizeAll: false };

	it('accepts the CMS host', () => {
		expect(shouldLocalizeUrl(`${CMS}/wp-content/uploads/a.jpg`, options)).toBe(true);
	});

	it('accepts an allowlisted media host', () => {
		expect(shouldLocalizeUrl('https://cdn.example.com/a.jpg', options)).toBe(true);
	});

	it('accepts a WordPress asset path on any host (stale DB URL)', () => {
		expect(shouldLocalizeUrl('https://jayainc.com/wp-content/uploads/a.jpg', options)).toBe(true);
	});

	it('rejects unrelated third-party hosts', () => {
		expect(shouldLocalizeUrl('https://cdn.fixture.test/product-c.png', options)).toBe(false);
	});

	it('rejects non-http schemes and relative URLs', () => {
		expect(shouldLocalizeUrl('data:image/png;base64,AAAA', options)).toBe(false);
		expect(shouldLocalizeUrl('/media/a.png', options)).toBe(false);
		expect(shouldLocalizeUrl('mailto:hi@example.com', options)).toBe(false);
	});

	it('accepts everything when localizeAll is on', () => {
		expect(
			shouldLocalizeUrl('https://cdn.fixture.test/product-c.png', { ...options, localizeAll: true })
		).toBe(true);
	});
});

describe('resolveDownloadUrl', () => {
	it('re-points a stale wp-content host at the CMS origin', () => {
		expect(
			resolveDownloadUrl('https://jayainc.com/wp-content/uploads/a.jpg?ver=2', {
				cmsOrigin: CMS,
				mediaHosts: [],
			})
		).toBe('https://cms.jayainc.com/wp-content/uploads/a.jpg?ver=2');
	});

	it('leaves the CMS host alone', () => {
		const url = `${CMS}/wp-content/uploads/a.jpg`;
		expect(resolveDownloadUrl(url, { cmsOrigin: CMS, mediaHosts: [] })).toBe(url);
	});

	it('trusts an allowlisted media host', () => {
		const url = 'https://cdn.example.com/wp-content/uploads/a.jpg';
		expect(resolveDownloadUrl(url, { cmsOrigin: CMS, mediaHosts: ['cdn.example.com'] })).toBe(url);
	});

	it('never rewrites a non-WordPress path', () => {
		const url = 'https://jayainc.com/images/hero.jpg';
		expect(resolveDownloadUrl(url, { cmsOrigin: CMS, mediaHosts: [] })).toBe(url);
	});
});

describe('proxyMediaPath', () => {
	it('maps a WordPress upload to the same-origin proxy path', () => {
		expect(proxyMediaPath('https://cms.jayainc.com/wp-content/uploads/2026/11/photo.jpg')).toBe(
			'/media/2026/11/photo.jpg'
		);
	});

	it('keeps percent-encoded names intact', () => {
		expect(proxyMediaPath('https://cms.test/wp-content/uploads/Hero%20Image.JPG')).toBe(
			'/media/Hero%20Image.JPG'
		);
	});

	it('ignores theme assets, wp-includes and non-http URLs', () => {
		expect(proxyMediaPath('https://cms.test/wp-content/themes/theme/a.png')).toBeUndefined();
		expect(proxyMediaPath('https://cms.test/wp-includes/images/x.png')).toBeUndefined();
		expect(proxyMediaPath('data:image/png;base64,AAAA')).toBeUndefined();
	});

	it('returns undefined for the uploads root itself', () => {
		expect(proxyMediaPath('https://cms.test/wp-content/uploads/')).toBeUndefined();
	});
});

describe('mediaFileName', () => {
	it('is deterministic and content-addressed', () => {
		const first = mediaFileName(`${CMS}/wp-content/uploads/Hero Image.JPG`, 'jpg');
		const second = mediaFileName(`${CMS}/wp-content/uploads/Hero Image.JPG`, 'jpg');
		expect(first).toBe(second);
		expect(first).toMatch(/^media\/[0-9a-f]{12}-hero-image\.jpg$/);
	});

	it('differs for different URLs, including query strings', () => {
		expect(mediaFileName(`${CMS}/a.png`, 'png')).not.toBe(mediaFileName(`${CMS}/b.png`, 'png'));
		expect(mediaFileName(`${CMS}/a.png?v=1`, 'png')).not.toBe(mediaFileName(`${CMS}/a.png?v=2`, 'png'));
	});

	it('falls back to a safe slug for hostile path segments', () => {
		expect(mediaFileName(`${CMS}/wp-content/uploads/%20%20..%2F..%2F`, 'png')).toMatch(
			/^media\/[0-9a-f]{12}-asset\.png$/
		);
	});
});

describe('extensionForContentType', () => {
	it('maps the image types WordPress serves', () => {
		expect(extensionForContentType('image/jpeg')).toBe('jpg');
		expect(extensionForContentType('image/webp; charset=binary')).toBe('webp');
		expect(extensionForContentType('image/svg+xml')).toBe('svg');
	});

	it('maps the document types a host serves without an extension', () => {
		expect(extensionForContentType('application/pdf')).toBe('pdf');
		expect(extensionForContentType('application/vnd.ms-excel')).toBe('xls');
		expect(extensionForContentType('application/octet-stream', 'pdf')).toBe('pdf');
	});

	it('falls back to the supplied extension', () => {
		expect(extensionForContentType('application/octet-stream', 'png')).toBe('png');
		expect(extensionForContentType('', 'png')).toBe('png');
		expect(extensionForContentType(undefined)).toBe('bin');
	});
});

describe('srcset handling', () => {
	it('extracts candidate URLs', () => {
		expect(srcsetUrls('https://a.test/1.png 480w, https://a.test/2.png 1200w')).toEqual([
			'https://a.test/1.png',
			'https://a.test/2.png',
		]);
	});

	it('rewrites only mapped candidates and keeps descriptors', () => {
		const map = new Map([['https://a.test/1.png', '/media/one.png']]);
		expect(rewriteSrcset('https://a.test/1.png 480w, https://b.test/2.png 1200w', map)).toBe(
			'/media/one.png 480w, https://b.test/2.png 1200w'
		);
	});

	it('returns the original value when nothing matched', () => {
		const value = 'https://b.test/2.png 1200w';
		expect(rewriteSrcset(value, new Map())).toBe(value);
	});
});

describe('collectAssetUrls', () => {
	it('finds image, source, favicon and CSS background URLs', () => {
		const html = [
			'<img src="https://cms.test/wp-content/uploads/a.jpg" srcset="https://cms.test/a-480.jpg 480w" alt="">',
			'<picture><source srcset="https://cms.test/a.webp 1x"></picture>',
			'<link rel="icon" href="https://cms.test/favicon.ico">',
			'<div style="background-image:url(https://cms.test/bg.jpg)"></div>',
		].join('\n');
		expect([...collectAssetUrls(html)].sort()).toEqual(
			[
				'https://cms.test/wp-content/uploads/a.jpg',
				'https://cms.test/a-480.jpg',
				'https://cms.test/a.webp',
				'https://cms.test/bg.jpg',
				'https://cms.test/favicon.ico',
			].sort()
		);
	});

	it('never collects anchors or JSON-LD images, but DOES collect social images', () => {
		const html = [
			'<a href="https://cms.test/wp-content/uploads/catalog.pdf">Catalogue</a>',
			'<meta property="og:image" content="https://cms.test/og.jpg">',
			'<meta name="twitter:image" content="https://cms.test/tw.jpg">',
			'<meta property="og:title" content="https://cms.test/not-an-image.jpg">',
			'<script type="application/ld+json">{"image":"https://cms.test/ld.jpg"}</script>',
		].join('\n');
		// `og:image` / `twitter:image` are MIRRORED (they are assets like any other)
		// but must stay ABSOLUTE, so `rewriteHtml` re-points them at the public
		// origin. Anchors and JSON-LD payloads are still never touched.
		expect([...collectAssetUrls(html)].sort()).toEqual([
			'https://cms.test/og.jpg',
			'https://cms.test/tw.jpg',
		]);
	});

	it('collects stylesheet url() assets', () => {
		expect([...collectCssAssetUrls('body{background:url("https://cms.test/bg.jpg")}')]).toEqual([
			'https://cms.test/bg.jpg',
		]);
	});
});

describe('rewriteHtml', () => {
	const map = new Map([
		['https://cms.test/wp-content/uploads/a.jpg', '/media/abc123-a.jpg'],
		['https://cms.test/a-480.jpg', '/media/abc123-a-480.jpg'],
		['https://cms.test/bg.jpg', '/media/def456-bg.jpg'],
		['https://cms.test/favicon.ico', '/media/ghi789-favicon.ico'],
	]);

	it('rewrites src, srcset, favicon and inline backgrounds', () => {
		const html = [
			'<img src="https://cms.test/wp-content/uploads/a.jpg" srcset="https://cms.test/a-480.jpg 480w" alt="">',
			'<link rel="icon" href="https://cms.test/favicon.ico">',
			'<div style="background-image:url(\'https://cms.test/bg.jpg\')"></div>',
		].join('\n');
		const out = rewriteHtml(html, map);
		expect(out).toContain('src="/media/abc123-a.jpg"');
		expect(out).toContain('srcset="/media/abc123-a-480.jpg 480w"');
		expect(out).toContain('href="/media/ghi789-favicon.ico"');
		expect(out).toContain('style="background-image:url(/media/def456-bg.jpg)"');
		expect(out).not.toContain('cms.test');
	});

	it('leaves image anchors, og:image and JSON-LD absolute', () => {
		const html = [
			'<a href="https://cms.test/wp-content/uploads/a.jpg">Download</a>',
			'<meta property="og:image" content="https://cms.test/wp-content/uploads/a.jpg">',
			'<script type="application/ld+json">{"image":"https://cms.test/wp-content/uploads/a.jpg"}</script>',
		].join('\n');
		const out = rewriteHtml(html, map);
		expect(out.match(/https:\/\/cms\.test\/wp-content\/uploads\/a\.jpg/g)).toHaveLength(3);
	});

	it('re-points a mirrored og:image at the PUBLIC origin (still absolute)', () => {
		const html = [
			'<meta property="og:image" content="https://cms.test/wp-content/uploads/a.jpg">',
			'<meta name="twitter:image" content="https://cms.test/wp-content/uploads/a.jpg">',
			'<meta property="og:title" content="Hello">',
		].join('\n');
		const out = rewriteHtml(html, map, { siteOrigin: 'https://site.test/' });
		expect(out).toContain('content="https://site.test/media/abc123-a.jpg"');
		expect(out).toContain('content="Hello"');
		expect(out).not.toContain('cms.test');
	});

	it('is a no-op with an empty map', () => {
		const html = '<img src="https://cms.test/a.jpg" alt="">';
		expect(rewriteHtml(html, new Map())).toBe(html);
	});
});

describe('stylesheet localization', () => {
	it('collects external stylesheet links only', () => {
		const html = [
			'<link rel="stylesheet" href="https://cms.test/wp-content/themes/t/css/icon.css?ver=1">',
			'<link rel="stylesheet" href="/_astro/site.css">',
			'<link rel="preload" as="font" href="https://cms.test/font.woff2">',
		].join('\n');
		expect([ ...collectStylesheetUrls( html ) ]).toEqual([
			'https://cms.test/wp-content/themes/t/css/icon.css?ver=1',
		]);
	});

	it('resolves relative refs and drops fragments', () => {
		expect(
			resolveStylesheetAsset( 'fonts/a.woff', 'https://cms.test/wp-content/themes/t/css/icon.css' )
		).toBe( 'https://cms.test/wp-content/themes/t/css/fonts/a.woff' );
		expect( resolveStylesheetAsset( 'fonts/a.eot?#iefix', 'https://cms.test/t/css/icon.css' ) ).toBe(
			'https://cms.test/t/css/fonts/a.eot'
		);
		expect( resolveStylesheetAsset( 'data:font/woff;base64,AA', 'https://cms.test/t/css/icon.css' ) ).toBeUndefined();
	});

	it('lists a stylesheet\u2019s asset URLs', () => {
		const css = '@font-face{src:url("fonts/a.woff") format("woff")}';
		expect([ ...stylesheetAssetUrls( css, 'https://cms.test/t/css/i.css' ) ]).toEqual([
			'https://cms.test/t/css/fonts/a.woff',
		]);
	});

	it('rewrites mapped refs and absolutises the rest', () => {
		const map = new Map( [ [ 'https://cms.test/t/css/fonts/a.woff', '/media/x-a.woff' ] ] );
		const out = rewriteStylesheet(
			'a{src:url("fonts/a.woff")}b{src:url("fonts/b.woff")}c{src:url(data:font/woff;base64,AA)}',
			'https://cms.test/t/css/i.css',
			map
		);
		expect( out ).toContain( 'url(/media/x-a.woff)' );
		expect( out ).toContain( 'url(https://cms.test/t/css/fonts/b.woff)' );
		expect( out ).toContain( 'url(data:font/woff;base64,AA)' );
	});

	it('rewrites a mapped stylesheet link in HTML', () => {
		const html = '<link rel="stylesheet" href="https://cms.test/icon.css">';
		const out = rewriteHtml( html, new Map( [ [ 'https://cms.test/icon.css', '/media/x-icon.css' ] ] ) );
		expect( out ).toContain( 'href="/media/x-icon.css"' );
	});
});

describe('document localization', () => {
	it('collects only allowlisted document links, fragment-free', () => {
		const html = [
			'<a href="https://cms.test/wp-content/uploads/2021/FIORI-CATALOG.pdf#page=3">Catalogue</a>',
			'<a href="https://cms.test/wp-content/uploads/price-list.xlsx">Prices</a>',
			'<a href="https://cms.test/about/">About</a>',
			'<a href="/wp-content/uploads/local.pdf">Local</a>',
			'<a href="mailto:hi@cms.test">Mail</a>',
			'<a href="https://cms.test/wp-content/uploads/setup.exe">Setup</a>',
		].join('\n');
		expect([ ...collectDocumentUrls( html ) ].sort()).toEqual(
			[
				'https://cms.test/wp-content/uploads/2021/FIORI-CATALOG.pdf',
				'https://cms.test/wp-content/uploads/price-list.xlsx',
			].sort()
		);
	});

	it('derives the extension from the path and refuses unknown types', () => {
		expect( documentExtension( 'https://cms.test/a/BROCHURE.PDF' ) ).toBe( 'pdf' );
		expect( documentExtension( 'https://cms.test/a/setup.exe' ) ).toBeUndefined();
		expect( documentExtension( 'https://cms.test/a/page' ) ).toBeUndefined();
	});

	it('rewrites a mapped document link, keeping the fragment and attributes', () => {
		const map = new Map( [
			[ 'https://cms.test/wp-content/uploads/FIORI-CATALOG.pdf', '/media/abc123-fiori-catalog.pdf' ],
		] );
		const html =
			'<a class="ds-button" href="https://cms.test/wp-content/uploads/FIORI-CATALOG.pdf#page=3" target="_blank" rel="noopener">Open</a>';
		const out = rewriteHtml( html, map );
		expect( out ).toContain( 'href="/media/abc123-fiori-catalog.pdf#page=3"' );
		expect( out ).toContain( 'target="_blank"' );
		expect( out ).toContain( 'rel="noopener"' );
	});

	it('leaves unmapped links, anchors and page links alone', () => {
		const html = '<a href="https://cms.test/about/">About</a><a href="#top">Top</a>';
		const map = new Map( [ [ 'https://x.example/y.pdf', '/media/y.pdf' ] ] );
		expect( rewriteHtml( html, map ) ).toBe( html );
	});
});

describe('rewriteCss', () => {
	it('rewrites mapped url() references only', () => {
		const map = new Map([['https://cms.test/bg.jpg', '/media/x-bg.jpg']]);
		expect(rewriteCss('a{background:url("https://cms.test/bg.jpg")}b{background:url(/local.png)}', map)).toBe(
			'a{background:url(/media/x-bg.jpg)}b{background:url(/local.png)}'
		);
	});
});

describe('Proposal 140 — re-encoding', () => {
	it('only re-encodes raster formats that can improve', () => {
		expect(isReencodableImage('jpg')).toBe(true);
		expect(isReencodableImage('.JPEG')).toBe(true);
		expect(isReencodableImage('png')).toBe(true);
		expect(isReencodableImage('tiff')).toBe(true);
		// Vector, animated, and already next-gen sources are left alone.
		expect(isReencodableImage('svg')).toBe(false);
		expect(isReencodableImage('gif')).toBe(false);
		expect(isReencodableImage('webp')).toBe(false);
		expect(isReencodableImage('avif')).toBe(false);
		expect(isReencodableImage('ico')).toBe(false);
	});
});

describe('Proposal 140 — webfonts', () => {
	it('recognizes the Google Fonts hosts and nothing else', () => {
		expect(isWebfontUrl('https://fonts.googleapis.com/css2?family=Rubik')).toBe(true);
		expect(isWebfontUrl('https://fonts.gstatic.com/s/rubik/v30/x.woff2')).toBe(true);
		expect(isWebfontUrl('https://cms.test/wp-content/font.woff2')).toBe(false);
		expect(isWebfontUrl('not a url')).toBe(false);
	});

	it('only localizes webfont hosts when opted in', () => {
		const url = 'https://fonts.gstatic.com/s/rubik/v30/x.woff2';
		expect(shouldLocalizeUrl(url)).toBe(false);
		expect(shouldLocalizeUrl(url, { webfontHosts: WEBFONT_HOSTS })).toBe(true);
	});

	it('injects font-display only where it is missing', () => {
		const css = '@font-face{font-family:Icons;src:url(/media/i.woff)}@font-face{font-family:Body;font-display:optional;src:url(/b.woff)}';
		const out = addFontDisplaySwap(css);
		// The gap is filled…
		expect(out).toContain('@font-face{font-display:swap;font-family:Icons');
		// …and the author's explicit choice survives.
		expect(out).toContain('font-display:optional');
		expect(out).not.toContain('font-display:swap;font-family:Body');
	});
	it('is idempotent', () => {
		const once = addFontDisplaySwap('@font-face{font-family:Icons;src:url(/i.woff)}');
		expect(addFontDisplaySwap(once)).toBe(once);
	});
});

describe('Proposal 140 — image dimensions', () => {
	it('reads a viewBox before width/height', () => {
		expect(svgIntrinsicSize('<svg viewBox="0 0 289 76" width="999"><path/></svg>')).toEqual({ width: 289, height: 76 });
		expect(svgIntrinsicSize('<svg width="120px" height="40px"></svg>')).toEqual({ width: 120, height: 40 });
		expect(svgIntrinsicSize('<svg></svg>')).toBeNull();
	});

	it('fills width/height on dimension-less img tags', () => {
		const sizes = new Map([['/media/logo.webp', { width: 289, height: 76 }]]);
		const html = '<img class="brand-logo" src="/media/logo.webp" alt="JAYAINC">';
		expect(addImageDimensions(html, sizes)).toBe(
			'<img class="brand-logo" src="/media/logo.webp" alt="JAYAINC" width="289" height="76">'
		);
	});

	it('never overrides an explicit dimension', () => {
		const sizes = new Map([['/media/logo.webp', { width: 289, height: 76 }]]);
		const html = '<img src="/media/logo.webp" width="40" height="11">';
		expect(addImageDimensions(html, sizes)).toBe(html);
	});

	it('leaves images it has no size for alone', () => {
		const html = '<img src="/media/unknown.webp" alt="">';
		expect(addImageDimensions(html, new Map())).toBe(html);
	});
});

describe( 'Proposal 140 — dead preconnects', () => {
	const head =
		'<link rel="preconnect" href="https://fonts.googleapis.com">' +
		'<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' +
		'<link rel="preconnect" href="https://cdn.example.com">' +
		'<link rel="stylesheet" href="/media/x-fonts.css">';

	it( 'drops the hints for hosts the build now serves', () => {
		const out = stripPreconnects( head, [ 'fonts.googleapis.com', 'fonts.gstatic.com' ] );
		expect( out ).not.toContain( 'fonts.googleapis.com' );
		expect( out ).not.toContain( 'fonts.gstatic.com' );
	} );

	it( 'keeps a preconnect that still earns its keep', () => {
		const out = stripPreconnects( head, [ 'fonts.gstatic.com' ] );
		expect( out ).toContain( 'https://cdn.example.com' );
		expect( out ).toContain( 'https://fonts.googleapis.com' );
	} );

	it( 'never touches a stylesheet link', () => {
		const out = stripPreconnects( '<link rel="stylesheet" href="https://fonts.googleapis.com/css2">', [ 'fonts.googleapis.com' ] );
		expect( out ).toContain( 'rel="stylesheet"' );
	} );

	it( 'is a no-op without hosts', () => {
		expect( stripPreconnects( head ) ).toBe( head );
	} );
} );

describe( 'Proposal 140 — webfont stylesheet collection', () => {
	const GOOGLE = 'https://fonts.googleapis.com/css2?family=Roboto:wght@300;400&display=swap';
	const ENCODED = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400&amp;display=swap">';

	it( 'collects the Google API even though it has no .css suffix', () => {
		const urls = [ ...collectStylesheetUrls( ENCODED ) ];
		expect( urls ).toHaveLength( 1 );
		// Decoded, so the download URL is the one Google actually accepts.
		expect( urls[ 0 ] ).toBe( GOOGLE );
	} );

	it( 'still ignores a stylesheet link that is neither .css nor a webfont', () => {
		const html = '<link rel="stylesheet" href="https://example.com/api/style?theme=dark">';
		expect( [ ...collectStylesheetUrls( html ) ] ).toHaveLength( 0 );
	} );

	it( 'rewrites the encoded link through the map', () => {
		const map = new Map( [ [ GOOGLE, '/media/x-fonts.css' ] ] );
		expect( rewriteHtml( ENCODED, map ) ).toContain( 'href="/media/x-fonts.css"' );
	} );
} );

describe( 'Proposal 140 — font-family coverage', () => {
	const ICON_SHEET = [
		'@font-face{font-family:"et-line";src:url(fonts/et-line.woff) format("woff")}',
		'.icon-flag,.icon-target{font-family:\'et-line\';display:inline-block}',
		'@media (max-width: 767px){.icon-envelope{font-family:"et-line"}}',
		'.unrelated{color:red}',
	].join( '\n' );

	it( 'names the families a sheet declares and counts their faces', () => {
		const families = fontFaceFamilies(
			'@font-face{font-family:"et-line";src:url(a.woff)}\n@font-face{font-family: et-line;font-weight:700;src:url(b.woff)}'
		);

		expect( families.get( 'et-line' ) ).toBe( 2 );
	} );

	it( 'finds the glyph classes a family draws, including inside a media query', () => {
		const classes = [ ...classesForFontFamily( ICON_SHEET, 'et-line' ) ].sort();

		expect( classes ).toEqual( [ 'icon-envelope', 'icon-flag', 'icon-target' ] );
	} );

	it( 'reads class tokens from both quote styles', () => {
		const tokens = htmlClassTokens( '<i class="icon-flag icon-large"></i><span class=\'icon-target\'></span>' );

		expect( [ ...tokens ].sort() ).toEqual( [ 'icon-flag', 'icon-large', 'icon-target' ] );
	} );

	it( 'reports a dropped family as used only when its class is in the HTML', () => {
		const after = dropUnavailableFontFaces(
			ICON_SHEET,
			[ 'https://cms.example.com/theme/fonts/et-line.woff' ],
			'https://cms.example.com/theme/icon.css'
		);
		const dropped = droppedFontFamilies( ICON_SHEET, after, '<i class="icon-flag"></i>' );

		expect( dropped ).toHaveLength( 1 );
		expect( dropped[ 0 ] ).toMatchObject( { family: 'et-line', used: true } );
		expect( droppedFontFamilies( ICON_SHEET, after, '<p>no icons</p>' )[ 0 ].used ).toBe( false );
	} );

	it( 'does not report a family that kept a usable encoding', () => {
		const face =
			'@font-face{font-family:"et-line";src:url(fonts/et-line.woff) format("woff"),url(fonts/et-line.ttf) format("truetype")}\n.icon-flag{font-family:"et-line"}';
		const after = dropUnavailableFontFaces(
			face,
			[ 'https://cms.example.com/theme/fonts/et-line.ttf' ],
			'https://cms.example.com/theme/icon.css'
		);

		expect( droppedFontFamilies( face, after, '<i class="icon-flag"></i>' ) ).toEqual( [] );
	} );

	it( 'parses rules nested in @media and skips at-rule bodies', () => {
		const selectors = cssRules(
			'@font-face{font-family:X;src:url(x.woff)}@media (max-width: 600px){.a{color:red}}@keyframes spin{from{opacity:0}to{opacity:1}}.b{color:blue}'
		).map( ( rule ) => rule.selector );

		expect( selectors ).toEqual( [ '.a', '.b' ] );
	} );
} );

describe( 'Proposal 140 — unmirrored webfont faces', () => {
	// A half-mirrored sheet is the worst outcome: it ships a render-blocking
	// third-party chain that a build-to-build network blip decides. Measured
	// 2026-11-15 — one failed woff2 left fonts.gstatic.com in an otherwise
	// local sheet, and only the e2e gate caught it.
	const SHEET = [
		'/* latin */',
		'@font-face { font-family: "Rubik"; src: url(https://fonts.gstatic.com/s/rubik/latin.woff2) format("woff2"); }',
		'/* latin-ext */',
		'@font-face { font-family: "Rubik"; src: url(https://fonts.gstatic.com/s/rubik/latin-ext.woff2) format("woff2"); }',
	].join( '\n' );

	it( 'drops only the face whose URL could not be mirrored', () => {
		const css = dropUnavailableFontFaces( SHEET, [ 'https://fonts.gstatic.com/s/rubik/latin-ext.woff2' ] );

		expect( css ).toContain( '/rubik/latin.woff2' );
		expect( css ).not.toContain( 'latin-ext.woff2' );
		expect( css.match( /@font-face/g ) ?? [] ).toHaveLength( 1 );
	} );

	it( 'removes every face when the whole family failed', () => {
		const css = dropUnavailableFontFaces( SHEET, [
			'https://fonts.gstatic.com/s/rubik/latin.woff2',
			'https://fonts.gstatic.com/s/rubik/latin-ext.woff2',
		] );

		expect( css ).not.toContain( '@font-face' );
		expect( css ).not.toContain( 'fonts.gstatic.com' );
	} );

	it( 'is a no-op when nothing is missing', () => {
		expect( dropUnavailableFontFaces( SHEET, [] ) ).toBe( SHEET );
		expect( dropUnavailableFontFaces( SHEET ) ).toBe( SHEET );
	} );

	it( 'resolves a relative src against the sheet URL', () => {
		// A sheet usually writes `url(fonts/x.woff)` while the caller holds the
		// ABSOLUTE download key — matching the raw text alone silently missed
		// every face (this was the first cut of the fix).
		const css = '@font-face{font-family:X;src:url(fonts/x.woff)}';
		const out = dropUnavailableFontFaces(
			css,
			[ 'https://cms.example.com/theme/fonts/x.woff' ],
			'https://cms.example.com/theme/style.css'
		);

		expect( out ).not.toContain( '@font-face' );
	} );

	it( 'keeps a multi-encoding face when one derivative is missing', () => {
		// A face lists the SAME font in several encodings. Dropping the block
		// because the `.ttf` (or `.svg`) 404'd took the icon glyphs with it while
		// the mirrored woff was perfectly usable (virtuallyjamaica, 2026-11-15).
		const face =
			'@font-face{font-family:"et-line";src:url(fonts/et-line.eot);src:url(fonts/et-line.eot?#iefix) format("embedded-opentype"),url(fonts/et-line.woff) format("woff"),url(fonts/et-line.ttf) format("truetype"),url(fonts/et-line.svg#et-line) format("svg")}';
		const out = dropUnavailableFontFaces(
			face,
			[ 'https://cms.example.com/theme/fonts/et-line.ttf' ],
			'https://cms.example.com/theme/style.css'
		);

		expect( out ).toContain( '@font-face' );
		expect( out ).toContain( 'et-line.woff' );
		expect( out ).not.toContain( 'et-line.ttf' );
		expect( out ).not.toContain( 'truetype' );
	} );

	it( 'keeps a local() fallback when the network sources all failed', () => {
		const css = '@font-face{font-family:X;src:local("X"),url(fonts/x.woff)}';
		const out = dropUnavailableFontFaces(
			css,
			[ 'https://cms.example.com/theme/fonts/x.woff' ],
			'https://cms.example.com/theme/style.css'
		);

		expect( out ).toContain( '@font-face' );
		expect( out ).toContain( 'local("X")' );
		expect( out ).not.toContain( 'x.woff' );
	} );

	it( 'drops a multi-encoding face when every derivative failed', () => {
		const face =
			'@font-face{font-family:"et-line";src:url(fonts/et-line.woff) format("woff"),url(fonts/et-line.ttf) format("truetype")}';
		const out = dropUnavailableFontFaces(
			face,
			[
				'https://cms.example.com/theme/fonts/et-line.woff',
				'https://cms.example.com/theme/fonts/et-line.ttf',
			],
			'https://cms.example.com/theme/style.css'
		);

		expect( out ).not.toContain( '@font-face' );
	} );

	it( 'leaves unrelated faces and other rules alone', () => {
		const css = 'body{color:red}\n@font-face{font-family:X;src:url(https://example.com/x.woff2)}';
		const out = dropUnavailableFontFaces( css, [ 'https://fonts.gstatic.com/missing.woff2' ] );

		expect( out ).toContain( 'body{color:red}' );
		expect( out ).toContain( 'example.com/x.woff2' );
	} );
} );

describe( 'rewriteHtml — JSON-LD assets', () => {
	const map = new Map( [
		[ 'https://old.example.com/wp-content/uploads/logo.svg', '/media/abc-logo.svg' ],
	] );
	const doc = ( payload: unknown ) =>
		`<script type="application/ld+json">${ JSON.stringify( payload ) }</script>`;

	it( 're-points a schema image at the public origin, not the retired CMS', () => {
		// A cut-over site retires the CMS, so an absolute logo URL that is left
		// alone 404s and the structured data advertises a dead image.
		const html = doc( { '@type': 'Organization', logo: { url: 'https://old.example.com/wp-content/uploads/logo.svg' } } );
		const out = rewriteHtml( html, map, { siteOrigin: 'https://new.example.com' } );
		expect( out ).toContain( 'https://new.example.com/media/abc-logo.svg' );
		expect( out ).not.toContain( 'old.example.com' );
	} );

	it( 'moves the escaped-slash form a serializer emits', () => {
		const html = doc( { logo: 'https:\/\/old.example.com\/wp-content\/uploads\/logo.svg' } );
		const out = rewriteHtml( html, map, { siteOrigin: 'https://new.example.com' } );
		expect( out ).not.toContain( 'old.example.com' );
	} );

	it( 'leaves the payload alone without a known origin', () => {
		// Schema.org needs an ABSOLUTE url; a root-relative rewrite would make
		// the document invalid, so without an origin nothing moves.
		const html = doc( { logo: 'https://old.example.com/wp-content/uploads/logo.svg' } );
		expect( rewriteHtml( html, map ) ).toBe( html );
	} );

	it( 'does not touch a JSON-LD payload that references no localized asset', () => {
		const html = doc( { '@type': 'WebSite', name: 'Monica & Rajiv' } );
		expect( rewriteHtml( html, map, { siteOrigin: 'https://new.example.com' } ) ).toBe( html );
	} );
} );
