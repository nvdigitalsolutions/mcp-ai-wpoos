// ---------------------------------------------------------------------------
// forms-direct.test.ts — the emitted DIRECT-delivery endpoint (Proposal 138).
//
// `forms.delivery: 'direct'` writes this module into the generated project as
// SOURCE. `astro build` never parses it (the scaffold writes it verbatim), so a
// broken emitter string ships a project that only fails later at `astro check`
// — the escape hazard documented in reference/email-delivery.md. These tests
// pin the things that bite:
//   - the emitted text is syntactically valid TypeScript
//   - the throttle keys on the PROXY-AWARE client address, not the socket peer
//   - the shared client envelope (`{ fields: { … } }`) is flattened
//   - Turnstile is verified server-side, before the throttle, and fails open
//
// The peer-keyed throttle shipped once and made the rate limit site-wide:
// behind the platform proxy every visitor shares one bucket (2026-11-15).
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

/** No site key — the shape every pre-Turnstile profile emits. */
const source = renderDirectFormsEndpointTs();

/** The virtuallyjamaica shape: a widget, bound to a host and an action. */
const turnstileSource = renderDirectFormsEndpointTs({
	siteKey: '1x00000000000000000000AA',
	action: 'contact',
	hostname: 'virtuallyjamaica.com',
	onProviderError: 'allow',
});

/** TypeScript syntax errors in emitted text (the build never catches them). */
function syntaxErrors(text: string): string[] {
	const { diagnostics } = transpileModule(text, {
		compilerOptions: { target: ScriptTarget.ES2022, module: ModuleKind.ESNext },
		reportDiagnostics: true,
	});
	return (diagnostics ?? [])
		.filter((diagnostic) => diagnostic.category === DiagnosticCategory.Error)
		.map((diagnostic) => flattenDiagnosticMessageText(diagnostic.messageText, ' '));
}

describe('renderDirectFormsEndpointTs', () => {
	it('emits syntactically valid TypeScript', () => {
		expect(syntaxErrors(source)).toEqual([]);
	});

	it('emits syntactically valid TypeScript with Turnstile enabled', () => {
		expect(syntaxErrors(turnstileSource)).toEqual([]);
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

	it('reads the shared client envelope through the shared parser', () => {
		// The flatten + reserved-field rules live in lib/forms.ts so the bridge
		// fallback cannot drift from this endpoint; they are unit-tested there.
		expect(source).toContain(
			"import { readSubmissionFields, submissionMessage } from '../../../forge/forms.ts';"
		);
		expect(source).toContain('const fields = await readSubmissionFields( request );');
	});

	it('builds the email through the shared message builder', () => {
		// Reserved fields, Reply-To and the idempotency digest are its job — a
		// second copy here is exactly what would drift.
		expect(source).toContain('const message = submissionMessage( fields );');
		expect(source).not.toContain('INTERNAL');
	});
});

describe('renderDirectFormsEndpointTs — Turnstile', () => {
	it('emits no verification code without a site key', () => {
		expect(source).not.toContain('siteverify');
		expect(source).not.toContain('TURNSTILE_SITE_KEY');
		expect(source).not.toContain('cf-turnstile-response');
	});

	it('verifies the token before consuming a throttle slot', () => {
		// A token is free to mint, so a rejected one must not burn a real
		// address's slots — that would let one bot lock the form behind a NAT.
		const verifyAt = turnstileSource.indexOf('verifyTurnstile( token, ip, request )');
		const throttleAt = turnstileSource.indexOf('if ( throttled( ip, Date.now() ) )');
		expect(verifyAt).toBeGreaterThan(-1);
		expect(throttleAt).toBeGreaterThan(verifyAt);
	});

	it('binds the token to this site and this widget', () => {
		expect(turnstileSource).toContain("const TURNSTILE_HOSTNAME = 'virtuallyjamaica.com';");
		expect(turnstileSource).toContain("const TURNSTILE_ACTION = 'contact';");
		expect(turnstileSource).toContain('payload.hostname !== expected');
		expect(turnstileSource).toContain('payload.action !== TURNSTILE_ACTION');
	});

	it('treats a bad token as a rejection, not a transport error', () => {
		// Single-use 300 s tokens replay as `timeout-or-duplicate`; that is a
		// rejection with a 400, distinct from siteverify being unreachable.
		expect(turnstileSource).toContain('if ( payload.success !== true ) {');
		expect(turnstileSource).toContain("detail: ( payload[ 'error-codes' ] ?? [] ).join( ',' )");
		expect(turnstileSource).toContain("'Verification failed, please retry'");
		expect(turnstileSource).toContain('{ status: unavailable ? 503 : 400 }');
	});

	it('fails open when siteverify cannot answer', () => {
		expect(turnstileSource).toContain("const TURNSTILE_ON_ERROR: 'allow' | 'block' = 'allow';");
		expect(turnstileSource).toContain("return { ok: TURNSTILE_ON_ERROR === 'allow', detail: 'unreachable' };");
		expect(turnstileSource).toContain('AbortSignal.timeout( TURNSTILE_TIMEOUT_MS )');
	});

	it('honours a block-on-provider-error profile', () => {
		const strict = renderDirectFormsEndpointTs({ siteKey: 'k', onProviderError: 'block' });
		expect(strict).toContain("const TURNSTILE_ON_ERROR: 'allow' | 'block' = 'block';");
	});

	it('accepts a missing token unverified, but loudly', () => {
		// A widget that never rendered is a CSP/script fault far more often
		// than a bot; 400ing on it is the silent-drop failure class this
		// endpoint exists to prevent.
		expect(turnstileSource).toContain("if ( token === '' ) {");
		expect(turnstileSource).toContain('Turnstile token missing - accepted unverified');
	});

	it('keeps the token out of the email body', () => {
		// cf-turnstile-response is reserved by the shared builder, so the token
		// cannot reach the body — pinned in tests/forms.test.ts, not duplicated.
		expect(turnstileSource).toContain('submissionMessage( fields )');
	});

	it('does not crash when TURNSTILE_SECRET is unset yet', () => {
		expect(turnstileSource).toContain("const secret = ( runtimeEnv().TURNSTILE_SECRET ?? '' ).trim();");
		expect(turnstileSource).toContain("if ( secret === '' ) {");
	});
});
