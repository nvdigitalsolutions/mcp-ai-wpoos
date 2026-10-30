// ---------------------------------------------------------------------------
// Account island (Q5) — register / login / me, client-side against the
// commerce contract's auth surface through the same-origin /api/* proxy.
//
// Cookie flow: the bridge sets the auth cookie; the BFF serve layer strips
// the Domain attribute so the cookie lands host-only on the app origin.
// ---------------------------------------------------------------------------

import { useEffect, useState } from 'react';
import type { Customer } from '../lib/commerce/types';
import { AuthForms } from './AuthForms';

const API_BASE = import.meta.env.PUBLIC_API_BASE || '/api';

interface MeState {
	loading: boolean;
	customer: Customer | null;
}

export default function AccountGate() {
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

	if (me.loading) {
		return <p>Loading account…</p>;
	}

	if (me.customer) {
		return (
			<div>
				<p>
					Signed in as <strong>{me.customer.name}</strong> ({me.customer.email}).
				</p>
				<LogoutButton />
			</div>
		);
	}

	return <AuthForms onAuthed={(customer) => setMe({ loading: false, customer })} />;
}

function LogoutButton() {
	const logout = async (): Promise<void> => {
		await fetch(`${API_BASE}/auth/logout`, {
			method: 'POST',
			credentials: 'include',
		});
		window.location.reload();
	};

	return (
		<button type="button" className="btn btn--ghost" onClick={logout}>
			Sign out
		</button>
	);
}
