// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview Credential resolution and output normalisation on the AI video routes.
 *
 * Two defects measured before the P3 port (`Proposal 164 section 164.131`):
 *
 *  - the generate and prediction routes read `process.env.REPLICATE_API_KEY`
 *    directly, so a multi-tenant worker could not serve a site whose key lives
 *    in `SITE_PROVIDER_KEYS`, unlike every image route;
 *  - `/prediction/:id` passed Replicate's `output` through untouched, so a
 *    caller got a string for one model and an array for another, while
 *    `/generate` already flattened it.
 *
 * The provider is stubbed with `node:test`'s mock rather than a fetch stub,
 * because these routes use axios. A succeeded prediction on the create call
 * means the route's internal poll loop never runs.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import express from 'express';
import axios from 'axios';
import sharp from 'sharp';
import { videoRouter } from './video.js';
import { resetProviderKeysCache } from '../utils/provider-keys.js';

/**
 * Start an Express app serving the video router as one site.
 *
 * @param {string} site Site slug the auth stub answers as.
 * @return {Promise<{server: import('http').Server, url: string}>} Server.
 */
async function startServer( site = 'default' ) {
	const app = express();
	app.use( ( req, _res, next ) => {
		req.site = site;
		next();
	} );
	app.use( express.json() );
	app.use( videoRouter );
	const server = app.listen( 0 );
	await new Promise( ( resolve ) => server.once( 'listening', resolve ) );
	return { server, url: `http://127.0.0.1:${ server.address().port }` };
}

/**
 * Run a callback with a fixed provider environment, cache included.
 *
 * The key parser memoises on first use, so every case resets it before and
 * after; otherwise the first test's map would answer for the rest of the file.
 *
 * @param {Object}   env Environment to set; other names are cleared.
 * @param {Function} fn  Test body.
 * @return {Promise<*>} Test body result.
 */
async function withEnv( env, fn ) {
	const names = [ 'SITE_PROVIDER_KEYS', 'REPLICATE_API_KEY' ];
	const saved = {};

	for ( const name of names ) {
		saved[ name ] = process.env[ name ];
		delete process.env[ name ];
	}

	for ( const [ name, value ] of Object.entries( env ) ) {
		process.env[ name ] = value;
	}

	resetProviderKeysCache();

	try {
		return await fn();
	} finally {
		for ( const name of names ) {
			if ( undefined === saved[ name ] ) {
				delete process.env[ name ];
			} else {
				process.env[ name ] = saved[ name ];
			}
		}

		resetProviderKeysCache();
	}
}

/**
 * One succeeded Replicate prediction.
 *
 * @param {*} output The `output` field to answer with.
 * @return {Object} Prediction payload.
 */
function prediction( output ) {
	return {
		id: 'pred-1',
		status: 'succeeded',
		version: 'version-1',
		output,
		error: null,
		metrics: {},
		created_at: '2026-11-28T00:00:00Z',
		completed_at: '2026-11-28T00:01:00Z',
	};
}

test( 'generate uses the key configured for the calling site', async ( t ) => {
	await withEnv(
		{
			SITE_PROVIDER_KEYS: JSON.stringify( { acme: { replicate_api_key: 'r8_acme' } } ),
		},
		async () => {
			let authorization = null;

			t.mock.method( axios, 'post', async ( _url, _body, config ) => {
				authorization = config.headers.Authorization;
				// Succeeded on the create call, so the internal poll loop is skipped.
				return { data: prediction( 'https://cdn.example.test/clip.mp4' ) };
			} );

			const { server, url } = await startServer( 'acme' );
			try {
				const res = await fetch( `${ url }/generate`, {
					method: 'POST',
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify( { prompt: 'waves at dusk', model: 'zeroscope-v2' } ),
				} );

				assert.equal( res.status, 200 );
				const body = await res.json();
				assert.equal( body.video_url, 'https://cdn.example.test/clip.mp4' );
			} finally {
				server.close();
			}

			assert.equal( authorization, 'Bearer r8_acme', 'the site key reached the provider' );
		}
	);
} );

test( 'generate names the missing key instead of reaching for the environment only', async ( t ) => {
	await withEnv( {}, async () => {
		t.mock.method( axios, 'post', async () => {
			throw new Error( 'the provider must not be called without a key' );
		} );

		const { server, url } = await startServer( 'acme' );
		try {
			const res = await fetch( `${ url }/generate`, {
				method: 'POST',
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify( { prompt: 'waves at dusk', model: 'zeroscope-v2' } ),
			} );

			assert.equal( res.status, 503 );
			const body = await res.json();
			assert.equal( body.capability, 'video_generation' );
			assert.equal( body.env_var, 'REPLICATE_API_KEY' );
			assert.equal( body.site, 'acme' );
		} finally {
			server.close();
		}
	} );
} );

test( 'prediction flattens an array output to one URL', async ( t ) => {
	await withEnv( { REPLICATE_API_KEY: 'r8_test' }, async () => {
		t.mock.method( axios, 'get', async () => ( {
			data: prediction( [ 'https://cdn.example.test/first.mp4' ] ),
		} ) );

		const { server, url } = await startServer();
		try {
			const res = await fetch( `${ url }/prediction/pred-1` );

			assert.equal( res.status, 200 );
			const body = await res.json();
			assert.equal( body.status, 'succeeded' );
			assert.equal( body.video_url, 'https://cdn.example.test/first.mp4' );
		} finally {
			server.close();
		}
	} );
} );

test( 'prediction reports a missing output as null while the job runs', async ( t ) => {
	await withEnv( { REPLICATE_API_KEY: 'r8_test' }, async () => {
		t.mock.method( axios, 'get', async () => ( {
			data: { ...prediction( null ), status: 'processing' },
		} ) );

		const { server, url } = await startServer();
		try {
			const res = await fetch( `${ url }/prediction/pred-1` );

			assert.equal( res.status, 200 );
			const body = await res.json();
			assert.equal( body.status, 'processing' );
			assert.equal( body.video_url, null, 'an empty string would read as a URL' );
		} finally {
			server.close();
		}
	} );
} );

test( 'wait false returns a prediction id without polling, in both representations', async ( t ) => {
	await withEnv( { REPLICATE_API_KEY: 'r8_test' }, async () => {
		let posts = 0;

		t.mock.method( axios, 'post', async () => {
			posts++;
			return { data: { id: `pred-${ posts }`, status: 'starting' } };
		} );

		t.mock.method( axios, 'get', async () => {
			throw new Error( 'a submit-only call must not poll' );
		} );

		const { server, url } = await startServer();
		try {
			// A JSON caller sends `false`; a form-ish caller may send the string.
			for ( const wait of [ false, 'false' ] ) {
				const res = await fetch( `${ url }/generate`, {
					method: 'POST',
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify( { prompt: 'waves at dusk', model: 'zeroscope-v2', wait } ),
				} );

				assert.equal( res.status, 200 );
				const body = await res.json();
				assert.equal( body.status, 'starting', 'the create response is passed through' );
				assert.equal( body.video_url, null, 'nothing is claimed before the render finishes' );
				assert.match( body.prediction_id, /^pred-/ );
				assert.match( body.tip, /\/api\/video\/prediction\// );
			}
		} finally {
			server.close();
		}

		assert.equal( posts, 2, 'one create call each, no polling' );
	} );
} );

/**
 * A PNG data URI, large enough that bounding it is observable.
 *
 * @param {number} width  Width in px.
 * @param {number} height Height in px.
 * @return {Promise<string>} Data URI.
 */
async function pngDataUri( width = 2000, height = 1200 ) {
	const bytes = await sharp( { create: { width, height, channels: 3, background: '#336699' } } ).png().toBuffer();
	return `data:image/png;base64,${ bytes.toString( 'base64' ) }`;
}

test( 'an image-conditioned model sends the bounded frame and no text prompt', async ( t ) => {
	await withEnv( { REPLICATE_API_KEY: 'r8_test' }, async () => {
		let sent = null;

		t.mock.method( axios, 'post', async ( _url, body ) => {
			sent = body;
			return { data: prediction( 'https://cdn.example.test/clip.mp4' ) };
		} );

		const { server, url } = await startServer();
		try {
			const res = await fetch( `${ url }/generate`, {
				method: 'POST',
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify( {
					model: 'stable-video-diffusion',
					reference_image: await pngDataUri(),
				} ),
			} );

			assert.equal( res.status, 200, 'a reference frame alone is enough' );
		} finally {
			server.close();
		}

		const input = sent?.input || {};

		assert.ok( String( input.input_image ).startsWith( 'data:image/jpeg;base64,' ), 're-encoded as a bounded JPEG' );
		assert.equal( input.video_length, '14_frames_with_svd', 'the base model has only the 14-frame option' );
		assert.equal( input.frames_per_second, 6, 'SVD default, not the text route 24' );
		assert.equal( input.sizing_strategy, 'maintain_aspect_ratio' );
		assert.equal( undefined, input.prompt, 'the model cannot read a prompt' );
		assert.equal( undefined, input.num_frames );
		assert.equal( undefined, input.width );

		const encoded = String( input.input_image ).split( ',' )[ 1 ];
		const meta = await sharp( Buffer.from( encoded, 'base64' ) ).metadata();
		assert.equal( meta.format, 'jpeg' );
		assert.equal( meta.width, 1024, '2000 px is bounded to 1024' );
		assert.equal( meta.height, 614 );
	} );
} );

test( 'the XT model takes the 25-frame option when the clip is longer', async ( t ) => {
	await withEnv( { REPLICATE_API_KEY: 'r8_test' }, async () => {
		let sent = null;

		t.mock.method( axios, 'post', async ( _url, body ) => {
			sent = body;
			return { data: prediction( 'https://cdn.example.test/clip.mp4' ) };
		} );

		const { server, url } = await startServer();
		try {
			const res = await fetch( `${ url }/generate`, {
				method: 'POST',
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify( {
					model: 'stable-video-diffusion-xt',
					duration: 4,
					reference_image: await pngDataUri( 320, 240 ),
				} ),
			} );

			assert.equal( res.status, 200 );
		} finally {
			server.close();
		}

		assert.equal( sent?.input?.video_length, '25_frames_with_svd_xt' );
	} );
} );

test( 'a text-to-video model refuses a reference image rather than dropping it', async () => {
	await withEnv( { REPLICATE_API_KEY: 'r8_test' }, async () => {
		const { server, url } = await startServer();
		try {
			const res = await fetch( `${ url }/generate`, {
				method: 'POST',
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify( {
					prompt: 'waves',
					model: 'zeroscope-v2',
					reference_image: await pngDataUri( 64, 48 ),
				} ),
			} );

			assert.equal( res.status, 400 );
			const body = await res.json();
			assert.match( body.error, /does not accept a reference image/ );
		} finally {
			server.close();
		}
	} );
} );

test( 'an image-conditioned model refuses a missing reference frame', async () => {
	await withEnv( { REPLICATE_API_KEY: 'r8_test' }, async () => {
		const { server, url } = await startServer();
		try {
			const res = await fetch( `${ url }/generate`, {
				method: 'POST',
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify( { prompt: 'waves', model: 'stable-video-diffusion' } ),
			} );

			assert.equal( res.status, 400 );
			const body = await res.json();
			assert.match( body.error, /needs reference_image/ );
		} finally {
			server.close();
		}
	} );
} );

test( 'a malformed reference frame is refused with a reason', async () => {
	await withEnv( { REPLICATE_API_KEY: 'r8_test' }, async () => {
		const { server, url } = await startServer();
		try {
			const res = await fetch( `${ url }/generate`, {
				method: 'POST',
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify( {
					model: 'stable-video-diffusion',
					reference_image: 'not-a-data-uri',
				} ),
			} );

			assert.equal( res.status, 400 );
			const body = await res.json();
			assert.match( body.error, /must be a data: URI/ );
		} finally {
			server.close();
		}
	} );
} );
