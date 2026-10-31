// ---------------------------------------------------------------------------
// github.ts — the GitHub deploy tools (Proposal 096 Phase 3.2).
//
// Per-invocation auth, never persisted: the CLI resolves the token from the
// `GITHUB_TOKEN` env var or the vault entry `github` for the lifetime of one
// command and drops it after. This module never writes tokens to disk, never
// logs them, and sanitizes every error it surfaces (octokit errors can embed
// request headers — only status + response-body message leave the module).
//
// Repos default to PRIVATE. A customer frontend is never published publicly
// without an explicit flag.
// ---------------------------------------------------------------------------

import type { Octokit } from 'octokit';

/** A minimal structural subset of octokit's rest surface (mockable in tests). */
export interface GitHubClient {
	rest: {
		repos: {
			createForAuthenticatedUser( args: unknown ): Promise<{ data: unknown }>;
			createInOrg( args: unknown ): Promise<{ data: unknown }>;
			get( args: unknown ): Promise<{ data: unknown }>;
		};
		git: {
			getRef( args: unknown ): Promise<{ data: unknown }>;
			createBlob( args: unknown ): Promise<{ data: unknown }>;
			createTree( args: unknown ): Promise<{ data: unknown }>;
			createCommit( args: unknown ): Promise<{ data: unknown }>;
			updateRef( args: unknown ): Promise<{ data: unknown }>;
		};
		users: {
			getAuthenticated( args?: unknown ): Promise<{ data: unknown }>;
		};
	};
}

/** Error type for deploy failures — sanitized, never carries credentials. */
export class GitHubError extends Error {
	constructor( message: string ) {
		super( message );
		this.name = 'GitHubError';
	}
}

/** A pushed commit summary (what the CLI prints — no secrets possible). */
export interface CommitResult {
	owner: string;
	repo: string;
	branch: string;
	commitSha: string;
	files: number;
}

/** A created repository summary (redacted by construction). */
export interface RepoResult {
	owner: string;
	name: string;
	isPrivate: boolean;
	htmlUrl: string;
}

/** Guard against runaway pushes — a forged site is tens of files, never more. */
export const MAX_FILES = 1000;
export const MAX_TOTAL_BYTES = 32 * 1024 * 1024;

function asRepo( data: unknown ): RepoResult {
	const repo = data as {
		owner?: { login?: string };
		full_name?: string;
		name?: string;
		private?: boolean;
		html_url?: string;
	};
	const owner = repo.owner?.login ?? repo.full_name?.split( '/' )[ 0 ] ?? 'unknown';
	const name = repo.name ?? repo.full_name?.split( '/' )[ 1 ] ?? 'unknown';
	return {
		owner,
		name,
		isPrivate: repo.private !== false,
		htmlUrl: repo.html_url ?? `https://github.com/${ owner }/${ name }`,
	};
}

function asSha( data: unknown, fallback: string ): string {
	if ( data && typeof data === 'object' && 'sha' in data ) {
		return String( ( data as { sha: unknown } ).sha );
	}
	return fallback;
}

/**
 * Sanitize an octokit error: status + the response body message only. Request
 * headers (Authorization) and the URL's credential-bearing parts never leak.
 */
export function sanitizeGitHubError( error: unknown ): string {
	if ( error instanceof GitHubError ) {
		return error.message;
	}
	const e = error as {
		status?: number;
		response?: { status?: number; data?: unknown };
		message?: string;
	};
	const status = e.status ?? e.response?.status;
	const body = e.response?.data as { message?: string } | undefined;
	const message = body?.message ?? e.message ?? 'unknown error';
	const url = String( ( error as { request?: { url?: string } } ).request?.url ?? '' );
	// Strip query strings entirely — tokens travel in query params sometimes.
	const bareUrl = url.split( '?' )[ 0 ];
	return [ status ? `HTTP ${ status }` : undefined, message, bareUrl ? `(${ bareUrl })` : undefined ]
		.filter( Boolean )
		.join( ' ' );
}

/**
 * The deploy client. `client` is octokit in production; tests inject a fake
 * with the same rest shape. No token is stored here.
 */
export class GitHubDeploy {
	private readonly client: GitHubClient;

	constructor( client: GitHubClient ) {
		this.client = client;
	}

	/** Create a repo (private by default) under the user or an org. */
	async createRepo( options: { name: string; description?: string; org?: string; isPrivate?: boolean } ): Promise<RepoResult> {
		this.assertRepoName( options.name );
		try {
			const args = {
				name: options.name,
				description: options.description ?? 'Generated with NV oOS Astro Forge.',
				private: options.isPrivate ?? true,
				auto_init: false,
			};
			const response = options.org
				? await this.client.rest.repos.createInOrg( { ...args, org: options.org } )
				: await this.client.rest.repos.createForAuthenticatedUser( args );
			return asRepo( response.data );
		} catch ( error ) {
			throw new GitHubError( `createRepo failed: ${ sanitizeGitHubError( error ) }` );
		}
	}

	/** Whether the repo exists. */
	async repoExists( owner: string, repo: string ): Promise<boolean> {
		try {
			await this.client.rest.repos.get( { owner, repo } );
			return true;
		} catch {
			return false;
		}
	}

	/**
	 * Commit a file set to a repo branch, creating the branch when it does
	 * not exist yet (the first commit of a generated site).
	 */
	async commitFiles( options: {
		owner: string;
		repo: string;
		branch?: string;
		message?: string;
		files: Record<string, string>;
	} ): Promise<CommitResult> {
		const branch = options.branch ?? 'main';
		const files = options.files;
		const paths = Object.keys( files );
		if ( paths.length === 0 ) {
			throw new GitHubError( 'commitFiles: nothing to push.' );
		}
		if ( paths.length > MAX_FILES ) {
			throw new GitHubError( `commitFiles: ${ paths.length } files exceeds the ${ MAX_FILES }-file guard.` );
		}
		let total = 0;
		for ( const path of paths ) {
			total += Buffer.byteLength( files[ path ], 'utf8' );
		}
		if ( total > MAX_TOTAL_BYTES ) {
			throw new GitHubError( `commitFiles: payload exceeds the ${ Math.round( MAX_TOTAL_BYTES / 1024 / 1024 ) } MB guard.` );
		}

		try {
			// Parent commit: the branch head when it exists, else a root commit.
			let parentSha: string | undefined;
			try {
				const ref = await this.client.rest.git.getRef( { owner: options.owner, repo: options.repo, ref: `heads/${ branch }` } );
				parentSha = asSha( ( ref.data as { object?: { sha?: string } } ).object ?? ref.data, '' ) || undefined;
			} catch {
				parentSha = undefined;
			}

			// Blobs first (content-addressed), then one tree + one commit.
			const treeEntries: { path: string; mode: string; type: string; sha: string }[] = [];
			for ( const path of paths ) {
				const blob = await this.client.rest.git.createBlob( {
					owner: options.owner,
					repo: options.repo,
					content: files[ path ],
					encoding: 'utf-8',
				} );
				treeEntries.push( { path, mode: '100644', type: 'blob', sha: asSha( blob.data, '' ) } );
			}
			const tree = await this.client.rest.git.createTree( {
				owner: options.owner,
				repo: options.repo,
				tree: treeEntries,
			} );
			const commit = await this.client.rest.git.createCommit( {
				owner: options.owner,
				repo: options.repo,
				message: options.message ?? 'Forge: generated site update',
				tree: asSha( tree.data, '' ),
				...( parentSha ? { parents: [ parentSha ] } : {} ),
			} );
			const commitSha = asSha( commit.data, '' );
			await this.client.rest.git.updateRef( {
				owner: options.owner,
				repo: options.repo,
				ref: `heads/${ branch }`,
				sha: commitSha,
				force: true,
			} );
			return { owner: options.owner, repo: options.repo, branch, commitSha, files: paths.length };
		} catch ( error ) {
			throw new GitHubError( `commitFiles failed: ${ sanitizeGitHubError( error ) }` );
		}
	}

	/** The authenticated account login (needed to reuse an existing repo). */
	async login(): Promise<string> {
		try {
			const response = await this.client.rest.users.getAuthenticated();
			const login = ( response.data as { login?: string } ).login;
			if ( ! login ) {
				throw new GitHubError( 'login: the authenticated user has no login.' );
			}
			return login;
		} catch ( error ) {
			throw new GitHubError( `login failed: ${ sanitizeGitHubError( error ) }` );
		}
	}

	private assertRepoName( name: string ): void {
		if ( !/^[a-z0-9][a-z0-9-]{0,98}$/.test( name ) ) {
			throw new GitHubError( `Invalid repo name: ${ name }` );
		}
	}
}

/** Production wiring — the CLI builds this once per invocation. */
export async function createOctokitClient( token: string ): Promise<GitHubClient> {
	// Dynamic import keeps octokit out of the unit-test module graph; the
	// rest surface is structurally compatible with GitHubClient.
	const { Octokit: RealOctokit } = await import( 'octokit' );
	return new RealOctokit( { auth: token } ) as unknown as GitHubClient;
}
