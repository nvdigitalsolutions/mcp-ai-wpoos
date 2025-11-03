#!/usr/bin/env bash
#
# Test script for LM Studio connection and model fetching.
# This script tests the connection to a local LM Studio instance.
#
# Usage:
#   ./bin/test-lm-studio-local.sh [endpoint_url]
#
# Default endpoint: http://127.0.0.1:1234
#

set -euo pipefail

# Configuration
ENDPOINT_URL="${1:-http://127.0.0.1:1234}"
MODELS_ENDPOINT="${ENDPOINT_URL}/v1/models"

echo ""
echo "=========================================="
echo "LM Studio Connection Test"
echo "=========================================="
echo ""
echo "Endpoint: ${ENDPOINT_URL}"
echo ""

# Test 1: Connection check
echo "Test 1: Testing connection..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 5 "${MODELS_ENDPOINT}" || echo "000")

if [ "$HTTP_CODE" = "000" ]; then
    echo "   ✗ FAILED: Could not connect to ${ENDPOINT_URL}"
    echo ""
    echo "Please ensure:"
    echo "  1. LM Studio is running"
    echo "  2. Local server is enabled in LM Studio"
    echo "  3. Server is listening on ${ENDPOINT_URL}"
    echo ""
    exit 1
elif [ "$HTTP_CODE" != "200" ]; then
    echo "   ✗ FAILED: HTTP ${HTTP_CODE}"
    exit 1
fi

echo "   ✓ Connected successfully (HTTP ${HTTP_CODE})"
echo ""

# Test 2: Fetch models
echo "Test 2: Fetching available models..."
RESPONSE=$(curl -s -H "Accept: application/json" "${MODELS_ENDPOINT}")

# Check if response is valid JSON
if ! echo "$RESPONSE" | jq . >/dev/null 2>&1; then
    echo "   ✗ FAILED: Invalid JSON response"
    echo "   Response: ${RESPONSE}"
    exit 1
fi

# Check for data array
MODEL_COUNT=$(echo "$RESPONSE" | jq '.data | length' 2>/dev/null || echo "0")

if [ "$MODEL_COUNT" = "0" ]; then
    echo "   ⚠ WARNING: No models found"
    echo ""
    echo "Please ensure:"
    echo "  1. At least one model is loaded in LM Studio"
    echo "  2. The model is enabled for the local server"
    echo ""
    echo "Raw response:"
    echo "$RESPONSE" | jq .
    exit 1
fi

echo "   ✓ Found ${MODEL_COUNT} model(s)"
echo ""

# Test 3: Verify data structure
echo "Test 3: Verifying data structure..."
echo ""

# Extract model IDs
MODEL_IDS=$(echo "$RESPONSE" | jq -r '.data[].id' 2>/dev/null)

if [ -z "$MODEL_IDS" ]; then
    echo "   ✗ FAILED: No model IDs found in response"
    echo "   Response structure:"
    echo "$RESPONSE" | jq .
    exit 1
fi

echo "   ✓ Models with 'id' field:"
while IFS= read -r model_id; do
    echo "      - ${model_id}"
done <<< "$MODEL_IDS"
echo ""

# Test 4: Simulate PHP processing
echo "Test 4: Simulating PHP data transformation..."
echo ""

# Create a temporary PHP script to test the transformation
cat > /tmp/test-lm-studio-transform.php << 'EOPHP'
<?php
$json_response = file_get_contents('php://stdin');
$decoded = json_decode($json_response, true);

$models = array();
if (isset($decoded['data']) && is_array($decoded['data'])) {
    foreach ($decoded['data'] as $model) {
        if (isset($model['id'])) {
            $models[] = array(
                'id'       => $model['id'],
                'owned_by' => isset($model['owned_by']) ? $model['owned_by'] : '',
                'created'  => isset($model['created']) ? $model['created'] : 0,
            );
        }
    }
}

echo json_encode($models, JSON_PRETTY_PRINT);
EOPHP

# Process through PHP
PROCESSED=$(echo "$RESPONSE" | php /tmp/test-lm-studio-transform.php)

echo "   Processed models array:"
echo "$PROCESSED" | jq .
echo ""

# Test 5: JavaScript compatibility check
echo "Test 5: Verifying JavaScript compatibility..."
echo ""

# Check if the processed array has 'id' key
HAS_ID=$(echo "$PROCESSED" | jq '.[0] | has("id")' 2>/dev/null)

if [ "$HAS_ID" = "true" ]; then
    FIRST_ID=$(echo "$PROCESSED" | jq -r '.[0].id')
    echo "   ✓ JavaScript can access model.id"
    echo "   Example: models[0].id = '${FIRST_ID}'"
else
    echo "   ✗ FAILED: Processed models missing 'id' key"
    exit 1
fi

echo ""
echo "=========================================="
echo "ALL TESTS PASSED ✓"
echo "=========================================="
echo ""
echo "Summary:"
echo "  - Connection: OK"
echo "  - Models found: ${MODEL_COUNT}"
echo "  - Data structure: Compatible with JavaScript"
echo ""
echo "The LM Studio integration should work correctly!"
echo ""
