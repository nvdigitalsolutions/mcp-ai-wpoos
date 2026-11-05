// /design-audit.json.ts — the build-time WCAG contrast audit (Proposal 114 §3.5).
//
// Prerendered in both render modes: every rendered token pair (light, dark,
// high-contrast) is checked against WCAG 2.2 (SC 1.4.3 4.5:1 text,
// SC 1.4.11 3:1 non-text) from the resolved design module, and the result
// ships as dist/design-audit.json. With the manifest flag
// `"designAudit": "strict"` (the scaffold's --strict-contrast), a failing
// pair THROWS here and fails the build — contrast then cannot creep back.
import type { APIRoute } from 'astro';
import manifest from '../../nvoos.json';
import { auditWarnings, contrastAudit } from '../forge/contrast.ts';
import { resolvedDesign } from '../lib/design.generated.ts';

export const prerender = true;

export const GET: APIRoute = () => {
	const audit = contrastAudit(resolvedDesign.tokens, resolvedDesign.darkTokens, resolvedDesign.highTokens);
	const strict = manifest.designAudit === 'strict';
	if (strict && (audit.failures.length > 0 || audit.unparsed.length > 0)) {
		throw new Error(`Design audit failed (strict mode): ${auditWarnings(audit).join(' ')}`);
	}
	return new Response(`${JSON.stringify({ generatedAt: resolvedDesign.source.analyzedAt ?? null, strict, audit }, null, '\t')}\n`, {
		headers: { 'content-type': 'application/json; charset=utf-8' },
	});
};
