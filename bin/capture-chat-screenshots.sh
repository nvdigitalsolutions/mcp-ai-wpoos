#!/bin/bash
#
# Screenshot Capture Script for Chat Interface
# This script automates the setup and screenshot capture process for the NV oOS chat interface.
#
# Prerequisites:
# - Docker and Docker Compose installed
# - Playwright browser tools available
# - OpenAI, Gemini, or Ollama API key (set via environment variable)
#
# Usage:
#   export OPENAI_API_KEY="your-key-here"  # Or GEMINI_API_KEY or OLLAMA_URL
#   bash bin/capture-chat-screenshots.sh
#

set -e

# Color output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
WORDPRESS_URL="${WORDPRESS_URL:-http://localhost:8000}"
ADMIN_USER="${WP_ADMIN_USER:-admin}"
ADMIN_PASS="${WP_ADMIN_PASS:-StrongPassword123!}"
SCREENSHOTS_DIR="docs/screenshots/chat"

# Helper functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

check_prerequisites() {
    log_info "Checking prerequisites..."
    
    # Check if Docker is running
    if ! docker compose ps | grep -q "Up"; then
        log_error "Docker containers are not running. Start them with: docker compose up -d"
        exit 1
    fi
    
    # Check if WordPress is accessible
    if ! curl -s -o /dev/null -w "%{http_code}" "$WORDPRESS_URL" | grep -q "200\|302"; then
        log_warn "WordPress might not be ready yet. Waiting 30 seconds..."
        sleep 30
    fi
    
    # Check for API keys
    if [ -z "$OPENAI_API_KEY" ] && [ -z "$GEMINI_API_KEY" ] && [ -z "$OLLAMA_URL" ]; then
        log_error "No AI provider configured. Set one of: OPENAI_API_KEY, GEMINI_API_KEY, or OLLAMA_URL"
        log_info "Example: export OPENAI_API_KEY='sk-...'"
        exit 1
    fi
    
    log_info "Prerequisites check passed!"
}

setup_wordpress() {
    log_info "Setting up WordPress..."
    
    # Check if WordPress is installed
    INSTALL_STATUS=$(docker compose run --rm wp-cli core is-installed 2>&1 || echo "not installed")
    
    if echo "$INSTALL_STATUS" | grep -q "not installed"; then
        log_info "Installing WordPress..."
        docker compose run --rm wp-cli core install \
            --url="$WORDPRESS_URL" \
            --title="NV oOS Demo" \
            --admin_user="$ADMIN_USER" \
            --admin_password="$ADMIN_PASS" \
            --admin_email="admin@example.com" \
            --skip-email
    else
        log_info "WordPress already installed"
    fi
}

activate_plugin() {
    log_info "Activating NV oOS plugin..."
    
    docker compose run --rm wp-cli plugin activate wp-mcp-ai 2>&1 || log_warn "Plugin may already be activated"
}

configure_ai_provider() {
    log_info "Configuring AI provider..."
    
    if [ -n "$OPENAI_API_KEY" ]; then
        log_info "Configuring OpenAI..."
        docker compose run --rm wp-cli option update wp_mcp_ai_openai_api_key "$OPENAI_API_KEY"
        docker compose run --rm wp-cli option update wp_mcp_ai_default_provider "openai"
        docker compose run --rm wp-cli option update wp_mcp_ai_default_model "gpt-4"
    elif [ -n "$GEMINI_API_KEY" ]; then
        log_info "Configuring Gemini..."
        docker compose run --rm wp-cli option update wp_mcp_ai_gemini_api_key "$GEMINI_API_KEY"
        docker compose run --rm wp-cli option update wp_mcp_ai_default_provider "gemini"
        docker compose run --rm wp-cli option update wp_mcp_ai_default_model "gemini-pro"
    elif [ -n "$OLLAMA_URL" ]; then
        log_info "Configuring Ollama..."
        docker compose run --rm wp-cli option update wp_mcp_ai_ollama_url "$OLLAMA_URL"
        docker compose run --rm wp-cli option update wp_mcp_ai_default_provider "ollama"
        docker compose run --rm wp-cli option update wp_mcp_ai_default_model "llama2"
    fi
    
    log_info "AI provider configured successfully"
}

create_test_assistant() {
    log_info "Creating test assistant..."
    
    # Create assistant post via WP-CLI
    ASSISTANT_ID=$(docker compose run --rm wp-cli post create \
        --post_type=mcp_ai_assistant \
        --post_title="Demo Chat Assistant" \
        --post_status=publish \
        --post_content="A helpful AI assistant for demonstration purposes." \
        --meta_input='{"_mcp_ai_system_prompt":"You are a helpful AI assistant. Provide clear, concise answers to user questions.","_mcp_ai_model":"gpt-4","_mcp_ai_provider":"openai"}' \
        --porcelain 2>&1 | grep -o '[0-9]*' | head -1)
    
    echo "$ASSISTANT_ID" > /tmp/assistant_id.txt
    log_info "Created assistant with ID: $ASSISTANT_ID"
}

create_chat_page() {
    log_info "Creating chat demo page..."
    
    ASSISTANT_ID=$(cat /tmp/assistant_id.txt)
    
    # Create page with shortcode
    PAGE_ID=$(docker compose run --rm wp-cli post create \
        --post_type=page \
        --post_title="AI Chat Demo" \
        --post_status=publish \
        --post_content="[mcp_ai_chat assistant=\"$ASSISTANT_ID\"]" \
        --porcelain 2>&1 | grep -o '[0-9]*' | head -1)
    
    echo "$PAGE_ID" > /tmp/page_id.txt
    log_info "Created page with ID: $PAGE_ID"
    log_info "Chat page URL: $WORDPRESS_URL/?page_id=$PAGE_ID"
}

create_guest_mode_page() {
    log_info "Creating guest mode chat page..."
    
    ASSISTANT_ID=$(cat /tmp/assistant_id.txt)
    
    # Create page with guest mode enabled
    GUEST_PAGE_ID=$(docker compose run --rm wp-cli post create \
        --post_type=page \
        --post_title="AI Chat Demo (Guest)" \
        --post_status=publish \
        --post_content="[mcp_ai_chat assistant=\"$ASSISTANT_ID\" allow_guests=\"true\"]" \
        --porcelain 2>&1 | grep -o '[0-9]*' | head -1)
    
    echo "$GUEST_PAGE_ID" > /tmp/guest_page_id.txt
    log_info "Created guest page with ID: $GUEST_PAGE_ID"
    log_info "Guest chat page URL: $WORDPRESS_URL/?page_id=$GUEST_PAGE_ID"
}

print_screenshot_instructions() {
    log_info "========================================="
    log_info "Setup Complete! Ready to capture screenshots"
    log_info "========================================="
    echo ""
    log_info "WordPress Admin:"
    echo "  URL: $WORDPRESS_URL/wp-admin"
    echo "  User: $ADMIN_USER"
    echo "  Pass: $ADMIN_PASS"
    echo ""
    
    ASSISTANT_ID=$(cat /tmp/assistant_id.txt)
    PAGE_ID=$(cat /tmp/page_id.txt)
    GUEST_PAGE_ID=$(cat /tmp/guest_page_id.txt)
    
    log_info "Chat Pages:"
    echo "  Standard: $WORDPRESS_URL/?page_id=$PAGE_ID"
    echo "  Guest Mode: $WORDPRESS_URL/?page_id=$GUEST_PAGE_ID"
    echo ""
    
    log_info "Screenshots to capture (use Playwright or browser):"
    echo ""
    echo "1. Basic interface:"
    echo "   - Visit: $WORDPRESS_URL/?page_id=$PAGE_ID"
    echo "   - Screenshot: $SCREENSHOTS_DIR/frontend-shortcode.png"
    echo ""
    echo "2. Active conversation:"
    echo "   - Send messages and get responses"
    echo "   - Screenshot: $SCREENSHOTS_DIR/chat-conversation-example.png"
    echo ""
    echo "3. File attachments:"
    echo "   - Click upload button, select file"
    echo "   - Screenshot: $SCREENSHOTS_DIR/chat-with-attachments.png"
    echo ""
    echo "4. Tool execution:"
    echo "   - Trigger a tool (e.g., ask 'search my website for X')"
    echo "   - Screenshot: $SCREENSHOTS_DIR/chat-tool-execution.png"
    echo ""
    echo "5. Streaming response:"
    echo "   - Capture during response generation"
    echo "   - Screenshot: $SCREENSHOTS_DIR/chat-streaming-response.png"
    echo ""
    echo "6. Prompt shortcuts (if configured):"
    echo "   - Screenshot: $SCREENSHOTS_DIR/chat-shortcuts-buttons.png"
    echo ""
    echo "7. Error handling:"
    echo "   - Trigger error (disconnect network, invalid request)"
    echo "   - Screenshot: $SCREENSHOTS_DIR/chat-error-handling.png"
    echo ""
    echo "8. Mobile portrait (375x667):"
    echo "   - Resize browser or use DevTools device emulation"
    echo "   - Screenshot: $SCREENSHOTS_DIR/chat-mobile-portrait.png"
    echo ""
    echo "9. Mobile landscape (667x375):"
    echo "   - Screenshot: $SCREENSHOTS_DIR/chat-mobile-landscape.png"
    echo ""
    echo "10. Guest mode:"
    echo "   - Visit in incognito: $WORDPRESS_URL/?page_id=$GUEST_PAGE_ID"
    echo "   - Screenshot: $SCREENSHOTS_DIR/frontend-guest-mode.png"
    echo ""
    echo "11. localStorage view:"
    echo "   - Open DevTools → Application → Local Storage"
    echo "   - Screenshot: $SCREENSHOTS_DIR/chat-history-localstorage.png"
    echo ""
    echo "12. History restoration:"
    echo "   - Have conversation, reload page"
    echo "   - Screenshot: $SCREENSHOTS_DIR/chat-history-restoration.png"
    echo ""
    echo "13-16. Elementor widgets (requires Elementor plugin):"
    echo "   - Install Elementor first"
    echo "   - Screenshots:"
    echo "     - $SCREENSHOTS_DIR/elementor-chat-widget.png"
    echo "     - $SCREENSHOTS_DIR/elementor-chat-widget-frontend.png"
    echo "     - $SCREENSHOTS_DIR/elementor-dashboard-widgets.png"
    echo "     - $SCREENSHOTS_DIR/elementor-chat-intro-widget.png"
    echo ""
    log_info "========================================="
}

# Main execution
main() {
    log_info "NV oOS Chat Screenshots Setup Script"
    echo ""
    
    check_prerequisites
    setup_wordpress
    activate_plugin
    configure_ai_provider
    create_test_assistant
    create_chat_page
    create_guest_mode_page
    print_screenshot_instructions
    
    log_info "Setup complete! Follow the instructions above to capture screenshots."
}

main "$@"
