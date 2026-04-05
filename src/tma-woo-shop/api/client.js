/**
 * API Client
 *
 * Thin wrapper around `fetch` providing:
 *  - Automatic WordPress nonce & TMA token injection.
 *  - `wooFetch()` – the single entry-point for ALL WooCommerce data calls.
 *    When `wooSource === 'local'` it calls built-in local WooCommerce tools.
 *    When `wooSource === 'remote'` it routes through `remote_wp_connection`
 *    with the configured `wooConnectionId`, using the same tool-execution
 *    endpoint that every other TMA template uses.
 *  - `sendChat()` – AI assistant chat.
 *  - `validateInitData()` – Telegram initData verification.
 *
 * All config is read from `window.wpTmaWooConfig` which is injected by the
 * PHP template before this script loads.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

/**
 * @type {{
 *   validateUrl: string,
 *   toolsUrl: string,
 *   chatUrl: string,
 *   nonce: string,
 *   assistantId: string,
 *   siteName: string,
 *   siteUrl: string,
 *   wooSource: 'local'|'remote',
 *   wooConnectionId: string,
 * }}
 */
const cfg = {
	validateUrl:     '',
	toolsUrl:        '',
	chatUrl:         '',
	nonce:           '',
	assistantId:     '',
	siteName:        '',
	siteUrl:         window.location.origin,
	wooSource:       'local',
	wooConnectionId: '',
	...( window.wpTmaWooConfig ?? {} ),
};

// TMA session token populated after calling /validate on page load.
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
 * Execute a plugin tool via the TMA tools/execute endpoint.
 *
 * @param {string} tool   Tool slug.
 * @param {object} args   Tool arguments.
 * @return {Promise<any>}
 */
export async function executeTool( tool, args ) {
	const url = cfg.toolsUrl + '/execute';
	const res = await fetch( url, {
		method: 'POST',
		headers: buildHeaders(),
		body: JSON.stringify( { slug: tool, arguments: args } ),
	} );
	if ( ! res.ok ) {
		throw new Error( `Tool "${ tool }" failed: HTTP ${ res.status }` );
	}
	return res.json();
}

// ─── WooCommerce data routing ──────────────────────────────────────────────

/**
 * Normalise the response shape from both local tools and remote_wp_connection.
 * The TMA controller wraps tool results in {success, result: {...}} while some
 * legacy paths may use {data: {...}}.
 *
 * @param {any}    raw
 * @param {string} dataKey  e.g. 'products', 'orders', 'categories', 'product'
 * @return {any}
 */
function extractData( raw, dataKey ) {
	// Controller returns {success, result: {...}} – check result first,
	// then fall back to legacy data paths for backward compatibility.
	return (
		raw?.result?.[ dataKey ] ??
		raw?.data?.[ dataKey ] ??
		raw?.[ dataKey ] ??
		raw?.result ??
		raw?.data ??
		null
	);
}

/**
 * Call a WooCommerce action, automatically routing to:
 *  - local built-in tools  (wooSource === 'local')
 *  - remote_wp_connection  (wooSource === 'remote')
 *
 * Supported actions and their argument shapes:
 *
 *  get_wc_products     { search, category, limit, orderby, order }
 *  get_wc_product      { product_id }
 *  get_wc_categories   { per_page }
 *  get_wc_orders       { per_page }
 *  get_wc_order        { order_id }
 *
 * @param {string} action  WooCommerce action name.
 * @param {object} args    Action arguments (action-specific).
 * @return {Promise<any>}  Resolved data (array or object).
 */
export async function wooFetch( action, args = {} ) {
	const source       = cfg.wooSource ?? 'local';
	const connectionId = cfg.wooConnectionId ?? '';

	if ( source === 'remote' && connectionId ) {
		// Route through the remote_wp_connection tool.
		const raw = await executeTool( 'remote_wp_connection', {
			action,
			connection_id: connectionId,
			...args,
		} );

		// remote_wp_connection returns { data: { products/orders/... } } or similar.
		const dataKeyMap = {
			get_wc_products:   'products',
			get_wc_product:    'product',
			get_wc_categories: 'categories',
			get_wc_orders:     'orders',
			get_wc_order:      'order',
		};
		return extractData( raw, dataKeyMap[ action ] ?? action );
	}

	// ── Local store path ──────────────────────────────────────────────────────
	// Map remote-style action names to the local tool slugs + arg shapes.
	switch ( action ) {
		case 'get_wc_products': {
			const raw = await executeTool( 'get_woo_products', {
				limit:   args.limit ?? 20,
				search:  args.search ?? '',
				category: args.category ?? '',
				orderby: args.orderby ?? '',
				order:   args.order ?? '',
			} );
			return extractData( raw, 'products' ) ?? [];
		}
		case 'get_wc_product': {
			// Local tool does not expose a single-product fetch; fall back to
			// the public WooCommerce Store API (no auth required for published products).
			const siteUrl = cfg.siteUrl || window.location.origin;
			const res = await fetch(
				siteUrl.replace( /\/$/, '' ) + `/wp-json/wc/store/v1/products/${ args.product_id }`,
				{ headers: { 'Content-Type': 'application/json' } }
			);
			if ( ! res.ok ) {
				throw new Error( `Product fetch failed: HTTP ${ res.status }` );
			}
			return res.json();
		}
		case 'get_wc_categories': {
			// Use the public WooCommerce Store API for category listing.
			const siteUrl = cfg.siteUrl || window.location.origin;
			const perPage = args.per_page ?? 50;
			const res = await fetch(
				siteUrl.replace( /\/$/, '' ) + `/wp-json/wc/store/v1/products/categories?per_page=${ perPage }`,
				{ headers: { 'Content-Type': 'application/json' } }
			);
			if ( ! res.ok ) {
				throw new Error( `Category fetch failed: HTTP ${ res.status }` );
			}
			return res.json();
		}
		case 'get_wc_orders': {
			const raw = await executeTool( 'get_woo_recent_orders', {
				per_page: args.per_page ?? 10,
			} );
			return extractData( raw, 'orders' ) ?? [];
		}
		default:
			throw new Error( `Unknown local WooCommerce action: ${ action }` );
	}
}

// ─── AI assistant ──────────────────────────────────────────────────────────

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

// ─── Auth ─────────────────────────────────────────────────────────────────

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
	} catch ( err ) {
		// eslint-disable-next-line no-console
		console.error( '[TmaWooShop] validateInitData failed:', err );
		return null;
	}
}

export { cfg };

