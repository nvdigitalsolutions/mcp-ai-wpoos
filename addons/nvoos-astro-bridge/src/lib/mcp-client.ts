/**
 * Minimal MCP Streamable HTTP client for the storefront's /mcp surface
 * (Proposal 095 §4.1) — the modern 2026-07-28 dialect, the exact wire
 * shape the storefront's handshake conformance tests pin.
 *
 * `credentials` is deliberately NOT set: fetch's default 'same-origin'
 * sends the /manage session cookie automatically (the gate and /mcp live
 * on one origin). Write auth is server-side and fail-closed — this client
 * only surfaces the denial, it never guesses at authorization.
 */

/** JSON-RPC response envelope for a tool call. */
interface JsonRpcEnvelope {
	jsonrpc: string;
	id: string;
	result?: {
		structuredContent?: unknown;
		content?: Array<{ type?: string; text?: string }>;
	};
	error?: { code?: number; message?: string };
}

/**
 * Call one MCP tool over Streamable HTTP.
 *
 * The tool name is mirrored in three places (the `Mcp-Name` header, the
 * JSON-RPC method params, and the body's `name`), per the SEP-2243
 * dialect the endpoint serves.
 *
 * @param tool Tool name to invoke.
 * @param args Tool arguments (optional).
 * @returns The tool's `structuredContent`, or its parsed text content.
 */
export async function mcpCall<T = unknown>(
	tool: string,
	args: Record<string, unknown> = {}
): Promise<T> {
	const response = await fetch('/mcp', {
		method: 'POST',
		headers: {
			accept: 'application/json',
			'content-type': 'application/json',
			'MCP-Protocol-Version': '2026-07-28',
			'Mcp-Method': 'tools/call',
			'Mcp-Name': tool,
		},
		body: JSON.stringify({
			jsonrpc: '2.0',
			id: crypto.randomUUID(),
			method: 'tools/call',
			params: {
				name: tool,
				arguments: args,
				_meta: {
					'io.modelcontextprotocol/protocolVersion': '2026-07-28',
					'io.modelcontextprotocol/clientCapabilities': {},
				},
			},
		}),
	});

	if (response.status === 401) {
		throw new Error('write tools require a manager session — sign in again');
	}
	if (!response.ok) {
		throw new Error(response.statusText || `HTTP ${response.status}`);
	}

	const envelope = (await response.json()) as JsonRpcEnvelope;
	if (envelope.error) {
		throw new Error(envelope.error.message || 'MCP tool call failed');
	}

	const result = envelope.result;
	if (result?.structuredContent != null) {
		return result.structuredContent as T;
	}
	const first = result?.content?.[0];
	if (typeof first?.text === 'string') {
		return JSON.parse(first.text) as T;
	}
	return result as T;
}
