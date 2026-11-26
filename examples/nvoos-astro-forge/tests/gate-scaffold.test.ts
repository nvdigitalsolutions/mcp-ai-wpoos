// SPDX-License-Identifier: MIT
/**
 * @fileoverview Pins how a profile's gatePublish block reaches scaffold output.
 *
 * Fixtures are temp scaffolds asserting gate.generated.json and the env template.
 */
// ---------------------------------------------------------------------------
// gate-scaffold.test.ts — the front-controller wiring (Proposal 165 P2).
//
// A managed profile WITHOUT `gatePublish` keeps the standalone adapter and the
// one-line shim. A profile WITH it gets middleware mode, the front controller
// and the emitted gate modules — the seam the request-time gate needs.
// ---------------------------------------------------------------------------

import { existsSync } from 'node:fs';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

import { afterEach, describe, expect, it } from 'vitest';

import { offPublication } from '../lib/gate-publish.ts';
import { scaffold } from '../lib/scaffold.ts';
import type { SiteProfile } from '../lib/types.ts';

const dirs: string[] = [];

afterEach( async () => {
	await Promise.all(
		dirs.splice( 0 ).map( ( dir ) =>
			rm( dir, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 } ).catch( () => undefined )
		)
	);
} );

function profile( gatePublish?: SiteProfile[ 'gatePublish' ], manage = false ): SiteProfile {
	return {
		url: 'https://cms.example.com',
		name: 'Example',
		deploy: { runtime: 'managed', manage },
		gatePublish,
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

async function build( gatePublish?: SiteProfile[ 'gatePublish' ], manage = false ) {
	const root = await mkdtemp( path.join( tmpdir(), 'forge-gate-scaffold-' ) );
	dirs.push( root );
	const target = path.join( root, 'site' );
	await scaffold( {
		profile: profile( gatePublish, manage ),
		targetDir: target,
		template: 'blog',
		gate: offPublication(),
	} );
	return target;
}

describe( 'managed scaffold — publication gate wiring', () => {
	it( 'emits the front controller (middleware mode) when the profile opts in', async () => {
		const target = await build( { honor: 'auto', session: { bypass: true } } );

		const config = await readFile( path.join( target, 'astro.config.mjs' ), 'utf8' );
		expect( config ).toContain( "adapter: node({ mode: 'middleware' })" );

		const server = await readFile( path.join( target, 'server.js' ), 'utf8' );
		expect( server ).toContain( 'createGate' );
		expect( server ).toContain( "from './forge-gate/runtime.mjs'" );

		expect( existsSync( path.join( target, 'forge-gate', 'runtime.mjs' ) ) ).toBe( true );
		expect( existsSync( path.join( target, 'forge-gate', 'static.mjs' ) ) ).toBe( true );

		const gate = JSON.parse( await readFile( path.join( target, 'gate.generated.json' ), 'utf8' ) );
		expect( gate.mode ).toBe( 'off' );
		expect( gate.session ).toEqual( {
			bypass: true,
			path: '/wp-json/nvoos-commerce/v1/auth/me',
			cookieName: 'nvoos_forge_session',
		} );

		const drift = JSON.parse( await readFile( path.join( target, 'nvoos.generated.json' ), 'utf8' ) );
		expect( Object.keys( drift.files ) ).toContain( 'gate.generated.json' );
	} );

	it( 'keeps standalone + the shim when the profile does not opt in', async () => {
		const target = await build();

		const config = await readFile( path.join( target, 'astro.config.mjs' ), 'utf8' );
		expect( config ).not.toContain( "mode: 'middleware'" );

		const server = await readFile( path.join( target, 'server.js' ), 'utf8' );
		expect( server ).not.toContain( 'createGate' );
		expect( existsSync( path.join( target, 'forge-gate' ) ) ).toBe( false );

		// The verdict is written even with the gate off: it is the runtime's
		// boot state, so a cold start during a gate knows to gate.
		const gate = JSON.parse( await readFile( path.join( target, 'gate.generated.json' ), 'utf8' ) );
		expect( gate.mode ).toBe( 'off' );
		expect( gate.honored ).toBe( true );
	} );

	it( 'ships both prerendered gate pages the runtime serves', async () => {
		const target = await build( { honor: 'auto' } );

		for ( const page of [ 'coming-soon.astro', 'maintenance.astro' ] ) {
			const file = path.join( target, 'src', 'pages', page );
			expect( existsSync( file ) ).toBe( true );
			const source = await readFile( file, 'utf8' );
			// Prerendered: the front controller reads the built file directly.
			expect( source ).toContain( 'export const prerender = true;' );
			expect( source ).toContain( 'noindex, nofollow' );
		}
	} );

	it( 'carries the site-independent gate page copy, never customer data', async () => {
		const target = await build( { honor: 'auto' } );
		const page = await readFile( path.join( target, 'src', 'pages', 'coming-soon.astro' ), 'utf8' );
		// A holding page is public: no CMS origin, no admin links.
		expect( page ).not.toContain( 'wp-admin' );
		expect( page ).not.toContain( 'cms.example.com' );
	} );
} );
