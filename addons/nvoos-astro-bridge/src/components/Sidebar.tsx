/**
 * Sidebar navigation — the single nav catalogue (auth/permissions NAV),
 * consumed the same way the console sidebar consumes CONSOLE_NAV_GROUPS:
 * visibility is decided once, in one place.
 */
import { NAV, isNavEntryVisible, isCollectionWritable } from '../auth/permissions';
import type { NavSlug } from '../types';

interface SidebarProps {
	activeSlug: NavSlug;
	onSelect: (slug: NavSlug) => void;
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
	const renderButton = (slug: NavSlug, label: string, readonly = false) => (
		<button
			key={slug}
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
	);

	const visibleCatalog = NAV.filter(
		(entry) => entry.section === 'catalog' && isNavEntryVisible(entry)
	);
	const visibleAccess = NAV.filter(
		(entry) => entry.section === 'access' && isNavEntryVisible(entry)
	);

	return (
		<nav aria-label="Astro CMS">
			<div style={{ padding: '0 16px 16px', fontWeight: 700, fontSize: 16 }}>
				Astro CMS
			</div>
			{visibleCatalog.map((entry) =>
				renderButton(
					entry.slug,
					entry.label,
					entry.readOnlyAware && !isCollectionWritable(entry.slug)
				)
			)}
			{visibleAccess.length > 0 && (
				<>
					<div
						style={{
							padding: '16px 16px 4px',
							fontSize: 11,
							textTransform: 'uppercase',
							opacity: 0.7,
						}}
					>
						Access
					</div>
					{visibleAccess.map((entry) => renderButton(entry.slug, entry.label))}
				</>
			)}
		</nav>
	);
}
