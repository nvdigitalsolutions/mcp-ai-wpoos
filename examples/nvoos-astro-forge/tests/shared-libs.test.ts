import { describe, expect, it } from 'vitest';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// The shared design-import core (126 §shared): the canonical package copy
// and the vendored forge copy stay byte-identical — edit the package first,
// then copy (never the reverse).

const FORGE_ROOT = fileURLToPath(new URL('..', import.meta.url));
const PACKAGE_ROOT = path.resolve(FORGE_ROOT, '..', '..', 'packages', 'nvoos-design-import');

describe('shared design-import core (126 §shared)', () => {
	it('lib/contrast.ts is the byte-synced canonical copy', async () => {
		const [canonical, vendored] = await Promise.all([
			readFile(path.join(PACKAGE_ROOT, 'src', 'contrast.ts'), 'utf8'),
			readFile(path.join(FORGE_ROOT, 'lib', 'contrast.ts'), 'utf8'),
		]);
		expect(vendored).toBe(canonical);
	});
});
