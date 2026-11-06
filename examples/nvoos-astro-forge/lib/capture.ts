// ---------------------------------------------------------------------------
// capture.ts — the screenshot contract (Proposal 118 §2).
//
// The forge package stays browser-free (no Playwright — hundreds of MB for
// one optional feature). Screenshots arrive through two seams instead:
//
//  1. A capture endpoint — NVOOS_FORGE_CAPTURE_URL. The operator's
//     browserless/media-worker (or any service) accepts
//     `POST { url, width, label }` and returns `{ image }` where image is
//     either a data URL (`data:image/png;base64,…`) or
//     `{ base64, mime }`. This covers REMOTE sources (the built site on
//     localhost is not reachable from a remote endpoint — use seam 2).
//  2. Dropped artifacts — PNG/JPG/WebP files placed in
//     `<target>/verify-artifacts/<label>.{png,jpg,jpeg,webp}` by the
//     operator, an agent with browser tooling, or a CI step. Deterministic,
//     zero network, works for localhost builds.
//
// Both seams normalize into the VisionImage shape the vision module wants.
// Fail-soft: a missing artifact/endpoint yields undefined — callers warn
// and continue (the visual layer is advisory unless gated).
//
// Zero dependencies, Node type-stripping safe.
// ---------------------------------------------------------------------------

import { readFile, readdir } from 'node:fs/promises';
import { join } from 'node:path';

import type { VisionImage } from './vision.ts';

/** The capture endpoint, when configured (undefined = seam 2 only). */
export function captureEndpoint( env: NodeJS.ProcessEnv = process.env ): { url: string } | undefined {
	const url = env.NVOOS_FORGE_CAPTURE_URL;
	if ( ! url ) {
		return undefined;
	}
	return { url: url.replace( /\/+$/, '' ) };
}

/** Normalize an endpoint's `image` field into the VisionImage shape. */
export function normalizeImagePayload( image: unknown ): VisionImage | undefined {
	if ( typeof image === 'string' ) {
		const match = /^data:(image\/[\w.+-]+);base64,(.+)$/s.exec( image.trim() );
		if ( match ) {
			return { mime: match[ 1 ], data: match[ 2 ] };
		}
		return undefined;
	}
	if ( image && typeof image === 'object' ) {
		const record = image as { base64?: unknown; mime?: unknown };
		if ( typeof record.base64 === 'string' && typeof record.mime === 'string' ) {
			return { mime: record.mime, data: record.base64 };
		}
	}
	return undefined;
}

/**
 * Capture a REMOTE page through the endpoint (the built site on localhost
 * cannot be reached this way — drop its artifact instead).
 */
export async function captureRemote(
	endpoint: { url: string },
	targetUrl: string,
	label: string,
	width: number = 1440,
	fetchImpl: typeof fetch = fetch,
	timeoutMs: number = 60_000
): Promise<VisionImage | undefined> {
	let response: Response;
	try {
		response = await fetchImpl( `${ endpoint.url }/capture`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { url: targetUrl, width, label } ),
			signal: AbortSignal.timeout( timeoutMs ),
		} );
	} catch ( error ) {
		console.warn( `capture endpoint request failed: ${ error instanceof Error ? error.message : error }` );
		return undefined;
	}
	if ( ! response.ok ) {
		console.warn( `capture endpoint refused: ${ response.status } ${ response.statusText }` );
		return undefined;
	}
	try {
		const payload = ( await response.json() ) as { image?: unknown };
		return normalizeImagePayload( payload.image );
	} catch ( error ) {
		console.warn( `capture endpoint response parse failed: ${ error instanceof Error ? error.message : error }` );
		return undefined;
	}
}

const ARTIFACT_MIMES: Array<{ ext: string; mime: string }> = [
	{ ext: 'png', mime: 'image/png' },
	{ ext: 'jpg', mime: 'image/jpeg' },
	{ ext: 'jpeg', mime: 'image/jpeg' },
	{ ext: 'webp', mime: 'image/webp' },
];

/**
 * Read a dropped artifact `<dir>/<label>.<ext>` into the VisionImage shape.
 * undefined when nothing was dropped (callers warn; never a crash).
 */
export async function readArtifact( dir: string, label: string ): Promise<VisionImage | undefined> {
	for ( const { ext, mime } of ARTIFACT_MIMES ) {
		try {
			const data = await readFile( join( dir, `${ label }.${ ext }` ), 'base64' );
			return { data, mime };
		} catch {
			// Try the next extension.
		}
	}
	return undefined;
}

/** List the dropped artifacts (the verify report + instructions use this). */
export async function listArtifacts( dir: string ): Promise<string[]> {
	try {
		return ( await readdir( dir ) ).filter( ( name ) => /\.(png|jpe?g|webp)$/i.test( name ) );
	} catch {
		return [];
	}
}
