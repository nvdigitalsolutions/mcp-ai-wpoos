import { describe, expect, it } from 'vitest';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// The shared design-import core (126 §shared): the canonical package copy
// and the storefront copy stay byte-identical — edit the package first,
// then copy (never the reverse).

const HERE = fileURLToPath(new URL('.', import.meta.url));
const APP_ROOT = path.resolve(HERE, '..', '..');
const PACKAGE_ROOT = path.resolve(HERE, '..', '..', '..', '..', 'packages', 'nvoos-design-import');

describe('shared design-import core (126 §shared)', () => {
	it('src/lib/webfonts.ts is the byte-synced canonical copy', async () => {
		const [canonical, local] = await Promise.all([
			readFile(path.join(PACKAGE_ROOT, 'src', 'webfonts.ts'), 'utf8'),
			readFile(path.join(APP_ROOT, 'src', 'lib', 'webfonts.ts'), 'utf8'),
		]);
		expect(local).toBe(canonical);
	});
});
