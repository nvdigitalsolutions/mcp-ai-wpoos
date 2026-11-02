/**
 * OpenAI plugin domain-verification challenge route.
 *
 * The ChatGPT plugin submission portal proves control of an MCP server's
 * origin by requesting an exact token at:
 *
 *   GET /.well-known/openai-apps-challenge
 *
 * The token is served as plain text (no JSON, no extra characters, no
 * trailing newline) and the route answers 404 while the token is unset, so
 * the surface stays inert until the operator configures it at submission
 * time. Mirrors the site-side contract
 * (`wp_mcp_ai_openai_apps_challenge_token` option in the WP plugin).
 *
 * Not OAuth-gated on purpose: the portal needs this endpoint for both
 * no-auth and OAuth MCP connections; the token itself is the secret.
 */

import express from 'express';

/**
 * Build the router.
 *
 * @return {import('express').Router} Router.
 */
export function challengeRouter() {
	const router = express.Router();

	router.get( '/.well-known/openai-apps-challenge', ( req, res ) => {
		const cfg = req.app.get( 'gatewayConfig' );
		const token = cfg && cfg.openaiChallengeToken ? cfg.openaiChallengeToken : '';

		if ( ! token ) {
			return res.status( 404 ).json( { error: 'challenge_not_configured' } );
		}

		res.setHeader( 'Cache-Control', 'no-store' );
		res.setHeader( 'X-Content-Type-Options', 'nosniff' );
		res.type( 'text/plain' );
		return res.send( token );
	} );

	return router;
}
