#!/usr/bin/env python3
"""
Link checker for WP oOS markdown documentation
Finds all markdown links and verifies if the referenced files exist
"""

import os
import re
import sys
from pathlib import Path
from urllib.parse import urlparse

# ANSI colors
RED = '\033[0;31m'
GREEN = '\033[0;32m'
YELLOW = '\033[1;33m'
BLUE = '\033[0;34m'
NC = '\033[0m'  # No Color

# Counters
total_links = 0
broken_links = 0
valid_links = 0
external_links = 0
anchor_only_links = 0

# Store broken links for reporting
broken_link_details = []

# Regex to match markdown links: [text](path) or [text](path "title")
LINK_PATTERN = re.compile(r'\[([^\]]+)\]\(([^)]+)\)')


def is_external_link(link):
    """Check if a link is external (http, https, mailto, etc.)"""
    return link.startswith(('http://', 'https://', 'mailto:', 'ftp://','tel:'))


def is_anchor_only(link):
    """Check if link is just an anchor (#section)"""
    return link.startswith('#')


def resolve_link_path(source_file, link_path):
    """Resolve the link path relative to the source file"""
    # Remove anchor from path
    link_path_no_anchor = link_path.split('#')[0]
    
    if not link_path_no_anchor:  # anchor-only link
        return None
    
    source_dir = source_file.parent
    
    # Handle absolute paths from repo root
    if link_path_no_anchor.startswith('/'):
        # Absolute from repo root
        repo_root = Path('/home/runner/work/mcp-ai-wpoos/mcp-ai-wpoos')
        resolved = repo_root / link_path_no_anchor.lstrip('/')
    else:
        # Relative path
        resolved = source_dir / link_path_no_anchor
    
    # Normalize the path
    try:
        resolved = resolved.resolve()
    except:
        pass
    
    return resolved


def check_markdown_file(file_path):
    """Check all links in a markdown file"""
    global total_links, broken_links, valid_links, external_links, anchor_only_links
    
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            content = f.read()
    except Exception as e:
        print(f"{YELLOW}Warning: Could not read {file_path}: {e}{NC}")
        return
    
    # Find all markdown links
    for match in LINK_PATTERN.finditer(content):
        link_text = match.group(1)
        link_path = match.group(2).strip()
        
        # Remove optional title from link
        link_path = link_path.split('"')[0].strip()
        link_path = link_path.split("'")[0].strip()
        
        # Get line number
        line_num = content[:match.start()].count('\n') + 1
        
        # Skip external links
        if is_external_link(link_path):
            external_links += 1
            continue
        
        # Skip anchor-only links
        if is_anchor_only(link_path):
            anchor_only_links += 1
            continue
        
        total_links += 1
        
        # Resolve and check the link
        resolved_path = resolve_link_path(file_path, link_path)
        
        if resolved_path is None:
            # Anchor-only, already handled
            continue
        
        if not resolved_path.exists():
            broken_links += 1
            broken_link_details.append({
                'file': str(file_path),
                'line': line_num,
                'link_text': link_text,
                'link_path': link_path,
                'resolved': str(resolved_path)
            })
        else:
            valid_links += 1


def main():
    """Main function to scan all markdown files"""
    repo_root = Path('/home/runner/work/mcp-ai-wpoos/mcp-ai-wpoos')
    
    print("=" * 50)
    print("WP oOS Documentation Link Checker")
    print("=" * 50)
    print()
    
    # Find all markdown files
    markdown_files = []
    for pattern in ['*.md', '**/*.md']:
        markdown_files.extend(repo_root.glob(pattern))
    
    # Exclude node_modules and vendor
    markdown_files = [
        f for f in markdown_files 
        if 'node_modules' not in str(f) and 'vendor' not in str(f)
    ]
    
    print(f"Scanning {len(markdown_files)} markdown files for links...")
    print()
    
    # Check each file
    for md_file in sorted(markdown_files):
        check_markdown_file(md_file)
    
    # Print summary
    print("=" * 50)
    print("Summary:")
    print("=" * 50)
    print(f"Total markdown files scanned: {len(markdown_files)}")
    print(f"Total markdown links checked: {total_links}")
    print(f"External links (skipped): {external_links}")
    print(f"Anchor-only links (skipped): {anchor_only_links}")
    print(f"{GREEN}Valid links: {valid_links}{NC}")
    print(f"{RED}Broken links: {broken_links}{NC}")
    print()
    
    if broken_links > 0:
        print("=" * 50)
        print("Broken Links Details:")
        print("=" * 50)
        
        # Group by source file
        by_file = {}
        for detail in broken_link_details:
            file = detail['file']
            if file not in by_file:
                by_file[file] = []
            by_file[file].append(detail)
        
        for file, details in sorted(by_file.items()):
            # Show relative path from repo
            rel_file = os.path.relpath(file, repo_root)
            print(f"\n{RED}✗ {rel_file}{NC}")
            for d in details:
                print(f"  Line {d['line']}: [{d['link_text']}]({d['link_path']})")
                print(f"    Expected file: {d['resolved']}")
        
        print()
        return 1
    else:
        print(f"{GREEN}✓ All links are valid!{NC}")
        return 0


if __name__ == '__main__':
    sys.exit(main())
