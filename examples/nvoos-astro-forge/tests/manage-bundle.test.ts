// SPDX-License-Identifier: MIT
/**
 * @fileoverview Pins bundle discovery, fingerprinting and the emitted SRI token.
 *
 * Fixtures are synthetic bundle files; the real bridge build is not required.
 */
// ---------------------------------------------------------------------------
// manage-bundle.test.ts — the §7b editor: the vendored Astro CMS bundle, the
// sealed credential the BFF holds, and the scaffold wiring.
//
// The generated credential module is imported and exercised for real: it is
// the only place an Application Password exists outside WordPress, so its
// seal/open contract is pinned rather than assumed.
// ---------------------------------------------------------------------------

import { createHash } from 'node:crypto';
import { existsSync } from 'node:fs';
import { mkdtemp, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { afterEach, describe, expect, it } from 'vitest';

import { parse } from '@astrojs/compiler-rs';

import { offPublication } from '../lib/gate-publish.ts';
import { ManageBundleError, bridgeBundleDir, resolveManageBundle, sriToken } from '../lib/manage-bundle.ts';
import { renderApiProxyTs, renderProxyHelperTs } from '../lib/managed-runtime.ts';
import {
	renderManageBootJs,
	renderManageCredentialTs,
	renderManageGeneratedTs,
	renderManageLoginTs,
	renderManageLogoutTs,
	renderManagePageTs,
	renderManageSessionTs,
} from '../lib/manage-runtime.ts';
import { scaffold } from '../lib/scaffold.ts';
import type { SiteProfile } from '../lib/types.ts';

const dirs: string[] = [];

const OPTIONS = {
	cookieName: 'nvoos_forge_session',
	ttlSeconds: 3600,
	targetSite: 'https://cms.example.com',
};

const BUNDLE = { integrity: 'sha384-JS_TOKEN', cssIntegrity: 'sha384-CSS_TOKEN' };

afterEach( async () => {
	delete process.env.NVOOS_MANAGE_SECRET;
	delete process.env.NVOOS_FORGE_GATE_SECRET;
	delete process.env.NVOOS_FORGE_BRIDGE_DIR;
	await Promise.all( dirs.splice( 0 ).map( ( dir ) => rm( dir, { recursive: true, force: true } ) ) );
} );

async function tempDir( prefix: string ): Promise< string > {
	const dir = await mkdtemp( path.join( tmpdir(), prefix ) );
	dirs.push( dir );
	return dir;
}

async function loadCredential() {
	const dir = await tempDir( 'forge-manage-cred-' );
	await writeFile( path.join( dir, 'manage-session.ts' ), renderManageSessionTs( OPTIONS ), 'utf8' );
	await writeFile( path.join( dir, 'manage-credential.ts' ), renderManageCredentialTs(), 'utf8' );
	const cred = ( await import(
		pathToFileURL( path.join( dir, 'manage-credential.ts' ) ).href
	) ) as {
		MANAGED_CRED_COOKIE: string;
		sealCredential( user: string, pass: string, uid: string ): string | null;
		openCredential( value: string | null | undefined, uid: string ): { u: string; p: string } | null;
		manageAuthorization( cookieHeader: string | null | undefined ): string;
	};
	const session = ( await import(
		pathToFileURL( path.join( dir, 'manage-session.ts' ) ).href
	) ) as {
		signManageSession( uid: string, name: string, now?: number ): string | null;
	};
	return { cred, session };
}

describe( 'the vendored bundle resolver', () => {
	it( 'finds the committed standalone build and fingerprints it', async () => {
		const bundle = await resolveManageBundle();

		expect( bundle.integrity ).toMatch( /^sha384-[A-Za-z0-9+/]+=*$/ );
		expect( bundle.cssIntegrity ).toMatch( /^sha384-/ );
		expect( bundle.jsSha256 ).toMatch( /^[0-9a-f]{64}$/ );
		expect( bundle.js.length ).toBeGreaterThan( 50_000 );
		expect( bundle.css.length ).toBeGreaterThan( 1_000 );
	} );

	it( 'sriToken emits base64, not hex — the browser base64-decodes the digest', () => {
		const token = sriToken( Buffer.from( 'hello' ) );
		// sha384("hello") base64: the hex form is rejected by every browser.
		expect( token ).toBe( 'sha384-' + createHash( 'sha384' ).update( 'hello' ).digest( 'base64' ) );
		expect( token ).not.toMatch( /^sha384-[0-9a-f]{96}$/ );
	} );

	it( 'fails closed with an actionable error when the checkout is absent', async () => {
		const empty = await tempDir( 'forge-manage-empty-' );
		await expect( resolveManageBundle( empty ) ).rejects.toBeInstanceOf( ManageBundleError );

		await expect( resolveManageBundle( empty ) ).rejects.toThrow( /build:standalone/ );
		await expect( resolveManageBundle( empty ) ).rejects.toThrow( /deploy\.manageBundle/ );
	} );

	it( 'honours NVOOS_FORGE_BRIDGE_DIR as the out-of-tree seam', () => {
		process.env.NVOOS_FORGE_BRIDGE_DIR = path.join( tmpdir(), 'addon-root' );
		expect( bridgeBundleDir() ).toBe(
			path.join( tmpdir(), 'addon-root', 'build', 'standalone' )
		);
	} );
} );

describe( 'the sealed credential', () => {
	it( 'seals and opens a credential for the session that minted it', async () => {
		process.env.NVOOS_MANAGE_SECRET = 'test-secret';
		const { cred: module } = await loadCredential();

		expect( module.MANAGED_CRED_COOKIE ).toBe( 'nvoos_forge_cred' );
		const secretValue = 'APP-PASSWORD-VALUE';
		const sealed = module.sealCredential( 'editor', secretValue, '7' );
		expect( sealed ).toBeTruthy();

		// The password is never in the cookie in the clear.
		expect( sealed ).not.toContain( secretValue );

		expect( module.openCredential( sealed, '7' ) ).toEqual( {
			u: 'editor',
			p: secretValue,
		} );
	} );

	it( 'refuses a foreign uid, a tampered body and a wrong secret', async () => {
		process.env.NVOOS_MANAGE_SECRET = 'test-secret';
		const { cred: module } = await loadCredential();
		const sealed = module.sealCredential( 'editor', 'pass', '7' ) as string;

		// The uid rides the GCM associated data: another session cannot open it.
		expect( module.openCredential( sealed, '8' ) ).toBeNull();

		const [ iv, body ] = sealed.split( '.' );
		const flipped = body.slice( 0, -2 ) + ( body.endsWith( 'A' ) ? 'BB' : 'AA' );
		expect( module.openCredential( `${ iv }.${ flipped }`, '7' ) ).toBeNull();

		delete process.env.NVOOS_MANAGE_SECRET;
		process.env.NVOOS_MANAGE_SECRET = 'another-secret';
		expect( module.openCredential( sealed, '7' ) ).toBeNull();
	} );

	it( 'fails closed without a secret and builds Basic auth only for a valid pair', async () => {
		const { cred: module, session } = await loadCredential();
		expect( module.sealCredential( 'editor', 'pass', '7' ) ).toBeNull();
		expect( module.openCredential( 'a.b', '7' ) ).toBeNull();
		expect( module.manageAuthorization( 'nvoos_forge_session=x' ) ).toBe( '' );

		process.env.NVOOS_MANAGE_SECRET = 'test-secret';
		const sealed = module.sealCredential( 'editor', 'pass', '7' ) as string;
		const token = session.signManageSession( '7', 'Editor' ) as string;
		const expected = 'Basic ' + Buffer.from( 'editor:pass', 'utf8' ).toString( 'base64' );

		// A credential alone is not enough — the session must match its uid.
		expect( module.manageAuthorization( `nvoos_forge_cred=${ sealed }` ) ).toBe( '' );
		expect(
			module.manageAuthorization(
				`nvoos_forge_session=${ session.signManageSession( '8', 'Other' ) as string }; nvoos_forge_cred=${ sealed }`
			)
		).toBe( '' );

		// Both together produce the header the proxy forwards.
		expect(
			module.manageAuthorization( `nvoos_forge_session=${ token }; nvoos_forge_cred=${ sealed }` )
		).toBe( expected );
	} );
} );

describe( 'the /manage page — editor mode', () => {
	it( 'parses as Astro and mounts the bundle behind an SRI-checked script', async () => {
		const page = renderManagePageTs( { ...OPTIONS, bundle: BUNDLE } );
		const compiled = await parse( page );
		expect( compiled.diagnostics ?? [] ).toEqual( [] );

		expect( page ).toContain( 'id="astro-admin-root"' );
		expect( page ).toContain( 'href={BUNDLE_CSS}' );
		expect( page ).toContain( 'integrity={BUNDLE_CSS_INTEGRITY}' );
		expect( page ).toContain( "const BUNDLE_JS_INTEGRITY = 'sha384-JS_TOKEN';" );
		expect( page ).toContain( "const BUNDLE_CSS_INTEGRITY = 'sha384-CSS_TOKEN';" );
		expect( page ).toContain( "root: '/api'" );
		expect( page ).toContain( "mediaBase: '/media/'" );
		expect( page ).toContain( 'manage-bundle-error' );
		// The config is a JSON DATA block and the boot is EXTERNAL: no inline
		// script, so /manage works under `script-src 'self'`.
		expect( page ).toContain( 'id="manage-bootstrap"' );
		expect( page ).toContain( 'set:html={configJson}' );
		expect( page ).toContain( 'src="/manage-assets/manage-boot.js"' );
		expect( page ).toContain( 'rel="preload"' );
		expect( page ).toContain( 'fetchpriority="high"' );
		expect( page ).not.toContain( 'is:inline' );
		expect( page ).not.toContain( 'createElement("script")' );
		// The bridge probe is server-side; the credential import is emitted.
		expect( page ).toContain( 'openCredential' );
		expect( page ).toContain( 'nvoos-commerce/v1/auth/me' );
		expect( page ).not.toContain( '__BUNDLE' );
		expect( page ).not.toContain( '__TARGET_SITE__' );
		// The fallback panel is still there for a non-manager / absent bridge.
		expect( page ).toContain( 'post_type=mcp_ai_eca' );
		expect( page ).toContain( '/wp-admin/' );
	} );

	it( 'keeps the standalone page when no bundle is configured', async () => {
		const page = renderManagePageTs( OPTIONS );
		const compiled = await parse( page );
		expect( compiled.diagnostics ?? [] ).toEqual( [] );

		expect( page ).not.toContain( 'manage-assets' );
		expect( page ).not.toContain( '__BUNDLE' );
		expect( page ).not.toContain( 'openCredential' );
		expect( page ).toContain( 'const showBundle = false;' );
	} );

	it( 'seals the credential at sign-in and clears it at sign-out', () => {
		const login = renderManageLoginTs( { bundle: true } );
		expect( login ).toContain( 'sealCredential(username, password, uid)' );
		expect( login ).toContain( 'MANAGED_CRED_COOKIE' );
		expect( login ).toContain( 'httpOnly: true' );
		// The credential cookie must never be script-readable.
		expect( login ).not.toContain( 'httpOnly: false' );

		const logout = renderManageLogoutTs( { bundle: true } );
		expect( logout ).toContain( "cookies.delete(MANAGED_CRED_COOKIE, { path: '/' });" );

		// Without the bundle, neither route knows the credential exists.
		expect( renderManageLoginTs() ).not.toContain( 'MANAGED_CRED_COOKIE' );
		expect( renderManageLogoutTs() ).not.toContain( 'MANAGED_CRED_COOKIE' );
	} );

	it( 'records the provenance module', () => {
		const generated = renderManageGeneratedTs( {
			integrity: 'sha384-JS',
			cssIntegrity: 'sha384-CSS',
			jsSha256: 'a'.repeat( 64 ),
			cssSha256: 'b'.repeat( 64 ),
			jsBytes: 1234,
			cssBytes: 56,
		} );
		expect( generated ).toContain( "integrity: 'sha384-JS'" );
		expect( generated ).toContain( 'bytes: { js: 1234, css: 56 }' );
		expect( generated ).not.toContain( '__' );
	} );

	it( 'ships the boot as an external module that fails closed', () => {
		const boot = renderManageBootJs( BUNDLE );

		expect( boot ).toContain( "getElementById( 'manage-bootstrap' )" );
		expect( boot ).toContain( "getElementById( 'manage-bundle-error' )" );
		expect( boot ).toContain( "css.integrity = 'sha384-CSS_TOKEN';" );
		expect( boot ).toContain( "script.integrity = 'sha384-JS_TOKEN';" );
		expect( boot ).toContain( "script.src = '/manage-assets/astro-admin.js';" );
		expect( boot ).toContain( "css.href = '/manage-assets/astro-admin.css';" );
		// A missing / user-less config must NOT inject the bundle.
		expect( boot ).toContain( '! config.user' );
		// Every placeholder is resolved.
		expect( boot ).not.toContain( '__' );
	} );
} );

describe( 'the API proxy — manage credentials', () => {
	it( 'attaches the sealed credential only on the opt-in variant', () => {
		const off = renderApiProxyTs();
		expect( off ).not.toContain( 'manageAuthorization' );
		expect( off ).toContain(
			'return proxyToWordPress(request, `/wp-json/nvoos-commerce/v1/${rest}`);'
		);

		const on = renderApiProxyTs( { manageCredentials: true } );
		expect( on ).toContain(
			"import { manageAuthorization } from '../../lib/manage-credential';"
		);
		expect( on ).toContain( "manageAuthorization(request.headers.get('cookie'))" );
		expect( on ).toContain( 'authorization ? { authorization } : undefined' );

		// The helper accepts the override and it REPLACES an inbound header.
		const helper = renderProxyHelperTs();
		expect( helper ).toContain( 'options: { authorization?: string } = {}' );
		expect( helper ).toContain( "headers.set( 'authorization', options.authorization );" );
		// A manager-scoped answer must never be shared by a cache.
		expect( helper ).toContain( 'private, no-store, max-age=0' );
	} );
} );

describe( 'scaffold wiring', () => {
	function profile( deploy: SiteProfile[ 'deploy' ] ): SiteProfile {
		return {
			url: 'https://cms.example.com',
			name: 'Example',
			deploy,
			collections: [
				{
					slug: 'posts',
					restBase: 'wp/v2/posts',
					fields: [ 'title', 'slug' ],
					perPage: 10,
					maxPages: 1,
				},
			],
		} as SiteProfile;
	}

	async function build( deploy: SiteProfile[ 'deploy' ] ) {
		const root = await tempDir( 'forge-manage-scaffold-' );
		const target = path.join( root, 'site' );
		await scaffold( {
			profile: profile( deploy ),
			targetDir: target,
			template: 'blog',
			gate: offPublication(),
		} );
		return target;
	}

	it( 'vendors the bundle and records it in both manifests', async () => {
		const target = await build( {
			runtime: 'managed',
			manage: true,
			manageBundle: true,
		} );

		const js = await stat( path.join( target, 'public', 'manage-assets', 'astro-admin.js' ) );
		const css = await stat( path.join( target, 'public', 'manage-assets', 'astro-admin.css' ) );
		expect( js.size ).toBeGreaterThan( 50_000 );
		expect( css.size ).toBeGreaterThan( 1_000 );

		const page = await readFile( path.join( target, 'src', 'pages', 'manage', 'index.astro' ), 'utf8' );
		expect( page ).toContain( 'id="astro-admin-root"' );
		expect( page ).toMatch( /const BUNDLE_JS_INTEGRITY = 'sha384-/ );

		const proxy = await readFile( path.join( target, 'src', 'pages', 'api', '[...path].ts' ), 'utf8' );
		expect( proxy ).toContain( 'manageAuthorization' );

		expect( existsSync( path.join( target, 'src', 'lib', 'manage-credential.ts' ) ) ).toBe( true );

		// The boot is an EXTERNAL module (the page carries no inline script).
		const boot = await readFile( path.join( target, 'public', 'manage-assets', 'manage-boot.js' ), 'utf8' );
		expect( boot ).toContain( 'manage-bootstrap' );
		expect( boot ).toMatch( /script\.integrity = 'sha384-/ );

		const generated = await readFile( path.join( target, 'src', 'lib', 'manage.generated.ts' ), 'utf8' );
		expect( generated ).toMatch( /integrity: 'sha384-/ );

		const manifest = JSON.parse( await readFile( path.join( target, 'nvoos.json' ), 'utf8' ) );
		expect( manifest.admin.enabled ).toBe( true );
		expect( manifest.admin.sessionCookie ).toBe( 'nvoos_forge_session' );
		expect( manifest.admin.bundle.integrity ).toMatch( /^sha384-/ );
		expect( manifest.admin.bundle.src ).toBe( '/manage-assets/astro-admin.js' );

		const drift = JSON.parse( await readFile( path.join( target, 'nvoos.generated.json' ), 'utf8' ) );
		expect( Object.keys( drift.files ) ).toContain( 'src/lib/manage.generated.ts' );
		expect( Object.keys( drift.files ) ).toContain( 'src/lib/manage-credential.ts' );
		expect( Object.keys( drift.files ) ).toContain( 'public/manage-assets/manage-boot.js' );
	} );

	it( 'emits the standalone staff panel when the bundle is off', async () => {
		const target = await build( { runtime: 'managed', manage: true } );

		expect( existsSync( path.join( target, 'public', 'manage-assets' ) ) ).toBe( false );
		expect( existsSync( path.join( target, 'src', 'lib', 'manage-credential.ts' ) ) ).toBe( false );

		const page = await readFile( path.join( target, 'src', 'pages', 'manage', 'index.astro' ), 'utf8' );
		expect( page ).toContain( 'Application Password' );
		expect( page ).not.toContain( 'manage-assets' );

		const manifest = JSON.parse( await readFile( path.join( target, 'nvoos.json' ), 'utf8' ) );
		expect( manifest.admin.enabled ).toBe( true );
		expect( manifest.admin.bundle ).toBeUndefined();
	} );

	it( 'refuses a bundle without the manage page or the managed tier', async () => {
		await expect(
			build( { runtime: 'static', manageBundle: true } )
		).rejects.toThrow( /deploy\.manageBundle requires BOTH/ );
	} );
} );
