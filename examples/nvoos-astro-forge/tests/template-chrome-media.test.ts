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

	it( 'maps uploads to /media only on the managed tier', async () => {
		const media = await readFile( path.join( TEMPLATE, 'src/lib/media.ts' ), 'utf8' );
		expect( media ).toContain( 'export function mediaUrl(' );
		expect( media ).toContain( "=== 'managed'" );
		expect( media ).toContain( "'/wp-content/uploads/'" );
		// A static artifact must never grow a /media reference nothing serves.
		expect( media ).toContain( 'if ( ! repaired || ! managedRuntime() )' );
	} );
} );
