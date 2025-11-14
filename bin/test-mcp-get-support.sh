#!/bin/bash
# Manual test script for MCP endpoint GET support
# This script tests the /mcp endpoint to ensure it properly handles GET requests

echo "=== Testing MCP Endpoint GET Support ==="
echo ""

# Set your WordPress site URL here
SITE_URL="${SITE_URL:-http://localhost:8000}"
MCP_URL="${SITE_URL}/wp-json/mcp-ai/v1/mcp"

echo "Testing endpoint: $MCP_URL"
echo ""

# Test 1: GET request without Accept header (should return discovery info)
echo "Test 1: GET request for endpoint discovery"
echo "-------------------------------------------"
curl -s -X GET "$MCP_URL" \
  -H "Content-Type: application/json" \
  | jq '.' || echo "Response is not valid JSON"
echo ""
echo ""

# Test 2: GET request with Accept: text/event-stream (should return SSE)
echo "Test 2: GET request with SSE Accept header"
echo "-------------------------------------------"
curl -s -X GET "$MCP_URL" \
  -H "Accept: text/event-stream" \
  -H "Content-Type: application/json" \
  | head -20
echo ""
echo ""

# Test 3: OPTIONS request (CORS preflight)
echo "Test 3: OPTIONS request for CORS"
echo "-------------------------------------------"
curl -s -X OPTIONS "$MCP_URL" \
  -H "Origin: https://example.com" \
  -H "Access-Control-Request-Method: GET" \
  -H "Access-Control-Request-Headers: Authorization" \
  -i | grep -E "HTTP/|Access-Control"
echo ""
echo ""

# Test 4: POST request (backward compatibility)
echo "Test 4: POST request for JSON-RPC (backward compatibility)"
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

# Test 5: Check CORS headers on GET
echo "Test 5: CORS headers on GET request"
echo "-------------------------------------------"
curl -s -X GET "$MCP_URL" \
  -H "Origin: https://example.com" \
  -i | grep -E "HTTP/|Access-Control"
echo ""
echo ""

echo "=== Tests Complete ==="
echo ""
echo "Summary:"
echo "- Test 1: Should show JSON discovery response with endpoints, capabilities, transports"
echo "- Test 2: Should show SSE stream (data: events)"
echo "- Test 3: Should show 204 status and Access-Control headers allowing GET"
echo "- Test 4: Should show JSON-RPC response with initialize result"
echo "- Test 5: Should show Access-Control-Allow-Methods including GET"
