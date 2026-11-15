/**
 * In-memory estate store with an advisory JSONL event log.
 *
 * The store is a cache for UX and job coordination — never the source of
 * truth (ADR-04). On restart, estates are rebuilt from the catalogue seeds
 * or re-created; the reconciler re-derives their state from providers.
 */

import { ApiError } from '../util/envelope.js';
import { appendEvent } from '../util/jsonl.js';

export function createEstateStore( options = {} ) {
	const { eventsFile = null, now = () => new Date() } = options;
	const estates = new Map();

	async function recordEvent( type, slug, payload = {} ) {
		if ( ! eventsFile ) {
			return;
		}
		await appendEvent( eventsFile, { type, slug, ...payload } );
	}

	async function create( { slug, name, owner = null, blueprint, mode = 'mock' } ) {
		if ( estates.has( slug ) ) {
			throw new ApiError( 'slug_conflict', `Estate "${ slug }" already exists`, { slug }, 409 );
		}
		const timestamp = now().toISOString();
		const estate = {
			slug,
			name: name ?? blueprint?.name ?? slug,
			owner,
			mode,
			blueprint,
			evidence: {},
			secrets: {}, // encrypted values only
			lastError: null,
			createdAt: timestamp,
			updatedAt: timestamp,
		};
		estates.set( slug, estate );
		await recordEvent( 'estate.created', slug, { mode } );
		return estate;
	}

	function get( slug ) {
		return estates.get( slug ) ?? null;
	}

	function list() {
		return [ ...estates.values() ].sort( ( a, b ) => a.slug.localeCompare( b.slug ) );
	}

	async function update( slug, patch ) {
		const estate = estates.get( slug );
		if ( ! estate ) {
			throw new ApiError( 'not_found', `Estate "${ slug }" not found`, { slug }, 404 );
		}
		Object.assign( estate, patch, { updatedAt: now().toISOString() } );
		return estate;
	}

	async function setEvidence( slug, evidencePatch, source = 'probe' ) {
		const estate = estates.get( slug );
		if ( ! estate ) {
			throw new ApiError( 'not_found', `Estate "${ slug }" not found`, { slug }, 404 );
		}
		estate.evidence = { ...estate.evidence, ...evidencePatch };
		estate.updatedAt = now().toISOString();
		await recordEvent( 'estate.evidence', slug, { evidence: evidencePatch, source } );
		return estate;
	}

	async function remove( slug ) {
		const existed = estates.delete( slug );
		if ( existed ) {
			await recordEvent( 'estate.removed', slug );
		}
		return existed;
	}

	return {
		create,
		get,
		list,
		update,
		setEvidence,
		remove,
		recordEvent,
		has: ( slug ) => estates.has( slug ),
	};
}
