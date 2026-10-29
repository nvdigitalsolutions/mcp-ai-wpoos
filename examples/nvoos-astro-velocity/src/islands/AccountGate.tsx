// ---------------------------------------------------------------------------
// Account island (Q5) — register / login / me, client-side against the
// commerce contract's auth surface through the same-origin /api/* proxy.
//
// Cookie flow: the bridge sets the auth cookie; the BFF serve layer strips
// the Domain attribute so the cookie lands host-only on the app origin —
// the proven console login pattern. CSRF for register uses the contract's
// own nonce endpoint (GET /api/auth/nonce), NOT backend nonce semantics.
// ---------------------------------------------------------------------------

import { useEffect, useState, type FormEvent } from 'react';
import type { Customer, NonceResponse } from '../lib/commerce/types';

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

function AuthForms({ onAuthed }: { onAuthed: (customer: Customer) => void }) {
	const [mode, setMode] = useState<'login' | 'register'>('login');
	const [email, setEmail] = useState('');
	const [name, setName] = useState('');
	const [password, setPassword] = useState('');
	const [error, setError] = useState<string | null>(null);
	const [busy, setBusy] = useState(false);

	const submit = async (event: FormEvent): Promise<void> => {
		event.preventDefault();
		setBusy(true);
		setError(null);
		try {
			const path = mode === 'login' ? 'login' : 'register';
			const body: Record<string, string> = { email, password };
			if (mode === 'register') {
				// Contract-level CSRF: fetch the contract's nonce first.
				const nonceRes = await fetch(`${API_BASE}/auth/nonce`, {
					headers: { accept: 'application/json' },
				});
				if (!nonceRes.ok) {
					throw new Error('Could not start registration.');
				}
				const { nonce } = (await nonceRes.json()) as NonceResponse;
				body.nonce = nonce;
				body.name = name;
			}
			const res = await fetch(`${API_BASE}/auth/${path}`, {
				method: 'POST',
				headers: { 'content-type': 'application/json', accept: 'application/json' },
				credentials: 'include',
				body: JSON.stringify(body),
			});
			if (!res.ok) {
				const payload = (await res.json().catch(() => ({}))) as { message?: string };
				throw new Error(payload.message || 'Authentication failed.');
			}
			onAuthed((await res.json()) as Customer);
		} catch (err) {
			setError(err instanceof Error ? err.message : 'Authentication failed.');
		} finally {
			setBusy(false);
		}
	};

	return (
		<div className="auth">
			<div role="tablist" aria-label="Account form">
				<button
					type="button"
					className="btn btn--ghost"
					aria-selected={mode === 'login'}
					onClick={() => {
						setMode('login');
						setError(null);
					}}
				>
					Sign in
				</button>
				<button
					type="button"
					className="btn btn--ghost"
					aria-selected={mode === 'register'}
					onClick={() => {
						setMode('register');
						setError(null);
					}}
				>
					Create account
				</button>
			</div>
			<form onSubmit={submit} className="auth__form">
				{mode === 'register' && (
					<div className="field">
						<label htmlFor="auth-name">Name</label>
						<input
							id="auth-name"
							type="text"
							value={name}
							onChange={(event) => setName(event.target.value)}
							autoComplete="name"
						/>
					</div>
				)}
				<div className="field">
					<label htmlFor="auth-email">Email</label>
					<input
						id="auth-email"
						type="email"
						required
						value={email}
						onChange={(event) => setEmail(event.target.value)}
						autoComplete="email"
					/>
				</div>
				<div className="field">
					<label htmlFor="auth-password">Password</label>
					<input
						id="auth-password"
						type="password"
						required
						minLength={8}
						value={password}
						onChange={(event) => setPassword(event.target.value)}
						autoComplete={mode === 'login' ? 'current-password' : 'new-password'}
					/>
				</div>
				{error && (
					<p role="alert" className="promo-field__err">
						{error}
					</p>
				)}
				<button type="submit" className="btn" disabled={busy}>
					{busy ? 'Please wait…' : mode === 'login' ? 'Sign in' : 'Create account'}
				</button>
			</form>
		</div>
	);
}
