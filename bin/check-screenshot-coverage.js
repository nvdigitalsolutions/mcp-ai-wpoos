#!/usr/bin/env node
/**
 * Screenshot Coverage Checker
 *
 * Compares captured screenshots against the expected inventory.
 * Reports missing, stale, and newly-added pages.
 *
 * Usage: node bin/check-screenshot-coverage.js
 */

const fs = require('fs');
const path = require('path');

const SCREENSHOT_DIR = path.resolve(__dirname, '..', 'docs', 'screenshots');
const INVENTORY_FILE = path.join(SCREENSHOT_DIR, 'INVENTORY.md');

// Read inventory and extract expected screenshots
const inventory = fs.readFileSync(INVENTORY_FILE, 'utf8');
const lines = inventory.split('\n');

const expected = [];
let currentSection = '';

for (const line of lines) {
  // Track section headers
  const sectionMatch = line.match(/^## (.+)/);
  if (sectionMatch) currentSection = sectionMatch[1];

  // Extract lines with | status |
  const rowMatch = line.match(/^\| `?([^`|]+)`?\s+\|\s+`?([^`|]+)`?\s+\|\s+([✅⏳🔑🔧📦⚠️])\s+\|/);
  if (rowMatch) {
    const [, slug, file, status] = rowMatch;
    expected.push({
      section: currentSection,
      slug: slug.trim(),
      file: file.trim(),
      status: status.trim(),
    });
  }
}

// Check what exists on disk
const captured = new Set();
function scan(dir, prefix = '') {
  if (!fs.existsSync(dir)) return;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.isDirectory()) {
      scan(path.join(dir, entry.name), prefix + entry.name + '/');
    } else if (entry.name.endsWith('.png')) {
      captured.add(prefix + entry.name);
    }
  }
}
scan(SCREENSHOT_DIR);

// Report
console.log('=== Screenshot Coverage Report ===\n');

let missing = 0;
let captured_count = 0;
let pending = 0;
const bySection = {};

for (const item of expected) {
  bySection[item.section] = (bySection[item.section] || 0) + 1;
  if (item.status === '⏳') {
    pending++;
  } else if (captured.has(item.file)) {
    captured_count++;
  } else {
    missing++;
    console.log(`  MISSING: ${item.file} (${item.section} — ${item.slug})`);
  }
}

console.log(`\nSummary:`);
console.log(`  Captured: ${captured_count}`);
console.log(`  Pending (needs API key): ${pending}`);
console.log(`  Missing: ${missing}`);
console.log(`  Total expected: ${expected.length}`);
console.log(`  Actual PNG files on disk: ${captured.size}`);

// Find orphan files (captured but not in inventory)
const orphans = [...captured].filter(f => !expected.some(e => e.file === f));
if (orphans.length > 0) {
  console.log(`\n  Orphan files (not in inventory):`);
  for (const f of orphans) console.log(`    - ${f}`);
}

// Section breakdown
console.log(`\nBy section:`);
for (const [section, count] of Object.entries(bySection)) {
  const captured_in_section = expected.filter(e => e.section === section && e.status === '✅').length;
  console.log(`  ${section}: ${captured_in_section}/${count} captured`);
}

process.exit(missing > 0 ? 1 : 0);
