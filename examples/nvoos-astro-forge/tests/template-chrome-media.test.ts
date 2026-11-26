// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// template-chrome-media.test.ts — the chrome serves same-origin media.
//
// A managed build serves `/media/<uploads path>` from the CMS server-to-server,
// and the post-build localizer rewrites that into the PRERENDERED html on disk.
// A page rendered per request never passes through the localizer, so the
// template itself has to ask for the proxied path (2026-11-26: eca.directory's
// 404 page hotlinked the CMS logo and its icon sheet while every prerendered
// page was correct).
//
// The template cannot be imported here — `src/lib/media.ts` reads the generated
// `nvoos.json` — so this asserts the source contract instead.
//
// A MATERIALIZED build is the mirror-image case (virtuallyjamaica, 2026-11-26):
// there is no CMS to proxy to, the uploaded files are mirrored under hashed
// names by the localizer, and the proxied `/media/<uploads path>` would 503. The
// template therefore hands the localizer the CMS URL untouched.
// ---------------------------------------------------------------------------

import { readFile } from 'node:fs/promises';
import path from 'node:path';

import { describe, expect, it } from 'vitest';

const TEMPLATE = path.join( process.cwd(), 'templates', 'blog' );

describe( 'template — chrome media', () => {
	it( 'routes the header logo and the favicon through mediaUrl', async () => {
		const base = await readFile( path.join( TEMPLATE, 'src/layouts/Base.astro' ), 'utf8' );
		expect( base ).toContain( 'src={mediaUrl(chrome.logo.src)}' );
		expect( base ).toContain( 'href={mediaUrl(chrome.favicon)}' );
	} );

	it( 'maps uploads to /media only on a managed CMS-backed build', async () => {
		const media = await readFile( path.join( TEMPLATE, 'src/lib/media.ts' ), 'utf8' );
		expect( media ).toContain( 'export function mediaUrl(' );
		expect( media ).toContain( "=== 'managed'" );
		expect( media ).toContain( "'/wp-content/uploads/'" );
		// A static artifact must never grow a /media reference nothing serves —
		// and neither must a materialized one: it has no CMS to proxy to, and the
		// proxied form would also hide the URL from the localizer's report, which
		// is the only source structured data is repaired from.
		expect( media ).toContain( 'if ( ! repaired || materializedContent() || ! managedRuntime() )' );
	} );

	it( 'stamps the proxied media path so a deploy reaches cached clients', async () => {
		const media = await readFile( path.join( TEMPLATE, 'src/lib/media.ts' ), 'utf8' );
		// The proxied path is the CMS's own, not content-hashed, so a CDN keeps a
		// broken answer for as long as the CMS says. A per-build stamp is what lets
		// a fix reach a client without a purge (the shared Cloudways zone is not
		// ours to purge).
		expect( media ).toContain( 'cacheBustToken' );
		expect( media ).toContain( "params.set( 'v', token )" );
	} );
} );
