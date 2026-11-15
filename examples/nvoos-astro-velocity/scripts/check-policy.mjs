#!/usr/bin/env node
// ---------------------------------------------------------------------------
// check-policy.mjs — CI drift gate (Proposal 105 §3), chained into `npm run
// check` like check:tokens. Recomputes the expected generated artifact from
// the CURRENT source (the live policy doc when NVOOS_API_BASE is set, the
// default doc in the hermetic CI) and fails when the committed file differs
// — run `npm run build:policy` and commit the result.
// ---------------------------------------------------------------------------

import { readFileSync } from 'node:fs';
import { POLICY_OUT, fetchPolicyDoc, renderPolicyDoc } from './lib/policy-build.mjs';

const { doc, source } = await fetchPolicyDoc(process.env.NVOOS_API_BASE);
const expected = renderPolicyDoc(doc, source);

let committed = '';
try {
	committed = readFileSync(POLICY_OUT, 'utf8');
} catch {
	console.error('[check-policy] policy.generated.ts is missing — run `npm run build:policy`.');
	process.exit(1);
}

if (committed !== expected) {
	console.error(
		'[check-policy] drift in generated artifact: src/lib/theme/policy.generated.ts'
	);
	console.error('[check-policy] run `npm run build:policy` and commit the output.');
	process.exit(1);
}
console.log('[check-policy] generated policy artifact is current');
