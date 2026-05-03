#!/bin/bash
# Helper script to create and push alpha release tags
# Usage: ./bin/publish-alpha.sh <version>
# Example: ./bin/publish-alpha.sh 0.1.0-alpha.1

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Check if version argument is provided
if [ -z "$1" ]; then
  echo -e "${RED}❌ Error: Version argument required${NC}"
  echo ""
  echo "Usage: $0 <version>"
  echo ""
  echo "Examples:"
  echo "  $0 0.1.0-alpha.1"
  echo "  $0 0.1.0-alpha.2"
  echo "  $0 0.2.0-alpha.1"
  exit 1
fi

VERSION="$1"

# Validate alpha version format
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+-alpha\.[0-9]+$ ]]; then
  echo -e "${RED}❌ Error: Invalid alpha version format${NC}"
  echo ""
  echo "Expected format: X.Y.Z-alpha.N"
  echo "Examples: 0.1.0-alpha.1, 0.1.0-alpha.2, 0.2.0-alpha.1"
  exit 1
fi

TAG="v${VERSION}"

echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  NPM Alpha Release Publisher${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo ""

# Check if tag already exists
if git rev-parse "$TAG" >/dev/null 2>&1; then
  echo -e "${RED}❌ Error: Tag $TAG already exists${NC}"
  echo ""
  echo "Existing tags:"
  git tag -l "v*-alpha.*" | tail -5
  exit 1
fi

echo -e "${YELLOW}📦 Preparing alpha release: ${VERSION}${NC}"
echo ""

# Check for uncommitted changes
if [ -n "$(git status --porcelain)" ]; then
  echo -e "${RED}❌ Error: Working directory has uncommitted changes${NC}"
  echo ""
  echo "Please commit or stash your changes before creating a release:"
  git status --short
  exit 1
fi

echo -e "${GREEN}✅ Working directory is clean${NC}"

# Verify we're on the correct branch
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
echo -e "${BLUE}📍 Current branch: ${CURRENT_BRANCH}${NC}"

# Update package versions (dry run first to show what will change)
echo ""
echo -e "${YELLOW}📝 Updating package.json files...${NC}"

PACKAGES="nvoos-storage nvoos-markdown nvoos-events nvoos-http-client nvoos-clipboard nvoos-offline-sync nvoos-slash-commands nvoos-audio nvoos-dom-batcher nvoos-llm-worker nvoos-model-loader nvoos-transformers-client nvoos-client-tools nvoos-chat-memory nvoos-attachments nvoos-cron-status nvoos-transcription"

for pkg in $PACKAGES; do
  cd packages/${pkg}
  npm version "${VERSION}" --no-git-tag-version
  echo -e "${GREEN}  ✅ Updated @nvdigitalsolutions/${pkg}${NC}"
  cd ../..
done

# Build all packages
echo ""
echo -e "${YELLOW}🔨 Building packages...${NC}"

for pkg in $PACKAGES; do
  cd packages/${pkg}
  npm run build
  echo -e "${GREEN}  ✅ Built ${pkg}${NC}"
  cd ../..
done

# Create a commit with version updates
echo ""
echo -e "${YELLOW}📝 Creating commit for version bump...${NC}"
git add packages/*/package.json packages/*/package-lock.json 2>/dev/null || true
git commit -m "Bump alpha version to ${VERSION}" || echo "No changes to commit"

# Create and push the tag
echo ""
echo -e "${YELLOW}🏷️  Creating tag ${TAG}...${NC}"
git tag -a "$TAG" -m "Alpha release ${VERSION}

Packages included (9 total):
- @nvdigitalsolutions/nvoos-storage@${VERSION}
- @nvdigitalsolutions/nvoos-markdown@${VERSION}
- @nvdigitalsolutions/nvoos-events@${VERSION}
- @nvdigitalsolutions/nvoos-http-client@${VERSION}
- @nvdigitalsolutions/nvoos-clipboard@${VERSION}
- @nvdigitalsolutions/nvoos-offline-sync@${VERSION}
- @nvdigitalsolutions/nvoos-slash-commands@${VERSION}
- @nvdigitalsolutions/nvoos-audio@${VERSION}
- @nvdigitalsolutions/nvoos-dom-batcher@${VERSION}
- @nvdigitalsolutions/nvoos-llm-worker@${VERSION}
- @nvdigitalsolutions/nvoos-model-loader@${VERSION}
- @nvdigitalsolutions/nvoos-transformers-client@${VERSION}
- @nvdigitalsolutions/nvoos-client-tools@${VERSION}
- @nvdigitalsolutions/nvoos-chat-memory@${VERSION}
- @nvdigitalsolutions/nvoos-attachments@${VERSION}
- @nvdigitalsolutions/nvoos-cron-status@${VERSION}
- @nvdigitalsolutions/nvoos-transcription@${VERSION}

This is an alpha release for testing purposes.
Use 'npm install <package>@alpha' to install."

echo -e "${GREEN}✅ Tag created: ${TAG}${NC}"

# Ask for confirmation before pushing
echo ""
echo -e "${YELLOW}⚠️  Ready to push tag ${TAG} to trigger NPM publication${NC}"
echo ""
read -p "Push tag and trigger GitHub Actions workflow? (y/N) " -n 1 -r
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
  echo -e "${YELLOW}📤 Pushing tag to origin...${NC}"
  git push origin "$TAG"
  
  echo ""
  echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
  echo -e "${GREEN}✅ Success!${NC}"
  echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
  echo ""
  echo -e "${BLUE}📦 Alpha release ${VERSION} has been triggered!${NC}"
  echo ""
  echo -e "${YELLOW}Next steps:${NC}"
  echo "  1. Monitor the GitHub Actions workflow:"
  echo "     https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions"
  echo ""
  echo "  2. Once published, packages will be available at:"
  for pkg in $PACKAGES; do
    echo "     - https://www.npmjs.com/package/@nvdigitalsolutions/${pkg}"
  done
  echo ""
  echo "  3. Install alpha versions with:"
  echo "     npm install @nvdigitalsolutions/nvoos-storage@alpha"
  echo "     npm install @nvdigitalsolutions/nvoos-slash-commands@alpha"
  echo "     npm install @nvdigitalsolutions/nvoos-audio@alpha"
  echo "     npm install @nvdigitalsolutions/nvoos-dom-batcher@alpha"
  echo ""
else
  echo ""
  echo -e "${YELLOW}⚠️  Skipped pushing tag. Release was not published.${NC}"
  echo ""
  echo "To push manually later, run:"
  echo "  git push origin $TAG"
  echo ""
  echo "To delete the tag and start over, run:"
  echo "  git tag -d $TAG"
  exit 1
fi
