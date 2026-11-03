/**
 * Sidebar navigation — the single nav catalogue (auth/permissions NAV),
 * consumed the same way the console sidebar consumes CONSOLE_NAV_GROUPS:
 * visibility is decided once, in one place.
 *
 * Sections are collapsible per the W3C APG Disclosure Navigation pattern:
 * each section header is a `<button aria-expanded aria-controls>` toggling
 * an item list; the collapsed state persists per browser (localStorage) so
 * the workspace shape survives reloads — the same persistence idea as the
 * plugin's chat-bubble dock (sessionStorage + late-injection tolerant).
 */
import { useState } from '@wordpress/element';
import { NAV, isNavEntryVisible, isCollectionWritable } from '../auth/permissions';
import type { NavSlug } from '../types';

interface SidebarProps {
	activeSlug: NavSlug;
	onSelect: (slug: NavSlug) => void;
}

/** Section labels — keyed by the NavEntry section value. */
const SECTION_LABELS: Record<string, string> = {
	catalog: 'Catalog',
	access: 'Access',
	system: 'System',
};

const SECTION_ORDER = ['catalog', 'access', 'system'] as const;

/** localStorage key for the collapsed-section map. */
const STORAGE_KEY = 'astro-admin-nav-sections';

/** Read the persisted collapse map; failures (blocked storage) are ignored. */
function readStoredCollapse(): Record<string, boolean> {
	try {
		const raw = window.localStorage.getItem(STORAGE_KEY);
		if (raw) {
			const parsed = JSON.parse(raw) as Record<string, boolean>;
			if (parsed && typeof parsed === 'object') {
				return parsed;
			}
		}
	} catch {
		// Storage blocked or corrupt — start expanded.
	}
	return {};
}

const BUTTON_STYLE: Record<string, string | number> = {
	display: 'block',
	width: '100%',
	textAlign: 'left',
	padding: '10px 16px',
	border: 'none',
	background: 'transparent',
	color: '#fff',
	cursor: 'pointer',
	fontSize: 14,
};

export function Sidebar({ activeSlug, onSelect }: SidebarProps) {
	const [collapsed, setCollapsed] = useState<Record<string, boolean>>(readStoredCollapse);

	const toggleSection = (section: string) => {
		setCollapsed((prev) => {
			const next = { ...prev, [section]: !prev[section] };
			try {
				window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
			} catch {
				// Storage blocked — the toggle still works for this session.
			}
			return next;
		});
	};

	const renderItem = (slug: NavSlug, label: string, readonly = false) => (
		<li key={slug}>
			<button
				type="button"
				onClick={() => onSelect(slug)}
				style={{
					...BUTTON_STYLE,
					background: slug === activeSlug ? '#007cba' : 'transparent',
				}}
				aria-current={slug === activeSlug ? 'page' : undefined}
			>
				{label}
				{readonly ? ' (read-only)' : ''}
			</button>
		</li>
	);

	const sections = SECTION_ORDER.map((section) => ({
		section,
		entries: NAV.filter(
			(entry) => entry.section === section && isNavEntryVisible(entry)
		),
	})).filter((group) => group.entries.length > 0);

	return (
		<nav aria-label="Astro CMS">
			<div style={{ padding: '0 16px 16px', fontWeight: 700, fontSize: 16 }}>
				Astro CMS
			</div>
			{sections.map(({ section, entries }) => {
				const isOpen = !collapsed[section];
				const panelId = `astro-admin-nav-${section}`;
				return (
					<div key={section} className="nav-section">
						<button
							type="button"
							className="nav-section-toggle"
							aria-expanded={isOpen}
							aria-controls={panelId}
							onClick={() => toggleSection(section)}
						>
							<span>{SECTION_LABELS[section]}</span>
							<span className="nav-section-chevron" aria-hidden="true">
								{isOpen ? '▾' : '▸'}
							</span>
						</button>
						<ul id={panelId} className="nav-section-items" hidden={!isOpen}>
							{entries.map((entry) =>
								renderItem(
									entry.slug,
									entry.label,
									entry.readOnlyAware && !isCollectionWritable(entry.slug)
								)
							)}
						</ul>
					</div>
				);
			})}
		</nav>
	);
}
