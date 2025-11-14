#!/bin/bash
# Manual test script for MCP endpoint with SSE as default
# This script tests the /mcp endpoint to ensure SSE is the default behavior

echo "=== Testing MCP Endpoint (SSE as Default) ==="
echo ""

# Set your WordPress site URL here
SITE_URL="${SITE_URL:-http://localhost:8000}"
MCP_URL="${SITE_URL}/wp-json/mcp-ai/v1/mcp"
NO_SSE_URL="${SITE_URL}/wp-json/mcp-ai/v1/no-sse"

echo "Testing endpoint: $MCP_URL"
echo ""

# Test 1: GET request to /mcp (should return SSE by default)
echo "Test 1: GET /mcp (should default to SSE)"
echo "-------------------------------------------"
curl -s -X GET "$MCP_URL" \
  -H "Content-Type: application/json" \
  | head -20
echo ""
echo ""

# Test 2: GET request with ?discovery=true (should return JSON)
echo "Test 2: GET /mcp?discovery=true (should return discovery JSON)"
echo "-------------------------------------------"
curl -s -X GET "$MCP_URL?discovery=true" \
  -H "Content-Type: application/json" \
  | jq '.' || echo "Response is not valid JSON"
echo ""
echo ""

# Test 3: GET request to /no-sse (should return JSON, no SSE)
echo "Test 3: GET /no-sse (should return assistant directory JSON)"
echo "-------------------------------------------"
curl -s -X GET "$NO_SSE_URL" \
  -H "Content-Type: application/json" \
  | jq '.' || echo "Response is not valid JSON"
echo ""
echo ""

# Test 4: GET with Accept: application/json (should return discovery JSON)
echo "Test 4: GET /mcp with Accept: application/json"
echo "-------------------------------------------"
curl -s -X GET "$MCP_URL" \
  -H "Accept: application/json" \
  | jq '.'
echo ""
echo ""

# Test 5: OPTIONS request (CORS preflight)
echo "Test 5: OPTIONS request for CORS"
echo "-------------------------------------------"
curl -s -X OPTIONS "$MCP_URL" \
  -H "Origin: https://example.com" \
  -H "Access-Control-Request-Method: GET" \
  -H "Access-Control-Request-Headers: Authorization" \
  -i | grep -E "HTTP/|Access-Control"
echo ""
echo ""

# Test 6: POST request (backward compatibility)
echo "Test 6: POST request for JSON-RPC (backward compatibility)"
echo "-------------------------------------------"
curl -s -X POST "$MCP_URL" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {
      "protocolVersion": "2024-11-05",
      "clientInfo": {
        "name": "Test Client",
        "version": "1.0"
      }
    }
  }' | jq '.'
echo ""
echo ""

# Test 7: Check CORS headers on GET
echo "Test 7: CORS headers on GET request"
echo "-------------------------------------------"
curl -s -X GET "$MCP_URL" \
  -H "Origin: https://example.com" \
  -i | grep -E "HTTP/|Access-Control"
echo ""
echo ""

echo "=== Tests Complete ==="
echo ""
echo "Summary:"
echo "- Test 1: Should show SSE stream (data: events) - SSE is now DEFAULT"
echo "- Test 2: Should show JSON discovery response with endpoints, capabilities, transports"
echo "- Test 3: Should show JSON assistant directory (no SSE)"
echo "- Test 4: Should show JSON discovery when explicitly requesting application/json"
echo "- Test 5: Should show 204 status and Access-Control headers allowing GET"
echo "- Test 6: Should show JSON-RPC response with initialize result"
echo "- Test 7: Should show Access-Control-Allow-Methods including GET"
echo ""
echo "Key Changes:"
echo "- GET /mcp now DEFAULTS to SSE (no Accept header needed)"
echo "- GET /mcp?discovery=true returns JSON discovery info"
echo "- GET /no-sse returns assistant directory without SSE"
echo "- /sse endpoint renamed to /no-sse (SSE is now default on /mcp)"
