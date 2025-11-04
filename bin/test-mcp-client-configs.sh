#!/bin/bash
# Test script to verify MCP client configurations
# This script tests both correct and incorrect MCP configurations

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo "=================================================="
echo "MCP Client Configuration Test Suite"
echo "=================================================="
echo ""

# Check if required parameters are provided
if [ -z "$1" ]; then
    echo "Usage: $0 <BASE_URL> [BEARER_TOKEN]"
    echo ""
    echo "Example:"
    echo "  $0 https://bots.nvdigital.solutions/wp-json/mcp-ai/v1 'cred_xxxxx.SECRET'"
    echo ""
    echo "This script tests both correct and incorrect MCP configurations."
    exit 1
fi

BASE_URL="$1"
BEARER_TOKEN="${2:-}"

# Setup curl options
CURL_OPTS=(-s -w "\n%{http_code}" -H "User-Agent: MCP-Test-Client/1.0")

if [ -n "$BEARER_TOKEN" ]; then
    CURL_OPTS+=(-H "Authorization: Bearer $BEARER_TOKEN")
fi

echo -e "${BLUE}Base URL:${NC} $BASE_URL"
if [ -n "$BEARER_TOKEN" ]; then
    echo -e "${BLUE}Auth:${NC} Provided (Bearer token)"
else
    echo -e "${YELLOW}Warning:${NC} No bearer token provided (may fail auth)"
fi
echo ""

# Test 1: Correct Configuration - Base URL with Accept header
echo "=================================================="
echo "Test 1: Correct Configuration (Base URL + Accept header)"
echo "=================================================="
echo -e "${BLUE}Simulating:${NC} Claude Desktop MCP configuration"
echo -e "${BLUE}Config:${NC}"
cat <<EOF
{
  "mcpServers": {
    "my-wordpress": {
      "url": "$BASE_URL",
      "headers": {
        "Authorization": "******"
      },
      "sse": true
    }
  }
}
EOF
echo ""
echo -e "${BLUE}Request:${NC} GET $BASE_URL/assistants with Accept: text/event-stream"

RESPONSE=$(curl "${CURL_OPTS[@]}" \
    -H "Accept: text/event-stream" \
    "$BASE_URL/assistants" 2>&1 || true)

HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | head -n -1)

if [ "$HTTP_CODE" = "200" ]; then
    echo -e "${GREEN}✓ PASS${NC} - HTTP $HTTP_CODE"
    
    # Check if response is SSE format
    if echo "$BODY" | grep -q "event: directory"; then
        echo -e "${GREEN}✓ PASS${NC} - SSE format detected (event: directory)"
    else
        echo -e "${YELLOW}⚠ WARNING${NC} - Response may not be in SSE format"
    fi
    
    # Check if response contains assistants data
    if echo "$BODY" | grep -q "assistants"; then
        echo -e "${GREEN}✓ PASS${NC} - Directory data present"
    else
        echo -e "${YELLOW}⚠ WARNING${NC} - Directory data not found"
    fi
else
    echo -e "${RED}✗ FAIL${NC} - HTTP $HTTP_CODE"
    echo "Response: ${BODY:0:200}..."
fi

echo ""

# Test 2: LM Studio Configuration - /sse endpoint
echo "=================================================="
echo "Test 2: LM Studio Configuration (/sse endpoint)"
echo "=================================================="
echo -e "${BLUE}Simulating:${NC} LM Studio MCP configuration"
echo -e "${BLUE}Config:${NC}"
cat <<EOF
{
  "servers": [{
    "baseUrl": "$BASE_URL",
    "sse": {
      "enabled": true,
      "endpoint": "/sse"
    }
  }]
}
EOF
echo ""
echo -e "${BLUE}Request:${NC} GET $BASE_URL/sse (no Accept header)"

RESPONSE=$(curl "${CURL_OPTS[@]}" \
    "$BASE_URL/sse" 2>&1 || true)

HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | head -n -1)

if [ "$HTTP_CODE" = "200" ]; then
    echo -e "${GREEN}✓ PASS${NC} - HTTP $HTTP_CODE"
    
    # Check if response is SSE format
    if echo "$BODY" | grep -q "event: directory"; then
        echo -e "${GREEN}✓ PASS${NC} - SSE format detected (event: directory)"
    else
        echo -e "${YELLOW}⚠ WARNING${NC} - Response may not be in SSE format"
    fi
else
    echo -e "${RED}✗ FAIL${NC} - HTTP $HTTP_CODE"
    echo "Response: ${BODY:0:200}..."
fi

echo ""

# Test 3: Incorrect Configuration - Direct /chat URL
echo "=================================================="
echo "Test 3: Incorrect Configuration (Direct /chat URL)"
echo "=================================================="
echo -e "${BLUE}Simulating:${NC} Incorrect MCP configuration"
echo -e "${RED}Config (WRONG):${NC}"
cat <<EOF
{
  "mcpServers": {
    "my-wordpress": {
      "url": "${BASE_URL%/v1}/chat",
      "headers": {
        "Authorization": "******",
        "Content-Type": "application/json",
        "Accept": "text/event-stream"
      }
    }
  }
}
EOF
echo ""
echo -e "${BLUE}Request:${NC} GET ${BASE_URL%/v1}/chat"

RESPONSE=$(curl "${CURL_OPTS[@]}" \
    -H "Accept: text/event-stream" \
    "${BASE_URL%/v1}/chat" 2>&1 || true)

HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | head -n -1)

if [ "$HTTP_CODE" = "200" ]; then
    echo -e "${RED}✗ UNEXPECTED${NC} - HTTP $HTTP_CODE (chat endpoint should not respond to GET)"
else
    echo -e "${GREEN}✓ EXPECTED${NC} - HTTP $HTTP_CODE (chat endpoint correctly rejects GET)"
    echo -e "${GREEN}✓ PASS${NC} - This proves /chat cannot be used as the base URL"
fi

echo ""

# Test 4: Verify directory includes endpoint URLs
echo "=================================================="
echo "Test 4: Directory Response Includes Endpoint URLs"
echo "=================================================="
echo -e "${BLUE}Request:${NC} GET $BASE_URL/assistants (JSON format)"

RESPONSE=$(curl "${CURL_OPTS[@]}" \
    -H "Accept: application/json" \
    "$BASE_URL/assistants" 2>&1 || true)

HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | head -n -1)

if [ "$HTTP_CODE" = "200" ]; then
    echo -e "${GREEN}✓ PASS${NC} - HTTP $HTTP_CODE"
    
    # Check for endpoint URLs in response
    if echo "$BODY" | grep -q '"chat"'; then
        echo -e "${GREEN}✓ PASS${NC} - Chat URL included in directory"
    else
        echo -e "${RED}✗ FAIL${NC} - Chat URL not found in directory"
    fi
    
    if echo "$BODY" | grep -q '"tools"'; then
        echo -e "${GREEN}✓ PASS${NC} - Tools URL included in directory"
    else
        echo -e "${RED}✗ FAIL${NC} - Tools URL not found in directory"
    fi
    
    if echo "$BODY" | grep -q '"sse"'; then
        echo -e "${GREEN}✓ PASS${NC} - SSE URL included in directory"
    else
        echo -e "${RED}✗ FAIL${NC} - SSE URL not found in directory"
    fi
else
    echo -e "${RED}✗ FAIL${NC} - HTTP $HTTP_CODE"
fi

echo ""

# Summary
echo "=================================================="
echo "Test Summary"
echo "=================================================="
echo ""
echo -e "${GREEN}Correct Configuration:${NC}"
echo "  URL: $BASE_URL"
echo "  SSE: true"
echo "  → Client calls /assistants or /sse to get directory"
echo "  → Server returns list of assistants and endpoint URLs"
echo "  → Client uses returned URLs for chat, tools, etc."
echo ""
echo -e "${RED}Incorrect Configuration:${NC}"
echo "  URL: ${BASE_URL%/v1}/chat"
echo "  → /chat is POST-only, not GET"
echo "  → /chat requires message payload"
echo "  → /chat is not the MCP directory endpoint"
echo "  → Client cannot discover other endpoints"
echo ""
echo "=================================================="
