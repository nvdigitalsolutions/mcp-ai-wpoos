#!/usr/bin/env python3
"""
Validate that all Table of Contents anchors in README.md match their corresponding section headers.

This script ensures that TOC links work correctly by checking that:
1. Each TOC anchor has a corresponding section header
2. The anchor format matches GitHub's anchor generation rules

Usage:
    python3 bin/validate-readme-anchors.py

Exit codes:
    0 - All anchors are valid
    1 - One or more broken anchors found
"""

import re
import sys
from pathlib import Path


def header_to_anchor(header):
    """
    Convert a markdown header to its GitHub-flavored markdown anchor.
    
    GitHub's anchor generation rules:
    - Convert to lowercase
    - Remove emojis and special characters (except hyphens and underscores)
    - Replace spaces with hyphens
    - Remove consecutive hyphens
    """
    # Remove markdown header markers
    text = re.sub(r'^#+\s+', '', header)
    # Convert to lowercase
    text = text.lower()
    # Remove emojis and special characters (keep alphanumeric, spaces, hyphens, underscores)
    text = re.sub(r'[^\w\s-]', '', text)
    # Replace spaces with hyphens
    text = re.sub(r'\s+', '-', text)
    # Remove multiple consecutive hyphens
    text = re.sub(r'-+', '-', text)
    # Strip leading/trailing hyphens
    text = text.strip('-')
    return text


def main():
    # Find README.md
    readme_path = Path(__file__).parent.parent / 'README.md'
    
    if not readme_path.exists():
        print(f"❌ Error: README.md not found at {readme_path}")
        return 1
    
    with open(readme_path, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # Extract all TOC links (from the Table of Contents section)
    # TOC is typically in the first 6000 characters
    toc_pattern = r'\[(.*?)\]\(#(.*?)\)'
    toc_matches = re.findall(toc_pattern, content[:6000])
    
    # Extract all level 2 headers (##)
    header_pattern = r'^## (.+)$'
    headers = re.findall(header_pattern, content, re.MULTILINE)
    
    # Create a map of valid anchors
    valid_anchors = {}
    for header in headers:
        anchor = header_to_anchor(header)
        valid_anchors[anchor] = header
    
    print("=" * 80)
    print("README.md TABLE OF CONTENTS VALIDATION")
    print("=" * 80)
    print()
    print(f"📊 Found {len(toc_matches)} TOC links")
    print(f"📊 Found {len(headers)} section headers (## level)")
    print()
    
    broken_links = []
    working_links = []
    
    for title, anchor in toc_matches:
        if anchor in valid_anchors:
            working_links.append({
                'title': title,
                'anchor': anchor,
                'header': valid_anchors[anchor]
            })
        else:
            broken_links.append({
                'title': title,
                'anchor': anchor
            })
    
    if broken_links:
        print(f"❌ VALIDATION FAILED - Found {len(broken_links)} broken link(s):")
        print()
        for link in broken_links:
            print(f"   • {link['title']}")
            print(f"     Anchor: #{link['anchor']}")
            print(f"     Status: NO MATCHING HEADER FOUND")
            print()
            
            # Try to suggest a close match
            for anchor, header in valid_anchors.items():
                if link['anchor'] in anchor or anchor in link['anchor']:
                    print(f"     💡 Did you mean: #{anchor}")
                    print(f"        Header: {header}")
                    print()
                    break
        
        print("=" * 80)
        return 1
    
    print(f"✅ VALIDATION PASSED - All {len(working_links)} TOC links are working!")
    print()
    print("Sample of validated links:")
    for link in working_links[:5]:
        print(f"   ✓ {link['title']}")
        print(f"     → #{link['anchor']}")
    
    if len(working_links) > 5:
        print(f"   ... and {len(working_links) - 5} more")
    
    print()
    print("=" * 80)
    return 0


if __name__ == '__main__':
    sys.exit(main())
