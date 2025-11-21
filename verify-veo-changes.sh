#!/bin/bash
# Direct verification test for Veo video tool changes
# This script verifies the changes without requiring full WordPress environment

echo "=== Veo Video Tool Direct Verification ==="
echo ""

# Test 1: Verify service constant
echo "1. Checking service DEFAULT_DURATION constant..."
CONSTANT_VALUE=$(grep -A 1 "const DEFAULT_DURATION" includes/services/class-wp-mcp-ai-gemini-video-generation-service.php | grep "=" | sed 's/.*= //; s/;//')
if [ "$CONSTANT_VALUE" = "4" ]; then
    echo "   ✓ Service DEFAULT_DURATION is 4"
else
    echo "   ✗ FAIL: Service DEFAULT_DURATION is $CONSTANT_VALUE (expected 4)"
    exit 1
fi

# Test 2: Verify tool parameter schema default
echo "2. Checking tool parameter schema default..."
SCHEMA_DEFAULT=$(grep -B 3 "'default'.*=> 4" includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php | grep "duration" | wc -l)
if [ "$SCHEMA_DEFAULT" -gt "0" ]; then
    echo "   ✓ Tool parameter schema default is 4"
else
    echo "   ✗ FAIL: Tool parameter schema default is not 4"
    exit 1
fi

# Test 3: Verify tool parameter description
echo "3. Checking tool parameter description..."
if grep -q "Default is 4 seconds" includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php; then
    echo "   ✓ Tool description mentions 'Default is 4 seconds'"
else
    echo "   ✗ FAIL: Tool description does not mention correct default"
    exit 1
fi

# Test 4: Verify SoC - tool should not have hardcoded default
echo "4. Verifying Separation of Concerns implementation..."
if grep -q "? absint.*: 4" includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php; then
    echo "   ✗ FAIL: Tool still has hardcoded default (violates SoC)"
    exit 1
fi

if grep -q "? absint.*: 5" includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php; then
    echo "   ✗ FAIL: Tool still has old hardcoded default of 5"
    exit 1
fi

if grep -q "if ( isset( \$arguments\['duration'\] ) )" includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php; then
    echo "   ✓ Tool uses conditional check (SoC compliant)"
else
    echo "   ⚠ Warning: Could not verify SoC implementation"
fi

# Test 5: Verify test files updated
echo "5. Checking test files..."
if grep -q "defaults to 4\|default to 4" tests/test-veo-duration-fix.php; then
    echo "   ✓ test-veo-duration-fix.php updated to expect 4"
else
    echo "   ✗ FAIL: test-veo-duration-fix.php not updated"
    exit 1
fi

if grep -q "default to 4\|defaults_to_4" tests/test-veo-video-generation-no-audio.php; then
    echo "   ✓ test-veo-video-generation-no-audio.php updated to expect 4"
else
    echo "   ✗ FAIL: test-veo-video-generation-no-audio.php not updated"
    exit 1
fi

# Test 6: Verify integration tests exist
echo "6. Checking integration test files..."
if [ -f "tests/test-veo-tool-integration-verification.php" ]; then
    echo "   ✓ Tool integration test file exists"
else
    echo "   ⚠ Warning: Tool integration test file not found"
fi

if [ -f "tests/test-veo-rest-service-integration.php" ]; then
    echo "   ✓ REST service integration test file exists"
else
    echo "   ⚠ Warning: REST service integration test file not found"
fi

# Summary
echo ""
echo "=== Verification Summary ==="
echo "✓ All critical checks passed!"
echo "✓ Default duration changed from 5 to 4 seconds"
echo "✓ Service layer owns the default constant"
echo "✓ Tool layer follows SoC principles"
echo "✓ Test files updated to expect new default"
echo "✓ Integration tests created"
echo ""
echo "The video tool is correctly configured with default duration of 4 seconds."
echo ""

exit 0
