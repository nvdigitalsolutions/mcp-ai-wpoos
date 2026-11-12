/**
 * Gateway verification client.
 *
 * Implements the deploy-verification ladder from the gateway ops skill:
 *   1. GET /health          — public, version marker present
 *   2. GET /health/full     — keyed, target site reports ok
 *   3. tools/list           — namespaced <slug>.* tools present, no
 *                             _meta.gateway.errors[slug]
 *   4. tools/call           — one allowlisted tool answers
 *
 * Tolerates both JSON and SSE (`text/event-stream`) JSON-RPC responses.
 */

import { requestJson } from '../util/http.js';

export function parseMcpPayload( data ) {
	if ( data && typeof data === 'object' ) {
		return data;
	}
	if ( typeof data !== 'string' ) {
		return null;
	}
	// SSE frame: collect `data:` lines, keep the last parseable JSON object.
	let last = null;
	for ( const line of data.split( /\r?\n/ ) ) {
		if ( ! line.startsWith( 'data:' ) ) {
			continue;
		}
		const payload = line.slice( 5 ).trim();
		if ( ! payload ) {
			continue;
		}
		try {
			last = JSON.parse( payload );
		} catch {
			// ignore non-JSON frames (comments, keepalives)
		}
	}
	return last;
}

export function createGatewayClient( { config, fetchImpl = globalThis.fetch } ) {
	const { gateway, mode } = config;

	async function tryJson( path, options = {} ) {
		try {
			const response = await requestJson( `${ gateway.baseUrl }${ path }`, {
				...options,
				timeoutMs: gateway.timeoutMs,
				retries: 0,
				fetchImpl,
			} );
			return { status: response.status, ok: response.ok, data: response.data };
		} catch {
			return { status: 0, ok: false, data: null };
		}
	}

	async function mcp( apiKey, payload ) {
		const response = await tryJson( '/mcp', {
			method: 'POST',
			headers: {
				Authorization: `Bearer ${ apiKey }`,
				Accept: 'application/json, text/event-stream',
			},
			body: payload,
		} );
		return parseMcpPayload( response.data );
	}

	/**
	 * @param {{slug:string, apiKey:string, tool?:string|null}} input
	 * @return {Promise<{ok:boolean, steps:object, namespacedTools:string[]}>}
	 */
	async function verify( { slug, apiKey, tool = null } ) {
		if ( mode === 'mock' ) {
			return {
				ok: true,
				steps: {
					health: { ok: true, version: '0.0.0-mock' },
					healthFull: { ok: true, siteStatus: 'ok' },
					toolsList: { ok: true, namespaced: 1 },
					toolsCall: { ok: true, tool: tool ?? `${ slug }.get_environment_status` },
				},
				namespacedTools: [ `${ slug }.get_environment_status` ],
			};
		}

		const health = await tryJson( '/health' );
		const healthOk = health.ok && Boolean( health.data?.version );

		const full = await tryJson( '/health/full', {
			headers: { Authorization: `Bearer ${ apiKey }` },
		} );
		const siteEntry = full.data?.sites?.[ slug ];
		const siteStatus = typeof siteEntry === 'string' ? siteEntry : siteEntry?.status;
		const siteOk = full.ok && ( siteStatus === 'ok' || siteEntry === true );

		const list = await mcp( apiKey, {
			jsonrpc: '2.0',
			id: 1,
			method: 'tools/list',
			params: {},
		} );
		const tools = Array.isArray( list?.result?.tools ) ? list.result.tools : [];
		const namespacedTools = tools
			.map( ( entry ) => entry?.name )
			.filter( ( name ) => typeof name === 'string' && name.startsWith( `${ slug }.` ) );
		const gatewayErrors = list?._meta?.gateway?.errors?.[ slug ];
		const listOk = namespacedTools.length > 0 && ! gatewayErrors;

		let callOk = false;
		let calledTool = null;
		const target = tool ? `${ slug }.${ tool }` : namespacedTools[ 0 ];
		if ( target && namespacedTools.includes( target ) ) {
			calledTool = target;
			const call = await mcp( apiKey, {
				jsonrpc: '2.0',
				id: 2,
				method: 'tools/call',
				params: { name: target, arguments: {} },
			} );
			callOk = Boolean( call?.result ) && ! call?.error;
		}

		return {
			ok: healthOk && siteOk && listOk && callOk,
			steps: {
				health: { ok: healthOk, version: health.data?.version ?? null },
				healthFull: { ok: siteOk, siteStatus: siteStatus ?? null },
				toolsList: { ok: listOk, namespaced: namespacedTools.length },
				toolsCall: { ok: callOk, tool: calledTool },
			},
			namespacedTools,
		};
	}

	return { verify };
}
