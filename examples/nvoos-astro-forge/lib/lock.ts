// ---------------------------------------------------------------------------
// lock.ts — the forge→human handoff lock.
//
// WHY: a forged project is FORGE-OWNED. `scaffold --overwrite` regenerates it
// wholesale, and `npm run check` fails on any drift — correct while the forge is
// the author, wrong the moment a developer takes the site over. The codegen
// convention across the ecosystem (Go's ``// Code generated … DO NOT EDIT``,
// scaffolders that declare ownership and refuse to overwrite user-owned files,
// lockfiles that only the owning tool updates) is the same: OWNERSHIP IS
// DECLARED, and regeneration must never silently destroy hand-written work.
//
// WHAT: after a sync completes, `forge lock` writes `forge.lock.json` at the
// project root. It records WHO took the site over, WHEN, WHY, and a sha256
// fingerprint of every file the forge generated at that moment. After that:
//
//   · `scaffold --overwrite` refuses to run (fail-closed) without `--unlock`
//   · `npm run check` reports hand-edits as expected drift, not failures
//   · `forge status` answers "who owns this tree, and what changed since?"
//
// The fingerprint is provenance, not permission: it lets an operator diff what
// the developer changed, and it proves the tree was a real forge output when
// the handoff happened.
// ---------------------------------------------------------------------------

import { createHash } from 'node:crypto';
import { readFile, readdir, rm, stat, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

/** The lock record written at handoff (`<target>/forge.lock.json`). */
export interface ForgeLock {
	version: 1;
	generator: string;
	/** The profile's site name. */
	site: string;
	/** The source the project was forged from. */
	source?: string;
	lockedAt: string;
	lockedBy?: string;
	reason?: string;
	/** sha256 of every forge-managed file at the moment of handoff. */
	fingerprint: Record< string, string >;
}

export interface LockDrift {
	/** Managed files whose hash changed (hand-edited). */
	changed: string[];
	/** Managed files that no longer exist. */
	removed: string[];
	/** Files added under the project that the forge never generated. */
	added: string[];
}

/** Where a project's lock lives. */
export function lockPath( target: string ): string {
	return join( target, 'forge.lock.json' );
}

/** True when the target carries a handoff lock. */
export function isLocked( target: string ): boolean {
	return existsSync( lockPath( target ) );
}

/** Read the lock, or undefined when the project is forge-owned. */
export async function readLock( target: string ): Promise< ForgeLock | undefined > {
	if ( ! isLocked( target ) ) {
		return undefined;
	}
	try {
		return JSON.parse( await readFile( lockPath( target ), 'utf8' ) ) as ForgeLock;
	} catch {
		// A corrupt lock must still stop a regeneration — fail closed.
		return {
			version: 1,
			generator: 'unknown',
			site: 'unknown',
			lockedAt: 'unknown',
			fingerprint: {},
			reason: 'lock file is unreadable',
		};
	}
}

async function sha256( path: string ): Promise< string > {
	const buffer = await readFile( path );
	return createHash( 'sha256' ).update( buffer ).digest( 'hex' );
}

/** Normalize a path to the lock's POSIX-style relative form. */
function toPosix( target: string, path: string ): string {
	return relative( target, path ).split( sep ).join( '/' );
}

/**
 * The files the forge manages: the generated-file manifest when present (it is
 * the forge's own declaration of ownership), else every source/config file.
 */
async function managedFiles( target: string ): Promise< string[] > {
	const manifestPath = join( target, 'nvoos.generated.json' );
	if ( existsSync( manifestPath ) ) {
		try {
			const manifest = JSON.parse( await readFile( manifestPath, 'utf8' ) ) as {
				files?: Record< string, string >;
			};
			const listed = Object.keys( manifest.files ?? {} );
			if ( listed.length > 0 ) {
				return listed;
			}
		} catch {
			// Fall through to the walk.
		}
	}
	const walk = async ( dir: string ): Promise< string[] > => {
		const found: string[] = [];
		for ( const entry of await readdir( dir, { withFileTypes: true } ) ) {
			if ( entry.name === 'node_modules' || entry.name === 'dist' || entry.name === '.astro' ) {
				continue;
			}
			const path = join( dir, entry.name );
			if ( entry.isDirectory() ) {
				found.push( ...( await walk( path ) ) );
			} else {
				found.push( toPosix( target, path ) );
			}
		}
		return found;
	};
	return walk( target );
}

/** Build a lock record for the CURRENT state of a project. */
export async function computeLock(
	target: string,
	meta: { site: string; source?: string; generator: string; by?: string; reason?: string }
): Promise< ForgeLock > {
	const fingerprint: Record< string, string > = {};
	for ( const path of await managedFiles( target ) ) {
		const full = join( target, path );
		if ( existsSync( full ) ) {
			fingerprint[ path ] = await sha256( full );
		}
	}
	return {
		version: 1,
		generator: meta.generator,
		site: meta.site,
		source: meta.source,
		lockedAt: new Date().toISOString(),
		lockedBy: meta.by,
		reason: meta.reason,
		fingerprint,
	};
}

/** Write the handoff lock. */
export async function writeLock( target: string, lock: ForgeLock ): Promise< void > {
	await writeFile( lockPath( target ), `${ JSON.stringify( lock, null, '\t' ) }\n`, 'utf8' );
}

/** Remove the lock (the operator explicitly returns the tree to the forge). */
export async function removeLock( target: string ): Promise< boolean > {
	if ( ! isLocked( target ) ) {
		return false;
	}
	await rm( lockPath( target ), { force: true } );
	return true;
}

/** What changed since handoff — hand-edits are expected, not a failure. */
export async function lockDrift( target: string, lock: ForgeLock ): Promise< LockDrift > {
	const changed: string[] = [];
	const removed: string[] = [];
	for ( const [ path, expected ] of Object.entries( lock.fingerprint ) ) {
		const full = join( target, path );
		if ( ! existsSync( full ) ) {
			removed.push( path );
			continue;
		}
		if ( ( await sha256( full ) ) !== expected ) {
			changed.push( path );
		}
	}
	const known = new Set( Object.keys( lock.fingerprint ) );
	const added: string[] = [];
	for ( const path of await managedFiles( target ) ) {
		if ( ! known.has( path ) && existsSync( join( target, path ) ) ) {
			added.push( path );
		}
	}
	return { changed: changed.sort(), removed: removed.sort(), added: added.sort() };
}

/** A one-line ownership summary for `forge status` / deploy guards. */
export function describeLock( lock: ForgeLock | undefined ): string {
	if ( ! lock ) {
		return 'forge-owned (regenerated freely; `npm run check` gates drift)';
	}
	const who = lock.lockedBy ?? 'an operator';
	const why = lock.reason ? ` — ${ lock.reason }` : '';
	return `LOCKED for hand-editing since ${ lock.lockedAt } by ${ who }${ why }; the forge will not overwrite it`;
}

/** True when a path is a directory that exists (the lock guard's precondition). */
export async function targetExists( target: string ): Promise< boolean > {
	try {
		return ( await stat( target ) ).isDirectory();
	} catch {
		return false;
	}
}
