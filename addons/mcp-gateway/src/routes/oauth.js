/**
 * OAuth well-known routes — public, outside the /api auth gate.
 *
 *   GET /.well-known/oauth-protected-resource        RFC 9728 metadata (root)
 *   GET /.well-known/oauth-protected-resource/mcp    Path-insertion variant
 *
 * Both answer 404 while GATEWAY_OAUTH_ISSUER is unset, so static-key
 * deployments are untouched. Each variant asserts the matching `resource`
 * identifier (RFC 9728 §3.3: the returned `resource` MUST equal the URL the
 * well-known suffix was inserted into).
 */

import express from 'express';
import { oauthEnabled, buildProtectedResourceDocument } from '../oauth/resource-server.js';

/**
 * Build the router.
 *
 * @return {import('express').Router} Router.
 */
export function oauthRouter() {
	const router = express.Router();

	router.get( '/.well-known/oauth-protected-resource', ( req, res ) => {
		const cfg = req.app.get( 'gatewayConfig' );
		if ( ! oauthEnabled( cfg ) ) {
			return res.status( 404 ).json( { error: 'oauth_not_configured' } );
		}
		return res.json( buildProtectedResourceDocument( cfg ) );
	} );

	router.get( '/.well-known/oauth-protected-resource/mcp', ( req, res ) => {
		const cfg = req.app.get( 'gatewayConfig' );
		if ( ! oauthEnabled( cfg ) ) {
			return res.status( 404 ).json( { error: 'oauth_not_configured' } );
		}
		return res.json( buildProtectedResourceDocument( cfg, `${ cfg.oauth.resource.replace( /\/$/, '' ) }/mcp` ) );
	} );

	return router;
}
