// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// materialize-from-wp.test.ts — freeze a LIVE CMS into the snapshot shape.
//
// `materialize --from <build>` needs `dist/server`, which a `static` runtime
// never produces, so a site cutting over while its CMS is still up had no way
// to snapshot anything. `materializeFromWordPress` drives the PRODUCTION loader
// against a capture-only context, so this test asserts the snapshot it writes is
// the one a build would have baked — including the sanitizer and the exclusion
// list — without touching the network.
// ---------------------------------------------------------------------------

import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { createServer } from 'node:http';
import type { Server } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { materializeFromWordPress } from '../lib/materialize.ts';
import type { SiteProfile } from '../lib/types.ts';

/** One `wp/v2/pages` entry, pruned the way the profile's `_fields` asks. */
const page = ( id: number, slug: string, title: string, content: string ) => ( {
	id,
	slug,
	title: { rendered: title },
	content: { rendered: content },
	link: `https://cms.example.com/${ slug === 'home' ? '' : `${ slug }/` }`,
	date_gmt: '2026-01-02T03:04:05',
	modified_gmt: '2026-02-03T04:05:06',
	excerpt: { rendered: '' },
} );

let server: Server | undefined;
let origin = '';
let outDir = '';
/** Reset per test; a test may append duplicate-slug pages before capturing. */
let entries: ReturnType< typeof page >[] = [];

beforeEach( async () => {
	outDir = await mkdtemp( join( tmpdir(), 'forge-materialize-' ) );
	entries = [
		page( 11, 'home', 'Home', '<div class="row"><p>Sri Lanka, with love</p></div>' ),
		page( 12, 'styled', 'Styled', '<p style="color:red" onclick="steal()">Ink</p>' ),
		page( 99, 'skip-me', 'Skip me', '<p>never shipped</p>' ),
	];
	server = createServer( ( request, response ) => {
		const url = new URL( request.url ?? '/', 'http://localhost' );
		if ( url.pathname.endsWith( '/wp/v2/pages' ) ) {
			response.writeHead( 200, {
				'content-type': 'application/json',
				'x-wp-totalpages': '1',
				'x-wp-total': String( entries.length ),
			} );
			response.end( JSON.stringify( entries ) );
			return;
		}
		// Anything else (the SEO sidecar, media lookups) is absent, not fatal.
		response.writeHead( 404, { 'content-type': 'application/json' } );
		response.end( JSON.stringify( { code: 'rest_no_route' } ) );
	} );
	await new Promise< void >( ( resolve ) => server!.listen( 0, '127.0.0.1', resolve ) );
	const address = server.address();
	const port = typeof address === 'object' && address ? address.port : 0;
	origin = `http://127.0.0.1:${ port }`;
} );

afterEach( async () => {
	await new Promise< void >( ( resolve ) => server?.close( () => resolve() ) );
	server = undefined;
	await rm( outDir, { recursive: true, force: true } );
} );

const profile = (): SiteProfile =>
	( {
		url: origin,
		collections: [
			{
				slug: 'pages',
				restBase: 'wp/v2/pages',
				fields: [ 'id', 'slug', 'title', 'content', 'link', 'date_gmt', 'modified_gmt', 'excerpt' ],
				embed: true,
				perPage: 100,
				maxPages: 5,
				exclude: [ 'skip-me' ],
			},
		],
	} ) as SiteProfile;

describe( 'materializeFromWordPress', () => {
	it( 'writes frontmatter + the SANITIZED body for every captured page', async () => {
		const documents = await materializeFromWordPress( { profile: profile(), outDir } );

		expect( documents.map( ( doc ) => `${ doc.collection }/${ doc.slug }` ).sort() ).toEqual( [
			'pages/home',
			'pages/styled',
		] );

		const mdx = await readFile( join( outDir, 'pages', 'home.mdx' ), 'utf8' );
		expect( mdx.startsWith( '---\n' ) ).toBe( true );
		expect( mdx ).toContain( 'id: 11' );
		expect( mdx ).toContain( 'title: "Home"' );
		expect( mdx ).toContain( 'slug: "home"' );
		expect( mdx ).toContain( 'link: "https://cms.example.com/"' );
		// The body never goes in the MDX — MDX would parse it as JSX.
		expect( mdx ).not.toContain( 'Sri Lanka' );

		const html = await readFile( join( outDir, 'pages', 'home.html' ), 'utf8' );
		expect( html ).toContain( 'Sri Lanka, with love' );
	} );

	it( 'runs the body through the versioned sanitizer, not the raw CMS markup', async () => {
		await materializeFromWordPress( { profile: profile(), outDir } );
		const html = await readFile( join( outDir, 'pages', 'styled.html' ), 'utf8' );
		expect( html ).toContain( 'Ink' );
		// `onclick` is an event handler and `style` is dropped by the allowlist —
		// a snapshot that kept them would ship the vulnerability permanently.
		expect( html ).not.toContain( 'onclick' );
		expect( html ).not.toMatch( /style="color:red"/ );
	} );

	it( 'honours the collection exclusion list', async () => {
		await materializeFromWordPress( { profile: profile(), outDir } );
		await expect( readFile( join( outDir, 'pages', 'skip-me.html' ), 'utf8' ) ).rejects.toThrow();
	} );

	it( 'carries only the frontmatter the loader schema understands', async () => {
		await materializeFromWordPress( { profile: profile(), outDir } );
		const mdx = await readFile( join( outDir, 'pages', 'home.mdx' ), 'utf8' );
		const fields = mdx
			.split( '\n' )
			.filter( ( line ) => /^[a-zA-Z]+: /.test( line ) )
			.map( ( line ) => line.slice( 0, line.indexOf( ':' ) ) );
		// A field the schema does not know is an Astro content error, so the
		// snapshot never writes one — `content` (the raw body) especially.
		expect( fields ).not.toContain( 'content' );
		expect( fields ).toEqual( [ 'id', 'title', 'slug', 'date', 'modified', 'link' ] );
	} );

	it( 'publishes unique slugs when two pages share one', async () => {
		// WordPress page slugs are unique among siblings only. The capture must
		// leave both pages renderable: one keeps `team`, the other becomes
		// `team-2` in BOTH the filename and the frontmatter (the route reads the
		// latter), with the source slug preserved for redirects and hybrid merges.
		entries.push(
			page( 13, 'team', 'About team', '<p>about</p>' ),
			page( 14, 'team', 'Services team', '<p>services</p>' )
		);

		const documents = await materializeFromWordPress( { profile: profile(), outDir } );
		const team = documents.filter( ( doc ) => doc.slug.startsWith( 'team' ) );

		expect( team.map( ( doc ) => doc.slug ).sort() ).toEqual( [ 'team', 'team-2' ] );
		expect( team.find( ( doc ) => doc.slug === 'team-2' )?.slugRenamedFrom ).toBe( 'team' );

		const mdx = await readFile( join( outDir, 'pages', 'team-2.mdx' ), 'utf8' );
		expect( mdx ).toContain( 'slug: "team-2"' );
		expect( mdx ).toContain( 'slugRenamedFrom: "team"' );
		expect( mdx ).toContain( 'link: "https://cms.example.com/team/"' );
	} );
} );
