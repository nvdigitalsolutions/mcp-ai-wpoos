/**
 * Tests for the scheduled-publish queue processor (v3.1.1).
 *
 * The processor replays due scheduled jobs through the worker's own
 * /api/social/post endpoint; these tests inject a fake HTTP transport and
 * assert the replay contract (schedule stripped, site token header).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { processScheduledPublish, ensureSocialHandler } from './social.js';

/** Fake transport capturing calls. */
function fakePost( response = { data: { success: true } } ) {
	const calls = [];
	const post = async ( url, body, options ) => {
		calls.push( { url, body, options } );
		return response;
	};
	return { post, calls };
}

test( 'processScheduledPublish replays to /api/social/post without the schedule', async () => {
	const { post, calls } = fakePost();

	await processScheduledPublish( {
		type: 'publish',
		data: {
			site: 'default',
			platform: 'twitter',
			content: 'Hello',
			media_url: null,
			hashtags: [ 'oOS' ],
			scheduled_for: '2026-08-18T12:00:00Z',
		},
	}, { post } );

	assert.equal( calls.length, 1 );
	assert.ok( calls[ 0 ].url.endsWith( '/api/social/post' ) );
	assert.deepEqual( calls[ 0 ].body, { platform: 'twitter', content: 'Hello', media_url: null, hashtags: [ 'oOS' ] } );
} );

test( 'processScheduledPublish sends the site token reconstructed from config', async () => {
	const original = process.env.WORKER_API_TOKEN;
	process.env.WORKER_API_TOKEN = 'single-token-123456789';
	const { post, calls } = fakePost();

	await processScheduledPublish( { type: 'publish', data: { site: 'default', platform: 'twitter', content: 'Hi' } }, { post } );

	assert.equal( calls[ 0 ].options.headers[ 'X-Site-Token' ], 'single-token-123456789' );

	if ( original ) {
		process.env.WORKER_API_TOKEN = original;
	} else {
		delete process.env.WORKER_API_TOKEN;
	}
} );

test( 'processScheduledPublish propagates provider failures for queue accounting', async () => {
	const post = async () => {
		const err = new Error( 'Twitter API error' );
		err.response = { status: 502 };
		throw err;
	};
	await assert.rejects(
		() => processScheduledPublish( { type: 'publish', data: { site: 'default' } }, { post } ),
		/Twitter API error/
	);
} );

test( 'ensureSocialHandler registers the publish type once per queue', () => {
	const registered = [];
	const queue = { process: ( type ) => registered.push( type ) };

	ensureSocialHandler( queue );
	ensureSocialHandler( queue );

	assert.deepEqual( registered, [ 'publish' ] );
} );
