// ---------------------------------------------------------------------------
// HomeStats island — the live counters on the home page (client:visible).
//
// The server-rendered HTML carries the real contract totals so crawlers and
// no-JS visitors see the numbers; on hydration the island animates 0 →
// target. prefers-reduced-motion visitors keep the server value untouched
// (the global reduced-motion kill switch only zeroes transition durations —
// a requestAnimationFrame counter still runs, so it is gated here too).
// ---------------------------------------------------------------------------

import { useEffect, useState } from 'react';

interface Counts {
	products: number;
	categories: number | null;
	brands: number | null;
	posts: number | null;
}

interface Props {
	counts: Counts;
}

const STATS: Array<{ key: keyof Counts; label: string }> = [
	{ key: 'products', label: 'Products' },
	{ key: 'categories', label: 'Categories' },
	{ key: 'brands', label: 'Brands' },
	{ key: 'posts', label: 'Journal posts' },
];

export default function HomeStats({ counts }: Props) {
	const [hydrated, setHydrated] = useState(false);

	useEffect(() => {
		setHydrated(true);
	}, []);

	return (
		<dl
			className="home-stats"
			data-hydrated={hydrated ? 'true' : 'false'}
			aria-label="Live store stats"
		>
			{STATS.map(({ key, label }) => (
				<StatItem key={key} label={label} target={counts[key]} />
			))}
		</dl>
	);
}

function StatItem({ label, target }: { label: string; target: number | null }) {
	// Start at the target so the server HTML (and any no-JS render) shows the
	// real number; hydration state matches and the effect animates from zero.
	// A null target (endpoint not deployed on the backend yet) renders "—"
	// on both sides and never animates.
	const [value, setValue] = useState<number | null>(target);

	useEffect(() => {
		if (target === null || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
			return;
		}
		setValue(0);
		let raf = 0;
		const start = performance.now();
		const duration = 900;
		const tick = (now: number) => {
			const progress = Math.min(1, (now - start) / duration);
			const eased = 1 - Math.pow(1 - progress, 3);
			setValue(Math.round(target * eased));
			if (progress < 1) {
				raf = requestAnimationFrame(tick);
			}
		};
		raf = requestAnimationFrame(tick);
		return () => cancelAnimationFrame(raf);
	}, [target]);

	return (
		<div className="home-stats__item">
			<dt>{label}</dt>
			<dd data-count={target === null ? undefined : target}>
				{value === null ? '—' : value.toLocaleString('en-US')}
			</dd>
		</div>
	);
}
