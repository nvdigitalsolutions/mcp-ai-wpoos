#!/bin/bash
# WP MCP AI Remote Connection Test Script
# Tests connectivity to WordPress MCP AI server from various clients

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Default values
BASE_URL=""
TOKEN=""
ASSISTANT_ID=""
VERIFY_SSL="true"
TIMEOUT=30

# Function to print colored output
print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_info() {
    echo -e "ℹ $1"
}

# Function to display usage
usage() {
    cat << EOF
Usage: $0 [OPTIONS]

Test remote MCP connection to WordPress MCP AI server.

OPTIONS:
    -u, --url URL           Base URL to MCP server (required)
                            Example: https://example.com/wp-json/mcp-ai/v1
    
    -t, --token TOKEN       Bearer token for authentication (required)
                            Assistant credential: cred_xxxxx.SECRET
                            Auth0 token: eyJhbGci...
    
    -a, --assistant-id ID   Specific assistant ID to test (optional)
    
    -k, --insecure         Skip SSL certificate verification
    
    --timeout SECONDS      Request timeout (default: 30)
    
    -h, --help             Display this help message

EXAMPLES:
    # Test with assistant credential
    $0 -u https://example.com/wp-json/mcp-ai/v1 -t cred_xxxxx.SECRET
    
    # Test specific assistant with custom timeout
    $0 -u https://example.com/wp-json/mcp-ai/v1 -t cred_xxxxx.SECRET -a 123 --timeout 60
    
    # Test with self-signed certificate (testing only)
    $0 -u https://localhost:8000/wp-json/mcp-ai/v1 -t cred_xxxxx.SECRET -k

EOF
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -u|--url)
            BASE_URL="$2"
            shift 2
            ;;
        -t|--token)
            TOKEN="$2"
            shift 2
            ;;
        -a|--assistant-id)
            ASSISTANT_ID="$2"
            shift 2
            ;;
        -k|--insecure)
            VERIFY_SSL="false"
            shift
            ;;
        --timeout)
            TIMEOUT="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            print_error "Unknown option: $1"
            usage
            exit 1
            ;;
    esac
done

# Validate required parameters
if [ -z "$BASE_URL" ]; then
    print_error "Base URL is required"
    usage
    exit 1
fi

if [ -z "$TOKEN" ]; then
    print_error "Token is required"
    usage
    exit 1
fi

# Check if curl is available
if ! command -v curl &> /dev/null; then
    print_error "curl is required but not installed"
    exit 1
fi

# Check if jq is available for JSON parsing
HAS_JQ=false
if command -v jq &> /dev/null; then
    HAS_JQ=true
fi

echo "================================================"
echo "WP MCP AI Remote Connection Test"
echo "================================================"
echo ""
print_info "Base URL: $BASE_URL"
print_info "Timeout: ${TIMEOUT}s"
print_info "SSL Verification: $VERIFY_SSL"
echo ""

# Prepare curl options
CURL_OPTS=(
    -s
    -w "\n%{http_code}"
    -H "Authorization: Bearer $TOKEN"
    -H "Accept: application/json"
    --max-time "$TIMEOUT"
)

if [ "$VERIFY_SSL" = "false" ]; then
    CURL_OPTS+=(-k)
fi

# Test 1: GET /assistants
echo "Test 1: Testing GET /assistants endpoint..."
echo "-------------------------------------------"

ASSISTANTS_URL="$BASE_URL/assistants"
if [ -n "$ASSISTANT_ID" ]; then
    ASSISTANTS_URL="${ASSISTANTS_URL}?assistant_id=${ASSISTANT_ID}"
fi

RESPONSE=$(curl "${CURL_OPTS[@]}" "$ASSISTANTS_URL" 2>&1 | tail -2)
HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | head -1)

if [ "$HTTP_CODE" = "200" ]; then
    print_success "GET /assistants returned HTTP $HTTP_CODE"
    
    if [ "$HAS_JQ" = true ]; then
        ASSISTANT_COUNT=$(echo "$BODY" | jq -r '.assistants | length' 2>/dev/null || echo "N/A")
        TOKEN_SCOPE=$(echo "$BODY" | jq -r '.token_scope.type // "N/A"' 2>/dev/null || echo "N/A")
        
        print_info "Assistants found: $ASSISTANT_COUNT"
        print_info "Token scope: $TOKEN_SCOPE"
        
        # Display assistant details
        if [ "$ASSISTANT_COUNT" != "N/A" ] && [ "$ASSISTANT_COUNT" -gt 0 ]; then
            echo ""
            print_info "Available assistants:"
            echo "$BODY" | jq -r '.assistants[] | "  • ID: \(.id) - \(.title) (\(.model))"' 2>/dev/null || echo "  (Could not parse assistant details)"
        fi
    else
        print_warning "Install jq for detailed response parsing"
    fi
else
    print_error "GET /assistants failed with HTTP $HTTP_CODE"
    
    if [ "$HAS_JQ" = true ]; then
        ERROR_CODE=$(echo "$BODY" | jq -r '.code // "N/A"' 2>/dev/null || echo "N/A")
        ERROR_MSG=$(echo "$BODY" | jq -r '.message // "N/A"' 2>/dev/null || echo "N/A")
        
        if [ "$ERROR_CODE" != "N/A" ]; then
            print_error "Error code: $ERROR_CODE"
            print_error "Message: $ERROR_MSG"
        fi
    fi
    
    echo ""
    print_info "Raw response:"
    echo "$BODY"
    exit 1
fi

echo ""

# Test 2: POST /chat (probe mode)
echo "Test 2: Testing POST /chat endpoint (probe mode)..."
echo "---------------------------------------------------"

CHAT_URL="$BASE_URL/chat"

# Determine assistant ID for chat probe
CHAT_ASSISTANT_ID="$ASSISTANT_ID"
if [ -z "$CHAT_ASSISTANT_ID" ] && [ "$HAS_JQ" = true ]; then
    # Try to extract first assistant ID from previous response
    CHAT_ASSISTANT_ID=$(echo "$BODY" | jq -r '.assistants[0].id // ""' 2>/dev/null)
fi

if [ -z "$CHAT_ASSISTANT_ID" ]; then
    print_warning "No assistant ID available for chat test, skipping..."
else
    CHAT_PAYLOAD=$(cat <<EOF
{
  "assistant_id": $CHAT_ASSISTANT_ID,
  "messages": [
    {
      "role": "user",
      "content": "Connectivity probe from remote test script."
    }
  ],
  "options": {
    "probe": true
  }
}
EOF
)

    CHAT_RESPONSE=$(curl "${CURL_OPTS[@]}" \
        -X POST \
        -H "Content-Type: application/json" \
        -d "$CHAT_PAYLOAD" \
        "$CHAT_URL" 2>&1 | tail -2)
    
    CHAT_HTTP_CODE=$(echo "$CHAT_RESPONSE" | tail -1)
    CHAT_BODY=$(echo "$CHAT_RESPONSE" | head -1)
    
    if [ "$CHAT_HTTP_CODE" = "200" ]; then
        print_success "POST /chat returned HTTP $CHAT_HTTP_CODE"
        
        if [ "$HAS_JQ" = true ]; then
            PROBE_STATUS=$(echo "$CHAT_BODY" | jq -r '.probe.status // "N/A"' 2>/dev/null || echo "N/A")
            PROBE_TIME=$(echo "$CHAT_BODY" | jq -r '.probe.checked_at // "N/A"' 2>/dev/null || echo "N/A")
            
            print_info "Probe status: $PROBE_STATUS"
            print_info "Checked at: $PROBE_TIME"
        fi
    else
        print_error "POST /chat failed with HTTP $CHAT_HTTP_CODE"
        
        if [ "$HAS_JQ" = true ]; then
            CHAT_ERROR_CODE=$(echo "$CHAT_BODY" | jq -r '.code // "N/A"' 2>/dev/null || echo "N/A")
            CHAT_ERROR_MSG=$(echo "$CHAT_BODY" | jq -r '.message // "N/A"' 2>/dev/null || echo "N/A")
            
            if [ "$CHAT_ERROR_CODE" != "N/A" ]; then
                print_error "Error code: $CHAT_ERROR_CODE"
                print_error "Message: $CHAT_ERROR_MSG"
            fi
        fi
        
        echo ""
        print_info "Raw response:"
        echo "$CHAT_BODY"
    fi
fi

echo ""

# Test 3: GET /sse (Server-Sent Events endpoint)
echo "Test 3: Testing GET /sse endpoint..."
echo "-------------------------------------"

SSE_URL="$BASE_URL/sse"

SSE_RESPONSE=$(curl "${CURL_OPTS[@]}" \
    -H "Accept: text/event-stream" \
    "$SSE_URL" 2>&1 | tail -2)

SSE_HTTP_CODE=$(echo "$SSE_RESPONSE" | tail -1)
SSE_BODY=$(echo "$SSE_RESPONSE" | head -1)

if [ "$SSE_HTTP_CODE" = "200" ]; then
    print_success "GET /sse returned HTTP $SSE_HTTP_CODE"
    
    # SSE response starts with "event: directory"
    if echo "$SSE_BODY" | grep -q "event: directory"; then
        print_success "SSE streaming format detected"
    else
        print_warning "Response may not be in SSE format"
    fi
else
    print_warning "GET /sse returned HTTP $SSE_HTTP_CODE (SSE may not be required for your client)"
fi

echo ""
echo "================================================"
echo "Test Summary"
echo "================================================"

if [ "$HTTP_CODE" = "200" ]; then
    print_success "Connection test PASSED"
    echo ""
    print_info "Your MCP server is reachable and functioning correctly."
    print_info "You can now configure your MCP client with these credentials."
    exit 0
else
    print_error "Connection test FAILED"
    echo ""
    print_info "Please check:"
    echo "  1. Base URL is correct and accessible"
    echo "  2. Token is valid and not revoked"
    echo "  3. WordPress REST API is enabled"
    echo "  4. SSL certificate is valid (or use -k for testing)"
    echo "  5. No firewall blocking /wp-json/mcp-ai/v1"
    exit 1
fi
