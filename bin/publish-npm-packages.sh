#!/bin/bash
#
# NPM Package Publishing Helper
#
# This script helps prepare and publish NPM packages to the NPM registry.
#
# Usage:
#   ./bin/publish-npm-packages.sh                    # Interactive mode
#   ./bin/publish-npm-packages.sh 0.1.0-alpha.2      # Specify version
#   ./bin/publish-npm-packages.sh 0.1.0-alpha.2 --dry-run  # Test mode
#   ./bin/publish-npm-packages.sh --trigger          # Trigger GitHub Action
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Parse arguments
VERSION=""
DRY_RUN=false
TRIGGER_ACTION=false
BUILD_ONLY=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --trigger)
            TRIGGER_ACTION=true
            shift
            ;;
        --build-only)
            BUILD_ONLY=true
            shift
            ;;
        -h|--help)
            echo "Usage: $0 [VERSION] [OPTIONS]"
            echo ""
            echo "Arguments:"
            echo "  VERSION         Alpha version to publish (e.g., 0.1.0-alpha.2)"
            echo ""
            echo "Options:"
            echo "  --dry-run       Build and verify packages without publishing"
            echo "  --build-only    Only build packages, don't publish or update versions"
            echo "  --trigger       Trigger GitHub Action workflow (requires gh CLI)"
            echo "  -h, --help      Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0                           # Interactive mode"
            echo "  $0 0.1.0-alpha.2             # Publish specific version"
            echo "  $0 0.1.0-alpha.2 --dry-run   # Test without publishing"
            echo "  $0 --trigger                 # Trigger GitHub Action"
            exit 0
            ;;
        *)
            if [ -z "$VERSION" ]; then
                VERSION="$1"
            fi
            shift
            ;;
    esac
done

echo "=========================================="
echo "NPM Package Publishing Helper"
echo "=========================================="
echo ""

# If trigger action flag is set, use GitHub CLI
if [ "$TRIGGER_ACTION" = true ]; then
    echo "🚀 Triggering GitHub Action workflow..."
    echo ""
    
    if ! command -v gh &> /dev/null; then
        echo -e "${RED}❌ Error: GitHub CLI (gh) is not installed.${NC}"
        echo ""
        echo "Please install GitHub CLI:"
        echo "  https://cli.github.com/"
        echo ""
        echo "Or trigger the workflow manually via GitHub UI:"
        echo "  1. Go to: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions"
        echo "  2. Select 'Publish Alpha to NPM' workflow"
        echo "  3. Click 'Run workflow'"
        exit 1
    fi
    
    if [ -z "$VERSION" ]; then
        echo "Enter version to publish (e.g., 0.1.0-alpha.2):"
        read -r VERSION
    fi
    
    # Validate version format
    if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+-alpha\.[0-9]+$ ]]; then
        echo -e "${RED}❌ Invalid alpha version format: $VERSION${NC}"
        echo "Expected format: X.Y.Z-alpha.N (e.g., 0.1.0-alpha.1)"
        exit 1
    fi
    
    echo "Triggering workflow with version: $VERSION"
    echo "Dry run: $DRY_RUN"
    echo ""
    
    if [ "$DRY_RUN" = true ]; then
        gh workflow run npm-publish-alpha.yml -f version="$VERSION" -f dry_run=true
    else
        gh workflow run npm-publish-alpha.yml -f version="$VERSION" -f dry_run=false
    fi
    
    echo ""
    echo -e "${GREEN}✅ Workflow triggered!${NC}"
    echo ""
    echo "View workflow runs:"
    echo "  gh run list --workflow=npm-publish-alpha.yml"
    echo "  Or visit: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions"
    exit 0
fi

# Get version if not specified
if [ -z "$VERSION" ] && [ "$BUILD_ONLY" = false ]; then
    echo "Current package versions:"
    echo "  nvoos-storage:  $(grep '"version"' packages/nvoos-storage/package.json | head -1 | sed 's/.*: "\(.*\)".*/\1/')"
    echo "  nvoos-markdown: $(grep '"version"' packages/nvoos-markdown/package.json | head -1 | sed 's/.*: "\(.*\)".*/\1/')"
    echo "  nvoos-events:   $(grep '"version"' packages/nvoos-events/package.json | head -1 | sed 's/.*: "\(.*\)".*/\1/')"
    echo ""
    echo "Enter new version to publish (e.g., 0.1.0-alpha.2):"
    read -r VERSION
fi

# Validate version format (only if not build-only mode)
if [ "$BUILD_ONLY" = false ]; then
    if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+-alpha\.[0-9]+$ ]]; then
        echo -e "${RED}❌ Invalid alpha version format: $VERSION${NC}"
        echo "Expected format: X.Y.Z-alpha.N (e.g., 0.1.0-alpha.1)"
        exit 1
    fi
    
    echo -e "${GREEN}✅ Version format valid: $VERSION${NC}"
    echo ""
fi

# Check for Node.js
if ! command -v node &> /dev/null; then
    echo -e "${RED}❌ Error: Node.js is not installed.${NC}"
    exit 1
fi

echo "Packages to process:"
echo "  1. @nvdigitalsolutions/nvoos-storage"
echo "  2. @nvdigitalsolutions/nvoos-markdown"
echo "  3. @nvdigitalsolutions/nvoos-events"
echo ""

if [ "$BUILD_ONLY" = false ]; then
    echo "Version: $VERSION"
    if [ "$DRY_RUN" = true ]; then
        echo -e "${YELLOW}Mode: Dry run (no actual publishing)${NC}"
    else
        echo -e "${BLUE}Mode: Live publishing${NC}"
    fi
    echo ""
fi

# Update versions if not build-only
if [ "$BUILD_ONLY" = false ]; then
    echo "=========================================="
    echo "Step 1: Updating Package Versions"
    echo "=========================================="
    echo ""
    
    cd packages/nvoos-storage
    npm version "$VERSION" --no-git-tag-version
    echo -e "${GREEN}✅ Updated @nvdigitalsolutions/nvoos-storage to $VERSION${NC}"
    cd ../..
    
    cd packages/nvoos-markdown
    npm version "$VERSION" --no-git-tag-version
    echo -e "${GREEN}✅ Updated @nvdigitalsolutions/nvoos-markdown to $VERSION${NC}"
    cd ../..
    
    cd packages/nvoos-events
    npm version "$VERSION" --no-git-tag-version
    echo -e "${GREEN}✅ Updated @nvdigitalsolutions/nvoos-events to $VERSION${NC}"
    cd ../..
    
    echo ""
fi

# Build packages
echo "=========================================="
echo "Step 2: Building Packages"
echo "=========================================="
echo ""

echo "Building @nvdigitalsolutions/nvoos-storage..."
cd packages/nvoos-storage
npm run build
echo -e "${GREEN}✅ Built successfully${NC}"
ls -lh dist/
cd ../..
echo ""

echo "Building @nvdigitalsolutions/nvoos-markdown..."
cd packages/nvoos-markdown
npm run build
echo -e "${GREEN}✅ Built successfully${NC}"
ls -lh dist/
cd ../..
echo ""

echo "Building @nvdigitalsolutions/nvoos-events..."
cd packages/nvoos-events
npm run build
echo -e "${GREEN}✅ Built successfully${NC}"
ls -lh dist/
cd ../..
echo ""

# Verify package contents
echo "=========================================="
echo "Step 3: Verifying Package Contents"
echo "=========================================="
echo ""

echo "Verifying @nvdigitalsolutions/nvoos-storage..."
cd packages/nvoos-storage
npm pack --dry-run
cd ../..
echo ""

echo "Verifying @nvdigitalsolutions/nvoos-markdown..."
cd packages/nvoos-markdown
npm pack --dry-run
cd ../..
echo ""

echo "Verifying @nvdigitalsolutions/nvoos-events..."
cd packages/nvoos-events
npm pack --dry-run
cd ../..
echo ""

# Exit if build-only mode
if [ "$BUILD_ONLY" = true ]; then
    echo "=========================================="
    echo -e "${GREEN}✅ Build Complete!${NC}"
    echo "=========================================="
    echo ""
    echo "Packages built successfully. Use --publish to publish them."
    exit 0
fi

# Exit if dry run
if [ "$DRY_RUN" = true ]; then
    echo "=========================================="
    echo -e "${GREEN}✅ Dry Run Complete!${NC}"
    echo "=========================================="
    echo ""
    echo "All packages built and verified successfully."
    echo "No packages were published (dry run mode)."
    echo ""
    echo "To publish for real, run without --dry-run flag."
    exit 0
fi

# Publish packages
echo "=========================================="
echo "Step 4: Publishing to NPM"
echo "=========================================="
echo ""

if ! npm whoami &> /dev/null; then
    echo -e "${RED}❌ Error: Not logged in to NPM${NC}"
    echo ""
    echo "Please log in first:"
    echo "  npm login"
    echo ""
    echo "Or use GitHub Action to publish (set NPM_TOKEN secret):"
    echo "  ./bin/publish-npm-packages.sh --trigger"
    exit 1
fi

echo "Publishing @nvdigitalsolutions/nvoos-storage..."
cd packages/nvoos-storage
npm publish --tag alpha --access public
echo -e "${GREEN}✅ Published @nvdigitalsolutions/nvoos-storage@$VERSION${NC}"
cd ../..
echo ""

echo "Publishing @nvdigitalsolutions/nvoos-markdown..."
cd packages/nvoos-markdown
npm publish --tag alpha --access public
echo -e "${GREEN}✅ Published @nvdigitalsolutions/nvoos-markdown@$VERSION${NC}"
cd ../..
echo ""

echo "Publishing @nvdigitalsolutions/nvoos-events..."
cd packages/nvoos-events
npm publish --tag alpha --access public
echo -e "${GREEN}✅ Published @nvdigitalsolutions/nvoos-events@$VERSION${NC}"
cd ../..
echo ""

# Summary
echo "=========================================="
echo -e "${GREEN}✅ All Packages Published Successfully!${NC}"
echo "=========================================="
echo ""
echo "Published packages:"
echo "  • @nvdigitalsolutions/nvoos-storage@$VERSION"
echo "  • @nvdigitalsolutions/nvoos-markdown@$VERSION"
echo "  • @nvdigitalsolutions/nvoos-events@$VERSION"
echo ""
echo "Install with:"
echo "  npm install @nvdigitalsolutions/nvoos-storage@alpha"
echo "  npm install @nvdigitalsolutions/nvoos-markdown@alpha"
echo "  npm install @nvdigitalsolutions/nvoos-events@alpha"
echo ""
echo "Or specific version:"
echo "  npm install @nvdigitalsolutions/nvoos-storage@$VERSION"
echo ""
echo "View on NPM:"
echo "  https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-storage"
echo "  https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-markdown"
echo "  https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-events"
