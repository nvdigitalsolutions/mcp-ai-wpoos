/**
 * Status monitoring routes (express router wrapper).
 *
 *   POST /api/status/heartbeat      — ingest a signed site heartbeat
 *   GET  /api/status/summary        — fleet summary (all sites)
 *   GET  /api/status/sites/:slug    — single site detail + history
 *   GET  /api/status/history/:slug  — daily uptime for a site
 *   GET  /api/status/metrics        — OpenMetrics exposition (opt-in)
 *
 * Everything under /api is behind authMiddleware; the site slug is always
 * derived from the authenticated token (req.site), never from the payload.
 * The request logic lives in status/handlers.js (express-free, testable).
 */

import { Router } from 'express';
import {
	counters,
	buildSummary,
	handleHeartbeat,
	handleSummary,
	handleSiteDetail,
	handleHistory,
} from '../status/handlers.js';
import { siteConfig } from '../status/config.js';
import { AlertBus } from '../status/alerts.js';
import { renderMetrics } from '../status/metrics.js';

export const statusRouter = Router();

export { counters, buildSummary, handleHeartbeat, handleSummary, handleSiteDetail, handleHistory };

/**
 * Shared alert bus (created once; adapters injected for tests).
 *
 * @param {Object} [cfg] Base config override.
 * @param {Object} [adapters] Injectable adapters (tests).
 * @return {AlertBus} Bus.
 */
export function alertBus( cfg, adapters ) {
	return new AlertBus( cfg || siteConfig( 'default' ), adapters );
}

statusRouter.post( '/heartbeat', ( req, res ) => handleHeartbeat( req, res ) );
statusRouter.get( '/summary', ( req, res ) => handleSummary( req, res ) );
statusRouter.get( '/sites/:slug', ( req, res ) => handleSiteDetail( req, res ) );
statusRouter.get( '/history/:slug', ( req, res ) => handleHistory( req, res ) );

// ── GET /metrics (OpenMetrics, opt-in) ──────────────────────
statusRouter.get( '/metrics', async ( _req, res ) => {
	if ( ! siteConfig( 'default' ).metrics ) {
		return res.status( 503 ).json( { error: 'metrics_not_enabled' } );
	}
	const summary = await buildSummary();
	res.set( 'Content-Type', 'text/plain; version=0.0.4; charset=utf-8' );
	return res.send( renderMetrics( summary, counters ) );
} );
