// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// content-source.ts — where a scaffold's local content comes from.
//
// Two profile states ship a materialized snapshot beside the profile
// (`sites/<name>.content/`, written by `forge materialize`):
//
//   content.source: 'materialized'  the CMS is GONE — the snapshot IS the source
//   content.snapshot: true          the CMS stays authoritative — the snapshot
//                                   is the build-time fallback it degrades to
//
// Both resolve to the same directory, so the scaffold copies one thing either
// way; only the intent differs. The loader already implements that intent
// (hybrid local, 106 P2): WordPress wins on collision, local fills the gaps,
// and local takes over entirely when the origin is unreachable.
//
// Forge-internal — the CLI calls it — and deliberately NOT in
// FORGE_LIB_FILES, so it never lands in a customer project.
// ---------------------------------------------------------------------------

import type { SiteProfile } from './types.ts';

/**
 * Does the profile declare its CMS GONE?
 *
 * `materialized` is a PROMISE, not a hint: the snapshot is the only source,
 * so nothing may contact the old origin. `snapshot: true` is the opposite
 * promise (the CMS stays authoritative and the snapshot is the net), which is
 * why the two must never collapse into one flag.
 */
export function isMaterializedContent(input: Pick<SiteProfile, 'content'>): boolean {
	return input.content?.source === 'materialized';
}

/**
 * Apply the defaults a `materialized` profile implies.
 *
 * With no CMS, a collection the snapshot does not carry is an EMPTY
 * collection, not a broken source: every collection becomes `optional`, so a
 * route that has no materialized pages still renders instead of failing the
 * build. The scaffolded loader never fetches anyway (`localOnly`), so this is
 * defence in depth for every other reader of the profile.
 *
 * Returns the SAME object when nothing changes, and never mutates the input.
 */
export function withMaterializedDefaults(profile: SiteProfile): SiteProfile {
	if (!isMaterializedContent(profile)) {
		return profile;
	}
	if (profile.collections.every((collection) => collection.optional)) {
		return profile;
	}
	return {
		...profile,
		collections: profile.collections.map((collection) =>
			collection.optional ? collection : { ...collection, optional: true }
		),
	};
}

export interface ContentDirInput {
	/** The profile's `content` block. */
	content?: SiteProfile['content'];
	/** The resolved `sites/<name>.json` path, when the caller knows it. */
	profilePath?: string;
	/** An explicit `--content <dir>` override. */
	explicit?: string;
}

/**
 * The snapshot directory a scaffold should copy, or `undefined` when the
 * profile asks for none.
 *
 * An explicit `--content` always wins: that is the operator escape hatch for a
 * snapshot kept somewhere else. Deriving the directory from the profile path
 * (`sites/<name>.json` → `sites/<name>.content`) is what makes a CI rebuild
 * need no extra flag.
 *
 * `snapshot` is independent of the fetch mode: a `source: 'none'` site may hold
 * its whole body in the snapshot too, so the flag still copies it.
 */
export function resolveContentDir( input: ContentDirInput ): string | undefined {
	if ( input.explicit ) {
		return input.explicit;
	}
	const wants = input.content?.source === 'materialized' || input.content?.snapshot === true;
	if ( ! wants ) {
		return undefined;
	}
	return input.profilePath ? input.profilePath.replace( /\.json$/, '.content' ) : undefined;
}

export interface MediaDirInput {
	/** The profile's `media` block. */
	media?: SiteProfile['media'];
	/** The resolved `sites/<name>.json` path, when the caller knows it. */
	profilePath?: string;
	/** An explicit `--media-snapshot <dir>` override. */
	explicit?: string;
}

/**
 * The committed media snapshot a scaffold should copy, or `undefined` when the
 * profile asks for none.
 *
 * Mirrors `resolveContentDir`: an explicit operator path wins, otherwise
 * `media.snapshot: true` derives `sites/<name>.media` from the profile path so
 * a CI rebuild needs no extra flag. A site that keeps its CMS may still leave
 * an old snapshot on disk; without the flag nothing is copied.
 */
export function resolveMediaDir( input: MediaDirInput ): string | undefined {
	if ( input.explicit ) {
		return input.explicit;
	}
	if ( input.media?.snapshot !== true ) {
		return undefined;
	}
	return input.profilePath ? input.profilePath.replace( /\.json$/, '.media' ) : undefined;
}
