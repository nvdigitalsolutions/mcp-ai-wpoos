// policy-drift.test.ts — Proposal 105 P2: the generated-policy drift pin.
// Mirrors tokens.test.ts: the committed artifact must (a) parse to the
// default doc, (b) render in repo style (tabs + single quotes) byte-for-byte
// with what scripts/build-policy.mjs emits for the hermetic (no
// NVOOS_API_BASE) case — the drift gate regression guard.
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { DEFAULT_POLICY_DOC, parsePolicyDoc } from '../../src/lib/theme/policy';
import { generatedPolicyDoc } from '../../src/lib/theme/policy.generated';
import { DEFAULT_DOC, POLICY_OUT, renderPolicyDoc } from '../../scripts/lib/policy-build.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

describe('policy.generated.ts drift gate', () => {
	it('parses to the default (auto) doc', () => {
		expect(generatedPolicyDoc).toEqual(DEFAULT_POLICY_DOC);
		expect(parsePolicyDoc(generatedPolicyDoc).policy).toBe('auto');
	});

	it('renders byte-identically to the hermetic emission (tabs + single quotes)', () => {
		const committed = readFileSync(join(ROOT, 'src', 'lib', 'theme', 'policy.generated.ts'), 'utf8');
		const expected = renderPolicyDoc(
			DEFAULT_DOC,
			'no NVOOS_API_BASE at build — the default document'
		);
		expect(committed).toBe(expected);
		// Format pins (the tokens.test.ts discipline — the lint gate would
		// fail on any regressed emission).
		expect(committed).toContain("\tpolicy: 'auto',");
		expect(committed).toContain("import type { ThemePolicy } from './policy';");
		expect(committed).not.toContain('"auto"');
	});

	it('resolves POLICY_OUT under src/lib/theme/', () => {
		expect(POLICY_OUT.replace(/\\/g, '/')).toContain('src/lib/theme/policy.generated.ts');
	});
});
