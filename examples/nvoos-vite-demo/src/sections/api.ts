import {
  chatEndpoint,
  chatClientEndpoint,
  toolsListEndpoint,
  toolExecuteEndpoint,
  uploadEndpoint,
  transcriptsEndpoint,
  historyEndpoint,
  sseEndpoint,
  buildChatPayload,
  buildToolExecutionPayload,
  buildAuthHeaders,
  buildGuestHeaders,
  sanitizeSessionKey,
  formatBytes,
} from '@nvdigitalsolutions/nvoos-api';

/**
 * @nvdigitalsolutions/nvoos-api — typed REST API client for the NV oOS
 * WordPress endpoints (`/wp-json/mcp-ai/v1/`). Endpoint builders, payload
 * constructors, and auth header helpers — all pure functions.
 */
export function initApi(): void {
  const out = document.querySelector<HTMLPreElement>('#api-out');
  if (!out) {
    return;
  }

  // Same config shape the plugin's REST client uses.
  const config = {
    restUrl: 'https://demo.example.com/wp-json/mcp-ai/v1',
    nonce: 'abc123',
  };

  const messages = [{ role: 'user', content: 'Hello NV oOS' }];

  const lines: Array<[string, unknown]> = [
    ['chatEndpoint(config)', chatEndpoint(config)],
    ['chatClientEndpoint(config)', chatClientEndpoint(config)],
    ['toolsListEndpoint(config)', toolsListEndpoint(config)],
    ['toolExecuteEndpoint(config)', toolExecuteEndpoint(config)],
    ['uploadEndpoint(config)', uploadEndpoint(config)],
    ['transcriptsEndpoint(config, "demo-session")', transcriptsEndpoint(config, 'demo-session')],
    ['historyEndpoint(config, { limit: 20 })', historyEndpoint(config, { limit: 20 })],
    ['sseEndpoint(config, { assistant_id: 7, stream: "true" })', sseEndpoint(config, { assistant_id: 7, stream: 'true' })],
    ['buildChatPayload(7, messages)', buildChatPayload(7, messages)],
    [
      'buildToolExecutionPayload({ tool: "web_search", arguments: { query: "vite" }, assistant_id: 7 })',
      buildToolExecutionPayload({ tool: 'web_search', arguments: { query: 'vite' }, assistant_id: 7 }),
    ],
    ['buildAuthHeaders(config)', buildAuthHeaders(config)],
    ['buildGuestHeaders()', buildGuestHeaders()],
    ['sanitizeSessionKey("Session/2026 #1")', sanitizeSessionKey('Session/2026 #1')],
    ['formatBytes(1536000)', formatBytes(1536000)],
  ];

  out.textContent = lines
    .map(([label, value]) => `${label}\n  → ${typeof value === 'string' ? value : JSON.stringify(value)}`)
    .join('\n\n');
}
