// ---------------------------------------------------------------------------
// registry.test.ts — the single-source coverage gate (096 §7): every tool
// must carry a Zod schema, explicit annotations, a write flag, and a
// description. Router mode exposes exactly the three meta-tools; full mode
// exposes the whole catalog; write-tool names match the write flags.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { ALL_TOOLS, metaTools, WRITE_TOOL_NAMES } from '../../mcp/registry.ts';

describe( 'tool registry coverage', () => {
	it( 'every tool has a name, title, description, Zod schema, annotations, and a write flag', () => {
		expect( ALL_TOOLS.length ).toBeGreaterThan( 15 );
		for ( const tool of ALL_TOOLS ) {
			expect( tool.name, 'tool name' ).toMatch( /^[a-z0-9_]+$/ );
			expect( tool.title.length, `${ tool.name } title` ).toBeGreaterThan( 3 );
			expect( tool.description.length, `${ tool.name } description` ).toBeGreaterThan( 20 );
			expect( tool.schema, `${ tool.name } schema` ).toBeDefined();
			expect( tool.annotations, `${ tool.name } annotations` ).toBeDefined();
			expect( typeof tool.annotations.readOnlyHint, `${ tool.name } readOnlyHint` ).toBe( 'boolean' );
			expect( typeof tool.annotations.destructiveHint, `${ tool.name } destructiveHint` ).toBe( 'boolean' );
			expect( typeof tool.annotations.openWorldHint, `${ tool.name } openWorldHint` ).toBe( 'boolean' );
			expect( typeof tool.write, `${ tool.name } write flag` ).toBe( 'boolean' );
			expect( typeof tool.handler, `${ tool.name } handler` ).toBe( 'function' );
		}
	} );

	it( 'WRITE_TOOL_NAMES matches the write flags exactly', () => {
		for ( const tool of ALL_TOOLS ) {
			expect( WRITE_TOOL_NAMES.has( tool.name ), `${ tool.name } in the write set` ).toBe( tool.write );
		}
	} );

	it( 'the catalog covers every 096 §3.3 slice', () => {
		const names = new Set( ALL_TOOLS.map( ( tool ) => tool.name ) );
		for ( const expected of [
			'site_add', 'site_test', 'site_list', 'site_get', 'site_remove', 'site_set_default',
			'site_analyze',
			'scaffold_project', 'scaffold_status', 'tokens_apply', 'scaffold_package',
			'rebuild_config', 'rebuild_trigger',
			'github_create_repo', 'github_commit', 'github_status',
			'deploy_config', 'setup_wizard',
		] ) {
			expect( names.has( expected ), `catalog includes ${ expected }` ).toBe( true );
		}
	} );

	it( 'open-world tools carry the openWorldHint', () => {
		const openWorld = new Set( ALL_TOOLS.filter( ( tool ) => tool.annotations.openWorldHint ).map( ( tool ) => tool.name ) );
		expect( openWorld.has( 'site_analyze' ) ).toBe( true );
		expect( openWorld.has( 'site_add' ) ).toBe( true );
		expect( openWorld.has( 'rebuild_trigger' ) ).toBe( true );
	} );
} );

describe( 'router vs full mode', () => {
	it( 'the router surface is exactly the three meta-tools', () => {
		expect( metaTools().map( ( tool ) => tool.name ) ).toEqual( [ 'forge_help', 'forge_plan', 'forge_status' ] );
	} );

	it( 'meta-tools are read-only and unauthenticated', () => {
		for ( const tool of metaTools() ) {
			expect( tool.write ).toBe( false );
			expect( tool.annotations.readOnlyHint ).toBe( true );
		}
	} );
} );
