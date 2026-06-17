/**
 * Build script: Copy type definitions from the TypeScript source into dist/.
 *
 * nvoos-types is a pure type-definition package — zero runtime code.
 * We extract from the canonical `assets/js/src/shared/types.ts`.
 *
 * @package @nvdigitalsolutions/nvoos-types
 */

const fs = require('fs');
const path = require('path');

const DIST = path.join(__dirname, 'dist');
const SRC = path.join(__dirname, '..', '..', 'assets', 'js', 'src', 'shared', 'types.ts');

if (!fs.existsSync(DIST)) {
  fs.mkdirSync(DIST, { recursive: true });
}

// Read the source TypeScript file.
let src = fs.readFileSync(SRC, 'utf8');

// Remove the `declare global` block (contains WordPress-specific window augmentations).
// We replace it with a generic Window augmentation comment for consumers.
src = src.replace(
  /\/\/ ── WordPress Global Declarations[\s\S]*$/,
  `// ── Consumer Overrides ────────────────────────────────────────────────

/**
 * Consumers can augment the NvOos global types in their own project:
 *
 * @example
 *   declare global {
 *     interface Window {
 *       wpMcpAiChat: import('@nvdigitalsolutions/nvoos-types').GlobalChatConfig;
 *     }
 *   }
 */

export {};
`
);

// Write the cleaned type definitions.
fs.writeFileSync(path.join(DIST, 'index.d.ts'), src, 'utf8');

console.log('✅ nvoos-types generated successfully');
console.log(`   Source: ${SRC}`);
console.log(`   Output: ${path.join(DIST, 'index.d.ts')}`);
