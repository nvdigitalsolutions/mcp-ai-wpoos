#!/bin/bash
#
# Test MCP JSON-RPC endpoint connectivity
# This script tests the /mcp endpoint which uses JSON-RPC 2.0 protocol
#

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Default values
URL=""
TOKEN=""
VERBOSE=false

# Help message
show_help() {
    cat << EOF
Usage: ${0##*/} -u URL -t TOKEN [-v]

Test MCP JSON-RPC endpoint connectivity.

Options:
    -u URL       WordPress site base URL (e.g., https://example.com)
    -t TOKEN     Bearer token credential (e.g., cred_xxxxx.SECRET)
    -v           Verbose output (show full responses)
    -h           Display this help message

Examples:
    # Basic test
    ${0##*/} -u https://example.com -t cred_abc123.secret456

    # Verbose test with full responses
    ${0##*/} -u https://example.com -t cred_abc123.secret456 -v

Note: This tests the JSON-RPC 2.0 endpoint at /wp-json/mcp-ai/v1/mcp
EOF
}

# Parse command line arguments
while getopts "u:t:vh" opt; do
    case ${opt} in
        u )
            URL=$OPTARG
            ;;
        t )
            TOKEN=$OPTARG
            ;;
        v )
            VERBOSE=true
            ;;
        h )
            show_help
            exit 0
            ;;
        \? )
            echo "Invalid option: -$OPTARG" >&2
            show_help
            exit 1
            ;;
    esac
done

# Check required parameters
if [ -z "$URL" ] || [ -z "$TOKEN" ]; then
    echo -e "${RED}Error: URL and TOKEN are required${NC}"
    show_help
    exit 1
fi

# Remove trailing slash from URL
URL=${URL%/}

# Construct MCP endpoint
MCP_ENDPOINT="${URL}/wp-json/mcp-ai/v1/mcp"

echo "=========================================="
echo "MCP JSON-RPC Endpoint Test"
echo "=========================================="
echo ""
echo "Endpoint: ${MCP_ENDPOINT}"
echo "Token: ${TOKEN:0:20}..."
echo ""

# Test 1: Initialize
echo "Test 1: Initialize connection"
echo "Method: initialize"
echo ""

INIT_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "${MCP_ENDPOINT}" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
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
  }' 2>&1)

CURL_EXIT_CODE=$?

if [ $CURL_EXIT_CODE -ne 0 ]; then
    echo -e "   ${RED}✗${NC} curl command failed (exit code: ${CURL_EXIT_CODE})"
    echo "   Possible causes:"
    echo "   - Network connectivity issues"
    echo "   - DNS resolution failure"
    echo "   - Invalid URL"
    echo "   - SSL certificate issues"
    exit 1
fi

HTTP_CODE=$(echo "$INIT_RESPONSE" | tail -n1)
RESPONSE_BODY=$(echo "$INIT_RESPONSE" | sed '$d')

if [ "$HTTP_CODE" -eq 200 ]; then
    echo -e "   ${GREEN}✓${NC} HTTP Status: 200 OK"
    
    if echo "$RESPONSE_BODY" | grep -q '"jsonrpc":"2.0"'; then
        echo -e "   ${GREEN}✓${NC} Valid JSON-RPC response"
    else
        echo -e "   ${RED}✗${NC} Invalid JSON-RPC format"
    fi
    
    if echo "$RESPONSE_BODY" | grep -q '"serverInfo"'; then
        echo -e "   ${GREEN}✓${NC} Server info present"
        
        if [ "$VERBOSE" = true ]; then
            echo ""
            echo "Response:"
            if echo "$RESPONSE_BODY" | python3 -m json.tool 2>/dev/null; then
                :
            else
                echo -e "   ${YELLOW}⚠${NC}  Warning: Response is not valid JSON, showing raw output:"
                echo "$RESPONSE_BODY"
            fi
        fi
    else
        echo -e "   ${YELLOW}⚠${NC}  Server info missing"
    fi
else
    echo -e "   ${RED}✗${NC} HTTP Status: ${HTTP_CODE}"
    
    if [ "$VERBOSE" = true ]; then
        echo ""
        echo "Response:"
        echo "$RESPONSE_BODY"
    fi
fi

echo ""

# Test 2: List Tools
echo "Test 2: List available tools"
echo "Method: tools/list"
echo ""

TOOLS_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "${MCP_ENDPOINT}" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -d '{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "tools/list",
    "params": {}
  }' 2>&1)

CURL_EXIT_CODE=$?

if [ $CURL_EXIT_CODE -ne 0 ]; then
    echo -e "   ${RED}✗${NC} curl command failed (exit code: ${CURL_EXIT_CODE})"
    echo ""
    # Continue to next test
else
    HTTP_CODE=$(echo "$TOOLS_RESPONSE" | tail -n1)
    RESPONSE_BODY=$(echo "$TOOLS_RESPONSE" | sed '$d')

    if [ "$HTTP_CODE" -eq 200 ]; then
        echo -e "   ${GREEN}✓${NC} HTTP Status: 200 OK"
        
        if echo "$RESPONSE_BODY" | grep -q '"tools"'; then
            TOOL_COUNT=$(echo "$RESPONSE_BODY" | grep -o '"name"' | wc -l)
            echo -e "   ${GREEN}✓${NC} Found ${TOOL_COUNT} tool(s)"
            
            if [ "$VERBOSE" = true ]; then
                echo ""
                echo "Response:"
                if echo "$RESPONSE_BODY" | python3 -m json.tool 2>/dev/null; then
                    :
                else
                    echo -e "   ${YELLOW}⚠${NC}  Warning: Response is not valid JSON, showing raw output:"
                    echo "$RESPONSE_BODY"
                fi
            fi
        else
            echo -e "   ${YELLOW}⚠${NC}  No tools found or invalid response"
        fi
    else
        echo -e "   ${RED}✗${NC} HTTP Status: ${HTTP_CODE}"
        
        if [ "$VERBOSE" = true ]; then
            echo ""
            echo "Response:"
            echo "$RESPONSE_BODY"
        fi
    fi
fi

echo ""

# Test 3: List Prompts (Assistants)
echo "Test 3: List available prompts"
echo "Method: prompts/list"
echo ""

PROMPTS_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "${MCP_ENDPOINT}" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -d '{
    "jsonrpc": "2.0",
    "id": 3,
    "method": "prompts/list",
    "params": {}
  }' 2>&1)

CURL_EXIT_CODE=$?

if [ $CURL_EXIT_CODE -ne 0 ]; then
    echo -e "   ${RED}✗${NC} curl command failed (exit code: ${CURL_EXIT_CODE})"
    echo ""
    # Continue to summary
else
    HTTP_CODE=$(echo "$PROMPTS_RESPONSE" | tail -n1)
    RESPONSE_BODY=$(echo "$PROMPTS_RESPONSE" | sed '$d')

    if [ "$HTTP_CODE" -eq 200 ]; then
        echo -e "   ${GREEN}✓${NC} HTTP Status: 200 OK"
        
        if echo "$RESPONSE_BODY" | grep -q '"prompts"'; then
            PROMPT_COUNT=$(echo "$RESPONSE_BODY" | grep -o '"name"' | wc -l)
            echo -e "   ${GREEN}✓${NC} Found ${PROMPT_COUNT} prompt(s)"
            
            if [ "$VERBOSE" = true ]; then
                echo ""
                echo "Response:"
                if echo "$RESPONSE_BODY" | python3 -m json.tool 2>/dev/null; then
                    :
                else
                    echo -e "   ${YELLOW}⚠${NC}  Warning: Response is not valid JSON, showing raw output:"
                    echo "$RESPONSE_BODY"
                fi
            fi
        else
            echo -e "   ${YELLOW}⚠${NC}  No prompts found or invalid response"
        fi
    else
        echo -e "   ${RED}✗${NC} HTTP Status: ${HTTP_CODE}"
        
        if [ "$VERBOSE" = true ]; then
            echo ""
            echo "Response:"
            echo "$RESPONSE_BODY"
        fi
    fi
fi

echo ""

# Summary
echo "=========================================="
echo "Test Summary"
echo "=========================================="
echo ""

INIT_CODE=$(echo "$INIT_RESPONSE" | tail -n1)
TOOLS_CODE=$(echo "$TOOLS_RESPONSE" | tail -n1)
PROMPTS_CODE=$(echo "$PROMPTS_RESPONSE" | tail -n1)

if [ "$INIT_CODE" -eq 200 ] && [ "$TOOLS_CODE" -eq 200 ] && [ "$PROMPTS_CODE" -eq 200 ]; then
    echo -e "${GREEN}✓ ALL TESTS PASSED${NC}"
    echo ""
    echo "The MCP JSON-RPC endpoint is working correctly!"
    echo "You can now configure your MCP clients to use:"
    echo "  ${MCP_ENDPOINT}"
    exit 0
else
    echo -e "${RED}✗ SOME TESTS FAILED${NC}"
    echo ""
    echo "Failed tests:"
    [ "$INIT_CODE" -ne 200 ] && echo "  - Initialize (HTTP ${INIT_CODE})"
    [ "$TOOLS_CODE" -ne 200 ] && echo "  - Tools list (HTTP ${TOOLS_CODE})"
    [ "$PROMPTS_CODE" -ne 200 ] && echo "  - Prompts list (HTTP ${PROMPTS_CODE})"
    echo ""
    echo "Troubleshooting:"
    echo "  1. Verify the URL is correct"
    echo "  2. Check the token is valid and not revoked"
    echo "  3. Ensure REST API is enabled on WordPress"
    echo "  4. Check no firewall or security plugin is blocking access"
    exit 1
fi
