/**
 * Sidebar navigation — collections plus the managers section.
 */
import { collections } from '../config/collections';
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
	const renderButton = (slug: NavSlug, label: string) => (
		<button
			key={slug}
			onClick={() => onSelect(slug)}
			style={{
				...BUTTON_STYLE,
				background: slug === activeSlug ? '#007cba' : 'transparent',
			}}
		>
			{label}
		</button>
	);

	return (
		<nav>
			<div style={{ padding: '0 16px 16px', fontWeight: 700, fontSize: 16 }}>
				Astro CMS
			</div>
			{collections.map((col) => renderButton(col.slug, col.pluralLabel))}
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
			{renderButton('managers', 'Managers')}
		</nav>
	);
}
