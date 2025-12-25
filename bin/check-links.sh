#!/bin/bash
#
# Link checker script for WP oOS documentation
# Finds all markdown links and verifies if the referenced files exist
#

# Get the repository root (go up one level from bin/)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$REPO_ROOT" || exit 1

echo "==================================="
echo "WP oOS Documentation Link Checker"
echo "==================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Counters
TOTAL_LINKS=0
BROKEN_LINKS=0
VALID_LINKS=0
EXTERNAL_LINKS=0

# Temporary file to store broken links
BROKEN_FILE=$(mktemp)

# Function to check if a file exists relative to a source file
check_link() {
    local source_file="$1"
    local link_path="$2"
    local line_number="$3"
    
    # Skip external links (http://, https://, mailto:, etc.)
    if [[ "$link_path" =~ ^https?:// ]] || [[ "$link_path" =~ ^mailto: ]] || [[ "$link_path" =~ ^ftp:// ]]; then
        ((EXTERNAL_LINKS++))
        return 0
    fi
    
    # Skip anchor-only links
    if [[ "$link_path" =~ ^# ]]; then
        return 0
    fi
    
    ((TOTAL_LINKS++))
    
    # Remove anchor from path
    link_path_no_anchor="${link_path%%#*}"
    
    # Get directory of source file
    source_dir=$(dirname "$source_file")
    
    # Resolve the full path
    if [[ "$link_path_no_anchor" = /* ]]; then
        # Absolute path from repo root
        full_path="${REPO_ROOT}${link_path_no_anchor}"
    else
        # Relative path
        full_path="${source_dir}/${link_path_no_anchor}"
    fi
    
    # Normalize the path
    full_path=$(realpath -m "$full_path" 2>/dev/null || echo "$full_path")
    
    # Check if file exists
    if [ ! -e "$full_path" ]; then
        echo "${RED}✗${NC} $source_file:$line_number" >> "$BROKEN_FILE"
        echo "  Link: $link_path" >> "$BROKEN_FILE"
        echo "  Expected: $full_path" >> "$BROKEN_FILE"
        echo "" >> "$BROKEN_FILE"
        ((BROKEN_LINKS++))
        return 1
    else
        ((VALID_LINKS++))
        return 0
    fi
}

echo "Scanning markdown files for links..."
echo ""

# Find all markdown files and extract links
while IFS= read -r file; do
    # Extract markdown links: [text](path)
    grep -n '\[.*\](.*\.md[^)]*)' "$file" 2>/dev/null | while IFS=: read -r line_number line_content; do
        # Extract just the path from [text](path)
        link=$(echo "$line_content" | grep -o '\](.*\.md[^)]*)' | sed 's/^](\(.*\))$/\1/' | sed 's/)$//')
        
        if [ -n "$link" ]; then
            check_link "$file" "$link" "$line_number"
        fi
    done
    
    # Also check for plain .md file references
    grep -n '(.*\.md)' "$file" 2>/dev/null | while IFS=: read -r line_number line_content; do
        # Extract paths in parentheses
        echo "$line_content" | grep -o '([^)]*\.md[^)]*)' | sed 's/[()]//g' | while read -r link; do
            if [ -n "$link" ] && [ "$link" != "*.md" ]; then
                check_link "$file" "$link" "$line_number"
            fi
        done
    done
done < <(find . -name "*.md" -type f | grep -v node_modules | grep -v vendor)

echo "==================================="
echo "Summary:"
echo "==================================="
echo "Total markdown links checked: $TOTAL_LINKS"
echo "External links skipped: $EXTERNAL_LINKS"
echo "${GREEN}Valid links: $VALID_LINKS${NC}"
echo "${RED}Broken links: $BROKEN_LINKS${NC}"
echo ""

if [ $BROKEN_LINKS -gt 0 ]; then
    echo "==================================="
    echo "Broken Links Details:"
    echo "==================================="
    cat "$BROKEN_FILE"
    rm "$BROKEN_FILE"
    exit 1
else
    echo "${GREEN}All links are valid!${NC}"
    rm "$BROKEN_FILE"
    exit 0
fi
