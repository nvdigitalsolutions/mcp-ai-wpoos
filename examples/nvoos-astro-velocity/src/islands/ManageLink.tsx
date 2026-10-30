// ---------------------------------------------------------------------------
// ManageLink island — dashboard entry point for store managers.
//
// The commerce contract's /auth/me surface answers who is signed in; when the
// session can manage the store the header shows a "Manage store" link to the
// standalone Astro CMS surface (/manage — served by this app, not a backend
// admin URL). Renders nothing for anonymous shoppers — no layout shift, no
// login prompts.
// ---------------------------------------------------------------------------

import { useEffect, useState } from 'react';

const API_BASE = import.meta.env.PUBLIC_API_BASE || '/api';
const MANAGE_PATH = '/manage/';

interface MeResponse {
	id?: number;
	canManage?: boolean;
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
				if (cancelled || !me.canManage) {
					return;
				}
				setHref(MANAGE_PATH);
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
