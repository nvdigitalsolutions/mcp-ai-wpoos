#!/usr/bin/env node
// ---------------------------------------------------------------------------
// build-policy.mjs — theme-policy generator CLI (Proposal 105 §3). Fetches
// the policy doc from the build-time contract base and writes
// src/lib/theme/policy.generated.ts. Never fails on an unreachable backend —
// the default (auto) doc is emitted instead (the hermetic build invariant).
// ---------------------------------------------------------------------------

import { writeFileSync } from 'node:fs';
import { POLICY_OUT, fetchPolicyDoc, renderPolicyDoc } from './lib/policy-build.mjs';

const { doc, source } = await fetchPolicyDoc(process.env.NVOOS_API_BASE);
writeFileSync(POLICY_OUT, renderPolicyDoc(doc, source));
console.log(`[build-policy] wrote policy.generated.ts (source: ${source})`);
