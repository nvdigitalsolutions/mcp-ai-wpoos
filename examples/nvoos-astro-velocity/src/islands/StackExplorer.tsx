// ---------------------------------------------------------------------------
// StackExplorer island — accessible tab panels on the home page that explain
// the storefront's layers (client:visible).
//
// Two panels carry live reads: the contract panel fetches the real totalDocs
// from the commerce envelope (same-origin /api/*, the PromoCodeField
// pattern), and the design panel reflects the active theme/contrast axes from
// <html> — re-read after every View Transition swap because the ClientRouter
// replaces the <html> element (Proposal 104 Defect A pattern).
// ---------------------------------------------------------------------------

import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';

const API_BASE = import.meta.env.PUBLIC_API_BASE || '/api';

type TabId = 'frontend' | 'contract' | 'design' | 'ai';

interface TabDef {
	id: TabId;
	label: string;
}

const TABS: TabDef[] = [
	{ id: 'frontend', label: 'Frontend' },
	{ id: 'contract', label: 'Commerce contract' },
	{ id: 'design', label: 'Design system' },
	{ id: 'ai', label: 'AI control' },
];

type ContractState =
	| { status: 'idle' }
	| { status: 'loading' }
	| { status: 'ok'; count: number }
	| { status: 'error' };

interface ThemeState {
	theme: string;
	contrast: string;
}

/** Read the active theme axes from <html> — data-theme is absent for light. */
function readTheme(): ThemeState {
	const root = document.documentElement;
	return {
		theme: root.getAttribute('data-theme') || 'light',
		contrast: root.getAttribute('data-contrast') === 'high' ? 'high' : 'standard',
	};
}

export default function StackExplorer() {
	const uid = useId();
	const contractStarted = useRef(false);
	const [hydrated, setHydrated] = useState(false);
	const [active, setActive] = useState<TabId>('frontend');
	const [contract, setContract] = useState<ContractState>({ status: 'idle' });
	const [theme, setTheme] = useState<ThemeState>({ theme: 'light', contrast: 'standard' });

	useEffect(() => {
		setHydrated(true);
		const syncTheme = () => setTheme(readTheme());
		syncTheme();
		document.addEventListener('astro:after-swap', syncTheme);
		return () => document.removeEventListener('astro:after-swap', syncTheme);
	}, []);

	// Live contract read — once, on first activation of the contract panel.
	// Guarded by a ref (not contract.status): adding the status to the deps
	// would make the 'loading' update re-run the effect, whose cleanup then
	// cancels the very fetch that set it — the panel would spin forever.
	useEffect(() => {
		if (active !== 'contract' || contractStarted.current) {
			return;
		}
		contractStarted.current = true;
		let cancelled = false;
		setContract({ status: 'loading' });
		fetch(`${API_BASE}/products?limit=1`, { headers: { accept: 'application/json' } })
			.then(async (res) => {
				if (!res.ok) {
					throw new Error(`HTTP ${res.status}`);
				}
				const data = (await res.json()) as { totalDocs?: unknown };
				const count = typeof data.totalDocs === 'number' ? data.totalDocs : 0;
				if (!cancelled) {
					setContract({ status: 'ok', count });
				}
			})
			.catch(() => {
				if (!cancelled) {
					setContract({ status: 'error' });
				}
			});
		return () => {
			cancelled = true;
		};
	}, [active]);

	const activate = (id: TabId) => {
		setActive(id);
		document.getElementById(`${uid}-tab-${id}`)?.focus();
	};

	// WAI-ARIA tabs pattern: arrows move selection, Home/End jump to the ends.
	const onKeyDown = (event: ReactKeyboardEvent<HTMLButtonElement>) => {
		const index = TABS.findIndex((tab) => tab.id === active);
		let next = -1;
		if (event.key === 'ArrowRight') {
			next = (index + 1) % TABS.length;
		} else if (event.key === 'ArrowLeft') {
			next = (index - 1 + TABS.length) % TABS.length;
		} else if (event.key === 'Home') {
			next = 0;
		} else if (event.key === 'End') {
			next = TABS.length - 1;
		}
		if (next === -1) {
			return;
		}
		event.preventDefault();
		activate(TABS[next].id);
	};

	return (
		<div className="stack-explorer" data-hydrated={hydrated ? 'true' : 'false'}>
			<div role="tablist" aria-label="Explore the stack">
				{TABS.map((tab) => (
					<button
						key={tab.id}
						type="button"
						role="tab"
						id={`${uid}-tab-${tab.id}`}
						aria-selected={active === tab.id}
						aria-controls={`${uid}-panel-${tab.id}`}
						tabIndex={active === tab.id ? 0 : -1}
						onClick={() => activate(tab.id)}
						onKeyDown={onKeyDown}
					>
						{tab.label}
					</button>
				))}
			</div>

			<div
				role="tabpanel"
				id={`${uid}-panel-frontend`}
				aria-labelledby={`${uid}-tab-frontend`}
				tabIndex={0}
				hidden={active !== 'frontend'}
			>
				<p>
					Every route is rendered on the server: complete HTML arrives before
					any JavaScript runs, so search engines, screen readers, and slow
					connections all get the real page. A handful of React islands
					hydrate only where interactivity is needed, and View Transitions
					make navigation feel instant.
				</p>
			</div>

			<div
				role="tabpanel"
				id={`${uid}-panel-contract`}
				aria-labelledby={`${uid}-tab-contract`}
				tabIndex={0}
				hidden={active !== 'contract'}
			>
				<p>
					The storefront speaks one stable, Payload-shaped REST envelope
					(<code>nvoos-commerce/v1</code>) through the same-origin{' '}
					<code>/api/*</code> proxy. WordPress and WooCommerce serve that
					envelope today; replacing the backend tomorrow would not touch a
					single line of frontend code.
				</p>
				{contract.status === 'loading' && (
					<p className="stack-live" role="status">
						Checking the contract…
					</p>
				)}
				{contract.status === 'ok' && (
					<p className="stack-live" role="status">
						{contract.count.toLocaleString('en-US')} products served by the
						contract right now.
					</p>
				)}
				{contract.status === 'error' && (
					<p className="stack-live" role="status">
						Could not reach the contract right now.
					</p>
				)}
			</div>

			<div
				role="tabpanel"
				id={`${uid}-panel-design`}
				aria-labelledby={`${uid}-tab-design`}
				tabIndex={0}
				hidden={active !== 'design'}
			>
				<p>
					Colors, radii, type, and spacing all flow from a DTCG design-token
					layer with light, dark, and high-contrast modes — and runtime
					brand themes that apply instantly with no rebuild. Every token pair
					is audited against WCAG 2.2 contrast thresholds.
				</p>
				<p className="stack-live">
					This page renders in the {theme.theme} theme with {theme.contrast}{' '}
					contrast.
				</p>
			</div>

			<div
				role="tabpanel"
				id={`${uid}-panel-ai`}
				aria-labelledby={`${uid}-tab-ai`}
				tabIndex={0}
				hidden={active !== 'ai'}
			>
				<p>
					An MCP endpoint (<code>/mcp</code>) exposes 25 tools across design,
					CMS, and ops, so an AI assistant can browse the catalog, apply
					themes, audit contrast, and manage content through the same
					surface a human manager uses. Every write is fail-closed and
					authenticated.
				</p>
			</div>
		</div>
	);
}
