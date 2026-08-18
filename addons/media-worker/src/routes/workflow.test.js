/**
 * Tests for the workflow queue processors (v3.1.1).
 *
 * The processors replay queued async jobs through the worker's own sync
 * endpoints; these tests inject a fake HTTP transport and assert the
 * replay contract (endpoint mapping, forced sync mode, site token header).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { processWorkflowJob, ensureWorkflowHandlers } from './workflow.js';

/** Fake transport capturing calls. */
function fakePost( response = { data: { success: true } } ) {
	const calls = [];
	const post = async ( url, body, options ) => {
		calls.push( { url, body, options } );
		return response;
	};
	return { post, calls };
}

test( 'processWorkflowJob routes each job type to its sync endpoint', async () => {
	const { post, calls } = fakePost();

	await processWorkflowJob( { type: 'social-package', data: { site: 'default', title: 'T' } }, { post } );
	await processWorkflowJob( { type: 'brand-assets', data: { site: 'default', brand_name: 'B' } }, { post } );
	await processWorkflowJob( { type: 'video-pipeline', data: { site: 'default', video_url: 'https://example.com/v.mp4' } }, { post } );

	assert.equal( calls.length, 3 );
	assert.ok( calls[ 0 ].url.endsWith( '/api/workflow/social-package' ) );
	assert.ok( calls[ 1 ].url.endsWith( '/api/workflow/brand-assets' ) );
	assert.ok( calls[ 2 ].url.endsWith( '/api/workflow/video-pipeline' ) );
} );

test( 'processWorkflowJob forces sync mode and strips site from the payload', async () => {
	const { post, calls } = fakePost();

	await processWorkflowJob( {
		type: 'social-package',
		data: { site: 'default', title: 'T', callback_url: 'https://cb.example.com/hook' },
	}, { post } );

	assert.equal( calls[ 0 ].body.async_mode, false, 'replayed job never re-enqueues' );
	assert.equal( calls[ 0 ].body.site, undefined, 'site slug is internal only' );
	assert.equal( calls[ 0 ].body.callback_url, 'https://cb.example.com/hook' );
	assert.equal( calls[ 0 ].body.title, 'T' );
} );

test( 'processWorkflowJob sends the site token reconstructed from config', async () => {
	const original = process.env.WORKER_API_TOKEN;
	process.env.WORKER_API_TOKEN = 'single-token-123456789';
	const { post, calls } = fakePost();

	await processWorkflowJob( { type: 'social-package', data: { site: 'default', title: 'T' } }, { post } );

	assert.equal( calls[ 0 ].options.headers[ 'X-Site-Token' ], 'single-token-123456789' );

	if ( original ) {
		process.env.WORKER_API_TOKEN = original;
	} else {
		delete process.env.WORKER_API_TOKEN;
	}
} );

test( 'processWorkflowJob rejects unknown job types', async () => {
	const { post } = fakePost();
	await assert.rejects(
		() => processWorkflowJob( { type: 'no-such-type', data: {} }, { post } ),
		/No workflow endpoint/
	);
} );

test( 'processWorkflowJob propagates transport errors for queue retries', async () => {
	const post = async () => {
		throw new Error( 'connection refused' );
	};
	await assert.rejects(
		() => processWorkflowJob( { type: 'social-package', data: { site: 'default' } }, { post } ),
		/connection refused/
	);
} );

test( 'ensureWorkflowHandlers registers all three types once per queue', () => {
	const registered = [];
	const queue = { process: ( type ) => registered.push( type ) };

	ensureWorkflowHandlers( queue );
	ensureWorkflowHandlers( queue );

	assert.deepEqual( registered.sort(), [ 'brand-assets', 'social-package', 'video-pipeline' ] );
} );
