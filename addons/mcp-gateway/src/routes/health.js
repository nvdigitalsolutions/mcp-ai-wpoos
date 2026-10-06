/**
 * Health endpoints.
 *
 * GET /health      — public, minimal: uptime monitors and directory
 *                    reviewers need nothing more.
 * GET /health/full — auth-gated: per-site health registry, version, node
 *                    version, key count (no secrets, host-only URLs).
 */

import express from 'express';
import { authMiddleware } from '../middleware/auth.js';

/**
 * Per-site health registry updated by the MCP router on upstream
 * success/failure. `degraded` entries carry the last error and timestamp.
 *
 * @type {Map<string, {status: string, lastError?: string, lastErrorAt?: string, lastOkAt?: string}>}
 */
export const siteHealth = new Map();

/**
 * Record an upstream outcome for a site slug.
 *
 * @param {string} slug   Site slug.
 * @param {boolean} ok    Whether the upstream call succeeded.
 * @param {string} error  Error detail when !ok.
 */
export function recordSiteHealth( slug, ok, error = '' ) {
	const now = new Date().toISOString();
	const entry = siteHealth.get( slug ) || { status: 'ok' };
	if ( ok ) {
		entry.status = 'ok';
		entry.lastOkAt = now;
		delete entry.lastError;
		delete entry.lastErrorAt;
	} else {
		entry.status = 'degraded';
		entry.lastError = String( error ).slice( 0, 300 );
		entry.lastErrorAt = now;
	}
	siteHealth.set( slug, entry );
}

/**
 * Build the health router.
 *
 * @return {import('express').Router} Router.
 */
export function healthRouter() {
	const router = express.Router();

	// ── Public, minimal ────────────────────────────────────────────
	router.get( '/health', ( _req, res ) => {
		const cfg = res.app.get( 'gatewayConfig' );
		const sites = {};
		for ( const [ slug, entry ] of siteHealth ) {
			sites[ slug ] = entry.status;
		}
		for ( const slug of cfg.sites.keys() ) {
			if ( ! ( slug in sites ) ) {
				sites[ slug ] = 'ok'; // never contacted yet
			}
		}
		res.json( {
			status: Object.values( sites ).some( ( s ) => 'degraded' === s ) ? 'degraded' : 'ok',
			service: 'design-mcp-gateway',
			version: res.app.get( 'gatewayVersion' ),
			uptime: process.uptime(),
			sites,
		} );
	} );

	// ── Auth-gated, full ───────────────────────────────────────────
	router.get( '/health/full', authMiddleware, ( req, res ) => {
		const cfg = res.app.get( 'gatewayConfig' );
		const sites = {};
		for ( const [ slug, site ] of cfg.sites ) {
			const entry = siteHealth.get( slug ) || { status: 'ok' };
			sites[ slug ] = {
				status: entry.status,
				url: new URL( site.url ).host,
				lastOkAt: entry.lastOkAt || null,
				lastErrorAt: entry.lastErrorAt || null,
				lastError: entry.lastError || null,
			};
		}
		res.json( {
			status: 'ok',
			service: 'design-mcp-gateway',
			version: res.app.get( 'gatewayVersion' ),
			node: process.version,
			uptime: process.uptime(),
			keys: cfg.keys.size,
			sites,
			// The authenticated key's own bindings — public-key ids only.
			bindings: req.gatewaySites || [],
		} );
	} );

	return router;
}
