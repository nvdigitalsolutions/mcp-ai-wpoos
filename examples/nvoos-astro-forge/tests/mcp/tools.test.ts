// ---------------------------------------------------------------------------
// tools.test.ts — MCP tool handler units (hermetic temp forge home, zero
// network): site registry round-trips, SSRF-gated analyze, target
// confinement, rebuild/github fail-closed paths, and the deploy config.
// ---------------------------------------------------------------------------

import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { beforeEach, describe, expect, it } from 'vitest';

import { SITE_TOOLS } from '../../mcp/tools/sites.ts';
import { DISCOVERY_TOOLS } from '../../mcp/tools/discovery.ts';
import { SCAFFOLD_TOOLS } from '../../mcp/tools/scaffold.ts';
import { REBUILD_TOOLS } from '../../mcp/tools/rebuild.ts';
import { GITHUB_TOOLS } from '../../mcp/tools/github.ts';
import { DEPLOY_TOOLS } from '../../mcp/tools/deploy.ts';
import type { ToolEnv, ToolResult } from '../../mcp/tool-def.ts';

let env: ToolEnv;

beforeEach( async () => {
	const home = await mkdtemp( join( tmpdir(), 'forge-mcp-' ) );
	env = { homeDir: home, workDir: join( home, 'projects' ), openAnalyze: false };
} );

function tool( name: string ) {
	const found = [ ...SITE_TOOLS, ...DISCOVERY_TOOLS, ...SCAFFOLD_TOOLS, ...REBUILD_TOOLS, ...GITHUB_TOOLS, ...DEPLOY_TOOLS ].find(
		( entry ) => entry.name === name
	);
	if ( ! found ) {
		throw new Error( `unknown tool ${ name }` );
	}
	return found;
}

async function call( name: string, args: unknown ): Promise<ToolResult> {
	return tool( name ).handler( args, env );
}

describe( 'site registry tools', () => {
	it( 'site_list starts empty; unknown site ops fail cleanly', async () => {
		const listed = await call( 'site_list', {} );
		expect( listed.structuredContent ).toEqual( { sites: [], default: null } );

		const got = await call( 'site_get', { name: 'ghost' } );
		expect( got.isError ).toBe( true );

		const tested = await call( 'site_test', { name: 'ghost' } );
		expect( tested.isError ).toBe( true );

		const removed = await call( 'site_remove', { name: 'ghost' } );
		expect( removed.isError ).toBe( true );
	} );

	it( 'site_set_default + site_remove enforce existence', async () => {
		const set = await call( 'site_set_default', { name: 'ghost' } );
		expect( set.isError ).toBe( true );
	} );

	it( 'site_add refuses private-range URLs before any network', async () => {
		const added = await call( 'site_add', { name: 'sneaky', url: 'http://127.0.0.1/' } );
		expect( added.isError ).toBe( true );
		expect( added.content[ 0 ].text ).toMatch( /Refusing IP-literal/ );
	} );

	it( 'site_analyze refuses non-allowlisted URLs without the opt-in', async () => {
		const analyzed = await call( 'site_analyze', { url: 'https://not-registered.example.com/' } );
		expect( analyzed.isError ).toBe( true );
		expect( analyzed.content[ 0 ].text ).toMatch( /not a registered site/ );
	} );

	it( 'site_analyze refuses private ranges even with the opt-in', async () => {
		env.openAnalyze = true;
		const analyzed = await call( 'site_analyze', { url: 'http://169.254.169.254/latest/meta-data' } );
		expect( analyzed.isError ).toBe( true );
		expect( analyzed.content[ 0 ].text ).toMatch( /Refusing IP-literal/ );
	} );
} );

describe( 'scaffold tools', () => {
	it( 'scaffold_project confines targets to the work directory', async () => {
		const result = await call( 'scaffold_project', { profilePath: 'x.json', target: join( tmpdir(), 'escape' ) } );
		expect( result.isError ).toBe( true );
		expect( result.content[ 0 ].text ).toMatch( /outside the forge work directory/ );
	} );

	it( 'scaffold_status reports a missing project', async () => {
		const result = await call( 'scaffold_status', { target: 'nope' } );
		expect( result.isError ).toBe( true );
	} );

	it( 'tokens_apply requires the FORGE:tokens markers', async () => {
		const target = join( env.workDir, 'site' );
		await import( 'node:fs/promises' ).then( ( { mkdir } ) => mkdir( join( target, 'src', 'styles' ), { recursive: true } ) );
		await writeFile( join( target, 'src', 'styles', 'global.css' ), ':root {}', 'utf8' );
		const result = await call( 'tokens_apply', { target, tokens: { accent: '#123456' } } );
		expect( result.isError ).toBe( true );
		expect( result.content[ 0 ].text ).toMatch( /FORGE:tokens/ );
	} );
} );

describe( 'rebuild + deploy + github tools', () => {
	it( 'rebuild_config and deploy_config emit the pipeline into a temp target', async () => {
		const target = join( env.workDir, 'pipeline' );
		const { mkdir } = await import( 'node:fs/promises' );
		await mkdir( target, { recursive: true } );

		const emitted = await call( 'rebuild_config', { target, mode: 'github-pages' } );
		expect( emitted.isError ).toBeUndefined();
		await expect( readFile( join( target, '.github', 'workflows', 'deploy.yml' ), 'utf8' ) ).resolves.toContain( 'configure-pages' );

		const again = await call( 'deploy_config', { target, mode: 'cloudflare-pages', cloudflareProject: 'demo' } );
		expect( again.isError ).toBeUndefined();
		await expect( readFile( join( target, '.github', 'workflows', 'deploy.yml' ), 'utf8' ) ).resolves.toContain( 'wrangler-action' );
	} );

	it( 'rebuild_trigger fails closed without vault entries', async () => {
		const result = await call( 'rebuild_trigger', {} );
		expect( result.isError ).toBe( true );
		expect( result.content[ 0 ].text ).toMatch( /Missing vault entries/ );
	} );

	it( 'github_create_repo fails cleanly without a token', async () => {
		delete process.env.GITHUB_TOKEN;
		const result = await call( 'github_create_repo', { name: 'demo-site' } );
		expect( result.isError ).toBe( true );
		expect( result.content[ 0 ].text ).toMatch( /No GitHub token/ );
	} );

	it( 'setup_wizard refuses unsafe URLs before any network', async () => {
		const result = await call( 'setup_wizard', { url: 'http://127.0.0.1/', target: join( env.workDir, 'w' ) } );
		expect( result.isError ).toBe( true );
		expect( result.content[ 0 ].text ).toMatch( /Refusing IP-literal/ );
	} );

	it( 'setup_wizard confines explicit targets to the work directory', async () => {
		const result = await call( 'setup_wizard', { url: 'https://example.com', target: join( tmpdir(), 'elsewhere' ) } );
		expect( result.isError ).toBe( true );
		expect( result.content[ 0 ].text ).toMatch( /outside the forge work directory/ );
	} );
} );
