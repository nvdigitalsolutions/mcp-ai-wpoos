#!/bin/bash
# Quick test script to check if federation directory setting is saved

echo "=== NV oOS Federation Directory Setting Check ==="
echo ""

# Check if wp-cli is available
if ! command -v wp &> /dev/null; then
    echo "❌ WP-CLI not found. Please install wp-cli to use this script."
    exit 1
fi

# Get the current setting value
echo "Fetching current settings..."
SETTINGS=$(wp option get wp_mcp_ai_settings --format=json 2>/dev/null)

if [ $? -ne 0 ]; then
    echo "❌ Failed to fetch settings. Make sure you're in a WordPress directory."
    exit 1
fi

echo "Current wp_mcp_ai_settings option:"
echo "$SETTINGS" | jq '.'

echo ""
echo "=== Specific Federation Settings ==="
echo ""

# Extract specific fields
FEDERATION_DIR=$(echo "$SETTINGS" | jq -r '.enable_federation_directory // "not set"')
MESH_ENABLED=$(echo "$SETTINGS" | jq -r '.enable_mesh // "not set"')
MESH_KEY=$(echo "$SETTINGS" | jq -r '.mesh_inbound_api_key // "not set"')
FED_REGIONS=$(echo "$SETTINGS" | jq -r '.federation_regions // "not set"')

echo "Enable Federation Directory: $FEDERATION_DIR"
echo "Enable Mesh Computing: $MESH_ENABLED"
echo "Mesh Inbound API Key: ${MESH_KEY:0:20}..." # Show only first 20 chars
echo "Federation Regions: $FED_REGIONS"

echo ""
echo "=== Status ==="
echo ""

if [ "$FEDERATION_DIR" = "true" ] || [ "$FEDERATION_DIR" = "1" ]; then
    echo "✅ Federation Directory is ENABLED"
else
    echo "❌ Federation Directory is DISABLED or not set"
fi

if [ "$MESH_ENABLED" = "true" ] || [ "$MESH_ENABLED" = "1" ]; then
    echo "✅ Mesh Computing is ENABLED"
    
    if [ "$MESH_KEY" = "not set" ] || [ "$MESH_KEY" = "" ]; then
        echo "⚠️  WARNING: Mesh is enabled but API key is missing!"
        echo "   This key should be auto-generated. Try saving settings again."
    fi
else
    echo "⚠️  Mesh Computing is DISABLED"
    echo "   Enable this in Tools → Features to generate the mesh_inbound_api_key"
fi

echo ""
echo "=== AI Peer Post Type Status ==="
PEER_COUNT=$(wp post list --post_type=ai_peer --format=count 2>/dev/null)
if [ $? -eq 0 ]; then
    echo "✅ AI Peer post type exists"
    echo "   Total AI Peers: $PEER_COUNT"
else
    echo "❌ AI Peer post type not registered"
    echo "   This is expected if federation directory is not enabled"
fi

echo ""
echo "=== Troubleshooting Tips ==="
echo ""
echo "If Federation Directory won't stay enabled:"
echo "1. Check browser console for JavaScript errors"
echo "2. Enable WP_DEBUG and check /wp-content/debug.log"
echo "3. Clear browser cache and hard refresh (Ctrl+Shift+R)"
echo "4. Verify subtab parameter in URL: &subtab=federation_mesh"
echo ""
echo "See FEDERATION_DIRECTORY_DEBUG.md for detailed debugging steps"
