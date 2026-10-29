// ---------------------------------------------------------------------------
// ManageLink island — dashboard entry point for store managers.
//
// The commerce contract's /auth/me surface answers who is signed in; when the
// session can manage the store it also carries a backend-owned dashboard URL,
// which this island re-roots onto the storefront origin (the BFF proxies that
// path same-origin). Renders nothing for anonymous shoppers — no layout
// shift, no login prompts.
// ---------------------------------------------------------------------------

import { useEffect, useState } from 'react';

const API_BASE = import.meta.env.PUBLIC_API_BASE || '/api';

interface MeResponse {
	id?: number;
	canManage?: boolean;
	adminUrl?: string;
}

export default function ManageLink() {
	const [href, setHref] = useState<string | null>(null);

	useEffect(() => {
		let cancelled = false;

		fetch(`${API_BASE}/auth/me`, {
			headers: { accept: 'application/json' },
			credentials: 'include',
		})
			.then(async (res) => {
				if (!res.ok) {
					return;
				}
				const me = (await res.json()) as MeResponse;
				if (cancelled || !me.canManage || !me.adminUrl) {
					return;
				}
				try {
					const url = new URL(me.adminUrl);
					if (!cancelled) {
						setHref(url.pathname + url.search);
					}
				} catch {
					// Malformed dashboard URL — the link simply stays hidden.
				}
			})
			.catch(() => {
				// Offline or contract unavailable — stay hidden.
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
