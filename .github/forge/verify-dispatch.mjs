// GENERATED — emitDeployConfig (examples/nvoos-astro-forge/lib/deploy-config.ts).
// Re-emit after changing the verifier template:
//   node scripts/forge.mjs deploy-config --target .tmp-verify-emit --to github-pages
//   (then copy scripts/verify-dispatch.mjs here and restore the header).
#!/usr/bin/env node
// verify-dispatch.mjs — fail-closed dispatch verification (Forge Phase 3.3).
// Contract with nvoos-forge-bridge: client_payload carries time (unix
// seconds), nonce, and signature = HMAC-SHA256(secret, "<time>.<nonce>").
// Anything stale (> max-age) or mismatched exits non-zero: no build.

import { createHmac, timingSafeEqual } from 'node:crypto';

function arg( name, fallback ) {
	const i = process.argv.indexOf( name );
	return i !== -1 && process.argv[ i + 1 ] !== undefined ? process.argv[ i + 1 ] : fallback;
}

const eventType = arg( "--event-type", "nvoos-forge-content-changed" );
const maxAge = Number( arg( "--max-age", "600" ) );
const secret = process.env.FORGE_WEBHOOK_SECRET ?? "";

function fail( message ) {
	console.error( `verify-dispatch: ${ message }` );
	process.exit( 1 );
}

let payload;
try {
	payload = JSON.parse( process.env.DISPATCH_PAYLOAD ?? "{}" );
} catch {
	fail( "DISPATCH_PAYLOAD is not valid JSON — refusing to build." );
}

const { time, nonce, signature, type } = payload ?? {};
if ( type !== eventType ) {
	fail( `unexpected event type "${ type }" (wanted ${ eventType }) — refusing to build.` );
}
if ( ! secret ) {
	fail( "FORGE_WEBHOOK_SECRET is not set — refusing to build." );
}
if ( ! time || ! nonce || ! signature ) {
	fail( "payload is missing time/nonce/signature — refusing to build." );
}
const age = Math.abs( Date.now() / 1000 - Number( time ) );
if ( ! Number.isFinite( age ) || age > maxAge ) {
	fail( `dispatch is ${ Math.round( age ) }s old (max ${ maxAge }s) — refusing to build.` );
}
const expected = createHmac( "sha256", secret ).update( `${ time }.${ nonce }` ).digest( "hex" );
const a = Buffer.from( expected, "hex" );
const b = Buffer.from( String( signature ), "hex" );
if ( a.length === 0 || a.length !== b.length || ! timingSafeEqual( a, b ) ) {
	fail( "signature mismatch — refusing to build." );
}
console.log( "verify-dispatch: signature verified." );
