import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createGithubClient, branchNameFor, validateArtifactFiles } from './github.js';

function config( mode = 'live' ) {
	return {
		mode,
		github: {
			token: 'gh-token',
			repo: 'nvdigitalsolutions/nvoos-complete-velocity',
			baseUrl: 'https://api.github.test',
			timeoutMs: 5_000,
		},
	};
}

function jsonResponse( status, body ) {
	return {
		ok: status >= 200 && status < 300,
		status,
		headers: new Headers(),
		text: async () => ( typeof body === 'string' ? body : JSON.stringify( body ) ),
	};
}

test( 'branch names are estate-scoped and validated', () => {
	assert.equal( branchNameFor( 'clinic-ops' ), 'estate/clinic-ops' );
	assert.throws( () => branchNameFor( 'Bad Slug' ), /Invalid estate slug/ );
} );

test( 'artifact files require an index.html and safe paths', () => {
	validateArtifactFiles( [
		{ path: 'index.html', content: '<html></html>' },
		{ path: 'server.js', content: '' },
	] );
	assert.throws(
		() => validateArtifactFiles( [ { path: 'server.js', content: '' } ] ),
		/missing index.html/
	);
	assert.throws(
		() => validateArtifactFiles( [ { path: '../index.html', content: '' } ] ),
		/Unsafe artifact path/
	);
} );

test( 'pushes blobs, a tree, a commit and a new ref', async () => {
	const calls = [];
	const fetchImpl = async ( url, options ) => {
		const method = options.method ?? 'GET';
		calls.push( { url, method } );
		if ( url.includes( '/git/ref/heads/main' ) ) {
			return jsonResponse( 200, { object: { sha: 'base-sha' } } );
		}
		if ( url.includes( '/git/commits/base-sha' ) ) {
			return jsonResponse( 200, { sha: 'base-sha', tree: { sha: 'base-tree' } } );
		}
		if ( url.endsWith( '/git/blobs' ) ) {
			return jsonResponse( 201, { sha: 'blob-sha' } );
		}
		if ( url.endsWith( '/git/trees' ) ) {
			return jsonResponse( 201, { sha: 'tree-sha' } );
		}
		if ( url.endsWith( '/git/commits' ) && method === 'POST' ) {
			return jsonResponse( 201, { sha: 'commit-sha' } );
		}
		if ( url.includes( '/git/refs/heads/estate/clinic-ops' ) && method === 'PATCH' ) {
			return jsonResponse( 404, { message: 'Not Found' } );
		}
		if ( url.endsWith( '/git/refs' ) && method === 'POST' ) {
			return jsonResponse( 201, { ref: 'refs/heads/estate/clinic-ops' } );
		}
		throw new Error( `Unexpected URL ${ method } ${ url }` );
	};

	const client = createGithubClient( { config: config(), fetchImpl } );
	const result = await client.pushEstateBranch( {
		slug: 'clinic-ops',
		files: [
			{ path: 'index.html', content: '<html></html>' },
			{ path: 'server.js', content: 'import( "./scripts/serve.mjs" );' },
		],
	} );

	assert.equal( result.branch, 'estate/clinic-ops' );
	assert.equal( result.commit, 'commit-sha' );
	assert.equal( calls.filter( ( call ) => call.url.endsWith( '/git/blobs' ) ).length, 2 );
	assert.ok( calls.some( ( call ) => call.method === 'POST' && call.url.endsWith( '/git/refs' ) ) );
} );

test( 'mock mode short-circuits with a deterministic commit', async () => {
	const client = createGithubClient( { config: config( 'mock' ) } );
	const result = await client.pushEstateBranch( {
		slug: 'clinic-ops',
		files: [ { path: 'index.html', content: '<html></html>' } ],
	} );
	assert.deepEqual( result, { branch: 'estate/clinic-ops', commit: 'mock-commit-sha' } );
} );
