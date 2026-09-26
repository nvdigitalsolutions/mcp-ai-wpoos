/**
 * Tool-selection policy for the gateway.
 *
 * Applies an allow/deny filter to the MCP `tools/list` response so a
 * compromised or over-privileged token can never surface admin tools.
 * Plugin-side per-assistant tool gating remains the primary UX; this is
 * the defence-in-depth layer.
 *
 * Patterns support a trailing `*` wildcard (e.g. `wp_*`); `*` alone means
 * "everything". Deny always wins over allow.
 */

/**
 * Whether a tool name matches a pattern.
 *
 * @param {string} pattern  Policy pattern (`*`, `wp_*`, or exact name).
 * @param {string} toolName Tool name.
 * @returns {boolean} True when the pattern matches.
 */
export function matches( pattern, toolName ) {
	if ( pattern === '*' ) {
		return true;
	}
	if ( pattern.endsWith( '*' ) ) {
		return toolName.startsWith( pattern.slice( 0, -1 ) );
	}
	return pattern === toolName;
}

/**
 * Whether an allow list admits a tool (empty/star list admits everything).
 *
 * @param {string[]} allow    Allow patterns.
 * @param {string}   toolName Tool name.
 * @returns {boolean} True when admitted.
 */
export function isAllowed( allow, toolName ) {
	return allow.length === 0 || allow.includes( '*' ) || allow.some( ( pattern ) => matches( pattern, toolName ) );
}

/**
 * Whether a deny list rejects a tool.
 *
 * @param {string[]} deny     Deny patterns.
 * @param {string}   toolName Tool name.
 * @returns {boolean} True when rejected.
 */
export function isDenied( deny, toolName ) {
	return deny.some( ( pattern ) => matches( pattern, toolName ) );
}

/**
 * Filter a tools/list result set through the policy.
 *
 * @param {Array<{name: string}>} tools Tools array from the upstream server.
 * @param {string[]}              allow Allow patterns.
 * @param {string[]}              deny  Deny patterns.
 * @returns {Array<{name: string}>} Filtered tools.
 */
export function filterTools( tools, allow, deny ) {
	if ( ! Array.isArray( tools ) ) {
		return [];
	}

	return tools.filter(
		( tool ) => tool && typeof tool.name === 'string' && isAllowed( allow, tool.name ) && ! isDenied( deny, tool.name )
	);
}

/**
 * Whether a parsed JSON body is an MCP tools/list request.
 *
 * @param {unknown} body Parsed request body.
 * @returns {boolean} True for JSON-RPC tools/list calls.
 */
export function isToolsListRequest( body ) {
	return Boolean( body && typeof body === 'object' && body.method === 'tools/list' );
}

/**
 * Rewrite a tools/list JSON-RPC response, applying the policy to
 * `result.tools`. Non-conforming payloads pass through untouched.
 *
 * @param {object}   payload Upstream response payload.
 * @param {string[]} allow   Allow patterns.
 * @param {string[]} deny    Deny patterns.
 * @returns {object} Rewritten payload.
 */
export function applyPolicyToToolsList( payload, allow, deny ) {
	if ( ! payload || typeof payload !== 'object' || ! payload.result || ! Array.isArray( payload.result.tools ) ) {
		return payload;
	}

	return {
		...payload,
		result: {
			...payload.result,
			tools: filterTools( payload.result.tools, allow, deny ),
		},
	};
}
