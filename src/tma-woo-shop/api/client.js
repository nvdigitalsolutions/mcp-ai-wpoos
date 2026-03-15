/**
 * API Client
 *
 * Thin wrapper around `fetch` providing:
 *  - Automatic WordPress nonce & TMA token injection.
 *  - A helper for executing plugin tool-endpoint calls.
 *  - A helper for direct WooCommerce Store API calls (cart / checkout).
 *
 * All config is read from `window.wpTmaWooConfig` which is injected by the
 * PHP template before this script loads.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

/** @type {{ validateUrl:string, toolsUrl:string, chatUrl:string, nonce:string, assistantId:string, siteName:string, siteUrl:string }} */
const cfg = window.wpTmaWooConfig ?? {};

// TMA session token is populated after calling /validate on page load.
let tmaToken = '';

/**
 * Store the TMA session token received from the validate endpoint.
 *
 * @param {string} token
 */
export function setTmaToken( token ) {
	tmaToken = token;
}

/**
 * Store an updated WP nonce received from the validate endpoint.
 *
 * @param {string} nonce
 */
export function setNonce( nonce ) {
	cfg.nonce = nonce;
}

/**
 * Build common request headers.
 *
 * @return {Record<string,string>}
 */
function buildHeaders() {
	/** @type {Record<string,string>} */
	const headers = { 'Content-Type': 'application/json' };
	if ( cfg.nonce ) {
		headers[ 'X-WP-Nonce' ] = cfg.nonce;
	}
	if ( tmaToken ) {
		headers[ 'X-WP-MCP-AI-TMA-Token' ] = tmaToken;
	}
	return headers;
}

/**
 * Execute a plugin tool via the TMA tools endpoint.
 *
 * @param {string} tool      Tool slug (e.g. 'get_woo_products').
 * @param {object} args      Tool arguments object.
 * @return {Promise<any>}    Parsed JSON response.
 */
export async function executeTool( tool, args ) {
	const url = cfg.toolsUrl + '/execute';
	const res = await fetch( url, {
		method: 'POST',
		headers: buildHeaders(),
		body: JSON.stringify( { tool, arguments: args } ),
	} );
	if ( ! res.ok ) {
		throw new Error( `Tool "${ tool }" failed: HTTP ${ res.status }` );
	}
	return res.json();
}

/**
 * Send a chat message to the TMA AI assistant.
 *
 * @param {Array<{role:string,content:string}>} messages  Conversation history.
 * @return {Promise<{reply:string}>}
 */
export async function sendChat( messages ) {
	const body = { messages };
	if ( cfg.assistantId ) {
		body.assistant_id = cfg.assistantId;
	}
	const res = await fetch( cfg.chatUrl, {
		method: 'POST',
		headers: buildHeaders(),
		body: JSON.stringify( body ),
	} );
	if ( ! res.ok ) {
		throw new Error( `Chat failed: HTTP ${ res.status }` );
	}
	return res.json();
}

/**
 * Validate Telegram initData against the server and receive a fresh nonce.
 *
 * @return {Promise<{nonce:string,tma_token:string}|null>}
 */
export async function validateInitData() {
	const initData = window.Telegram?.WebApp?.initData;
	if ( ! initData ) {
		return null;
	}
	try {
		const res = await fetch( cfg.validateUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { init_data: initData } ),
		} );
		if ( ! res.ok ) {
			return null;
		}
		return res.json();
	} catch ( _e ) {
		return null;
	}
}

/**
 * Direct WooCommerce Store API call (cart, checkout).
 *
 * @param {string} path    e.g. '/wc/store/v1/cart'
 * @param {'GET'|'POST'|'PUT'|'DELETE'} method
 * @param {object|null} body
 * @param {string|null} storeNonce  WooCommerce Store API nonce.
 * @return {Promise<any>}
 */
export async function storeApi( path, method = 'GET', body = null, storeNonce = null ) {
	const siteUrl = cfg.siteUrl || window.location.origin;
	const url = siteUrl.replace( /\/$/, '' ) + '/wp-json' + path;
	/** @type {Record<string,string>} */
	const headers = { 'Content-Type': 'application/json' };
	if ( cfg.nonce ) {
		headers[ 'X-WP-Nonce' ] = cfg.nonce;
	}
	if ( storeNonce ) {
		headers[ 'Nonce' ] = storeNonce;
	}
	const init = { method, headers };
	if ( body ) {
		init.body = JSON.stringify( body );
	}
	const res = await fetch( url, init );
	if ( ! res.ok ) {
		const errBody = await res.json().catch( () => ( {} ) );
		throw new Error( errBody?.message || `Store API ${ method } ${ path } failed: ${ res.status }` );
	}
	return res.json();
}

export { cfg };
