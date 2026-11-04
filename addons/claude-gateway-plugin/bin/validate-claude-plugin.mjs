#!/usr/bin/env node
/**
 * Dependency-free sanity validator for Claude Code plugin folders.
 *
 * Stands in for `claude plugin validate --strict` on machines without the
 * Claude Code CLI. It checks the subset of the official manifest and
 * marketplace rules that can be verified from files alone:
 *
 *   - plugin.json: name shape, required metadata, path containment,
 *     https remote MCP endpoints, ${user_config.KEY} header discipline,
 *     userConfig key shapes
 *   - marketplace.json (when present): marketplace name shape, plugin
 *     entries, entry/manifest name agreement
 *   - skills/**\/SKILL.md and commands/*.md: front matter parses and
 *     carries a string `description`
 *   - README.md word count (>= 40 outside code fences) and LICENSE file
 *
 * Usage: node bin/validate-claude-plugin.mjs <plugin-dir> [--strict]
 * Exit codes: 0 = pass, 1 = errors (or warnings under --strict),
 *             2 = usage error.
 */

import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { join, resolve, relative } from 'node:path';

const [dirArg, ...flags] = process.argv.slice(2);
const strict = flags.includes('--strict');

const errors = [];
const warnings = [];

const fail = (msg) => errors.push(msg);
const warn = (msg) => warnings.push(msg);

if (!dirArg) {
  console.error('Usage: node bin/validate-claude-plugin.mjs <plugin-dir> [--strict]');
  process.exit(2);
}

const root = resolve(dirArg);
const rel = (p) => relative(root, p).replace(/\\/g, '/');

const readJson = (p) => {
  if (!existsSync(p)) return { value: undefined, ok: true };
  try {
    return { value: JSON.parse(readFileSync(p, 'utf8')), ok: true };
  } catch (e) {
    fail(`${rel(p)}: invalid JSON — ${e.message}`);
    return { value: undefined, ok: false };
  }
};

/* ------------------------------------------------------------------ */
/* plugin.json                                                         */
/* ------------------------------------------------------------------ */

const manifestPath = join(root, '.claude-plugin', 'plugin.json');
if (!existsSync(manifestPath)) {
  fail('.claude-plugin/plugin.json: missing');
} else {
  const { value: m, ok } = readJson(manifestPath);
  if (ok && m) {
    const NAME_RE = /^[a-z0-9][a-z0-9-]{0,62}[a-z0-9]$/;
    if (typeof m.name !== 'string' || !NAME_RE.test(m.name)) {
      fail('plugin.json name: must be lowercase letters/digits/hyphens, start and end alphanumeric, <= 64 chars');
    }
    for (const key of ['version', 'description', 'license']) {
      if (!m[key]) fail(`plugin.json ${key}: missing`);
    }
    if (!m.author || typeof m.author.name !== 'string' || !m.author.name) {
      fail('plugin.json author.name: missing');
    }
    if (m.homepage !== undefined) {
      try {
        const u = new URL(m.homepage);
        if (u.protocol !== 'https:' && u.protocol !== 'http:') {
          fail(`plugin.json homepage: not an absolute URL (${m.homepage})`);
        }
      } catch {
        fail(`plugin.json homepage: does not parse as a URL (${m.homepage})`);
      }
    }
    if (m.icon && typeof m.icon === 'string') {
      const iconPath = resolve(root, m.icon.replace(/^\.\//, ''));
      if (!existsSync(iconPath)) fail(`plugin.json icon: path not found (${m.icon})`);
    }

    // MCP server entries: inline manifest and .mcp.json both count.
    const serverMaps = [];
    if (m.mcpServers && typeof m.mcpServers === 'object') serverMaps.push(m.mcpServers);
    const dotMcp = join(root, '.mcp.json');
    if (existsSync(dotMcp)) {
      const { value: d, ok: dok } = readJson(dotMcp);
      if (dok && d && d.mcpServers) serverMaps.push(d.mcpServers);
    }
    for (const servers of serverMaps) {
      for (const [name, entry] of Object.entries(servers)) {
        if (!entry || typeof entry !== 'object') {
          fail(`MCP server "${name}": invalid entry`);
          continue;
        }
        if (entry.command || entry.args) {
          // stdio server — plugin must keep commands inside the plugin dir.
          warn(`MCP server "${name}": local stdio server — review command path (${entry.command || ''})`);
          continue;
        }
        const t = entry.type;
        if (!['http', 'sse', 'ws'].includes(t)) {
          fail(`MCP server "${name}": remote servers need type http/sse/ws (got "${t || 'none'}")`);
          continue;
        }
        const url = entry.url || '';
        if (url === '' || /\$\{user_config\.\w+\}/.test(url)) {
          // Placeholder endpoint — allowed when the plugin has no fixed URL.
        } else if (!/^https:\/\//.test(url) && !/^wss:\/\//.test(url)) {
          fail(`MCP server "${name}": url must be https:// or wss:// (got ${url})`);
        }
        for (const [h, v] of Object.entries(entry.headers || {})) {
          if (/\$\{user_config\.\w+\}/.test(String(v))) continue;
          if (/bearer\s+[a-z0-9._-]{20,}/i.test(String(v))) {
            fail(`MCP server "${name}" headers.${h}: literal credential — use a userConfig option instead`);
          } else if (/token|key|secret|password/i.test(h)) {
            warn(`MCP server "${name}" headers.${h}: header value does not reference userConfig`);
          }
        }
      }
    }

    // userConfig options.
    for (const [key, opt] of Object.entries(m.userConfig || {})) {
      if (!/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(key)) {
        fail(`userConfig "${key}": keys must be letters/digits/underscores, not starting with a digit`);
      }
      if (!opt || typeof opt !== 'object') {
        fail(`userConfig "${key}": invalid option`);
        continue;
      }
      if (!['string', 'number', 'boolean', 'directory', 'file'].includes(opt.type)) {
        fail(`userConfig "${key}": unknown type "${opt.type}"`);
      }
      if (!opt.title || !opt.description) {
        fail(`userConfig "${key}": title and description are required`);
      }
      if (/key|token|secret|password/i.test(key) && !opt.sensitive) {
        fail(`userConfig "${key}": credential-like option must set "sensitive": true`);
      }
    }
  }
}

/* ------------------------------------------------------------------ */
/* marketplace.json                                                    */
/* ------------------------------------------------------------------ */

const marketplacePath = join(root, '.claude-plugin', 'marketplace.json');
if (existsSync(marketplacePath)) {
  const { value: mp, ok } = readJson(marketplacePath);
  if (ok && mp) {
    if (typeof mp.name !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(mp.name) || mp.name.includes('..')) {
      fail('marketplace.json name: must be letters/digits/./_/-, start alphanumeric, no ".."');
    }
    if (!mp.owner || typeof mp.owner.name !== 'string' || !mp.owner.name) {
      fail('marketplace.json owner.name: missing');
    }
    if (!Array.isArray(mp.plugins) || mp.plugins.length === 0) {
      warn('marketplace.json plugins: no plugins defined');
    } else {
      const manifestName = (() => {
        const { value: m } = readJson(manifestPath);
        return m && m.name;
      })();
      for (const [i, entry] of mp.plugins.entries()) {
        if (typeof entry.name !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(entry.name)) {
          fail(`marketplace.json plugins[${i}].name: invalid plugin identifier`);
        }
        if (!entry.source) {
          fail(`marketplace.json plugins[${i}].source: missing`);
        } else if (typeof entry.source === 'string' && !entry.source.startsWith('./') && entry.source !== '.') {
          fail(`marketplace.json plugins[${i}].source: relative paths must start with "./"`);
        }
        if (
          typeof entry.source === 'string' &&
          (entry.source === './' || entry.source === '.') &&
          manifestName &&
          entry.name !== manifestName
        ) {
          fail(`marketplace.json plugins[${i}]: entry name "${entry.name}" disagrees with plugin.json name "${manifestName}"`);
        }
      }
    }
  }
}

/* ------------------------------------------------------------------ */
/* Skills, commands, README, LICENSE                                   */
/* ------------------------------------------------------------------ */

const checkFrontMatter = (p, label) => {
  if (!existsSync(p)) return;
  const text = readFileSync(p, 'utf8');
  if (!text.startsWith('---')) {
    warn(`${label}: no YAML front matter`);
    return;
  }
  const end = text.indexOf('\n---', 3);
  if (end === -1) {
    fail(`${label}: front matter never closes`);
    return;
  }
  const fm = text.slice(4, end);
  const descMatch = fm.match(/^description:\s*(.+)$/m);
  if (!descMatch) {
    fail(`${label}: front matter has no description`);
  } else if (descMatch[1].trim().startsWith('-') || descMatch[1].includes('\n  -')) {
    fail(`${label}: description must be a single text value, not a list`);
  }
};

const walkSkills = (dir) => {
  const out = [];
  if (!existsSync(dir)) return out;
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, entry.name);
    if (entry.isDirectory()) {
      const skillMd = join(p, 'SKILL.md');
      if (existsSync(skillMd)) out.push([skillMd, `skill ${entry.name}`]);
      out.push(...walkSkills(p));
    }
  }
  return out;
};

for (const [p, label] of walkSkills(join(root, 'skills'))) {
  checkFrontMatter(p, label);
}
const commandsDir = join(root, 'commands');
if (existsSync(commandsDir)) {
  for (const entry of readdirSync(commandsDir, { withFileTypes: true }).filter((e) => e.isFile() && e.name.endsWith('.md'))) {
    checkFrontMatter(join(commandsDir, entry.name), `command ${entry.name}`);
  }
}

const readmePath = join(root, 'README.md');
if (!existsSync(readmePath)) {
  fail('README.md: missing');
} else {
  const text = readFileSync(readmePath, 'utf8');
  const words = text
    .replace(/```[\s\S]*?```/g, ' ')
    .split(/\s+/)
    .filter((w) => /[a-zA-Z0-9]/.test(w));
  if (words.length < 40) {
    fail(`README.md: too short (${words.length} words outside code blocks; need >= 40)`);
  }
}

if (!existsSync(join(root, 'LICENSE'))) {
  fail('LICENSE: missing');
}

/* ------------------------------------------------------------------ */
/* Report                                                              */
/* ------------------------------------------------------------------ */

for (const w of warnings) console.warn(`⚠ ${w}`);
for (const e of errors) console.error(`✘ ${e}`);

const failures = errors.length + (strict ? warnings.length : 0);
if (failures > 0) {
  console.error(
    `\nValidation failed: ${errors.length} error(s)${strict ? `, ${warnings.length} warning(s) treated as errors (--strict)` : ''}`
  );
  process.exit(1);
}
console.log(
  `✔ Validation passed (${warnings.length} warning(s))` +
    (strict ? ' — strict mode' : '')
);
