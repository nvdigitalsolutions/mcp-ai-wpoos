// ---------------------------------------------------------------------------
// forms-direct.test.ts — the emitted DIRECT-delivery endpoint (Proposal 138).
//
// `forms.delivery: 'direct'` writes this module into the generated project as
// SOURCE. `astro build` never parses it (the scaffold writes it verbatim), so a
// broken emitter string ships a project that only fails later at `astro check`
// — the escape hazard documented in reference/email-delivery.md. These tests
// pin the two things that bite:
//   - the emitted text is syntactically valid TypeScript
//   - the throttle keys on the PROXY-AWARE client address, not the socket peer
//
// The peer-keyed version shipped once and made the throttle site-wide: behind
// the platform proxy every visitor shares one bucket (measured 2026-11-15).
// ---------------------------------------------------------------------------

import {
	DiagnosticCategory,
	ModuleKind,
	ScriptTarget,
	flattenDiagnosticMessageText,
	transpileModule,
} from 'typescript';
import { describe, expect, it } from 'vitest';

import { renderDirectFormsEndpointTs } from '../lib/forms-direct.ts';

const source = renderDirectFormsEndpointTs();

describe('renderDirectFormsEndpointTs', () => {
	it('emits syntactically valid TypeScript', () => {
		const { diagnostics } = transpileModule(source, {
			compilerOptions: { target: ScriptTarget.ES2022, module: ModuleKind.ESNext },
			reportDiagnostics: true,
		});
		const errors = (diagnostics ?? [])
			.filter((diagnostic) => diagnostic.category === DiagnosticCategory.Error)
			.map((diagnostic) => flattenDiagnosticMessageText(diagnostic.messageText, ' '));
		expect(errors).toEqual([]);
	});

	it('derives the throttle key from the proxy-aware client address', () => {
		expect(source).toContain('function clientIp(');
		expect(source).toContain("request.headers.get( 'x-forwarded-for' )");
		expect(source).toContain('const ip = clientIp( request, clientAddress );');
		// The rightmost hop is the only one the proxy itself appended.
		expect(source).toContain('hops[ hops.length - 1 ]');
		// A header without a proxy in front must never win over the peer.
		expect(source).toContain('isLocalPeer( peer )');
	});

	it('treats a future timestamp as clock skew, not a bot', () => {
		// The page fills `timestamp` from the visitor's clock; a client running
		// ahead of the server must not be silently dropped (2026-11-15).
		expect(source).toContain('const elapsed = ( Date.now() - stamp ) / 1000;');
		expect(source).toContain('elapsed >= 0 && elapsed < MIN_SECONDS');
	});

	it('keeps the per-address throttle bucket bounded', () => {
		expect(source).toContain('MAX_TRACKED_IPS');
		expect(source).toContain('recent.splice');
	});
});
