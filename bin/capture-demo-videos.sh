#!/usr/bin/env bash
# ───────────────────────────────────────────────────────────────
# NV oOS — Demo Video Capture Pipeline
# ───────────────────────────────────────────────────────────────
#
# Spins up the Dockerized WordPress environment, configures the
# plugin via WP-CLI, creates test data, and runs all Playwright
# video capture scripts.
#
# Usage:
#   bash bin/capture-demo-videos.sh
#
# Environment variables:
#   BASE_URL         WordPress URL (default: http://localhost:8000)
#   WP_ADMIN_USER    Admin username (default: admin)
#   WP_ADMIN_PASS    Admin password (default: password)
#   OPENAI_API_KEY   OpenAI key for AI provider config (optional)
#   GEMINI_API_KEY   Gemini key for AI provider config (optional)
#   CAPTURE_PRO      Set to 'false' to skip Pro videos (default: true)
#
# Requirements:
#   - Docker 24+ with Docker Compose plugin
#   - Node.js 18+ with Playwright installed (npx playwright install chromium)
#   - FFmpeg (optional, for .webm → .mp4 optimization)
# ───────────────────────────────────────────────────────────────

set -euo pipefail

# ── Configuration ─────────────────────────────────────────────
BASE_URL="${BASE_URL:-http://localhost:8000}"
ADMIN_USER="${WP_ADMIN_USER:-admin}"
ADMIN_PASS="${WP_ADMIN_PASS:-password}"
VIDEO_DIR="docs/videos"
BASE_VIDEO_DIR="${VIDEO_DIR}/base"
PRO_VIDEO_DIR="${VIDEO_DIR}/pro"

# Resolve to repo root (parent of bin/)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

# ── Colour helpers ────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color
info()  { echo -e "${GREEN}[VIDEO]${NC} $1"; }
warn()  { echo -e "${YELLOW}[VIDEO]${NC} $1"; }
err()   { echo -e "${RED}[VIDEO]${NC} $1"; }
step()  { echo -e "${CYAN}[STEP]${NC}  $1"; }

# ── Prerequisites check ───────────────────────────────────────
check_prereqs() {
	info "Checking prerequisites..."

	# Docker
	if ! docker compose version &>/dev/null; then
		err "Docker Compose is not available. Please install Docker 24+."
		exit 1
	fi

	# Start Docker if not running
	if ! docker compose ps 2>/dev/null | grep -q "Up"; then
		info "Starting Docker environment..."
		docker compose up -d
		info "Waiting for WordPress to be ready..."
		for i in $(seq 1 30); do
			if curl -s -o /dev/null -w "%{http_code}" "$BASE_URL" 2>/dev/null | grep -q "200\|302"; then
				break
			fi
			sleep 2
		done
	fi

	# Verify WordPress responds
	if ! curl -s -o /dev/null -w "%{http_code}" "$BASE_URL" | grep -q "200\|302"; then
		err "WordPress is not responding at $BASE_URL"
		err "Check: docker compose logs wordpress"
		exit 1
	fi

	info "WordPress is ready at $BASE_URL"

	# Node.js
	if ! command -v node &>/dev/null; then
		err "Node.js is not installed. Please install Node.js 18+."
		exit 1
	fi

	# Playwright
	if ! node -e "require('playwright')" 2>/dev/null; then
		warn "Playwright is not installed globally."
		warn "Install with: npm install playwright && npx playwright install chromium"
		warn "Attempting to continue..."
	fi
}

# ── WordPress setup via WP-CLI ─────────────────────────────────
setup_wordpress() {
	info "Setting up WordPress..."

	INSTALL_STATUS=$(docker compose run --rm wp-cli core is-installed 2>&1 || echo "not installed")

	if echo "$INSTALL_STATUS" | grep -q "not installed"; then
		step "Installing WordPress..."
		docker compose run --rm wp-cli core install \
			--url="$BASE_URL" \
			--title="NV oOS Demo" \
			--admin_user="$ADMIN_USER" \
			--admin_password="$ADMIN_PASS" \
			--admin_email="demo@example.com" \
			--skip-email
	else
		info "WordPress already installed"
	fi

	step "Activating plugin..."
	docker compose run --rm wp-cli plugin activate mcp-ai-wpoos 2>&1 || true

	# Activate pro if available
	if [ -d "addons/pro" ]; then
		step "Activating Pro addon..."
		docker compose run --rm wp-cli plugin activate mcp-ai-wpoos-pro 2>&1 || \
			warn "Pro activation failed — Pro page videos may be blank"
	fi

	# Configure AI provider if API key is set
	if [ -n "${OPENAI_API_KEY:-}" ]; then
		step "Configuring OpenAI..."
		docker compose run --rm wp-cli option update wp_mcp_ai_openai_api_key "$OPENAI_API_KEY"
		docker compose run --rm wp-cli option update wp_mcp_ai_default_provider "openai"
		docker compose run --rm wp-cli option update wp_mcp_ai_default_model "gpt-4o"
	elif [ -n "${GEMINI_API_KEY:-}" ]; then
		step "Configuring Gemini..."
		docker compose run --rm wp-cli option update wp_mcp_ai_gemini_api_key "$GEMINI_API_KEY"
		docker compose run --rm wp-cli option update wp_mcp_ai_default_provider "gemini"
	else
		warn "No AI provider API key set (OPENAI_API_KEY or GEMINI_API_KEY)"
		warn "Chat-dependent videos will show blank/error states"
	fi

	info "WordPress setup complete."
}

# ── Create test data ──────────────────────────────────────────
create_test_data() {
	info "Creating test data..."

	# Create a demo page with chat shortcode
	PAGE_ID=$(docker compose run --rm wp-cli post create \
		--post_type=page \
		--post_title="AI Chat Demo" \
		--post_status=publish \
		--post_content='[mcp_ai_chat allow_guests="true"]' \
		--porcelain 2>&1 | grep -o '[0-9]*' | head -1 || echo "")

	if [ -n "$PAGE_ID" ]; then
		info "Created chat demo page (ID: $PAGE_ID)"
		export PAGE_ID
	else
		warn "Could not create chat demo page"
	fi

	# Create sample posts for search tools to find
	for title in "Sample Blog Post" "Getting Started Guide" "Product Announcement"; do
		docker compose run --rm wp-cli post create \
			--post_type=post \
			--post_title="$title" \
			--post_status=publish \
			--post_content="This is a sample post for testing the AI search and content tools." \
			--porcelain 2>&1 | grep -o '[0-9]*' | head -1 > /dev/null || true
	done
	info "Created sample content"
}

# ── Run video capture scripts ──────────────────────────────────
capture_base_videos() {
	mkdir -p "$BASE_VIDEO_DIR"

	info "──────────────────────────────────────────"
	info "Capturing Base Plugin videos..."
	info "──────────────────────────────────────────"
	echo ""

	local scripts=(
		"capture-demo-video-assistant.js:Add Assistant & Tools"
	)

	if [ -f "$SCRIPT_DIR/capture-demo-video-provider.js" ]; then
		scripts+=("capture-demo-video-provider.js:Configure AI Provider")
	fi
	if [ -f "$SCRIPT_DIR/capture-demo-video-chat.js" ]; then
		scripts+=("capture-demo-video-chat.js:Chat Conversation")
	fi
	if [ -f "$SCRIPT_DIR/capture-demo-video-chat-tools.js" ]; then
		scripts+=("capture-demo-video-chat-tools.js:Chat with Tool Execution")
	fi
	if [ -f "$SCRIPT_DIR/capture-demo-video-guest.js" ]; then
		scripts+=("capture-demo-video-guest.js:Guest Mode Chat")
	fi
	if [ -f "$SCRIPT_DIR/capture-demo-video-tools-manager.js" ]; then
		scripts+=("capture-demo-video-tools-manager.js:Manage Tools & Presets")
	fi
	if [ -f "$SCRIPT_DIR/capture-demo-video-profession.js" ]; then
		scripts+=("capture-demo-video-profession.js:Create Profession Template")
	fi

	for entry in "${scripts[@]}"; do
		local script_file="${entry%%:*}"
		local label="${entry##*:}"

		step "Recording: $label"
		if node "$SCRIPT_DIR/$script_file"; then
			info "  ✅ $label"
		else
			warn "  ⚠️  $label FAILED (continuing...)"
		fi
		echo ""
	done
}

capture_pro_videos() {
	if [ ! -d "addons/pro" ]; then
		warn "Pro addon not found — skipping Pro videos."
		return
	fi

	mkdir -p "$PRO_VIDEO_DIR"

	info "──────────────────────────────────────────"
	info "Capturing Pro Plugin videos..."
	info "──────────────────────────────────────────"
	echo ""

	if [ -f "$SCRIPT_DIR/capture-demo-video-pro.js" ]; then
		step "Recording: Pro Plugin Features"
		if node "$SCRIPT_DIR/capture-demo-video-pro.js"; then
			info "  ✅ Pro videos"
		else
			warn "  ⚠️  Pro videos FAILED (continuing...)"
		fi
	else
		warn "Pro video script not found — skipping."
	fi
	echo ""
}

# ── Optimize output ────────────────────────────────────────────
optimize_videos() {
	info "──────────────────────────────────────────"
	info "Optimizing videos..."
	info "──────────────────────────────────────────"

	if ! command -v ffmpeg &>/dev/null; then
		warn "FFmpeg not found — videos remain as .webm"
		warn "Install FFmpeg for automatic .webm → .mp4 conversion."
		return
	fi

	local count=0
	while IFS= read -r -d '' webm; do
		local mp4="${webm%.webm}.mp4"
		if ffmpeg -y -i "$webm" -c:v libx264 -preset fast -crf 28 \
			-c:a aac -b:a 128k -movflags +faststart "$mp4" 2>/dev/null; then
			echo "  ✅ ${mp4#$REPO_ROOT/}"
			((count++))
		else
			warn "  ⚠️  Failed: ${webm#$REPO_ROOT/}"
		fi
	done < <(find "$VIDEO_DIR" -name "*.webm" -print0)

	info "Optimized $count video(s)"
}

# ── Print summary ──────────────────────────────────────────────
print_summary() {
	echo ""
	info "═══════════════════════════════════════════"
	info "  NV oOS Demo Video Pipeline — Complete"
	info "═══════════════════════════════════════════"
	echo ""

	if [ -d "$BASE_VIDEO_DIR" ]; then
		info "Base plugin videos:"
		find "$BASE_VIDEO_DIR" -type f \( -name "*.webm" -o -name "*.mp4" \) -exec basename {} \; | sort | while read -r f; do
			echo "    $BASE_VIDEO_DIR/$f"
		done
	fi

	if [ -d "$PRO_VIDEO_DIR" ]; then
		info "Pro plugin videos:"
		find "$PRO_VIDEO_DIR" -type f \( -name "*.webm" -o -name "*.mp4" \) -exec basename {} \; | sort | while read -r f; do
			echo "    $PRO_VIDEO_DIR/$f"
		done
	fi

	echo ""
	info "To re-record everything:"
	echo "    docker compose down -v && bash bin/capture-demo-videos.sh"
	echo ""
	info "To record a single video:"
	echo "    node bin/capture-demo-video-assistant.js"
	echo ""
}

# ── Main ───────────────────────────────────────────────────────
main() {
	echo ""
	info "═══════════════════════════════════════════"
	info "  NV oOS Demo Video Pipeline"
	info "═══════════════════════════════════════════"
	echo ""

	check_prereqs
	setup_wordpress
	create_test_data

	echo ""
	capture_base_videos

	if [ "${CAPTURE_PRO:-true}" = "true" ]; then
		capture_pro_videos
	fi

	optimize_videos
	print_summary
}

main "$@"
