/**
 * API Client – Shopify Jewelry TMA
 *
 * Thin wrapper around `fetch` providing:
 *  - Automatic WordPress nonce & TMA token injection.
 *  - `shopifyFetch()` – executes Shopify tool calls via the plugin tool-execution
 *    endpoint, routing to the configured `connection_id`.
 *  - `sendChat()` – AI jewelry assistant chat.
 *  - `validateInitData()` – Telegram initData verification.
 *
 * All config is read from `window.wpTmaJewelryConfig` which is injected by the
 * PHP template before this script loads.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

/**
 * @type {{
 *   validateUrl:    string,
 *   toolsUrl:       string,
 *   chatUrl:        string,
 *   nonce:          string,
 *   assistantId:    string,
 *   siteName:       string,
 *   connectionId:   string,
 * }}
 */
const cfg = {
	validateUrl:  '',
	toolsUrl:     '',
	chatUrl:      '',
	nonce:        '',
	assistantId:  '',
	siteName:     '',
	connectionId: '',
	...( window.wpTmaJewelryConfig ?? {} ),
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
		method:  'POST',
		headers: buildHeaders(),
		body:    JSON.stringify( { tool, arguments: args } ),
	} );
	if ( ! res.ok ) {
		throw new Error( `Tool "${ tool }" failed: HTTP ${ res.status }` );
	}
	return res.json();
}

// ─── Shopify data helpers ─────────────────────────────────────────────────

/**
 * Extract the first image URL from a Shopify product node.
 *
 * Shopify GraphQL returns images as { edges: [{ node: { url, altText } }] }
 * or sometimes already { nodes: [{ url }] }.
 *
 * @param {object} product Shopify product node.
 * @return {string}
 */
export function extractProductImage( product ) {
	// edges/node shape.
	const firstEdge = product?.images?.edges?.[ 0 ]?.node;
	if ( firstEdge?.url ) {
		return firstEdge.url;
	}
	// nodes shape.
	const firstNode = product?.images?.nodes?.[ 0 ];
	if ( firstNode?.url ) {
		return firstNode.url;
	}
	// Flat `image` field (some simplified shapes).
	if ( product?.image?.url ) {
		return product.image.url;
	}
	if ( product?.image?.src ) {
		return product.image.src;
	}
	return '';
}

/**
 * Extract the first variant's price from a Shopify product node.
 *
 * @param {object} product Shopify product node.
 * @return {number}
 */
export function extractProductPrice( product ) {
	const variant =
		product?.variants?.edges?.[ 0 ]?.node ??
		product?.variants?.nodes?.[ 0 ] ??
		null;
	if ( variant?.price ) {
		return parseFloat( variant.price );
	}
	if ( variant?.priceV2?.amount ) {
		return parseFloat( variant.priceV2.amount );
	}
	return 0;
}

/**
 * Extract the first variant's GID from a Shopify product node.
 *
 * @param {object} product Shopify product node.
 * @return {string}
 */
export function extractDefaultVariantId( product ) {
	const variant =
		product?.variants?.edges?.[ 0 ]?.node ??
		product?.variants?.nodes?.[ 0 ] ??
		null;
	return variant?.id ?? '';
}

/**
 * Normalise the response shape returned by the `shopify_products` tool.
 *
 * @param {any} raw Raw tool response.
 * @return {object[]}
 */
function extractProducts( raw ) {
	return (
		raw?.data?.products ??
		raw?.products ??
		raw?.data ??
		[]
	);
}

/**
 * Normalise the response shape returned by the `shopify_orders` tool.
 *
 * @param {any} raw Raw tool response.
 * @return {object[]}
 */
function extractOrders( raw ) {
	return (
		raw?.data?.orders ??
		raw?.orders ??
		raw?.data ??
		[]
	);
}

// ─── Public data API ──────────────────────────────────────────────────────

/**
 * Fetch products from the configured Shopify connection.
 *
 * @param {{ search?: string, first?: number, after?: string }} params
 * @return {Promise<object[]>}
 */
export async function getProducts( params = {} ) {
	const args = {
		action:        params.search ? 'search' : 'list',
		connection_id: cfg.connectionId,
		first:         params.first ?? 20,
	};
	if ( params.after ) {
		args.after = params.after;
	}
	if ( params.search ) {
		args.query = params.search;
	}
	const raw  = await executeTool( 'shopify_products', args );
	const list = extractProducts( raw );
	return Array.isArray( list ) ? list : [];
}

/**
 * Fetch a single product by its Shopify GID.
 *
 * @param {string} productId Shopify product GID.
 * @return {Promise<object|null>}
 */
export async function getProduct( productId ) {
	const raw = await executeTool( 'shopify_products', {
		action:        'get',
		connection_id: cfg.connectionId,
		product_id:    productId,
	} );
	return raw?.data?.product ?? raw?.product ?? raw?.data ?? null;
}

/**
 * Fetch recent orders from the configured Shopify connection.
 *
 * @param {{ first?: number }} params
 * @return {Promise<object[]>}
 */
export async function getOrders( params = {} ) {
	const raw  = await executeTool( 'shopify_orders', {
		action:        'list',
		connection_id: cfg.connectionId,
		first:         params.first ?? 10,
	} );
	const list = extractOrders( raw );
	return Array.isArray( list ) ? list : [];
}

// ─── AI assistant ─────────────────────────────────────────────────────────

/**
 * Send a chat message to the TMA AI assistant.
 *
 * @param {Array<{role:string,content:string}>} messages Conversation history.
 * @return {Promise<{reply:string}>}
 */
export async function sendChat( messages ) {
	const body = { messages };
	if ( cfg.assistantId ) {
		body.assistant_id = cfg.assistantId;
	}
	const res = await fetch( cfg.chatUrl, {
		method:  'POST',
		headers: buildHeaders(),
		body:    JSON.stringify( body ),
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
			method:  'POST',
			headers: { 'Content-Type': 'application/json' },
			body:    JSON.stringify( { init_data: initData } ),
		} );
		if ( ! res.ok ) {
			return null;
		}
		return res.json();
	} catch ( err ) {
		// eslint-disable-next-line no-console
		console.error( '[TmaJewelryShop] validateInitData failed:', err );
		return null;
	}
}

export { cfg };
