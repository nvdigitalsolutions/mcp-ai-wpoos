/**
 * GitHub client for per-estate console branches (ADR-05).
 *
 * One mirror repo, one branch per estate (`estate/<slug>`), force-updated.
 * The branch tree contains the prebuilt console artifact only. The API
 * sequence is the standard Git Data flow: blobs → tree → commit → ref.
 */

import { ApiError } from '../util/envelope.js';
import { requestJson } from '../util/http.js';

const BRANCH_RE = /^estate\/[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/;
const FILE_PATH_RE = /^[A-Za-z0-9._/-]+$/;

export function branchNameFor( slug ) {
	const branch = `estate/${ slug }`;
	if ( ! BRANCH_RE.test( branch ) ) {
		throw new ApiError( 'bad_request', 'Invalid estate slug for a branch name', { field: 'slug' }, 400 );
	}
	return branch;
}

export function validateArtifactFiles( files ) {
	if ( ! Array.isArray( files ) || files.length === 0 ) {
		throw new ApiError( 'bad_request', 'Artifact has no files', {}, 400 );
	}
	const paths = [];
	for ( const file of files ) {
		if ( ! file || typeof file.path !== 'string' || typeof file.content !== 'string' ) {
			throw new ApiError( 'bad_request', 'Artifact file must have path and content', {}, 400 );
		}
		if ( ! FILE_PATH_RE.test( file.path ) || file.path.includes( '..' ) || file.path.startsWith( '/' ) ) {
			throw new ApiError( 'bad_request', `Unsafe artifact path: ${ file.path }`, {}, 400 );
		}
		paths.push( file.path );
	}
	if ( ! paths.includes( 'index.html' ) ) {
		throw new ApiError( 'bad_request', 'Artifact is missing index.html', {}, 400 );
	}
	return files;
}

export function createGithubClient( { config, fetchImpl = globalThis.fetch } ) {
	const { github, mode } = config;

	async function gh( path, { method = 'GET', body } = {} ) {
		if ( mode === 'mock' ) {
			return { data: mockResponse( path, method ) };
		}
		const response = await requestJson( `${ github.baseUrl }${ path }`, {
			method,
			headers: {
				Authorization: `Bearer ${ github.token }`,
				Accept: 'application/vnd.github+json',
				'User-Agent': 'nvoos-service-exchange',
				'X-GitHub-Api-Version': '2022-11-28',
			},
			body,
			timeoutMs: github.timeoutMs,
			retries: 1,
			fetchImpl,
		} );
		if ( ! response.ok ) {
			throw new ApiError(
				'provider_error',
				`GitHub ${ method } ${ path } failed (${ response.status })`,
				{ provider: 'github', status: response.status },
				502
			);
		}
		return { data: response.data };
	}

	function mockResponse( path, method ) {
		if ( path.includes( '/git/ref/heads/' ) ) {
			return { object: { sha: 'mock-base-sha' } };
		}
		if ( path.includes( '/git/commits/' ) && method === 'GET' ) {
			return { sha: 'mock-base-sha', tree: { sha: 'mock-base-tree' } };
		}
		if ( path.endsWith( '/git/blobs' ) ) {
			return { sha: 'mock-blob-sha' };
		}
		if ( path.endsWith( '/git/trees' ) ) {
			return { sha: 'mock-tree-sha' };
		}
		if ( path.endsWith( '/git/commits' ) && method === 'POST' ) {
			return { sha: 'mock-commit-sha' };
		}
		return { ok: true };
	}

	/**
	 * Force-push an artifact tree to `estate/<slug>`.
	 *
	 * @param {object} input
	 * @param {string} input.slug
	 * @param {Array<{path:string, content:string}>} input.files
	 * @param {string} [input.base] Base branch (default main).
	 * @param {string} [input.message]
	 * @return {Promise<{branch:string, commit:string}>}
	 */
	async function pushEstateBranch( { slug, files, base = 'main', message } ) {
		const branch = branchNameFor( slug );
		validateArtifactFiles( files );

		if ( mode === 'mock' ) {
			return { branch, commit: 'mock-commit-sha' };
		}

		const repo = github.repo;
		const ref = await gh( `/repos/${ repo }/git/ref/heads/${ base }` );
		const baseSha = ref.data?.object?.sha;
		if ( ! baseSha ) {
			throw new ApiError( 'provider_error', 'Could not resolve the console mirror base branch', { provider: 'github' }, 502 );
		}

		const parent = await gh( `/repos/${ repo }/git/commits/${ baseSha }` );
		const baseTree = parent.data?.tree?.sha;
		if ( ! baseTree ) {
			throw new ApiError( 'provider_error', 'Could not resolve the base tree', { provider: 'github' }, 502 );
		}

		const tree = [];
		for ( const file of files ) {
			const blob = await gh( `/repos/${ repo }/git/blobs`, {
				method: 'POST',
				body: { content: Buffer.from( file.content, 'utf8' ).toString( 'base64' ), encoding: 'base64' },
			} );
			tree.push( { path: file.path, mode: '100644', type: 'blob', sha: blob.data.sha } );
		}

		const newTree = await gh( `/repos/${ repo }/git/trees`, {
			method: 'POST',
			body: { base_tree: baseTree, tree },
		} );

		const newCommit = await gh( `/repos/${ repo }/git/commits`, {
			method: 'POST',
			body: {
				message: message ?? `Estate ${ slug } console artifact`,
				tree: newTree.data.sha,
				parents: [ baseSha ],
			},
		} );

		const refPath = `/repos/${ repo }/git/refs/heads/${ branch }`;
		try {
			await gh( refPath, { method: 'PATCH', body: { sha: newCommit.data.sha, force: true } } );
		} catch ( error ) {
			if ( error?.extra?.status !== 404 ) {
				throw error;
			}
			await gh( `/repos/${ repo }/git/refs`, {
				method: 'POST',
				body: { ref: `refs/heads/${ branch }`, sha: newCommit.data.sha },
			} );
		}

		return { branch, commit: newCommit.data.sha };
	}

	return { pushEstateBranch, branchNameFor };
}
