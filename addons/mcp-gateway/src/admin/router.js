/**
 * Admin & inspection router (Phases 4–5 of Proposal 094).
 *
 * Operator-token-gated surface (`GATEWAY_ADMIN_TOKEN`) exposing:
 *
 *   GET  /admin/policy      Effective policy + quota table (read-only —
 *                           config is env/Git-owned; this surface never
 *                           mutates it).
 *   POST /admin/policy-sim  Replay a candidate policy against the recorded
 *                           call ring buffer (HarrisonCN policy-sim
 *                           pattern) and diff it against the live policy.
 *   GET  /admin/catalog     Fleet catalog: gateway identity + per-site
 *                           manifests with lifecycle states (create →
 *                           test → publish → retire, WSO2-Hub-style).
 *
 * The public /mcp surface never reaches this router; secrets never appear
 * (site tokens and admin tokens are compare-only).
 */

import express from 'express';
import { timingSafeEqual, createHash } from 'crypto';
import { evaluatePolicy, parsePolicy } from '../policy/evaluator.js';
import { recorder } from './recorder.js';
import { siteHealth } from '../routes/health.js';

/**
 * Constant-time digest for token comparison.
 *
 * @param {string} value Value.
 * @return {Buffer} 32-byte digest.
 */
function digest( value ) {
	return createHash( 'sha256' ).update( String( value ) ).digest();
}

/**
 * Operator-token middleware. 401 with no token configured (the admin
 * surface is inert until GATEWAY_ADMIN_TOKEN is set — fail closed).
 *
 * @param {import('express').Request}  req  Request.
 * @param {import('express').Response} res  Response.
 * @param {Function}                   next Next middleware.
 */
export function adminAuth( req, res, next ) {
	const cfg = req.app.get( 'gatewayConfig' );
	if ( ! cfg?.adminToken ) {
		return res.status( 401 ).json( {
			error: 'admin_disabled',
			message: 'Set GATEWAY_ADMIN_TOKEN to enable the admin surface.',
		} );
	}
	const header = String( req.get( 'Authorization' ) || '' );
	const provided = /^Bearer\s+(.+)$/i.exec( header.trim() )?.[ 1 ]?.trim() || '';
	if ( ! provided || ! timingSafeEqual( digest( provided ), digest( cfg.adminToken ) ) ) {
		return res.status( 401 ).json( { error: 'unauthorized', message: 'Invalid operator token.' } );
	}
	return next();
}

/**
 * Serialize the quota table for display (patterns only — no state).
 *
 * @param {Array} quotas Compiled quota rules.
 * @return {object[]} Rows.
 */
function quotaTable( quotas ) {
	return ( quotas || [] ).map( ( rule ) => ( {
		pattern: rule.pattern,
		rate: rule.rate,
		window_seconds: Math.round( rule.windowMs / 1000 ),
		burst: rule.burst,
	} ) );
}

/**
 * Build the admin router.
 *
 * @return {import('express').Router} Router.
 */
export function adminRouter() {
	const router = express.Router();
	router.use( adminAuth );

	// ── Effective policy (read-only) ────────────────────────────────
	router.get( '/policy', ( req, res ) => {
		const cfg = req.app.get( 'gatewayConfig' );
		res.json( {
			policy: cfg.policy || null,
			shadowPolicy: cfg.shadowPolicy || null,
			quotas: quotaTable( cfg.quotas ),
			geoMapKeys: cfg.geoMap ? [ ...cfg.geoMap.keys() ] : [],
			auditSinkConfigured: Boolean( cfg.auditSinkUrl ),
		} );
	} );

	// ── Policy simulation: replay a candidate against recorded calls ──
	router.post( '/policy-sim', ( req, res ) => {
		const errors = [];
		const candidate = parsePolicy(
			'string' === typeof req.body?.policy ? req.body.policy : '',
			errors
		);
		if ( errors.length || ! candidate ) {
			return res.status( 400 ).json( {
				error: 'invalid_policy',
				message: errors.length ? errors.join( ' ' ) : 'Provide a "policy" JSON string in the body.',
			} );
		}

		const cfg = req.app.get( 'gatewayConfig' );
		const calls = recorder.recent();
		const decisions = calls.map( ( entry ) => {
			const live = evaluatePolicy( cfg.policy, {
				tool: entry.tool,
				site: entry.site,
				key: entry.key,
				time: new Date( entry.ts ),
				country: entry.country,
			} );
			const sim = evaluatePolicy( candidate, {
				tool: entry.tool,
				site: entry.site,
				key: entry.key,
				time: new Date( entry.ts ),
				country: entry.country,
			} );
			return {
				tool: entry.tool,
				site: entry.site,
				at: entry.ts,
				live: live,
				simulated: sim,
				changed: live.effect !== sim.effect || live.ruleId !== sim.ruleId,
			};
		} );

		const summary = {
			replayed: calls.length,
			live: tally( decisions.map( ( d ) => d.live.effect ) ),
			simulated: tally( decisions.map( ( d ) => d.simulated.effect ) ),
			changed: decisions.filter( ( d ) => d.changed ).length,
		};

		return res.json( { summary, decisions } );
	} );

	// ── Fleet catalog with lifecycle states ─────────────────────────
	router.get( '/catalog', ( req, res ) => {
		const cfg = req.app.get( 'gatewayConfig' );
		const sites = [];
		for ( const [ slug, site ] of cfg.sites ) {
			const health = siteHealth.get( slug ) || { status: 'ok' };
			sites.push( {
				slug,
				name: `NV oOS site — ${ slug }`,
				url: site.url,
				lifecycle: cfg.siteLifecycle?.get( slug ) || 'published',
				health: health.status,
				lastOkAt: health.lastOkAt || null,
				mcpEndpoint: site.url,
				toolsAreNamespacedAs: `${ slug }_<tool>`,
			} );
		}
		res.json( {
			gateway: {
				name: 'NV oOS Gateway',
				version: req.app.get( 'gatewayVersion' ),
				registryId: 'io.github.nvdigitalsolutions/nvoos-mcp-gateway',
				lifecycle: 'published',
			},
			sites,
		} );
	} );

	return router;
}

/**
 * Count decisions by effect.
 *
 * @param {string[]} effects Effect list.
 * @return {object} `{ allow, deny, require_approval }`.
 */
function tally( effects ) {
	return effects.reduce(
		( acc, effect ) => {
			if ( effect in acc ) {
				acc[ effect ] += 1;
			}
			return acc;
		},
		{ allow: 0, deny: 0, require_approval: 0 }
	);
}
