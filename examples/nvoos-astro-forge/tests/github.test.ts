// ---------------------------------------------------------------------------
// github.test.ts — Phase 3.2: repo create (private by default), git-data
// commit/push, redacted outputs, sanitized errors. Zero network: the octokit
// surface is faked.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { GitHubDeploy, GitHubError, MAX_FILES, sanitizeGitHubError } from '../lib/github.ts';

/** A recording fake of the octokit rest subset the deploy client uses. */
function fakeClient( overrides: Partial<Record<string, ( args: any ) => any>> = {} ) {
	const calls: { endpoint: string; args: any }[] = [];
	let refSha = '';

	const client = {
		rest: {
			repos: {
				createForAuthenticatedUser: async ( args: any ) => {
					calls.push( { endpoint: 'createForAuthenticatedUser', args } );
					return { data: { name: args.name, full_name: `me/${ args.name }`, private: args.private, html_url: `https://github.com/me/${ args.name }` } };
				},
				createInOrg: async ( args: any ) => {
					calls.push( { endpoint: 'createInOrg', args } );
					return { data: { name: args.name, full_name: `${ args.org }/${ args.name }`, private: args.private, html_url: `https://github.com/${ args.org }/${ args.name }` } };
				},
				get: async () => {
					calls.push( { endpoint: 'get', args: {} } );
					return { data: { full_name: 'me/site', private: true } };
				},
			},
			git: {
				getRef: async () => {
					if ( ! refSha ) {
						throw new Error( '404' );
					}
					return { data: { ref: 'refs/heads/main', object: { sha: refSha } } };
				},
				createBlob: async ( args: any ) => {
					calls.push( { endpoint: 'createBlob', args } );
					return { data: { sha: `blob-${ calls.length }` } };
				},
				createTree: async ( args: any ) => {
					calls.push( { endpoint: 'createTree', args } );
					return { data: { sha: `tree-${ calls.length }` } };
				},
				createCommit: async ( args: any ) => {
					calls.push( { endpoint: 'createCommit', args } );
					return { data: { sha: `commit-${ calls.length }` } };
				},
				updateRef: async ( args: any ) => {
					calls.push( { endpoint: 'updateRef', args } );
					refSha = args.sha;
					return { data: {} };
				},
			},
			users: {
				getAuthenticated: async () => {
					calls.push( { endpoint: 'getAuthenticated', args: {} } );
					return { data: { login: 'me' } };
				},
			},
		},
		...overrides,
	};
	return { client, calls, getRefSha: () => refSha };
}

describe( 'createRepo', () => {
	it( 'defaults to private for the authenticated user', async () => {
		const { client, calls } = fakeClient();
		const deploy = new GitHubDeploy( client );
		const result = await deploy.createRepo( { name: 'customer-frontend' } );
		expect( result ).toEqual( {
			owner: 'me',
			name: 'customer-frontend',
			isPrivate: true,
			htmlUrl: 'https://github.com/me/customer-frontend',
		} );
		expect( calls[ 0 ].args.private ).toBe( true );
	} );

	it( 'supports orgs and an explicit public flag', async () => {
		const { client, calls } = fakeClient();
		const deploy = new GitHubDeploy( client );
		const result = await deploy.createRepo( { name: 'site', org: 'agency', isPrivate: false } );
		expect( result.owner ).toBe( 'agency' );
		expect( result.isPrivate ).toBe( false );
		expect( calls[ 0 ].args.private ).toBe( false );
	} );

	it( 'rejects invalid repo names', async () => {
		const { client } = fakeClient();
		const deploy = new GitHubDeploy( client );
		await expect( deploy.createRepo( { name: 'Bad Name!' } ) ).rejects.toThrow( GitHubError );
	} );
} );

describe( 'commitFiles', () => {
	it( 'creates blobs, a tree, a root commit, and the branch ref', async () => {
		const { client, calls, getRefSha } = fakeClient();
		const deploy = new GitHubDeploy( client );
		const result = await deploy.commitFiles( {
			owner: 'me',
			repo: 'site',
			files: { 'index.html': '<h1>hi</h1>', 'src/pages/index.astro': '---\n---' },
			message: 'Forge: first build',
		} );
		expect( result ).toEqual( { owner: 'me', repo: 'site', branch: 'main', commitSha: expect.stringContaining( 'commit-' ), files: 2 } );
		expect( getRefSha() ).toBe( result.commitSha );
		const treeCall = calls.find( ( c ) => c.endpoint === 'createTree' )!;
		expect( treeCall.args.tree ).toHaveLength( 2 );
		const commitCall = calls.find( ( c ) => c.endpoint === 'createCommit' )!;
		expect( commitCall.args.parents ).toBeUndefined(); // root commit
	} );

	it( 'appends a parent commit when the branch exists', async () => {
		const { client, calls } = fakeClient();
		const deploy = new GitHubDeploy( client );
		await deploy.commitFiles( { owner: 'me', repo: 'site', files: { 'a.html': '1' } } );
		await deploy.commitFiles( { owner: 'me', repo: 'site', files: { 'b.html': '2' } } );
		const commitCalls = calls.filter( ( c ) => c.endpoint === 'createCommit' );
		// The ref state after the first push is the authoritative parent for
		// the second commit (recorded by updateRef).
		const firstUpdate = calls.find( ( c ) => c.endpoint === 'updateRef' )!;
		expect( commitCalls[ 1 ].args.parents ).toEqual( [ firstUpdate.args.sha ] );
	} );

	it( 'refuses empty and oversized payloads', async () => {
		const { client } = fakeClient();
		const deploy = new GitHubDeploy( client );
		await expect( deploy.commitFiles( { owner: 'me', repo: 'site', files: {} } ) ).rejects.toThrow( GitHubError );
		const tooMany: Record<string, string> = {};
		for ( let i = 0; i <= MAX_FILES; i++ ) {
			tooMany[ `f${ i }.html` ] = 'x';
		}
		await expect( deploy.commitFiles( { owner: 'me', repo: 'site', files: tooMany } ) ).rejects.toThrow( /guard/ );
	} );

	it( 'sanitizes octokit errors — status and message only, no headers', async () => {
		const client = {
			rest: {
				repos: {
					createForAuthenticatedUser: async () => {
						throw {
							status: 401,
							response: {
								status: 401,
								data: { message: 'Bad credentials' },
								headers: { authorization: 'token ghp_LEAKED', 'x-oauth-client-id': 'secret' },
							},
							request: { url: 'https://api.github.com/repos/x?token=ghp_ALSO_LEAKED' },
						};
					},
					createInOrg: async () => ( { data: {} } ),
					get: async () => ( { data: {} } ),
				},
				git: { getRef: async () => ( { data: {} } ), createBlob: async () => ( { data: {} } ), createTree: async () => ( { data: {} } ), createCommit: async () => ( { data: {} } ), updateRef: async () => ( { data: {} } ) },
				users: { getAuthenticated: async () => ( { data: { login: 'me' } } ) },
			},
		};
		const deploy = new GitHubDeploy( client as any );
		const error = await deploy.createRepo( { name: 'site' } ).catch( ( e ) => e );
		expect( error ).toBeInstanceOf( GitHubError );
		expect( error.message ).toContain( 'Bad credentials' );
		expect( error.message ).not.toContain( 'ghp_' );
		expect( error.message ).not.toContain( 'token=' );
	} );
} );

describe( 'login', () => {
	it( 'returns the authenticated account login', async () => {
		const { client } = fakeClient();
		const deploy = new GitHubDeploy( client );
		expect( await deploy.login() ).toBe( 'me' );
	} );

	it( 'sanitizes login errors', async () => {
		const client = {
			rest: {
				repos: {
					createForAuthenticatedUser: async () => ( { data: {} } ),
					createInOrg: async () => ( { data: {} } ),
					get: async () => ( { data: {} } ),
				},
				git: { getRef: async () => ( { data: {} } ), createBlob: async () => ( { data: {} } ), createTree: async () => ( { data: {} } ), createCommit: async () => ( { data: {} } ), updateRef: async () => ( { data: {} } ) },
				users: {
					getAuthenticated: async () => {
						throw { status: 401, response: { data: { message: 'Requires authentication' }, headers: { authorization: 'token ghp_LEAKED' } }, request: { url: 'https://api.github.com/user?x=ghp_MORE' } };
					},
				},
			},
		};
		const deploy = new GitHubDeploy( client as any );
		const error = await deploy.login().catch( ( e ) => e );
		expect( error ).toBeInstanceOf( GitHubError );
		expect( error.message ).toContain( 'Requires authentication' );
		expect( error.message ).not.toContain( 'ghp_' );
	} );
} );

describe( 'sanitizeGitHubError', () => {
	it( 'strips query strings and header leaks', () => {
		const text = sanitizeGitHubError( {
			status: 422,
			message: 'boom',
			request: { url: 'https://api.github.com/repos/x?access_token=ghp_SECRET' },
			response: { data: { message: 'Validation Failed' } },
		} );
		expect( text ).toContain( '422' );
		expect( text ).toContain( 'Validation Failed' );
		expect( text ).not.toContain( 'ghp_' );
		expect( text ).not.toContain( '?' );
	} );
} );
