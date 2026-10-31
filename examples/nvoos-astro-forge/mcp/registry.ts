// ---------------------------------------------------------------------------
// registry.ts — SINGLE SOURCE OF TRUTH (096 §3.3 / 095 registry pattern):
// every tool name → Zod schema + annotations + write flag + handler. The
// coverage test fails CI when a tool lacks any of these. buildServer()
// assembles the McpServer per connection; NVOOS_FORGE_MODE=router serves
// only the three meta-tools, full serves everything (default).
// ---------------------------------------------------------------------------

import { McpServer } from '@modelcontextprotocol/server';
import type { StandardSchemaWithJSON, ToolAnnotations } from '@modelcontextprotocol/server';
import { z } from 'zod/v4';

import { forgeHome } from '../lib/vault.ts';
import type { ToolDef, ToolEnv, ToolResult } from './tool-def.ts';
import { DEPLOY_TOOLS } from './tools/deploy.ts';
import { DISCOVERY_TOOLS } from './tools/discovery.ts';
import { GITHUB_TOOLS } from './tools/github.ts';
import { REBUILD_TOOLS } from './tools/rebuild.ts';
import { SCAFFOLD_TOOLS } from './tools/scaffold.ts';
import { SITE_TOOLS } from './tools/sites.ts';
import { envValue, errorResult, READ_ONLY_TOOL, text } from './util.ts';

export const SERVER_NAME = 'nvoos-astro-forge';
export const SERVER_VERSION = '0.1.0';

/** The router recipe — the ordered tool sequence for a stated goal. */
const RECIPES: Array<{ match: RegExp; steps: string[]; note: string }> = [
	{
		match: /wizard|from (a )?url|end[- ]to[- ]end|zero[- ]to/i,
		steps: [ 'setup_wizard' ],
		note: 'One command: analyze → register → scaffold → build → git init → deploy pipeline.',
	},
	{
		match: /analy|discover|audit|inspect/i,
		steps: [ 'site_add', 'site_analyze', 'site_test' ],
		note: 'Register (or open-analyze), discover, then verify counts against page caps.',
	},
	{
		match: /scaffold|generate|build the site/i,
		steps: [ 'site_get', 'scaffold_project', 'tokens_apply' ],
		note: 'Read the profile, scaffold into the work dir, then apply the 098 design language.',
	},
	{
		match: /deploy|publish|ship|push|github/i,
		steps: [ 'rebuild_config', 'github_create_repo', 'github_commit', 'github_status' ],
		note: 'Emit the pipeline, create a PRIVATE repo, push the source, verify the result.',
	},
	{
		match: /rebuild|webhook|freshness/i,
		steps: [ 'rebuild_config', 'rebuild_trigger' ],
		note: 'Emit the dispatch-verified pipeline, then trigger the bridge webhook.',
	},
];

/** The full v1 catalog, one source for registration, help, and coverage. */
export const ALL_TOOLS: ToolDef[] = [
	...SITE_TOOLS,
	...DISCOVERY_TOOLS,
	...SCAFFOLD_TOOLS,
	...REBUILD_TOOLS,
	...GITHUB_TOOLS,
	...DEPLOY_TOOLS,
];

/** Write-gated tool names (auth.ts consumes this — never duplicated). */
export const WRITE_TOOL_NAMES: ReadonlySet<string> = new Set(
	ALL_TOOLS.filter( ( tool ) => tool.write ).map( ( tool ) => tool.name )
);

/** Resolve the per-call env from the request context / process env. */
export function resolveToolEnv(): ToolEnv {
	const homeDir = envValue( 'NVOOS_FORGE_HOME' ) ?? forgeHome();
	return {
		homeDir,
		workDir: envValue( 'NVOOS_FORGE_WORKDIR' ) ?? `${ homeDir.replace( /[/\\]$/, '' ) }/projects`,
		openAnalyze: envValue( 'NVOOS_FORGE_OPEN_ANALYZE' ) === '1',
	};
}

/** The three meta-tools (router mode surface). Exported for coverage tests. */
export function metaTools(): ToolDef[] {
	return [
		{
			name: 'forge_help',
			title: 'List the forge tool catalog',
			description: 'List every available tool with its one-line purpose, plus the current mode. The router surface — start here.',
			schema: z.object( {} ),
			annotations: READ_ONLY_TOOL,
			write: false,
			handler: async ( _args, env ) => {
				const mode = envValue( 'NVOOS_FORGE_MODE' ) === 'router' ? 'router' : 'full';
				const tools = ALL_TOOLS.map( ( tool ) => ( { name: tool.name, description: tool.description.split( '\n' )[ 0 ], write: tool.write } ) );
				return text( `nvoos-astro-forge ${ SERVER_VERSION } (mode: ${ mode }, ${ tools.length } tools).`, { mode, tools } );
			},
		},
		{
			name: 'forge_plan',
			title: 'Plan the tool sequence for a goal',
			description: 'Map a natural-language goal ("deploy the site", "analyze this URL") onto the ordered tool sequence to run. Read-only.',
			schema: z.object( { goal: z.string().min( 3 ) } ),
			annotations: READ_ONLY_TOOL,
			write: false,
			handler: async ( args ) => {
				const { goal } = args as { goal: string };
				const recipe = RECIPES.find( ( entry ) => entry.match.test( goal ) );
				if ( ! recipe ) {
					return text( `No exact recipe for "${ goal }" — the closest flows are: ${ RECIPES.map( ( entry ) => entry.steps.join( ' → ' ) ).join( '; ' ) }.`, { steps: [] } );
				}
				return text( `${ recipe.note } Steps: ${ recipe.steps.join( ' → ' ) }.`, { goal, steps: recipe.steps, note: recipe.note } );
			},
		},
		{
			name: 'forge_status',
			title: 'Read the forge server status',
			description: 'Registered sites, vault entry names, mode, and server version — names only, never secrets.',
			schema: z.object( {} ),
			annotations: READ_ONLY_TOOL,
			write: false,
			handler: async ( _args, env ) => {
				const { SitesStore } = await import( '../lib/sites.ts' );
				const { Vault } = await import( '../lib/vault.ts' );
				const sites = await new SitesStore( env.homeDir ).list().catch( () => ( {} ) );
				const vaultNames = await new Vault( { dir: env.homeDir } ).list().catch( () => [] );
				return text( `Forge ${ SERVER_VERSION } — ${ Object.keys( sites ).length } site(s), ${ vaultNames.length } vault entry(ies).`, {
					mode: envValue( 'NVOOS_FORGE_MODE' ) === 'router' ? 'router' : 'full',
					sites: Object.keys( sites ),
					vaultEntries: vaultNames,
				} );
			},
		},
	];
}

/**
 * registerTool's ToolCallback generic is a conditional type that plain
 * function expressions cannot satisfy even when the return type is provably
 * assignable (verified by a dedicated type probe: ToolResult → CallToolResult
 * | InputRequiredResult). Register through this exact signature instead.
 */
type RegisterToolFn = (
	name: string,
	config: {
		title?: string;
		description?: string;
		annotations?: ToolAnnotations;
		inputSchema?: StandardSchemaWithJSON;
	},
	cb: ( args: unknown ) => Promise<ToolResult>
) => unknown;

/**
 * Assemble one McpServer. `mode` 'router' registers the meta-tools only.
 * Return the server itself — registerTool returns the tool record, not the
 * server (the SDK v2 factory gotcha).
 */
export function buildServer( options: { mode?: string } = {} ): McpServer {
	const mode = ( options.mode ?? envValue( 'NVOOS_FORGE_MODE' ) ?? 'full' ) === 'router' ? 'router' : 'full';
	const server = new McpServer( { name: SERVER_NAME, version: SERVER_VERSION } );
	const tools = mode === 'router' ? metaTools() : [ ...metaTools(), ...ALL_TOOLS ];
	const register = server.registerTool.bind( server ) as unknown as RegisterToolFn;
	for ( const tool of tools ) {
		register(
			tool.name,
			{
				title: tool.title,
				description: tool.description,
				annotations: tool.annotations,
				inputSchema: tool.schema,
			},
			async ( args ) => {
				try {
					// StandardSchemaWithJSON is structural — zod instances carry
					// `parse` in addition to the standard-schema surface.
					const schema = tool.schema as unknown as { parse?: ( input: unknown ) => unknown };
					const parsed = schema.parse ? schema.parse( args ) : args;
					return await tool.handler( parsed, resolveToolEnv() );
				} catch ( error ) {
					return errorResult( error instanceof Error ? error.message : String( error ) );
				}
			}
		);
	}
	return server;
}
