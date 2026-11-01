// ---------------------------------------------------------------------------
// github.ts — publish tool slice (096 §3.3): private-by-default repo create,
// source commit/push, and a redacted status view. Tokens come from
// GITHUB_TOKEN or the vault entry `github` — per invocation, never echoed.
// ---------------------------------------------------------------------------

import { z } from 'zod/v4';

import { createOctokitClient, GitHubDeploy } from '../../lib/github.ts';
import { collectProjectFiles } from '../../lib/project-files.ts';
import { Vault } from '../../lib/vault.ts';
import type { ToolDef } from '../tool-def.ts';
import { errorResult, READ_ONLY_TOOL, text, WRITE_TOOL } from '../util.ts';

async function resolveToken( env: { homeDir: string } ): Promise<string | undefined> {
	return process.env.GITHUB_TOKEN ?? ( await new Vault( { dir: env.homeDir } ).get( 'github' ).catch( () => undefined ) );
}

export const GITHUB_TOOLS: ToolDef[] = [
	{
		name: 'github_create_repo',
		title: 'Create the GitHub repository for a generated site',
		description:
			'Create the repository that will host a generated site. PRIVATE by default — pass isPublic: true only as an explicit opt-in. Requires a GitHub token (GITHUB_TOKEN env or the vault entry `github`).',
		schema: z.object( {
			name: z.string().regex( /^[a-z0-9][a-z0-9-]{0,98}$/ ),
			description: z.string().optional(),
			org: z.string().optional(),
			isPublic: z.boolean().optional(),
		} ),
		annotations: WRITE_TOOL,
		write: true,
		handler: async ( args, env ) => {
			const { name, description, org, isPublic } = args as { name: string; description?: string; org?: string; isPublic?: boolean };
			const token = await resolveToken( env );
			if ( ! token ) {
				return errorResult( 'No GitHub token — set GITHUB_TOKEN or store one in the vault (`github`).' );
			}
			try {
				const deploy = new GitHubDeploy( await createOctokitClient( token ) );
				const result = await deploy.createRepo( { name, description, org, isPrivate: isPublic !== true } );
				return text( `Repository ${ result.owner }/${ result.name } (${ result.isPrivate ? 'private' : 'PUBLIC' }).`, result );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'github_commit',
		title: 'Commit + push a scaffolded project to GitHub',
		description:
			'Collect a scaffolded project\'s source (build/runtime artifacts and binaries excluded), create the blobs/tree/commit via the git-data API, and force-update the branch ref. The branch is created on first push. Requires a GitHub token (GITHUB_TOKEN env or the vault entry `github`).',
		schema: z.object( {
			target: z.string(),
			owner: z.string(),
			repo: z.string(),
			branch: z.string().optional(),
			message: z.string().optional(),
		} ),
		annotations: WRITE_TOOL,
		write: true,
		handler: async ( args, env ) => {
			const { target, owner, repo, branch, message } = args as {
				target: string;
				owner: string;
				repo: string;
				branch?: string;
				message?: string;
			};
			const token = await resolveToken( env );
			if ( ! token ) {
				return errorResult( 'No GitHub token — set GITHUB_TOKEN or store one in the vault (`github`).' );
			}
			try {
				const files = await collectProjectFiles( target );
				const deploy = new GitHubDeploy( await createOctokitClient( token ) );
				const pushed = await deploy.commitFiles( {
					owner,
					repo,
					branch,
					message: message ?? 'Forge: generated site update',
					files,
				} );
				return text( `Pushed ${ pushed.files } files -> ${ pushed.owner }/${ pushed.repo }@${ pushed.branch } (${ pushed.commitSha.slice( 0, 8 ) }).`, pushed );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'github_status',
		title: 'Read a repository\'s redacted status',
		description: 'Report a repository\'s public facts (name, visibility, HTML URL) — never tokens or credentials. Requires a GitHub token for the lookup.',
		schema: z.object( { owner: z.string(), repo: z.string() } ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { owner, repo } = args as { owner: string; repo: string };
			const token = await resolveToken( env );
			if ( ! token ) {
				return errorResult( 'No GitHub token — set GITHUB_TOKEN or store one in the vault (`github`).' );
			}
			try {
				const deploy = new GitHubDeploy( await createOctokitClient( token ) );
				const exists = await deploy.repoExists( owner, repo );
				return text( exists ? `${ owner }/${ repo } exists.` : `${ owner }/${ repo } does not exist.`, { owner, repo, exists } );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
];
