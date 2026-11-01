// ---------------------------------------------------------------------------
// auth.ts — nvoos-astro-forge MCP authorization (Proposal 096 Phase 4; 095
// pattern). Write tools are gated on a bearer NVOOS_FORGE_MCP_TOKEN with a
// timing-safe compare. Fail closed: no token configured, or no/mismatched
// bearer = deny. Read tools pass without credentials. The write-tool name
// set comes from the single-source registry (passed in, never duplicated).
// ---------------------------------------------------------------------------

import { timingSafeEqual } from 'node:crypto';

export interface AuthContext {
	ok: boolean;
	message?: string;
	bearer?: boolean;
}

export function constantTimeEqual( a: string, b: string ): boolean {
	const bufA = Buffer.from( a, 'utf8' );
	const bufB = Buffer.from( b, 'utf8' );
	if ( bufA.length !== bufB.length ) {
		return false;
	}
	return timingSafeEqual( bufA, bufB );
}

export function verifyBearer( authHeader: string | undefined, expected: string | undefined ): boolean {
	if ( ! expected ) {
		return false;
	}
	if ( ! authHeader ) {
		return false;
	}
	const match = /^Bearer\s+(.+)$/i.exec( authHeader.trim() );
	if ( ! match ) {
		return false;
	}
	return constantTimeEqual( match[ 1 ].trim(), expected );
}

export function firstHeaderValue( value: string | string[] | undefined ): string | undefined {
	if ( Array.isArray( value ) ) {
		return value[ 0 ];
	}
	return value;
}

export function isWriteToolCall( body: unknown, writeToolNames: ReadonlySet<string> ): boolean {
	if ( ! body || typeof body !== 'object' ) {
		return false;
	}
	const message = body as { method?: string; params?: { name?: string } };
	if ( message.method !== 'tools/call' ) {
		return false;
	}
	const name = message.params?.name;
	return typeof name === 'string' && writeToolNames.has( name );
}

export function authorizeRequest(
	req: { headers: Record<string, string | string[] | undefined> },
	body: unknown,
	env: { NVOOS_FORGE_MCP_TOKEN?: string },
	writeToolNames: ReadonlySet<string>
): AuthContext {
	if ( ! isWriteToolCall( body, writeToolNames ) ) {
		return { ok: true };
	}
	const bearer = verifyBearer( firstHeaderValue( req.headers[ 'authorization' ] ), env.NVOOS_FORGE_MCP_TOKEN );
	if ( bearer ) {
		return { ok: true, bearer: true };
	}
	return {
		ok: false,
		message: 'write tools require a valid NVOOS_FORGE_MCP_TOKEN bearer token',
	};
}
