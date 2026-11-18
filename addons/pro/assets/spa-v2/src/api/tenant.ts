/**
 * Estate (tenant) selection for the console.
 *
 * The Laravel runtime resolves the estate a request acts in from the
 * `X-Tenant-Id` header (`App\Http\Middleware\ResolveApiTenant`, 149 §21). The
 * header is a selector, never an authority: the middleware accepts only an
 * estate the caller is a member of (404 `tenant_not_found` otherwise), answers
 * 409 `tenant_required` when a multi-estate caller sends nothing, and never
 * falls back to a default estate.
 *
 * The selection lives in `localStorage` because the fetch wrapper has to read
 * it on every request, not only inside the React tree. WordPress ignores the
 * header, so a value left behind by a Laravel session is inert on that
 * runtime — but a client should still only send it to a backend that reads it
 * (see `tenantId` on the console fetch wrapper).
 *
 * @package NV_oOS_Pro_Spa
 */

/** The selector header the middleware reads. */
export const TENANT_HEADER = 'X-Tenant-Id';

/**
 * Matches the `tenants.id` column width the middleware enforces: a longer
 * value is rejected with 404 rather than truncated, so it must never be
 * stored in the first place.
 */
const MAX_TENANT_ID_LENGTH = 64;

/** localStorage key — namespaced so a console release cannot collide. */
export const TENANT_STORAGE_KEY = 'nvoos-console.tenant';

/** One estate the caller can act in, as `GET /api/v1/session/me` reports it. */
export interface EstateSummary {
	id: string;
	name: string;
	slug?: string;
	role?: string;
	is_operator?: boolean;
}

/**
 * What the client must do before issuing estate-scoped requests.
 *
 * - `none`    — no memberships reported (WordPress, or an account that has
 *               none yet). Nothing to select; a scoped route answers its own
 *               error, which is the honest surface.
 * - `proceed` — exactly one membership, or a stored id that is still one of
 *               the caller's memberships.
 * - `pick`    — several memberships and no usable stored selection. Sending
 *               nothing would 409 every scoped request, so the client must
 *               ask before booting the shell.
 */
export type TenantSelection =
	| { action: 'none' }
	| { action: 'pick' }
	| { action: 'proceed'; tenantId: string };

/**
 * An id is usable when it is a non-empty string with no whitespace and no
 * more than the column width. Anything else is treated as "not selected",
 * never normalised — a mangled id would be a different estate.
 */
function isUsableId( value: unknown ): value is string {
	if ( typeof value !== 'string' ) {
		return false;
	}
	const trimmed = value.trim();
	return (
		trimmed !== '' &&
		trimmed.length <= MAX_TENANT_ID_LENGTH &&
		! /\s/.test( trimmed )
	);
}

/** The selected estate id, or `''` when none is stored (or storage is denied). */
export function readTenantId(): string {
	try {
		const raw = window.localStorage.getItem( TENANT_STORAGE_KEY );
		return isUsableId( raw ) ? raw.trim() : '';
	} catch {
		return '';
	}
}

/**
 * Persist the selected estate id. An empty or invalid id clears the selection
 * rather than storing a value the server would reject on every request.
 */
export function writeTenantId( id: string ): void {
	try {
		if ( isUsableId( id ) ) {
			window.localStorage.setItem( TENANT_STORAGE_KEY, id.trim() );
			return;
		}
		window.localStorage.removeItem( TENANT_STORAGE_KEY );
	} catch {
		// Storage can be denied (private mode, quota). A single-membership
		// caller is still served — the middleware resolves it without a
		// header — so this degrades instead of failing boot.
	}
}

/** Drop the stored selection (sign-out, stale id, or a re-pick). */
export function clearTenantId(): void {
	writeTenantId( '' );
}

/**
 * Decide what the client must do before issuing estate-scoped requests.
 *
 * A stored id that is no longer a membership is treated as absent, so an
 * estate the caller was removed from lands on the picker instead of a
 * permanent 404 on every page.
 */
export function resolveTenantSelection(
	estates: readonly EstateSummary[],
	storedId: string,
): TenantSelection {
	if ( estates.length === 0 ) {
		return { action: 'none' };
	}

	const membershipIds = estates.map( ( estate ) => estate.id );

	if ( isUsableId( storedId ) && membershipIds.includes( storedId.trim() ) ) {
		return { action: 'proceed', tenantId: storedId.trim() };
	}

	if ( estates.length === 1 ) {
		return { action: 'proceed', tenantId: estates[ 0 ].id };
	}

	return { action: 'pick' };
}
