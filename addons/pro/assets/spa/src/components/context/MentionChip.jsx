/**
 * MentionChip — Rendered mention in the message editor.
 * Shows as a small pill that can be removed.
 */

export default function MentionChip({ mention, onRemove }) {
	return (
		<span className="nvoos-mention-chip">
			<span className="nvoos-mention-chip__icon">@</span>
			<span className="nvoos-mention-chip__label">
				{mention.type}:{mention.title || mention.id}
			</span>
			<button
				className="nvoos-mention-chip__remove"
				onClick={() => onRemove?.(mention)}
			>
				&times;
			</button>
		</span>
	);
}
