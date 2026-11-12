import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createExchangeServer } from '../scripts/serve.mjs';

function config( dir ) {
	return {
		mode: 'mock',
		port: 0,
		dataDir: dir,
		jobsFile: join( dir, 'jobs.jsonl' ),
		sessionSecret: '',
		encryptionKey: 'test-key',
		adminToken: '',
		controlSiteUrl: '',
		rateLimit: { windowMs: 60_000, max: 100 },
		cloudways: {
			email: '',
			apiKey: '',
			baseUrl: 'https://api.cloudways.test/v2',
			legacyBaseUrl: 'https://api.cloudways.test/v1',
			defaultServerId: '1',
			appTimeoutMs: 60_000,
			appPollMs: 1_000,
			timeoutMs: 1_000,
			retries: 0,
		},
		wp: { sshKeyPresent: false, wpManagerEnabled: true },
		github: {
			token: '',
			repo: 'nvdigitalsolutions/nvoos-complete-velocity',
			baseUrl: 'https://api.github.test',
			timeoutMs: 1_000,
		},
		gateway: { baseUrl: 'https://gateway.test', adminKey: '', timeoutMs: 1_000 },
	};
}

test( 'serves health, static, SPA fallback and API envelopes', async () => {
	const dir = await mkdtemp( join( tmpdir(), 'exchange-http-' ) );
	const distDir = join( dir, 'dist' );
	await mkdir( join( distDir, 'assets' ), { recursive: true } );
	await writeFile( join( distDir, 'index.html' ), '<!doctype html><title>Exchange</title>' );
	await writeFile( join( distDir, 'assets', 'app.js' ), 'console.log(1)' );

	const { server } = createExchangeServer( { config: config( dir ), distDir, seeds: [], version: 'test' } );
	await new Promise( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
	const base = `http://127.0.0.1:${ server.address().port }`;

	try {
		const health = await fetch( `${ base }/api/v1/health` );
		assert.equal( health.status, 200 );
		const healthBody = await health.json();
		assert.equal( healthBody.data.mode, 'mock' );
		assert.equal( healthBody.data.version, 'test' );

		const entry = await fetch( `${ base }/` );
		assert.equal( entry.status, 200 );
		assert.match( entry.headers.get( 'content-type' ), /text\/html/ );
		assert.match( entry.headers.get( 'cache-control' ), /no-cache/ );

		const fallback = await fetch( `${ base }/wizard/clinic-ops` );
		assert.equal( fallback.status, 200 );
		assert.match( await fallback.text(), /Exchange/ );

		const asset = await fetch( `${ base }/assets/app.js` );
		assert.equal( asset.status, 200 );
		assert.match( asset.headers.get( 'cache-control' ), /immutable/ );
		assert.match( asset.headers.get( 'content-type' ), /javascript/ );

		const list = await fetch( `${ base }/api/v1/estates` );
		assert.equal( list.status, 200 );
		assert.deepEqual( await list.json(), { ok: true, data: [] } );

		const missing = await fetch( `${ base }/api/v1/nope` );
		assert.equal( missing.status, 404 );
		const missingBody = await missing.json();
		assert.equal( missingBody.error.code, 'not_found' );

		const badJson = await fetch( `${ base }/api/v1/estates`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: '{not json',
		} );
		assert.equal( badJson.status, 400 );
	} finally {
		await new Promise( ( resolve ) => server.close( resolve ) );
		await rm( dir, { recursive: true, force: true } );
	}
} );

test( 'returns 503 when the UI is not built', async () => {
	const dir = await mkdtemp( join( tmpdir(), 'exchange-http-' ) );
	const { server } = createExchangeServer( {
		config: config( dir ),
		distDir: join( dir, 'missing-dist' ),
		seeds: [],
	} );
	await new Promise( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
	const base = `http://127.0.0.1:${ server.address().port }`;
	try {
		const response = await fetch( `${ base }/` );
		assert.equal( response.status, 503 );
		assert.match( await response.text(), /npm run build/ );
	} finally {
		await new Promise( ( resolve ) => server.close( resolve ) );
		await rm( dir, { recursive: true, force: true } );
	}
} );
