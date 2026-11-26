// SPDX-License-Identifier: MIT
/**
 * @fileoverview Pins the /manage routes, the sealed credential and the session HMAC.
 *
 * Fixtures are temp projects and stub upstreams; no WordPress site is contacted.
 */
// ---------------------------------------------------------------------------
// manage-runtime.test.ts — the /manage surface and the session the gate trusts
// (Proposal 165 §7, standalone path).
//
// The generated signer/verifier is imported and exercised for real: the gate
// duplicates its format in forge-gate/runtime.mjs (pinned by gate-runtime.test)
// and the two must agree byte-for-byte on the HMAC recipe.
// ---------------------------------------------------------------------------

import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { afterEach, describe, expect, it } from 'vitest';

import { parse } from '@astrojs/compiler-rs';

import {
	renderManageLoginTs,
	renderManageLogoutTs,
	renderManageMeTs,
	renderManagePageTs,
	renderManageSessionTs,
} from '../lib/manage-runtime.ts';

const dirs: string[] = [];
const OPTIONS = {
	cookieName: 'nvoos_forge_session',
	ttlSeconds: 3600,
	targetSite: 'https://cms.example.com',
};

afterEach( async () => {
	delete process.env.NVOOS_MANAGE_SECRET;
	delete process.env.NVOOS_FORGE_GATE_SECRET;
	await Promise.all( dirs.splice( 0 ).map( ( dir ) => rm( dir, { recursive: true, force: true } ) ) );
} );

async function loadSession() {
	const dir = await mkdtemp( path.join( tmpdir(), 'forge-manage-session-' ) );
	dirs.push( dir );
	const file = path.join( dir, 'manage-session.ts' );
	await mkdir( path.dirname( file ), { recursive: true } );
	await writeFile( file, renderManageSessionTs( OPTIONS ), 'utf8' );
	return import( pathToFileURL( file ).href ) as Promise< {
		MANAGED_COOKIE: string;
		MANAGED_TTL_SECONDS: number;
		signManageSession( uid: string, name: string, now?: number ): string | null;
		verifyManageSession(
			value: string | null | undefined,
			now?: number
		): { uid: string; name: string; expires: number } | null;
		readCookie( header: string | null | undefined, name: string ): string;
	} >;
}

describe( 'generated manage session', () => {
	it( 'signs and verifies its own token', async () => {
		process.env.NVOOS_MANAGE_SECRET = 'test-secret';
		const session = await loadSession();

		expect( session.MANAGED_COOKIE ).toBe( 'nvoos_forge_session' );
		expect( session.MANAGED_TTL_SECONDS ).toBe( 3600 );

		const token = session.signManageSession( '7', 'Manager' );
		expect( token ).toBeTruthy();
		const verified = session.verifyManageSession( token );
		expect( verified?.uid ).toBe( '7' );
		expect( verified?.name ).toBe( 'Manager' );
		expect( verified?.expires ).toBeGreaterThan( Math.floor( Date.now() / 1000 ) );
	} );

	it( 'rejects an expired, tampered or foreign-secret token', async () => {
		process.env.NVOOS_MANAGE_SECRET = 'test-secret';
		const session = await loadSession();
		const now = Date.now();

		const expired = session.signManageSession( '7', 'Manager', now - 3_700_000 );
		expect( session.verifyManageSession( expired ) ).toBeNull();

		const token = session.signManageSession( '7', 'Manager', now ) as string;
		expect( session.verifyManageSession( token.slice( 0, -1 ) + ( token.endsWith( 'a' ) ? 'b' : 'a' ) ) ).toBeNull();

		process.env.NVOOS_MANAGE_SECRET = 'another-secret';
		expect( session.verifyManageSession( token ) ).toBeNull();
	} );

	it( 'fails closed without a secret, and falls back to the gate secret', async () => {
		const session = await loadSession();
		expect( session.signManageSession( '7', 'Manager' ) ).toBeNull();
		expect( session.verifyManageSession( '1.7.bnVsbA.deadbeef' ) ).toBeNull();

		// A blank value is how the env template ships the key: it must not
		// shadow a configured gate secret.
		process.env.NVOOS_MANAGE_SECRET = '   ';
		process.env.NVOOS_FORGE_GATE_SECRET = 'gate-secret';
		const token = session.signManageSession( '7', 'Manager' );
		expect( token ).toBeTruthy();
		expect( session.verifyManageSession( token )?.name ).toBe( 'Manager' );
	} );

	it( 'reads one cookie out of a raw header', async () => {
		const session = await loadSession();
		expect( session.readCookie( 'a=1; nvoos_forge_session=abc; b=2', 'nvoos_forge_session' ) ).toBe( 'abc' );
		expect( session.readCookie( 'a=1', 'nvoos_forge_session' ) ).toBe( '' );
		expect( session.readCookie( null, 'nvoos_forge_session' ) ).toBe( '' );
	} );
} );

describe( 'generated manage routes', () => {
	it( 'emits a noindex sign-in page with the ECA destination and no site chrome', async () => {
		const page = renderManagePageTs( OPTIONS );

		// The emitted page must PARSE as Astro — a scaffold that ships a syntax
		// error would only fail after `npm install` in the customer project.
		const compiled = await parse( page );
		expect( compiled.diagnostics ?? [] ).toEqual( [] );

		expect( page ).toContain( 'export const prerender = false;' );
		expect( page ).toContain( 'noindex, nofollow' );
		expect( page ).toContain( 'action="/api/manage/login"' );
		expect( page ).toContain( 'action="/api/manage/logout"' );
		expect( page ).toContain( 'Application Password' );
		expect( page ).toContain( 'post_type=mcp_ai_eca' );
		// The CMS origin is baked in as the runtime fallback; the admin link is
		// derived from it server-side.
		expect( page ).toContain( "'https://cms.example.com'" );
		expect( page ).toContain( "/wp-admin/" );
		// The public site behind the gate — the Elementor behaviour.
		expect( page ).toContain( 'href="/"' );
	} );

	it( 'verifies credentials server-to-server and mints an HttpOnly cookie', () => {
		const login = renderManageLoginTs();

		expect( login ).toContain( 'export const prerender = false;' );
		expect( login ).toContain( 'NVOOS_TARGET_SITE' );
		expect( login ).toContain( '/wp-json/wp/v2/users/me?context=edit' );
		expect( login ).toContain( 'capabilities.edit_posts' );
		expect( login ).toContain( 'capabilities.manage_options' );
		expect( login ).toContain( 'httpOnly: true' );
		expect( login ).toContain( "sameSite: 'lax'" );
		expect( login ).toContain( 'secure: url.protocol' );
		expect( login ).toContain( 'ASTRO_SITE' );
		expect( login ).toContain( 'sameOrigin' );
		// The password is normalised (application passwords display with spaces)
		// and never logged.
		expect( login ).toContain( "replace(/\\s+/g, '')" );
		expect( login ).not.toContain( 'console.log' );
	} );

	it( 'emits a logout that deletes the host-only cookie and a local me probe', () => {
		expect( renderManageLogoutTs() ).toContain( 'cookies.delete(MANAGED_COOKIE' );
		const me = renderManageMeTs();
		expect( me ).toContain( 'verifyManageSession' );
		expect( me ).toContain( 'authenticated: false' );
	} );
} );
