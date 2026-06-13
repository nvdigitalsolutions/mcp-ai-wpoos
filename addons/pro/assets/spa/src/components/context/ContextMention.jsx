/**
 * ContextMention — @-mention popover with grouped results.
 * Zed equivalent: @-mention autocomplete.
 */

export default function ContextMention({ results, visible, selectedIndex, onSelect, onClose }) {
	if (!visible) return null;

	const groups = Object.entries(results || {});
	const flat = [];
	groups.forEach(([, group]) => {
		if (group.items) flat.push(...group.items);
	});

	return (
		<div className="nvoos-context-mention">
			{groups.map(([type, group]) => (
				<div key={type} className="nvoos-context-mention__group">
					<div className="nvoos-context-mention__group-label">{group.label || type}</div>
					{(group.items || []).map((item) => {
						const idx = flat.indexOf(item);
						return (
							<div
								key={`${type}-${item.id}`}
								className={`nvoos-context-mention__item ${idx === selectedIndex ? 'nvoos-context-mention__item--selected' : ''}`}
								onClick={() => onSelect?.({ type, ...item })}
							>
								<span className="nvoos-context-mention__item-title">{item.title}</span>
								<span className="nvoos-context-mention__item-type">{type}</span>
								{item.excerpt && (
									<span className="nvoos-context-mention__item-excerpt">{item.excerpt}</span>
								)}
							</div>
						);
					})}
				</div>
			))}
			{flat.length === 0 && (
				<div className="nvoos-context-mention__empty">No results</div>
			)}
		</div>
	);
}
