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
	collectAssetUrls,
	collectCssAssetUrls,
	collectStylesheetUrls,
	extensionForContentType,
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
	stylesheetAssetUrls,
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

	it('never collects anchors, meta tags or JSON-LD images', () => {
		const html = [
			'<a href="https://cms.test/wp-content/uploads/catalog.pdf">Catalogue</a>',
			'<meta property="og:image" content="https://cms.test/og.jpg">',
			'<script type="application/ld+json">{"image":"https://cms.test/ld.jpg"}</script>',
		].join('\n');
		expect([...collectAssetUrls(html)]).toEqual([]);
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

	it('leaves anchors, og:image and JSON-LD absolute', () => {
		const html = [
			'<a href="https://cms.test/wp-content/uploads/a.jpg">Download</a>',
			'<meta property="og:image" content="https://cms.test/wp-content/uploads/a.jpg">',
			'<script type="application/ld+json">{"image":"https://cms.test/wp-content/uploads/a.jpg"}</script>',
		].join('\n');
		const out = rewriteHtml(html, map);
		expect(out.match(/https:\/\/cms\.test\/wp-content\/uploads\/a\.jpg/g)).toHaveLength(3);
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

describe('rewriteCss', () => {
	it('rewrites mapped url() references only', () => {
		const map = new Map([['https://cms.test/bg.jpg', '/media/x-bg.jpg']]);
		expect(rewriteCss('a{background:url("https://cms.test/bg.jpg")}b{background:url(/local.png)}', map)).toBe(
			'a{background:url(/media/x-bg.jpg)}b{background:url(/local.png)}'
		);
	});
});
