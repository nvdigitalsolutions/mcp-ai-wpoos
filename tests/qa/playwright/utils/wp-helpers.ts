/**
 * REST API helper utilities.
 *
 * Provides typed helpers for calling the NV oOS MCP REST endpoints
 * with proper authentication and validation.
 */

import { APIRequestContext, expect } from '@playwright/test';

export interface AssistantItem {
  id: number;
  name: string;
  description: string;
  [key: string]: unknown;
}

export interface ToolItem {
  slug: string;
  name: string;
  description: string;
  parameters: Record<string, unknown>;
  [key: string]: unknown;
}

const API_PREFIX = '/wp-json/mcp-ai/v1';

/**
 * Make an authenticated request to the MCP API.
 */
export async function mcpApiRequest(
  request: APIRequestContext,
  method: 'GET' | 'POST',
  endpoint: string,
  options?: {
    nonce?: string;
    data?: Record<string, unknown>;
    bearerToken?: string;
  },
) {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
  };

  if (options?.nonce) {
    headers['X-WP-Nonce'] = options.nonce;
  }
  if (options?.bearerToken) {
    headers['Authorization'] = `Bearer ${options.bearerToken}`;
  }

  return request[method === 'GET' ? 'get' : 'post'](
    `${API_PREFIX}${endpoint}`,
    {
      headers,
      data: options?.data,
    },
  );
}

/**
 * List all assistants via the MCP API.
 * Requires authentication (nonce or bearer token).
 */
export async function listAssistants(
  request: APIRequestContext,
  nonce: string,
): Promise<AssistantItem[]> {
  const response = await mcpApiRequest(request, 'GET', '/assistants', { nonce });
  expect(response.status()).toBe(200);
  return response.json();
}

/**
 * List all tools via the MCP API.
 * Requires authentication (nonce or bearer token).
 */
export async function listTools(
  request: APIRequestContext,
  nonce: string,
): Promise<ToolItem[]> {
  const response = await mcpApiRequest(request, 'GET', '/tools/list', { nonce });
  expect(response.status()).toBe(200);
  return response.json();
}

/**
 * Execute a tool via the MCP API.
 * Returns the raw response for inspection.
 */
export async function executeTool(
  request: APIRequestContext,
  nonce: string,
  toolSlug: string,
  args: Record<string, unknown> = {},
) {
  return mcpApiRequest(request, 'POST', '/tools/run', {
    nonce,
    data: { tool_slug: toolSlug, arguments: args },
  });
}

/**
 * Send a chat message via the MCP API (non-streaming).
 */
export async function sendChatMessage(
  request: APIRequestContext,
  message: string,
  options?: {
    nonce?: string;
    guestToken?: string;
    assistantId?: number;
  },
) {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
  };
  if (options?.nonce) {
    headers['X-WP-Nonce'] = options.nonce;
  }
  if (options?.guestToken) {
    headers['X-WP-MCP-AI-Guest'] = options.guestToken;
  }

  return request.post(`${API_PREFIX}/chat`, {
    headers,
    data: {
      message,
      assistant_id: options?.assistantId,
      stream: false,
    },
  });
}
