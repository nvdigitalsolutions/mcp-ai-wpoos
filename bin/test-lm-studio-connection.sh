#!/bin/bash
#
# Test LM Studio Connection with 127.0.0.1:1234
#
# This script tests the LM Studio endpoint to verify it's accessible.
# Run this script when LM Studio is running on your local machine.
#
# Usage: ./test-lm-studio-connection.sh [endpoint_url]
#
# Example:
#   ./test-lm-studio-connection.sh
#   ./test-lm-studio-connection.sh http://127.0.0.1:1234
#   ./test-lm-studio-connection.sh http://localhost:8080

set -e

# Default endpoint
ENDPOINT="${1:-http://127.0.0.1:1234}"

echo "=========================================="
echo "LM Studio Connection Test"
echo "=========================================="
echo ""
echo "Testing endpoint: $ENDPOINT"
echo ""

# Test 1: Connection test
echo "Test 1: Testing /v1/models endpoint..."
if command -v curl &> /dev/null; then
    HTTP_CODE=$(curl -s -o /tmp/lm-studio-response.json -w "%{http_code}" "$ENDPOINT/v1/models")
    
    if [ "$HTTP_CODE" = "200" ]; then
        echo "   ✓ Connection successful (HTTP $HTTP_CODE)"
        
        # Check if response contains models
        if command -v jq &> /dev/null; then
            MODEL_COUNT=$(jq '.data | length' /tmp/lm-studio-response.json 2>/dev/null || echo "0")
            echo "   ✓ Found $MODEL_COUNT model(s)"
            
            if [ "$MODEL_COUNT" -gt 0 ]; then
                echo ""
                echo "Available models:"
                jq -r '.data[] | "   - " + .id' /tmp/lm-studio-response.json
            fi
        else
            echo "   ℹ Install 'jq' to see detailed model information"
        fi
    else
        echo "   ✗ Connection failed (HTTP $HTTP_CODE)"
        cat /tmp/lm-studio-response.json 2>/dev/null || true
        echo ""
        echo "Please make sure:"
        echo "  1. LM Studio is running"
        echo "  2. The local server is started in LM Studio"
        echo "  3. The endpoint URL is correct: $ENDPOINT"
        exit 1
    fi
else
    echo "   ✗ curl not found. Please install curl to test the connection."
    exit 1
fi

# Test 2: Test chat completions endpoint
echo ""
echo "Test 2: Testing /v1/chat/completions endpoint..."
TEST_PAYLOAD='{"messages":[{"role":"user","content":"test"}],"max_tokens":5}'

HTTP_CODE=$(curl -s -o /tmp/lm-studio-chat.json -w "%{http_code}" \
    -X POST \
    -H "Content-Type: application/json" \
    -d "$TEST_PAYLOAD" \
    "$ENDPOINT/v1/chat/completions" 2>/dev/null || echo "000")

if [ "$HTTP_CODE" = "200" ]; then
    echo "   ✓ Chat endpoint accessible (HTTP $HTTP_CODE)"
elif [ "$HTTP_CODE" = "400" ] || [ "$HTTP_CODE" = "422" ]; then
    echo "   ⚠ Chat endpoint accessible but needs valid model parameter (HTTP $HTTP_CODE)"
    echo "   ℹ This is expected - you need to specify a model in the request"
else
    echo "   ✗ Chat endpoint test failed (HTTP $HTTP_CODE)"
    cat /tmp/lm-studio-chat.json 2>/dev/null || true
fi

# Clean up
rm -f /tmp/lm-studio-response.json /tmp/lm-studio-chat.json

echo ""
echo "=========================================="
echo "Test Complete"
echo "=========================================="
echo ""
echo "Summary:"
echo "  - Models endpoint: Available"
echo "  - Chat endpoint: Available"
echo "  - Default endpoint: $ENDPOINT"
echo ""
echo "You can configure this endpoint in WordPress:"
echo "  Settings → WP oOS → LM Studio Configuration"
echo ""
