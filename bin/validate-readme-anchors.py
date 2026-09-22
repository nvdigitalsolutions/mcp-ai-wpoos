#!/usr/bin/env python3
"""
Validate that all anchor links (Table of Contents and in-document `#fragment`
links) in README.md match their corresponding section headers.

This script ensures that anchor links work correctly by checking that:
1. Each `#anchor` link has a corresponding section header (h1-h4)
2. The anchor format matches GitHub's anchor generation rules

Anchor rules (verified against GitHub's server-rendered HTML, 2026-09):
- Convert to lowercase
- Keep only letters, numbers, spaces, and hyphens. Everything else is removed:
  emoji, U+FE0F (emoji variation selector), U+200D (zero-width joiner),
  punctuation (& . , : ( ) / etc.)
- Replace EVERY space with a hyphen, one-for-one ("& " -> two hyphens)
- Do NOT collapse consecutive hyphens and do NOT strip leading/trailing
  hyphens: emoji-prefixed headings keep the leading hyphen
  (e.g. "## 🧩 Overview" -> "-overview")

Note on invisible characters: for headings whose emoji carries a variation
selector or ZWJ sequence (e.g. "🛡️", "🧑‍💻"), GitHub's canonical anchor
contains the invisible character and GitHub additionally injects a hidden
fallback anchor for the visible form. The TOC therefore always links the
visible slug form produced by these rules.

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

    Matches GitHub's rendering of visible (invisible-character-free) anchors
    exactly: lowercase, strip everything except a-z 0-9 space hyphen, then
    replace each space with a hyphen.
    """
    # Remove markdown header markers
    text = re.sub(r'^#+\s+', '', header)
    # Convert to lowercase
    text = text.lower()
    # Keep only letters, numbers, spaces, and hyphens (strips emoji, VS16,
    # ZWJ, and all punctuation).
    text = re.sub(r'[^0-9a-z -]', '', text)
    # Replace every space with a hyphen (do NOT collapse, do NOT trim).
    text = text.replace(' ', '-')
    return text


def strip_code_fences(content):
    """Return the content with fenced code blocks removed.

    Shell comment lines inside code fences (e.g. ``# some comment``) must not
    be mistaken for markdown headings.
    """
    lines = content.split('\n')
    out = []
    in_fence = False
    for line in lines:
        if line.lstrip().startswith('```'):
            in_fence = not in_fence
            continue
        if not in_fence:
            out.append(line)
    return '\n'.join(out)


def main():
    # Ensure UTF-8 output on consoles defaulting to legacy codepages (Windows).
    try:
        sys.stdout.reconfigure(encoding='utf-8', errors='replace')  # type: ignore[attr-defined]
    except Exception:
        pass

    # Find README.md
    readme_path = Path(__file__).parent.parent / 'README.md'

    if not readme_path.exists():
        print("ERROR: README.md not found at {readme_path}")
        return 1

    with open(readme_path, 'r', encoding='utf-8') as f:
        content = f.read()

    # Extract all `#anchor` markdown links (TOC + in-document links).
    link_pattern = r'\[([^\]]*?)\]\(#([^)\s]+?)\)'
    link_matches = re.findall(link_pattern, content)

    # Extract all h1-h4 headers, skipping fenced code blocks.
    heading_text = strip_code_fences(content)
    header_pattern = r'^#{1,4} (.+)$'
    headers = re.findall(header_pattern, heading_text, re.MULTILINE)

    # Create a map of valid anchors
    valid_anchors = {}
    for header in headers:
        anchor = header_to_anchor(header)
        if anchor:  # Skip empty anchors from degenerate headers
            valid_anchors[anchor] = header

    print("=" * 80)
    print("README.md ANCHOR VALIDATION")
    print("=" * 80)
    print()
    print(f"Found {len(link_matches)} anchor links")
    print(f"Found {len(headers)} section headers (h1-h4)")
    print()

    broken_links = []
    working_links = []

    for title, anchor in link_matches:
        if anchor in valid_anchors:
            working_links.append({
                'title': title,
                'anchor': anchor,
                'header': valid_anchors[anchor],
            })
        else:
            broken_links.append({
                'title': title,
                'anchor': anchor,
            })

    if broken_links:
        print(f"VALIDATION FAILED - Found {len(broken_links)} broken link(s):")
        print()
        for link in broken_links:
            print(f"   - {link['title']}")
            print(f"     Anchor: #{link['anchor']}")
            print(f"     Status: NO MATCHING HEADER FOUND")
            print()

            # Try to suggest a close match
            for anchor, header in valid_anchors.items():
                if link['anchor'] in anchor or anchor in link['anchor']:
                    print(f"     Did you mean: #{anchor}")
                    print(f"        Header: {header}")
                    print()
                    break

        print("=" * 80)
        return 1

    print(f"VALIDATION PASSED - All {len(working_links)} anchor links are working!")
    print()
    print("Sample of validated links:")
    for link in working_links[:5]:
        print(f"   [OK] {link['title']}")
        print(f"        -> #{link['anchor']}")

    if len(working_links) > 5:
        print(f"   ... and {len(working_links) - 5} more")

    print()
    print("=" * 80)
    return 0


if __name__ == '__main__':
    sys.exit(main())
