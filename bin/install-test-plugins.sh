#!/bin/bash
# Install optional plugins for integration tests

set -e

WP_DIR="${WP_DIR:-.codex-wordpress/wordpress}"
PLUGINS_DIR="$WP_DIR/wp-content/plugins"

echo "🔌 Installing test plugins for integration tests..."

# Check if WP-CLI is available
if ! command -v wp &> /dev/null; then
    echo "⚠️  WP-CLI not found. Installing plugins will be skipped."
    echo "   Integration tests requiring these plugins will be marked as skipped."
    exit 0
fi

# Function to install and activate plugin
install_plugin() {
    local plugin_slug=$1
    local plugin_name=$2
    
    if [ ! -d "$PLUGINS_DIR/$plugin_slug" ]; then
        echo "📦 Installing $plugin_name..."
        wp plugin install "$plugin_slug" --activate --path="$WP_DIR" --allow-root 2>&1 || {
            echo "⚠️  Failed to install $plugin_name. Tests requiring this plugin will be skipped."
            return 1
        }
        echo "✅ $plugin_name installed"
    else
        echo "✓ $plugin_name already installed"
        wp plugin activate "$plugin_slug" --path="$WP_DIR" --allow-root 2>&1 || true
    fi
}

# Install WooCommerce (free)
install_plugin "woocommerce" "WooCommerce"

# Install Elementor (free)
install_plugin "elementor" "Elementor"

# Install Rank Math (free)
install_plugin "seo-by-rank-math" "Rank Math SEO"

# Install WPCode (free)
install_plugin "insert-headers-and-footers" "WPCode"

# Install Simple JWT Login (free)
install_plugin "simple-jwt-login" "Simple JWT Login"

echo ""
echo "✨ Test plugin installation complete!"
echo ""
echo "Installed plugins:"
echo "  ✅ WooCommerce - E-commerce features (3 tools)"
echo "  ✅ Elementor - Page builder (1 tool + widgets)"
echo "  ✅ Rank Math SEO - SEO features (1 tool)"
echo "  ✅ WPCode - Code snippets (1 tool)"
echo "  ✅ Simple JWT Login - JWT tokens (1 tool)"
echo ""
echo "Note: JetEngine is a premium plugin and must be installed manually."
echo "      Tests requiring JetEngine will be marked as skipped."
echo ""
