/**
 * MCP route — the gateway's core.
 *
 * GET  /mcp — public discovery JSON (streamable HTTP, MCP 2026-07-28).
 * POST /mcp — JSON-RPC 2.0 dispatch:
 *
 *   - `initialize` / `ping`          answered gateway-level (stateless).
 *   - `notifications/*`              forwarded to every bound site, no reply.
 *   - `tools/list`                   fan-out to bound sites; tool names
 *                                    prefixed `<site-slug>.`; a failing site
 *                                    degrades to _meta instead of failing
 *                                    the whole list.
 *   - `tools/call`                   reverse-maps the prefix, forwards to the
 *                                    owning site, passes the response through
 *                                    unchanged.
 *   - `resources/*`, `prompts/*`,
 *     `completion/complete`          forwarded to the first bound site
 *                                    (single-site semantics; documented
 *                                    limitation of v1 aggregation).
 *
 * Stateless by design (no sessions, no SSE in v1) — the July 2026 spec
 * revision makes stateless transport first-class, and a proxy adds nothing
 * by holding state.
 */

import express from 'express';
import { callUpstream } from '../utils/upstream.js';
import { prefixTool, splitToolName, isNamespacedFor } from '../utils/namespacer.js';
import { recordSiteHealth } from './health.js';
import { consumeToolQuota, quotaHeaders } from '../quota/check.js';
import { createQuotaStore } from '../quota/token-bucket.js';
import { evaluatePolicy } from '../policy/evaluator.js';
import { resolveCountry } from '../policy/geo.js';
import { emitAudit } from '../audit/sink.js';
import { recorder } from '../admin/recorder.js';

const PROTOCOL_VERSION = '2026-07-28';
const UPSTREAM_TIMEOUT_MS = Number( process.env.UPSTREAM_TIMEOUT_MS ) || 20000;

/** Module-level quota store (scoped per key × site × tool). */
const quotaStore = createQuotaStore();

/**
 * Timeout budget for tools/call proxying (read per call so tests and ops can
 * tune it without a restart). Long-running tools (deep_research at
 * standard/comprehensive depth, background-style generations) outlive the
 * default list/notification budget; tools/list keeps the snappy default so
 * one slow site never stalls discovery for every key.
 *
 * @return {number} Effective tool-call timeout in ms.
 */
function toolCallTimeoutMs() {
	const value = Number( process.env.UPSTREAM_TOOL_TIMEOUT_MS );
	return Number.isFinite( value ) && value > 0 ? value : UPSTREAM_TIMEOUT_MS;
}

/**
 * Description hints appended to known long-running tools in tools/list, so
 * clients can self-select background execution before hitting client-side
 * tool timeouts. Keyed by the unprefixed upstream tool slug.
 */
const TOOL_DESCRIPTION_HINTS = {
	deep_research:
		' Long-running: for standard/comprehensive depth prefer run_mode: "background" (returns immediately; results are cached for one hour) — immediate mode may exceed client-side tool timeouts.',
};

/**
 * Protocol versions this gateway can speak, newest first. Mirrors the
 * plugin's own MCP negotiation list so every NV oOS surface agrees.
 */
const SUPPORTED_PROTOCOL_VERSIONS = [
	PROTOCOL_VERSION,
	'2025-06-18',
	'2025-03-26',
	'2024-11-05',
];

/**
 * Negotiate the protocol version for an initialize handshake.
 *
 * Clients (Zed, Claude Desktop, Cursor) declare the newest version they
 * support in `protocolVersion` and, on newer SDKs, may also send the full
 * `supportedProtocolVersions` list. Answering with a version the client did
 * not offer makes strict clients abort the connection with "Unsupported
 * protocol version" — so the gateway echoes the highest mutually supported
 * version, defaulting to the oldest supported dialect (2024-11-05) when the
 * client provides no version information or nothing matches. Semantics
 * mirror the WordPress plugin's `negotiate_protocol_version()`.
 *
 * @param {object} params Client's initialize params.
 * @return {string} Negotiated protocol version.
 */
function negotiateProtocolVersion( params ) {
	const clientVersions = [];

	if ( 'string' === typeof params?.protocolVersion ) {
		clientVersions.push( params.protocolVersion );
	}
	if ( Array.isArray( params?.supportedProtocolVersions ) ) {
		for ( const version of params.supportedProtocolVersions ) {
			if ( 'string' === typeof version ) {
				clientVersions.push( version );
			}
		}
	}

	const unique = [ ...new Set( clientVersions ) ];
	if ( 0 === unique.length ) {
		return '2024-11-05';
	}

	for ( const serverVersion of SUPPORTED_PROTOCOL_VERSIONS ) {
		if ( unique.includes( serverVersion ) ) {
			return serverVersion;
		}
	}

	return '2024-11-05';
}

/**
 * Evaluate the live policy (and the shadow policy, log-only) for a tool
 * call.
 *
 * @param {object}  ctx      Request context.
 * @param {string}  slug     Target site slug.
 * @param {string}  toolName Unprefixed tool slug.
 * @param {object}  [opts]   `shadow: true` to also evaluate + log the
 *                           shadow policy (tools/call only — list filtering
 *                           stays quiet).
 * @return {{effect: string, ruleId: string|null, input: object}} Decision.
 */
function policyDecision( ctx, slug, toolName, opts = {} ) {
	const input = {
		tool: toolName,
		site: slug,
		key: ctx.req.gatewayKeyId || 'unknown',
		time: new Date(),
		country: resolveCountry( ctx.req, ctx.cfg ),
	};
	const decision = evaluatePolicy( ctx.cfg.policy, input );

	// Shadow policy: evaluate + log, never enforce (Proposal 094, Phase 4).
	if ( opts.shadow && ctx.cfg.shadowPolicy?.rules?.length ) {
		const shadow = evaluatePolicy( ctx.cfg.shadowPolicy, input );
		if ( 'allow' !== shadow.effect ) {
			console.warn(
				JSON.stringify( {
					ts: new Date().toISOString(),
					traceId: ctx.req.traceId || null,
					kind: 'shadow_policy',
					ruleId: shadow.ruleId,
					effect: shadow.effect,
					tool: toolName,
					site: slug,
				} )
			);
		}
	}

	return { ...decision, input };
}

/**
 * JSON-RPC error envelope factory.
 *
 * @param {*} id      Request id (null for parse errors).
 * @param {number} code    JSON-RPC error code.
 * @param {string} message Error message.
 * @return {object} Response envelope.
 */
function rpcError( id, code, message ) {
	return { jsonrpc: '2.0', id, error: { code, message } };
}

/**
 * Gateway-level initialize response (stateless, mirrors the upstream sites'
 * discovery surface without leaking bound-site names). The protocol version
 * is negotiated with the client instead of being hardcoded, so strict
 * clients (Zed, Claude Desktop) do not abort on an unsupported version.
 *
 * @param {object} req    Express request.
 * @param {*}      id     Request id.
 * @param {object} params Client's initialize params.
 * @return {object} Response envelope.
 */
function respondInitialize( req, id, params ) {
	return {
		jsonrpc: '2.0',
		id,
		result: {
			protocolVersion: negotiateProtocolVersion( params ),
			capabilities: {
				tools: { listChanged: true },
				resources: { subscribe: false, listChanged: true },
				prompts: { listChanged: true },
			},
			serverInfo: {
				name: 'NV oOS Gateway',
				version: req.app.get( 'gatewayVersion' ),
			},
			instructions:
				'Tools are namespaced as <site-slug>.<tool>. Call tools/list to see the surface bound to your key.',
		},
	};
}

/**
 * Fan out a notification to every bound site (no reply, best effort).
 *
 * @param {object} ctx Request context: `{ req, sites }`.
 * @param {object} payload JSON-RPC notification.
 * @return {Promise<void>}
 */
async function forwardNotification( ctx, payload ) {
	await Promise.allSettled(
		ctx.sites.map( async ( slug ) => {
			const site = ctx.cfg.sites.get( slug );
			const outcome = await callUpstream(
				site,
				payload,
				UPSTREAM_TIMEOUT_MS,
				{ traceparent: ctx.req.traceparent }
			);
			recordSiteHealth( slug, outcome.ok, outcome.error || '' );
		} )
	);
}

/**
 * tools/list: parallel fan-out with graceful degradation.
 *
 * @param {object} ctx Request context.
 * @param {*}      id  Request id.
 * @return {Promise<object>} Response envelope.
 */
async function listTools( ctx, id ) {
	const results = await Promise.allSettled(
		ctx.sites.map( async ( slug ) => {
			const site = ctx.cfg.sites.get( slug );
			const outcome = await callUpstream(
				site,
				{ jsonrpc: '2.0', id, method: 'tools/list', params: {} },
				UPSTREAM_TIMEOUT_MS,
				{ traceparent: ctx.req.traceparent }
			);
			recordSiteHealth( slug, outcome.ok, outcome.error || '' );
			return { slug, outcome };
		} )
	);

	const tools = [];
	const errors = {};
	for ( const result of results ) {
		if ( 'rejected' === result.status ) {
			errors[ result.reason?.slug || 'unknown' ] = 'fan-out failed';
			continue;
		}
		const { slug, outcome } = result.value;
		if ( ! outcome.ok || ! outcome.data || ! Array.isArray( outcome.data?.result?.tools ) ) {
			errors[ slug ] = outcome.error || `upstream error (${ outcome.status })`;
			continue;
		}
		for ( const tool of outcome.data.result.tools ) {
			if ( tool && 'string' === typeof tool.name ) {
				// Catalog filtering: tools the policy denies for this key never
				// appear in tools/list (kept out of model context).
				if ( ctx.cfg.policy?.rules?.length && 'deny' === policyDecision( ctx, slug, tool.name ).effect ) {
					continue;
				}
				const prefixed = prefixTool( slug, tool );
				const hint = TOOL_DESCRIPTION_HINTS[ tool.name ];
				if ( hint && 'string' === typeof prefixed.description ) {
					prefixed.description += hint;
				}
				tools.push( prefixed );
			}
		}
	}

	const meta = { gateway: { sites: [ ...ctx.sites ] } };
	if ( Object.keys( errors ).length ) {
		meta.gateway.errors = errors;
	}

	return { jsonrpc: '2.0', id, result: { tools, _meta: meta } };
}

/**
 * Forward a request to one site and pass the upstream JSON-RPC envelope
 * through unchanged (results are never re-written by the gateway).
 *
 * @param {object} ctx    Request context.
 * @param {string} slug   Target site slug.
 * @param {object} payload Full JSON-RPC request (original id preserved).
 * @param {number} [timeoutMs] Upstream budget (defaults to the shared list budget).
 * @return {Promise<object>} Upstream envelope, or a gateway error envelope.
 */
async function proxyToSite( ctx, slug, payload, timeoutMs = UPSTREAM_TIMEOUT_MS ) {
	const site = ctx.cfg.sites.get( slug );
	if ( ! site ) {
		return rpcError( payload.id ?? null, -32602, `Unknown site slug "${ slug }".` );
	}
	const outcome = await callUpstream(
		site,
		payload,
		timeoutMs,
		{ traceparent: ctx.req.traceparent }
	);
	recordSiteHealth( slug, outcome.ok, outcome.error || '' );
	if ( ! outcome.ok ) {
		return rpcError(
			payload.id ?? null,
			-32603,
			`Upstream site "${ slug }" failed: ${ outcome.error || `HTTP ${ outcome.status }` }`
		);
	}
	if ( null === outcome.data ) {
		return { jsonrpc: '2.0', id: payload.id ?? null, result: {} };
	}
	return outcome.data;
}

/**
 * Dispatch one JSON-RPC message.
 *
 * @param {object} ctx Request context.
 * @param {object} msg JSON-RPC request/notification.
 * @return {Promise<object|null>} Response envelope, or null for
 *                                notifications.
 */
async function dispatch( ctx, msg ) {
	if ( ! msg || 'object' !== typeof msg || '2.0' !== msg.jsonrpc ) {
		return rpcError( null, -32600, 'Invalid Request: expected a JSON-RPC 2.0 object.' );
	}

	const id = undefined === msg.id ? null : msg.id;
	const method = String( msg.method || '' );
	const params = msg.params || {};

	// Notifications: no response, ever.
	if ( null === id ) {
		if ( method.startsWith( 'notifications/' ) ) {
			await forwardNotification( ctx, msg );
		}
		return null;
	}

	switch ( method ) {
		case 'initialize':
			return respondInitialize( ctx.req, id, params );

		case 'ping':
			return { jsonrpc: '2.0', id, result: {} };

		case 'tools/list':
			return listTools( ctx, id );

		case 'tools/call': {
			const name = params?.name;
			if ( 'string' !== typeof name || ! name ) {
				return rpcError( id, -32602, 'tools/call requires a "name".' );
			}
			// A prefixed name bound to one of this key's sites always wins
			// (tools/list returns prefixed names, so callbacks use them). Bare
			// names are accepted only in single-site mode (one bound site).
			const boundSlugs = new Set( ctx.sites );
			let slug;
			let toolName = name;
			if ( isNamespacedFor( name, boundSlugs ) ) {
				const parts = splitToolName( name );
				slug = parts.slug;
				toolName = parts.tool;
			} else if ( 1 === ctx.sites.length ) {
				slug = ctx.sites[ 0 ];
			} else {
				return rpcError(
					id,
					-32602,
					`Tool name must be namespaced "<site-slug>.<tool>" — this key is bound to: ${ ctx.sites.join( ', ' ) }.`
				);
			}

			// Record the call attempt for policy simulation (identity +
			// argument KEYS only — never values).
			recorder.record( {
				ts: new Date().toISOString(),
				tool: toolName,
				site: slug,
				key: ctx.req.gatewayKeyId || 'unknown',
				country: resolveCountry( ctx.req, ctx.cfg ),
				argsKeys: Object.keys( params?.arguments || {} ),
				traceId: ctx.req.traceId || null,
			} );

			// dryRun: evaluate everything, execute nothing (Phase 4).
			if ( true === params?.dryRun ) {
				const dryDecision = policyDecision( ctx, slug, toolName, { shadow: true } );
				const dryQuota = consumeToolQuota(
					quotaStore,
					ctx.cfg.quotas || [],
					ctx.req.gatewayKeyId || 'unknown',
					slug,
					toolName,
					Date.now(),
					true
				);
				return {
					jsonrpc: '2.0',
					id,
					result: {
						dryRun: true,
						would_execute: 'allow' === dryDecision.effect && dryQuota.allowed,
						policy: { effect: dryDecision.effect, ruleId: dryDecision.ruleId },
						quota: dryQuota.scoped
							? { limit: dryQuota.limit, remaining: dryQuota.remaining, reset_seconds: dryQuota.resetSeconds }
							: null,
					},
				};
			}

			// Fleet per-tool quota (Phase 1) — additive to the group
			// budgets: the gateway ceiling never weakens site enforcement.
			if ( ctx.cfg.quotas?.length ) {
				const quota = consumeToolQuota(
					quotaStore,
					ctx.cfg.quotas,
					ctx.req.gatewayKeyId || 'unknown',
					slug,
					toolName
				);
				if ( ! quota.allowed ) {
					void emitAudit( ctx.cfg, {
						kind: 'quota_exceeded',
						traceId: ctx.req.traceId || null,
						key: ctx.req.gatewayKeyId || 'unknown',
						site: slug,
						tool: toolName,
						limit: quota.limit,
						window_seconds: quota.windowSeconds,
					} );
					return {
						jsonrpc: '2.0',
						id,
						error: {
							code: -32099,
							message: 'Rate limit exceeded',
							data: {
								requests_per_window: quota.limit,
								window_seconds: quota.windowSeconds,
								retry_after_seconds: quota.resetSeconds,
							},
						},
						gateway: { quota },
					};
				}
			}

			// Policy evaluation (Phase 2) — deny and require_approval both
			// short-circuit before upstream dispatch.
			const decision = policyDecision( ctx, slug, toolName, { shadow: true } );
			if ( 'deny' === decision.effect ) {
				void emitAudit( ctx.cfg, {
					kind: 'deny',
					traceId: ctx.req.traceId || null,
					key: ctx.req.gatewayKeyId || 'unknown',
					site: slug,
					tool: toolName,
					ruleId: decision.ruleId,
				} );
				return {
					jsonrpc: '2.0',
					id,
					error: {
						code: -32001,
						message: 'Tool execution denied by gateway policy',
						data: { policy_id: decision.ruleId },
					},
					gateway: { policy: { effect: 'deny', ruleId: decision.ruleId } },
					statusCode: 403,
				};
			}
			if ( 'require_approval' === decision.effect ) {
				void emitAudit( ctx.cfg, {
					kind: 'require_approval',
					traceId: ctx.req.traceId || null,
					key: ctx.req.gatewayKeyId || 'unknown',
					site: slug,
					tool: toolName,
					ruleId: decision.ruleId,
				} );
				return {
					jsonrpc: '2.0',
					id,
					error: {
						code: -32002,
						message: 'Tool call requires approval',
						data: {
							policy_id: decision.ruleId,
							preview: {
								tool: name,
								site: slug,
								argument_keys: Object.keys( params?.arguments || {} ),
							},
						},
					},
					gateway: { policy: { effect: 'require_approval', ruleId: decision.ruleId } },
					statusCode: 428,
				};
			}

			const envelope = await proxyToSite(
				ctx,
				slug,
				{ jsonrpc: '2.0', id, method, params: { ...params, name: toolName } },
				toolCallTimeoutMs()
			);
			void emitAudit( ctx.cfg, {
				kind: 'call_ok',
				traceId: ctx.req.traceId || null,
				key: ctx.req.gatewayKeyId || 'unknown',
				site: slug,
				tool: toolName,
				upstream_ok: ! envelope?.error,
			} );
			return envelope;
		}

		case 'resources/list':
		case 'resources/read':
		case 'resources/templates/list':
		case 'prompts/list':
		case 'prompts/get':
		case 'completion/complete':
			// v1 aggregation limitation: resource/prompt surfaces use the
			// first bound site (documented in the README).
			return proxyToSite( ctx, ctx.sites[ 0 ], msg );

		default:
			return rpcError( id, -32601, `Method not found: ${ method }` );
	}
}

/**
 * Public discovery handler (mounted before the auth gate in index.js).
 *
 * @param {import('express').Request}  req Request.
 * @param {import('express').Response} res Response.
 */
export function discoveryHandler( req, res ) {
	res.json( {
		name: 'NV oOS Gateway',
		version: req.app.get( 'gatewayVersion' ),
		protocolVersion: PROTOCOL_VERSION,
		capabilities: {
			tools: { listChanged: true },
			resources: { subscribe: false, listChanged: true },
			prompts: { listChanged: true },
		},
		auth: { type: 'bearer', note: 'Send the public API key as "Authorization: Bearer <key>".' },
		transports: {
			streamable_http: {
				endpoint: `${ req.protocol }://${ req.get( 'host' ) }/mcp`,
				methods: [ 'GET', 'POST' ],
				default: true,
				note: 'MCP 2026-07-28 Streamable HTTP — GET for discovery, POST for JSON-RPC 2.0.',
			},
		},
	} );
}

/**
 * Build the MCP router (POST /mcp only — discovery is mounted public).
 *
 * @return {import('express').Router} Router.
 */
export function mcpRouter() {
	const router = express.Router();
	router.post( '/', async ( req, res ) => {
		const cfg = req.app.get( 'gatewayConfig' );
		const ctx = {
			req,
			cfg,
			sites: req.gatewaySites || [],
		};

		// JSON-RPC batch: process each entry; notifications contribute null.
		if ( Array.isArray( req.body ) ) {
			if ( 0 === req.body.length ) {
				return res.status( 400 ).json( rpcError( null, -32600, 'Invalid Request: empty batch.' ) );
			}
			const responses = await Promise.all( req.body.map( ( msg ) => dispatch( ctx, msg ) ) );
			return res.json( responses.filter( ( r ) => null !== r ) );
		}

		let envelope;
		try {
			envelope = await dispatch( ctx, req.body );
		} catch ( err ) {
			console.error( `[mcp] dispatch failed: ${ err.message }` );
			envelope = rpcError( null, -32603, 'Internal error.' );
		}
		if ( null === envelope ) {
			// Notification: 202, no content.
			return res.status( 202 ).end();
		}
		// Quota breaches surface with RFC 9558 headers + HTTP 429.
		if ( envelope?.gateway?.quota ) {
			for ( const [ header, value ] of Object.entries( quotaHeaders( envelope.gateway.quota ) ) ) {
				res.setHeader( header, value );
			}
			return res.status( 429 ).json( envelope );
		}
		// Policy short-circuits carry their own HTTP status (403/428).
		if ( envelope?.statusCode ) {
			return res.status( envelope.statusCode ).json( envelope );
		}
		return res.json( envelope );
	} );

	return router;
}
