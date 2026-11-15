// ---------------------------------------------------------------------------
// ManageLink island — dashboard entry point for store managers.
//
// The commerce contract's /auth/me surface answers who is signed in; when the
// session can manage the store the header shows a "Manage store" link to the
// standalone Astro CMS surface (/manage — served by this app, not a backend
// admin URL). Renders nothing for anonymous shoppers — no login prompts.
//
// Header stability (CLS discipline): the verdict is cached per tab
// (stale-while-revalidate) so returning managers see the link at hydration
// instead of after the round-trip, and the layout mounts this island with
// transition:persist so ClientRouter swaps neither refetch nor flicker it.
// ---------------------------------------------------------------------------

import { useEffect, useState } from 'react';

const API_BASE = import.meta.env.PUBLIC_API_BASE || '/api';
const MANAGE_PATH = '/manage/';
const CACHE_KEY = 'nvoos-can-manage';

interface MeResponse {
	id?: number;
	canManage?: boolean;
}

export default function ManageLink() {
	// Server-rendered and first client render stay null (hydration parity);
	// the cached verdict is adopted inside the effect below.
	const [href, setHref] = useState<string | null>(null);

	useEffect(() => {
		let cancelled = false;

		// Adopt the cached verdict synchronously — no pop-in for returning
		// managers in this tab. A stale cache self-corrects below (a 401 or
		// canManage:false hides the link).
		try {
			if (sessionStorage.getItem(CACHE_KEY) === '1') {
				setHref(MANAGE_PATH);
			}
		} catch {
			/* storage unavailable */
		}

		fetch(`${API_BASE}/auth/me`, {
			headers: { accept: 'application/json' },
			credentials: 'include',
		})
			.then(async (res) => {
				const me = res.ok ? ((await res.json()) as MeResponse) : null;
				const canManage = me?.canManage === true;
				if (cancelled) {
					return;
				}
				setHref(canManage ? MANAGE_PATH : null);
				try {
					sessionStorage.setItem(CACHE_KEY, canManage ? '1' : '0');
				} catch {
					/* storage unavailable */
				}
			})
			.catch(() => {
				// Offline or contract unavailable — keep the cached verdict.
			});

		return () => {
			cancelled = true;
		};
	}, []);

	if (!href) {
		return null;
	}

	return (
		<a className="site-nav__manage" href={href}>
			Manage store
		</a>
	);
}
