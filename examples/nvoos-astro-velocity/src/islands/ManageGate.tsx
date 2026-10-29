// ---------------------------------------------------------------------------
// ManageGate island — the standalone Astro CMS surface (/manage).
//
// Boot sequence (console-pattern parity, but entirely contract-based):
//   1. /api/auth/me decides the state: signed-out → sign-in form;
//      signed-in without the manage capability → not-authorized notice.
//   2. Managers get the host config (relative /api root, /media preview
//      base) + a fresh REST nonce, then the prebuilt Astro CMS bundle is
//      injected and self-mounts into #astro-admin-root.
//
// No backend admin URLs are referenced — the CMS talks to the contract
// through the same-origin /api/* proxy like every other island.
// ---------------------------------------------------------------------------

import { useEffect, useState } from 'react';
import type { Customer } from '../lib/commerce/types';
import { buildAdminConfig } from '../lib/manage-config';
import { AuthForms } from './AuthForms';

const API_BASE = import.meta.env.PUBLIC_API_BASE || '/api';
const BUNDLE_SRC = '/manage-assets/astro-admin.js';

interface MeState {
	loading: boolean;
	customer: Customer | null;
}

declare global {
	interface Window {
		AstroAdminConfig?: ReturnType<typeof buildAdminConfig>;
		wpApiSettings?: { root?: string; nonce?: string };
	}
}

export default function ManageGate() {
	const [me, setMe] = useState<MeState>({ loading: true, customer: null });

	useEffect(() => {
		fetch(`${API_BASE}/auth/me`, {
			headers: { accept: 'application/json' },
			credentials: 'include',
		})
			.then(async (res) => {
				if (res.ok) {
					setMe({ loading: false, customer: (await res.json()) as Customer });
				} else {
					setMe({ loading: false, customer: null });
				}
			})
			.catch(() => setMe({ loading: false, customer: null }));
	}, []);

	// Once a manager session is confirmed, wire the host config and load the
	// CMS bundle (which self-mounts on #astro-admin-root).
	useEffect(() => {
		if (me.loading || !me.customer?.canManage) {
			return;
		}

		let cancelled = false;

		const boot = async (): Promise<void> => {
			// Fresh REST nonce for the cookie-authenticated state-changing
			// calls the CMS makes (create/update/delete/upload).
			let nonce = '';
			try {
				const nonceRes = await fetch(`${API_BASE}/auth/nonce`, {
					headers: { accept: 'application/json' },
					credentials: 'include',
				});
				if (nonceRes.ok) {
					const payload = (await nonceRes.json()) as { nonce?: string };
					nonce = payload.nonce || '';
				}
			} catch {
				// Nonce unavailable — reads still work; writes will 403.
			}

			if (cancelled) {
				return;
			}

			// Server-authored config: root + media base + fresh REST nonce +
			// the session user (capabilities + read-only map) — the console's
			// buildConsoleRuntime() contract for the admin bundle.
			window.AstroAdminConfig = buildAdminConfig(me.customer, nonce, API_BASE);
			window.wpApiSettings = { root: API_BASE, nonce };

			const script = document.createElement('script');
			script.src = BUNDLE_SRC;
			script.defer = true;
			document.body.appendChild(script);
		};

		void boot();

		return () => {
			cancelled = true;
		};
	}, [me]);

	if (me.loading) {
		return <p className="manage__state">Loading…</p>;
	}

	if (!me.customer) {
		return (
			<div className="manage__auth">
				<h1>Manage store</h1>
				<AuthForms
					loginOnly
					onAuthed={(customer) => setMe({ loading: false, customer })}
				/>
			</div>
		);
	}

	if (!me.customer.canManage) {
		return (
			<div className="manage__state">
				<h1>Manage store</h1>
				<p role="alert">
					Your account does not have permission to manage this store.
				</p>
				<a className="btn" href="/">
					Back to the store
				</a>
			</div>
		);
	}

	return (
		<div className="manage">
			<div id="astro-admin-root" />
		</div>
	);
}
