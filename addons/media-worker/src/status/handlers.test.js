/**
 * Tests for the status request handlers (fake req/res, in-memory store).
 *
 * The store falls back to memory when Redis is unreachable; the test pins
 * REDIS_URL to an unused local port so the fallback is immediate and
 * deterministic.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

process.env.REDIS_URL = 'redis://127.0.0.1:6399';
process.env.STATUS_ENABLED = '1';

const { handleHeartbeat, handleSummary, handleSiteDetail, handleHistory } = await import( './handlers.js' );

/** Minimal mock Express response. */
function mockRes() {
	return {
		statusCode: 200,
		body: null,
		headers: {},
		status( code ) {
			this.statusCode = code;
			return this;
		},
		json( payload ) {
			this.body = payload;
			return this;
		},
		set( name, value ) {
			this.headers[ name ] = value;
			return this;
		},
		send( payload ) {
			this.body = payload;
			return this;
		},
	};
}

function mockReq( { site = 'site-a', body = {}, params = {}, query = {} } = {} ) {
	return { site, siteUrl: 'https://site-a.example.com', body, params, query };
}

const VALID_BODY = {
	v: 1,
	site_url: 'https://site-a.example.com',
	sent_at: Math.floor( Date.now() / 1000 ),
	checks: { overall: 'operational', components: {} },
	meta: { wp_version: '6.7.2', plugin_version: '1.1.92' },
};

test( 'heartbeat rejects an invalid payload with 400', async () => {
	const res = mockRes();
	await handleHeartbeat( mockReq( { body: { nope: true } } ), res );
	assert.equal( res.statusCode, 400 );
	assert.equal( res.body.ok, undefined );
	assert.match( res.body.error, /version/i );
} );

test( 'heartbeat accepts a valid payload and answers server_time', async () => {
	const res = mockRes();
	await handleHeartbeat( mockReq( { body: VALID_BODY } ), res );
	assert.equal( res.statusCode, 200 );
	assert.equal( res.body.ok, true );
	assert.equal( typeof res.body.server_time, 'number' );
} );

test( 'summary lists the site with an operational status', async () => {
	const res = mockRes();
	await handleSummary( mockReq(), res );
	assert.equal( res.statusCode, 200 );
	assert.equal( res.body.overall_status, 'operational' );
	assert.equal( res.body.sites.length, 1 );
	assert.equal( res.body.sites[ 0 ].slug, 'site-a' );
	assert.equal( res.body.sites[ 0 ].status, 'operational' );
} );

test( 'site detail returns the stored record for a known slug', async () => {
	const res = mockRes();
	await handleSiteDetail( mockReq( { params: { slug: 'site-a' } } ), res );
	assert.equal( res.statusCode, 200 );
	assert.equal( res.body.slug, 'site-a' );
	assert.equal( res.body.meta.wp_version, '6.7.2' );
} );

test( 'site detail answers 404 for an unknown slug', async () => {
	const res = mockRes();
	await handleSiteDetail( mockReq( { params: { slug: 'ghost' } } ), res );
	assert.equal( res.statusCode, 404 );
	assert.equal( res.body.error, 'site_not_found' );
} );

test( 'history returns daily uptime for a known slug', async () => {
	const res = mockRes();
	await handleHistory( mockReq( { params: { slug: 'site-a' }, query: { days: '7' } } ), res );
	assert.equal( res.statusCode, 200 );
	assert.equal( res.body.slug, 'site-a' );
	assert.equal( res.body.days, 7 );
	assert.equal( typeof res.body.overall_uptime, 'number' );
} );
