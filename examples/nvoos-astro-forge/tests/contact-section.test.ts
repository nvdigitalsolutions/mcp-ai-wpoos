// ---------------------------------------------------------------------------
// contact-section.test.ts — the rebuilt contact band (issue #7549) + footer ink.
//
// The source's contact band is theme-injected (absent from post_content), so a
// one-pager lost both the band and its `#contact` anchor. The profile now
// declares the copy; the form posts through Proposal 138's transport.
// ---------------------------------------------------------------------------

import { mkdtemp, readFile, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

import { afterEach, describe, expect, it } from 'vitest';

import { FORGE_ROOT, scaffold } from '../lib/scaffold.ts';
import type { SiteProfile } from '../lib/types.ts';

const tempDirs: string[] = [];

async function tempDir(): Promise<string> {
	const dir = await mkdtemp(path.join(os.tmpdir(), 'forge-contact-'));
	tempDirs.push(dir);
	return dir;
}

afterEach(async () => {
	await Promise.all(tempDirs.splice(0).map((dir) => rm(dir, { recursive: true, force: true })));
});

const CONTACT = {
	heading: 'Contact Us',
	blurb: 'Choose the access point that works best for you.',
	email: 'hello@example.test',
};

const PROFILE: SiteProfile = {
	url: 'https://virtuallyjamaica.com',
	name: 'Virtually Jamaica',
	shape: 'one-pager',
	collections: [
		{ slug: 'pages', restBase: 'wp/v2/pages', fields: [ 'id', 'slug', 'content' ], embed: true },
	],
	contact: CONTACT,
};

describe( 'contact band (issue #7549)', () => {
	it( 'carries the contact block into the manifest', async () => {
		const target = await tempDir();
		await scaffold( { profile: PROFILE, targetDir: target, template: 'blog' } );

		const manifest = JSON.parse( await readFile( path.join( target, 'nvoos.json' ), 'utf8' ) ) as {
			contact?: unknown;
		};
		expect( manifest.contact ).toEqual( CONTACT );
	} );

	it( 'renders the band from the front page with the #contact anchor', async () => {
		const target = await tempDir();
		await scaffold( { profile: PROFILE, targetDir: target, template: 'blog' } );

		const index = await readFile( path.join( target, 'src', 'pages', 'index.astro' ), 'utf8' );
		expect( index ).toContain( "import Contact from '../components/design/Contact.astro'" );
		expect( index ).toContain( '{contactBlock && <Contact />}' );

		const component = await readFile(
			path.join( target, 'src', 'components', 'design', 'Contact.astro' ),
			'utf8'
		);
		expect( component ).toContain( 'id="contact"' );
		// Proposal 138 transport ladder: direct route, then the bridge.
		expect( component ).toContain( "'/api/forms/submit'" );
		expect( component ).toContain( 'forms/submit' );
		expect( component ).toContain( 'data-ds-contact' );
	} );

	it( 'omits the block when the profile declares no contact', async () => {
		const target = await tempDir();
		const profile: SiteProfile = { ...PROFILE, contact: undefined };
		await scaffold( { profile, targetDir: target, template: 'blog' } );

		const manifest = JSON.parse( await readFile( path.join( target, 'nvoos.json' ), 'utf8' ) ) as Record<
			string,
			unknown
		>;
		expect( manifest ).not.toHaveProperty( 'contact' );
	} );
} );

describe( 'virtuallyjamaica contact transport', () => {
	it( 'uses direct Brevo delivery to the client inbox', async () => {
		const profile = JSON.parse(
			await readFile( path.join( FORGE_ROOT, 'sites', 'virtuallyjamaica.json' ), 'utf8' )
		) as {
			deploy?: { runtime?: string };
			forms?: { delivery?: string; mail?: { provider?: string; from?: string; to?: string } };
		};

		// Proposal 138: `direct` requires the managed runtime plus a mail block —
		// a static build has nowhere to keep the provider key.
		expect( profile.deploy?.runtime ).toBe( 'managed' );
		expect( profile.forms?.delivery ).toBe( 'direct' );
		expect( profile.forms?.mail ).toMatchObject( {
			provider: 'brevo',
			to: 'info@virtuallyjamaica.com',
		} );
		// The sender must be on a domain authenticated at the provider.
		expect( profile.forms?.mail?.from ).toMatch( /@virtuallyjamaica\.com$/ );
		// Secrets are env-only — never in the profile.
		expect( JSON.stringify( profile ) ).not.toContain( 'API_KEY' );
	} );
} );

describe( 'footer ink', () => {
	it( 'renders footer copy in body ink, not the muted token', async () => {
		const css = await readFile(
			path.join( FORGE_ROOT, 'templates', 'blog', 'src', 'styles', 'global.css' ),
			'utf8'
		);
		expect( css ).toMatch( /\.site-footer\s*\{[^}]*color:\s*var\(--forge-ink\)/s );
		expect( css ).toMatch( /\.site-footer-sub\s*\{[^}]*var\(--forge-footer-ink,\s*var\(--forge-ink\)\)/s );
	} );
} );
