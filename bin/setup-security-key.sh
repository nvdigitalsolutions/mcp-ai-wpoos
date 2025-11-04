#!/bin/bash
# Quick setup script for WP MCP AI security key
# Run this script in your WordPress root directory

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# The security key
KEY="WP_MCP_AI_AUTH_eb9923d6159ee0283ffdcfcb1bbfb821"
KEY_FILE=".wp-mcp-ai-key"

echo "======================================"
echo "WP MCP AI Security Key Setup (Optional)"
echo "======================================"
echo ""
echo -e "${YELLOW}Note: The security key is OPTIONAL and disabled by default.${NC}"
echo "Only use this if you want enhanced security for production environments."
echo ""

# Check if we're in WordPress root by looking for wp-config.php
if [ ! -f "wp-config.php" ]; then
    echo -e "${RED}Error: wp-config.php not found!${NC}"
    echo "Please run this script from your WordPress root directory."
    echo ""
    echo "Example:"
    echo "  cd /path/to/wordpress"
    echo "  bash wp-content/plugins/wp-mcp-ai/bin/setup-security-key.sh"
    exit 1
fi

# Check if key file already exists
if [ -f "$KEY_FILE" ]; then
    echo -e "${YELLOW}Warning: $KEY_FILE already exists!${NC}"
    read -p "Do you want to overwrite it? (y/N) " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Setup cancelled."
        exit 0
    fi
fi

# Create the key file
echo "$KEY" > "$KEY_FILE"

# Set secure permissions
chmod 600 "$KEY_FILE"

echo -e "${GREEN}✓ Security key file created successfully!${NC}"
echo ""
echo "File: $(pwd)/$KEY_FILE"
echo "Permissions: $(ls -l $KEY_FILE | awk '{print $1}')"
echo ""
echo -e "${YELLOW}IMPORTANT: To enable the security check, add this to wp-config.php:${NC}"
echo "  define( 'WP_MCP_AI_REQUIRE_KEY', true );"
echo ""
echo "Without this constant, the plugin will work normally without requiring the key file."
echo ""
echo "For more information, see:"
echo "  - SECURITY-KEY-SETUP.md"
echo "  - https://github.com/nvdigitalsolutions/wp-mcp-ai"
